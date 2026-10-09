import { Pencil, Plus, Trash2 } from "lucide-react";
import { useMemo, useState } from "react";

import type { CalendarEntry } from "@/features/cockpit/api";
import { periodAdjustWeeks } from "@/features/cockpit/lib/date";
import { useWorkingSeason } from "@/shared/session/queries";
import { ResourceFilter } from "@/features/planning/ResourceFilter";
import { usePriorityTiers, useWizardCoachPlayers, useWizardCoaches, useWizardTeamCoaches, useWizardTeams } from "@/features/wizard/queries";
import { dayLabel } from "@/features/wizard/lib/days";
import { groupedCoaches } from "@/features/wizard/lib/ranking";
import { groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Modal } from "@/shared/components/ui/modal";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { cn } from "@/shared/lib/utils";

import type { CoachWish, CoachWishPayload } from "./api";
import { CoachWishForm } from "./CoachWishForm";
import type { CoachWishMutualization, CoachWishMutualizationPayload } from "./mutualizationApi";
import { MutualizationForm } from "./MutualizationForm";
import { useCoachWishMutualizations, useCreateCoachWishMutualization, useDeleteCoachWishMutualization, useUpdateCoachWishMutualization } from "./mutualizationQueries";
import { useCoachWishes, useCreateCoachWish, useDeleteCoachWish, useUpdateCoachWish } from "./queries";

/**
 * La todo-list des doléances coachs d'une période de vacances (feature #10, lot C1).
 * Composant PARTAGÉ : ouvert depuis le wizard (bandeau période, filtré sur la semaine du
 * plan courant) ET depuis le cockpit (carte de la période mère, toutes semaines). Même
 * objet, deux portes : cocher « traité » ici se voit là-bas.
 *
 * `mother` est toujours l'entrée MÈRE des vacances (le wizard résout parentEntryId ?? id
 * avant d'ouvrir). `weekFilter` (lundi ISO | null) = la semaine du plan courant, ou null
 * pour tout voir groupé par semaine.
 */
export function CoachWishesModal({ mother, weekFilter, onClose }: { mother: CalendarEntry; weekFilter: string | null; onClose: () => void }) {
  const season = useWorkingSeason();
  const { data: wishes = [] } = useCoachWishes(mother.id);
  const { data: teams = [] } = useWizardTeams();
  const { data: coaches = [] } = useWizardCoaches();
  const { data: teamCoaches = [] } = useWizardTeamCoaches();
  const { data: coachPlayers = [] } = useWizardCoachPlayers();
  const { data: tiers = [] } = usePriorityTiers();
  const createWish = useCreateCoachWish();
  const updateWish = useUpdateCoachWish();
  const deleteWish = useDeleteCoachWish();
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

  // ⚠ Les équipes offertes à la SAISIE ne sont pas celles du FILTRE (décision fondateur
  // 2026-08-01 : « comment avoir une doléance de coach si une équipe n'a pas de coach ?
  // ben c'est pas possible »). Le filtre, lui, garde toutes les équipes : il sert à LIRE
  // des doléances existantes — dont celles d'une équipe qui a perdu son coach depuis.
  const teamsWithMainCoach = useMemo(() => {
    const withMain = new Set(teamCoaches.filter((tc) => "MAIN" === tc.role).map((tc) => tc.teamId));

    return teams.filter((t) => withMain.has(t.id));
  }, [teams, teamCoaches]);

  const visible = wishes.filter(
    (w) =>
      shownWeeks.some((sw) => sw.monday === w.weekStart) &&
      (0 === teamFilter.length || teamFilter.includes(w.teamId)) &&
      (0 === coachFilter.length || (null !== w.coachId && coachFilter.includes(w.coachId))),
  );

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

  const toggleDone = (w: CoachWish) =>
    updateWish.mutate({
      id: w.id,
      // coachId PRÉSERVÉ tel quel (null si dé-attribuée) : envoyer "" échouait le NotBlank
      // et une doléance dé-attribuée ne pouvait jamais être cochée (revue #10 C1).
      body: { calendarEntryId: w.calendarEntryId, weekStart: w.weekStart, teamId: w.teamId, coachId: w.coachId, slotsWanted: w.slotsWanted, unavailableDays: w.unavailableDays, wishedDays: w.wishedDays, comment: w.comment, done: !w.done },
    });

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

  const title = null === weekFilter ? `Doléances des coachs — ${mother.title}` : "Doléances des coachs — semaine";

  return (
    <Modal label="Doléances des coachs" title={title} onClose={onClose} size="lg">
      <div className="mt-3 flex flex-wrap items-center gap-2">
        <ResourceFilter viewMode="coach" groups={coachGroups} selected={coachFilter} onToggle={(id) => setCoachFilter((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]))} onClear={() => setCoachFilter([])} />
        <ResourceFilter viewMode="equipe" groups={teamGroups} selected={teamFilter} onToggle={(id) => setTeamFilter((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]))} onClear={() => setTeamFilter([])} />
        <Button
          type="button"
          size="sm"
          variant="outline"
          className="ml-auto"
          disabled={0 === teamsWithMainCoach.length}
          disabledReason={0 === teamsWithMainCoach.length ? "Aucune équipe n'a de coach principal" : undefined}
          onClick={() => {
            setEditing(null);
            setFormOpen(true);
          }}
        >
          <Plus className="size-4" />
          Ajouter
        </Button>
      </div>

      {/* Sans équipe à coach principal, « Ajouter » n'ouvrirait qu'un formulaire sans
          cible : on dit ce qui manque, au lieu de laisser un select vide. */}
      {0 === teamsWithMainCoach.length ? (
        <NoticeBanner
          tone="muted"
          className="mt-2"
          message="Aucune équipe n'a de coach principal : rattachez-en un pour pouvoir saisir une doléance."
        />
      ) : null}

      {formOpen && teamsWithMainCoach.length > 0 ? (
        <div className="mt-2">
          <CoachWishForm
            calendarEntryId={mother.id}
            weeks={shownWeeks}
            lockedWeek={weekFilter}
            teams={teamsWithMainCoach}
            coaches={coaches}
            teamCoaches={teamCoaches}
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

      <div className="mt-3 space-y-3">
        {shownWeeks.map((week) => {
          const items = visible.filter((w) => w.weekStart === week.monday);
          return (
            <section key={week.monday}>
              {null === weekFilter ? <h3 className="mb-1 text-xs font-semibold text-muted-foreground">Semaine du {week.startDate}</h3> : null}
              {0 === items.length ? (
                <EmptyHint className="text-xs">Aucune doléance pour cette semaine.</EmptyHint>
              ) : (
                <ul className="space-y-1">
                  {items.map((w) => (
                    <li key={w.id} className="flex items-start gap-2 rounded-md border border-border px-3 py-2 text-sm">
                      <input type="checkbox" aria-label={`Traité — ${teamName.get(w.teamId) ?? "équipe"}`} className="mt-1 size-4" checked={w.done} onChange={() => toggleDone(w)} />
                      <div className={cn("min-w-0 flex-1", w.done && "line-through")}>
                        <p className="font-medium">
                          {teamName.get(w.teamId) ?? "Équipe"} · {null === w.coachId ? <span className="italic text-muted-foreground">coach dé-attribué</span> : (coachName.get(w.coachId) ?? "Coach")}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {0 === w.slotsWanted ? "Aucun créneau souhaité" : `${w.slotsWanted} créneau${w.slotsWanted > 1 ? "x" : ""} souhaité${w.slotsWanted > 1 ? "s" : ""}`}
                          {w.wishedDays.length > 0 ? ` · souhaité : ${w.wishedDays.map(dayLabel).join(", ")}` : ""}
                          {w.unavailableDays.length > 0 ? ` · indispo : ${w.unavailableDays.map(dayLabel).join(", ")}` : ""}
                        </p>
                        {null !== w.comment ? <p className="mt-0.5 text-xs">{w.comment}</p> : null}
                      </div>
                      <div className="flex shrink-0 gap-1">
                        <Button
                          type="button"
                          size="icon"
                          variant="ghost"
                          className="size-7"
                          aria-label={`Modifier la doléance · ${teamName.get(w.teamId) ?? "équipe"}`}
                          onClick={() => {
                            setEditing(w);
                            setFormOpen(true);
                          }}
                        >
                          <Pencil className="size-4" />
                        </Button>
                        <Button type="button" size="icon" variant="ghost" className="size-7 text-destructive" aria-label={`Supprimer la doléance · ${teamName.get(w.teamId) ?? "équipe"}`} disabled={deleteWish.isPending} onClick={() => setToDelete(w)}>
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
    </Modal>
  );
}
