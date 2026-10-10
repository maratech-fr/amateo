import { Check, Pencil, Plus, Trash2 } from "lucide-react";
import { useEffect, useMemo, useRef, useState } from "react";

import type { CalendarEntry } from "@/features/cockpit/api";
import { periodAdjustWeeks } from "@/features/cockpit/lib/date";
import { useWorkingSeason } from "@/shared/session/queries";
import { ResourceFilter } from "@/features/planning/ResourceFilter";
import { usePriorityTiers, useWizardCoachPlayers, useWizardCoaches, useWizardTeamCoaches, useWizardTeams } from "@/features/wizard/queries";
import { dayLabel } from "@/features/wizard/lib/days";
import { groupedCoaches } from "@/features/wizard/lib/ranking";
import { groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";
import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { cn } from "@/shared/lib/utils";
import { toast } from "@/shared/stores/toastStore";

import type { CoachWish, CoachWishPayload, CoachWishTransferRecap } from "./api";
import { CoachWishForm } from "./CoachWishForm";
import type { CoachWishMutualization, CoachWishMutualizationPayload } from "./mutualizationApi";
import { MutualizationForm } from "./MutualizationForm";
import { useCoachWishMutualizations, useCreateCoachWishMutualization, useDeleteCoachWishMutualization, useUpdateCoachWishMutualization } from "./mutualizationQueries";
import { useCoachWishes, useCreateCoachWish, useDeleteCoachWish, useTransferCoachWishes, useUpdateCoachWish } from "./queries";
import { frDate } from "./wishSections";

/** État du geste « Transférer » remonté au pied de la fenêtre (CoachWishesHub) : le pied unique est
 *  piloté par l'onglet actif, mais le DÉTAIL (compteur, action, attente) vit ici, avec la sélection
 *  et les filtres. `run` appelle TOUJOURS le dernier `doTransfer` (via une ref), jamais une capture
 *  figée. `null` = rien à transférer dans la vue (aucune doléance visible) → pas de pied. */
export interface WishTransferFooter {
  count: number;
  pending: boolean;
  run: () => void;
}

/** Libellés FR des motifs d'ignorance du serveur (`CoachWishTransferService`) — on INFORME toujours
 *  de la raison d'une ignorée (retour fondateur 2026-10-11). Clé inconnue → « autre » (jamais un code
 *  serveur brut à l'écran). */
const IGNORED_REASON_LABELS: Record<string, string> = {
  semaine_sans_plan: "semaine sans planning",
  introuvable: "introuvable",
  id_invalide: "identifiant invalide",
  autre_saison: "autre saison",
};

const ignoredReasonLabel = (key: string): string => IGNORED_REASON_LABELS[key] ?? "autre";

/** Résume les motifs d'ignorance, regroupés et comptés : un seul motif couvrant TOUTES les ignorées
 *  → juste son libellé (« semaine sans planning ») ; plusieurs → comptés (« 2 semaine sans planning,
 *  1 autre saison »), du plus fréquent au moins fréquent. */
function ignoredReasonsSummary(reasons: Record<string, number>, total: number): string {
  const entries = Object.entries(reasons)
    .filter(([, n]) => n > 0)
    .sort((a, b) => b[1] - a[1]);
  if (0 === entries.length) {
    return "";
  }
  if (1 === entries.length && entries[0][1] === total) {
    return ignoredReasonLabel(entries[0][0]);
  }
  return entries.map(([key, n]) => `${n} ${ignoredReasonLabel(key)}`).join(", ");
}

/** Le texte de récap d'un transfert : « N converties · M ignorées : <raisons> » (singulier/pluriel
 *  soignés). On donne TOUJOURS la raison des ignorées (retour fondateur 2026-10-11). */
function transferRecapMessage(recap: CoachWishTransferRecap): string {
  const converted = `${recap.converted} convertie${recap.converted > 1 ? "s" : ""}`;
  if (0 === recap.ignored) {
    return 0 === recap.converted ? "Aucune doléance à transférer" : converted;
  }
  const ignored = `${recap.ignored} ignorée${recap.ignored > 1 ? "s" : ""}`;
  const reasons = ignoredReasonsSummary(recap.ignoredReasons, recap.ignored);
  return `${converted} · ${ignored}${"" === reasons ? "" : ` : ${reasons}`}`;
}

/**
 * La todo-list des doléances coachs d'une période de vacances (feature #10, lot C1) — onglet
 * « Doléances » de la fenêtre unique `CoachWishesHub`. Ouvert depuis le wizard (bandeau période,
 * filtré sur la semaine du plan courant) ET depuis le cockpit (carte de la période mère, toutes
 * semaines). Même objet, deux portes : cocher « traité » ici se voit là-bas.
 *
 * `mother` est toujours l'entrée MÈRE des vacances (le wizard résout parentEntryId ?? id avant
 * d'ouvrir). `weekFilter` (lundi ISO | null) = la semaine du plan courant, ou null pour tout voir
 * groupé par semaine. Déplacement VERBATIM du corps de l'ex-`CoachWishesModal` (le titre et le
 * `Modal` vivent désormais au niveau du conteneur `CoachWishesHub`).
 */
export function WishesTab({
  mother,
  weekFilter,
  onTransferStateChange,
}: {
  mother: CalendarEntry;
  weekFilter: string | null;
  /** Remonte l'état du geste « Transférer » au pied de la fenêtre (CoachWishesHub), ou `null` quand
   *  il n'y a rien à transférer dans la vue. Stable (c'est le `setState` du hub) : pas de boucle. */
  onTransferStateChange?: (state: WishTransferFooter | null) => void;
}) {
  const season = useWorkingSeason();
  const { data: wishes = [] } = useCoachWishes(mother.id);
  const teamsQuery = useWizardTeams();
  // Mémorisé sur `teamsQuery.data` : stable d'un rendu à l'autre (sinon le `?? []` fabriquerait un
  // nouveau tableau à chaque rendu et ferait « bouger » les useMemo qui en dépendent).
  const teams = useMemo(() => teamsQuery.data ?? [], [teamsQuery.data]);
  // « Aucune équipe dans ce club » ne doit s'afficher que sur une lecture POSÉE, jamais pendant le
  // chargement (sinon un vide crédible : le bandeau s'affichait À TORT alors qu'une doléance existait,
  // retour fondateur 2026-10-11). `undefined` = pas encore lu (règle readState).
  const teamsReady = undefined !== teamsQuery.data;
  const { data: coaches = [] } = useWizardCoaches();
  const { data: teamCoaches = [] } = useWizardTeamCoaches();
  const { data: coachPlayers = [] } = useWizardCoachPlayers();
  const { data: tiers = [] } = usePriorityTiers();
  const createWish = useCreateCoachWish();
  const updateWish = useUpdateCoachWish();
  const deleteWish = useDeleteCoachWish();
  const transfer = useTransferCoachWishes();
  const { data: mutualizations = [] } = useCoachWishMutualizations(mother.id);
  const createMut = useCreateCoachWishMutualization();
  const updateMut = useUpdateCoachWishMutualization();
  const deleteMut = useDeleteCoachWishMutualization();

  const [coachFilter, setCoachFilter] = useState<string[]>([]);
  const [teamFilter, setTeamFilter] = useState<string[]>([]);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<CoachWish | null>(null);
  const [toDelete, setToDelete] = useState<CoachWish | null>(null);
  const [mutFormOpen, setMutFormOpen] = useState(false);
  const [editingMut, setEditingMut] = useState<CoachWishMutualization | null>(null);
  const [toDeleteMut, setToDeleteMut] = useState<CoachWishMutualization | null>(null);
  // P2-63 PR 5 (D-b) — la case de SÉLECTION du transfert (à gauche), distincte de l'état
  // « traité » (à droite). Présélection = les doléances NON traitées de la vue (effet one-shot).
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const preselectedView = useRef<string | null>(null);
  // Après un transfert réussi la sélection se vide → le bouton « Transférer » se désactive ALORS
  // QU'IL A LE FOCUS, qui retombe sur <body> (même piège que la bascule d'onglet, revue #346). On
  // ramène le focus sur la liste (ses lignes converties sont barrées, le résultat est là).
  const listRegionRef = useRef<HTMLDivElement>(null);

  const weeks = useMemo(
    () => (null === season ? [] : periodAdjustWeeks(mother.startDate, mother.endDate, season, mother.periodType)),
    [mother.startDate, mother.endDate, mother.periodType, season],
  );
  const shownWeeks = useMemo(() => (null === weekFilter ? weeks : weeks.filter((w) => w.monday === weekFilter)), [weeks, weekFilter]);

  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  const coachName = new Map(coaches.map((c) => [c.id, `${c.firstName} ${c.lastName}`.trim()]));
  // P3-14 (retour terrain) — les deux filtres se lisaient dans l'ordre BRUT de l'API. Les
  // regroupements existent déjà et servent partout ailleurs : on les réutilise plutôt que
  // d'inventer un tri de plus (staffing pour les coachs — récap et onglet contraintes ;
  // rang pour les équipes — comme partout où une équipe se choisit).
  const coachStaffing = useMemo(() => groupedCoaches(coaches, new Set(coachPlayers.filter((cp) => cp.isActive).map((cp) => cp.coachId))), [coaches, coachPlayers]);
  const coachGroups = (
    [
      ["Salariés", coachStaffing.salaried],
      ["Coachs-joueurs", coachStaffing.player],
      ["Bénévoles", coachStaffing.other],
    ] as const
  )
    .filter(([, group]) => group.length > 0)
    .map(([label, group]) => ({ label, resources: group.map((c) => ({ id: c.id, label: `${c.firstName} ${c.lastName}`.trim() })) }));
  const teamGroups = groupTeamsByTier(teams, tiers).map((g) => ({ label: tierGroupLabel(g.tier), resources: g.teams.map((t) => ({ id: t.id, label: t.name })) }));

  // La COLLECTE par mail reste bornée aux équipes à coach principal (décision fondateur
  // 2026-08-01) — c'est ce que sert `teamsWithMainCoach`, encore la cible de la mutualisation.
  // ⚠ L'AJOUT MANUEL, lui, accepte TOUTE équipe depuis P2-63 PR 2 (Q5 : « il est facultatif si
  // on passe en mode manuel ») : le gestionnaire doit pouvoir saisir une doléance pour une
  // équipe sans coach (Vétérans). Le formulaire reçoit donc `teams` entier, coach facultatif.
  const teamsWithMainCoach = useMemo(() => {
    const withMain = new Set(teamCoaches.filter((tc) => "MAIN" === tc.role).map((tc) => tc.teamId));

    return teams.filter((t) => withMain.has(t.id));
  }, [teams, teamCoaches]);

  // P2-63 D — par semaine, les équipes DÉJÀ servies : le formulaire d'ajout les désactive
  // (jamais masquées) et le serveur rend un 422 sur un doublon (période, équipe, semaine).
  const servedByWeek = useMemo(() => {
    const map = new Map<string, Set<string>>();
    for (const w of wishes) {
      const set = map.get(w.weekStart) ?? new Set<string>();
      set.add(w.teamId);
      map.set(w.weekStart, set);
    }

    return map;
  }, [wishes]);

  const visible = wishes.filter(
    (w) =>
      shownWeeks.some((sw) => sw.monday === w.weekStart) &&
      (0 === teamFilter.length || teamFilter.includes(w.teamId)) &&
      (0 === coachFilter.length || (null !== w.coachId && coachFilter.includes(w.coachId))),
  );

  // Présélection one-shot des doléances NON traitées quand la vue (période × semaine) se charge.
  // Keyée par la vue : rouvrir sur une autre semaine re-présélectionne ; les cases cochées à la
  // main ensuite ne sont pas écrasées (l'effet ne retourne qu'une fois par vue).
  useEffect(() => {
    const key = `${mother.id}|${weekFilter ?? "all"}`;
    if (preselectedView.current === key || 0 === wishes.length) {
      return;
    }
    preselectedView.current = key;
    const untreated = wishes.filter((w) => shownWeeks.some((sw) => sw.monday === w.weekStart) && !w.done).map((w) => w.id);
    setSelected(new Set(untreated));
  }, [mother.id, weekFilter, wishes, shownWeeks]);

  const selectedVisible = visible.filter((w) => selected.has(w.id));
  const toggleSelected = (id: string) =>
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });

  const doTransfer = () => {
    const ids = selectedVisible.map((w) => w.id);
    if (0 === ids.length) {
      return;
    }
    transfer.mutate(ids, {
      // onSuccess (jamais onError) peut poser un toast : le récap EST le retour du geste. Les
      // erreurs (dont le 409 « génération en cours ») restent au filet global (règle FRT-38).
      onSuccess: (recap) => {
        toast.success(transferRecapMessage(recap));
        setSelected(new Set());
        listRegionRef.current?.focus();
      },
    });
  };

  // Le geste « Transférer » vit désormais dans le PIED de la fenêtre (CoachWishesHub) — un seul pied
  // piloté par l'onglet actif (retour fondateur 2026-10-11). On remonte le DÉTAIL (compteur, attente,
  // action) ; la sélection et les filtres restent ici. `run` appelle le dernier `doTransfer` via une
  // ref (jamais une capture figée), donc l'effet ne dépend QUE des valeurs primitives : pas de boucle
  // (`onTransferStateChange` est le `setState` stable du hub). `null` quand rien n'est visible → pas de pied.
  const doTransferRef = useRef(doTransfer);
  // La ref se met à jour dans un EFFET (jamais pendant le rendu — react-hooks/refs) : `run` appelle le
  // dernier `doTransfer` au clic, toujours après commit, donc la ref est à jour.
  useEffect(() => {
    doTransferRef.current = doTransfer;
  });
  const visibleCount = visible.length;
  const selectedCount = selectedVisible.length;
  const transferPending = transfer.isPending;
  useEffect(() => {
    if (0 === visibleCount) {
      onTransferStateChange?.(null);
      return;
    }
    onTransferStateChange?.({ count: selectedCount, pending: transferPending, run: () => doTransferRef.current() });
    return () => onTransferStateChange?.(null);
  }, [onTransferStateChange, visibleCount, selectedCount, transferPending]);

  const submit = (payload: CoachWishPayload) => {
    const after = () => {
      setFormOpen(false);
      setEditing(null);
    };
    if (null !== editing) {
      updateWish.mutate({ id: editing.id, body: payload }, { onSuccess: after });
    } else {
      createWish.mutate(payload, { onSuccess: after });
    }
  };

  const submitMut = (payload: CoachWishMutualizationPayload) => {
    const after = () => {
      setMutFormOpen(false);
      setEditingMut(null);
    };
    if (null !== editingMut) {
      updateMut.mutate({ id: editingMut.id, body: payload }, { onSuccess: after });
    } else {
      createMut.mutate(payload, { onSuccess: after });
    }
  };

  const toggleMutDone = (m: CoachWishMutualization) =>
    updateMut.mutate({
      id: m.id,
      // coachId PRÉSERVÉ tel quel (null si dé-attribuée) — parité toggleDone des doléances.
      body: { calendarEntryId: m.calendarEntryId, teamId: m.teamId, coachId: m.coachId, partnerTeamIds: m.partnerTeamIds, sharedSlots: m.sharedSlots, done: !m.done },
    });

  // Les mutualisations sont au grain PÉRIODE (pas par semaine) : on les filtre seulement par
  // équipe/coach (les mêmes filtres que les doléances), jamais par la semaine affichée.
  const visibleMut = mutualizations.filter(
    (m) => (0 === teamFilter.length || teamFilter.includes(m.teamId)) && (0 === coachFilter.length || (null !== m.coachId && coachFilter.includes(m.coachId))),
  );

  return (
    <>
      <div className="mt-3 flex flex-wrap items-center gap-2">
        <ResourceFilter viewMode="coach" groups={coachGroups} selected={coachFilter} onToggle={(id) => setCoachFilter((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]))} onClear={() => setCoachFilter([])} />
        <ResourceFilter viewMode="equipe" groups={teamGroups} selected={teamFilter} onToggle={(id) => setTeamFilter((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]))} onClear={() => setTeamFilter([])} />
        <Button
          type="button"
          size="sm"
          variant="outline"
          className="ml-auto"
          disabled={0 === teams.length}
          disabledReason={0 === teams.length ? "Aucune équipe dans ce club" : undefined}
          onClick={() => {
            setEditing(null);
            setFormOpen(true);
          }}
        >
          <Plus className="size-4" />
          Ajouter
        </Button>
      </div>

      {/* P2-63 PR 5 — le geste de MASSE vit dans le PIED de la fenêtre (retour fondateur 2026-10-11) :
          ici, juste l'aide qui explique les cases (prose → corps défilant, jamais le pied). */}
      {visible.length > 0 ? (
        <p className="mt-2 text-xs text-muted-foreground">Cochez les doléances à transférer : elles deviennent des réglages du plan de leur semaine.</p>
      ) : null}

      {/* Sans équipe du tout, « Ajouter » n'ouvrirait qu'un formulaire sans cible. Sur une lecture
          POSÉE seulement (jamais pendant le chargement — vide crédible, retour fondateur 2026-10-11). */}
      {teamsReady && 0 === teams.length ? (
        <NoticeBanner
          tone="muted"
          className="mt-2"
          message="Aucune équipe dans ce club : ajoutez-en une pour pouvoir saisir une doléance."
        />
      ) : null}

      {formOpen && teams.length > 0 ? (
        <div className="mt-2">
          <CoachWishForm
            calendarEntryId={mother.id}
            weeks={shownWeeks}
            lockedWeek={weekFilter}
            teams={teams}
            tiers={tiers}
            teamCoaches={teamCoaches}
            servedByWeek={servedByWeek}
            editing={editing}
            pending={createWish.isPending || updateWish.isPending}
            onSubmit={submit}
            onCancel={() => {
              setFormOpen(false);
              setEditing(null);
            }}
          />
        </div>
      ) : null}

      <div ref={listRegionRef} tabIndex={-1} className="mt-3 space-y-3 outline-none">
        {shownWeeks.map((week) => {
          const items = visible.filter((w) => w.weekStart === week.monday);
          return (
            <section key={week.monday}>
              {null === weekFilter ? <h3 className="mb-1 text-xs font-semibold text-muted-foreground">Semaine du {frDate(week.startDate)}</h3> : null}
              {0 === items.length ? (
                <EmptyHint className="text-xs">Aucune doléance pour cette semaine.</EmptyHint>
              ) : (
                <ul className="space-y-1">
                  {items.map((w) => (
                    <li key={w.id} className="flex items-start gap-2 rounded-md border border-border px-3 py-2 text-sm">
                      {/* Case de SÉLECTION du transfert, à gauche. Une doléance TRAITÉE est déjà
                          transférée : elle n'a plus de case (retour fondateur 2026-10-11). */}
                      {w.done ? null : (
                        <input type="checkbox" aria-label={`Sélectionner la doléance — ${teamName.get(w.teamId) ?? "équipe"} · semaine du ${frDate(week.startDate)}`} className="mt-1 size-4" checked={selected.has(w.id)} onChange={() => toggleSelected(w.id)} />
                      )}
                      <div className="min-w-0 flex-1">
                        {/* Pas de coach (coachId null) = doléance manuelle sans coach : on n'affiche
                            AUCUN coach (juste l'équipe). « coach dé-attribué » ne vaut QUE pour le vrai
                            cas « coach retiré » : un coachId posé mais introuvable (coach supprimé/parti),
                            retours fondateur 2026-10-10. */}
                        <div className="flex flex-wrap items-center gap-1.5">
                          <p className="font-medium">
                            {teamName.get(w.teamId) ?? "Équipe"}
                            {null !== w.coachId ? <> · {coachName.get(w.coachId) ?? <span className="italic text-muted-foreground">coach dé-attribué</span>}</> : null}
                          </p>
                          {/* Pastille VERTE « Traité » À CÔTÉ du nom (plus de case à droite), retour
                              fondateur 2026-10-11 : une doléance transférée/traitée est marquée ici. */}
                          {w.done ? (
                            <StatusPill variant="success" icon={<Check className="size-3 text-success" aria-hidden="true" />}>
                              Traité
                            </StatusPill>
                          ) : null}
                        </div>
                        <p className="text-xs text-muted-foreground">
                          {0 === w.slotsWanted ? "Aucun créneau souhaité" : `${w.slotsWanted} créneau${w.slotsWanted > 1 ? "x" : ""} souhaité${w.slotsWanted > 1 ? "s" : ""}`}
                          {w.wishedDays.length > 0 ? ` · souhaité : ${w.wishedDays.map(dayLabel).join(", ")}` : ""}
                          {w.unavailableDays.length > 0 ? ` · indispo : ${w.unavailableDays.map(dayLabel).join(", ")}` : ""}
                        </p>
                        {null !== w.comment ? <p className="mt-0.5 text-xs">{w.comment}</p> : null}
                        {/* Volet B — le coach souhaite garder ses créneaux habituels (saison). */}
                        {w.keepSeasonSlots ? (
                          <StatusPill variant="accent" className="mt-1">
                            souhaite garder ses créneaux
                          </StatusPill>
                        ) : null}
                      </div>
                      <div className="flex shrink-0 items-center gap-1">
                        {/* Le marquage « traité / non traité » passe par le crayon (formulaire),
                            retour fondateur 2026-10-11 : plus de coche « traité » ici. */}
                        <Button
                          type="button"
                          size="icon"
                          variant="ghost"
                          className="size-7"
                          aria-label={`Modifier la doléance · ${teamName.get(w.teamId) ?? "équipe"} · semaine du ${frDate(week.startDate)}`}
                          onClick={() => {
                            setEditing(w);
                            setFormOpen(true);
                          }}
                        >
                          <Pencil className="size-4" />
                        </Button>
                        <Button type="button" size="icon" variant="ghost" className="size-7 text-destructive" aria-label={`Supprimer la doléance · ${teamName.get(w.teamId) ?? "équipe"} · semaine du ${frDate(week.startDate)}`} disabled={deleteWish.isPending} onClick={() => setToDelete(w)}>
                          <Trash2 className="size-4" />
                        </Button>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          );
        })}
      </div>

      {/* Mutualisations — grain PÉRIODE (une par équipe), hors de la boucle des semaines. */}
      <section className="mt-5 border-t border-border pt-3">
        <div className="flex flex-wrap items-center gap-2">
          <h3 className="text-sm font-semibold">Mutualisations</h3>
          <Button
            type="button"
            size="sm"
            variant="outline"
            className="ml-auto"
            disabled={0 === teamsWithMainCoach.length}
            disabledReason={0 === teamsWithMainCoach.length ? "Aucune équipe n'a de coach principal" : undefined}
            onClick={() => {
              setEditingMut(null);
              setMutFormOpen(true);
            }}
          >
            <Plus className="size-4" />
            Ajouter une mutualisation
          </Button>
        </div>

        {mutFormOpen && teamsWithMainCoach.length > 0 ? (
          <div className="mt-2">
            <MutualizationForm
              calendarEntryId={mother.id}
              teams={teamsWithMainCoach}
              allTeams={teams}
              tiers={tiers}
              coaches={coaches}
              teamCoaches={teamCoaches}
              editing={editingMut}
              pending={createMut.isPending || updateMut.isPending}
              onSubmit={submitMut}
              onCancel={() => {
                setMutFormOpen(false);
                setEditingMut(null);
              }}
            />
          </div>
        ) : null}

        {0 === visibleMut.length ? (
          <EmptyHint className="mt-2 text-xs">Aucune mutualisation déclarée pour cette période.</EmptyHint>
        ) : (
          <ul className="mt-2 space-y-1">
            {visibleMut.map((m) => (
              <li key={m.id} className="flex items-start gap-2 rounded-md border border-border px-3 py-2 text-sm">
                <input type="checkbox" aria-label={`Traité — mutualisation ${teamName.get(m.teamId) ?? "équipe"}`} className="mt-1 size-4" checked={m.done} onChange={() => toggleMutDone(m)} />
                <div className={cn("min-w-0 flex-1", m.done && "line-through")}>
                  <p className="font-medium">
                    {teamName.get(m.teamId) ?? "Équipe"} · {null === m.coachId ? <span className="italic text-muted-foreground">coach dé-attribué</span> : (coachName.get(m.coachId) ?? "Coach")}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    souhaite mutualiser {m.sharedSlots} séance{m.sharedSlots > 1 ? "s" : ""} avec : {m.partnerTeamIds.map((id) => teamName.get(id) ?? "équipe").join(", ")}
                  </p>
                </div>
                <div className="flex shrink-0 gap-1">
                  <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="size-7"
                    aria-label={`Modifier la mutualisation · ${teamName.get(m.teamId) ?? "équipe"}`}
                    onClick={() => {
                      setEditingMut(m);
                      setMutFormOpen(true);
                    }}
                  >
                    <Pencil className="size-4" />
                  </Button>
                  <Button type="button" size="icon" variant="ghost" className="size-7 text-destructive" aria-label={`Supprimer la mutualisation · ${teamName.get(m.teamId) ?? "équipe"}`} disabled={deleteMut.isPending} onClick={() => setToDeleteMut(m)}>
                    <Trash2 className="size-4" />
                  </Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>

      <ConfirmDialog
        open={null !== toDeleteMut}
        title="Supprimer cette mutualisation ?"
        description={null !== toDeleteMut ? `La mutualisation de « ${teamName.get(toDeleteMut.teamId) ?? "l'équipe"} » sera définitivement retirée.` : undefined}
        confirmLabel="Supprimer"
        destructive
        onConfirm={() => {
          if (null !== toDeleteMut) {
            deleteMut.mutate(toDeleteMut.id);
          }
          setToDeleteMut(null);
        }}
        onCancel={() => setToDeleteMut(null)}
      />

      <ConfirmDialog
        open={null !== toDelete}
        title="Supprimer cette doléance ?"
        description={null !== toDelete ? `La doléance de « ${teamName.get(toDelete.teamId) ?? "l'équipe"} » sera définitivement retirée.` : undefined}
        confirmLabel="Supprimer"
        destructive
        onConfirm={() => {
          if (null !== toDelete) {
            deleteWish.mutate(toDelete.id);
          }
          setToDelete(null);
        }}
        onCancel={() => setToDelete(null)}
      />
    </>
  );
}
