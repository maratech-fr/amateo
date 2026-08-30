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
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Modal } from "@/shared/components/ui/modal";
import { cn } from "@/shared/lib/utils";

import type { CoachWish, CoachWishPayload } from "./api";
import { CoachWishForm } from "./CoachWishForm";
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

  const [coachFilter, setCoachFilter] = useState<string[]>([]);
  const [teamFilter, setTeamFilter] = useState<string[]>([]);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<CoachWish | null>(null);

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
      body: { calendarEntryId: w.calendarEntryId, weekStart: w.weekStart, teamId: w.teamId, coachId: w.coachId, slotsWanted: w.slotsWanted, unavailableDays: w.unavailableDays, comment: w.comment, done: !w.done },
    });

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
          title={0 === teamsWithMainCoach.length ? "Aucune équipe n'a de coach principal" : undefined}
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
        <p className="mt-2 rounded-md border border-border bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
          Aucune équipe n'a de coach principal : rattachez-en un pour pouvoir saisir une doléance.
        </p>
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
                    <li key={w.id} className={cn("flex items-start gap-2 rounded-md border border-border px-3 py-2 text-sm", w.done && "opacity-60")}>
                      <input type="checkbox" aria-label={`Traité — ${teamName.get(w.teamId) ?? "équipe"}`} className="mt-1 size-4" checked={w.done} onChange={() => toggleDone(w)} />
                      <div className={cn("min-w-0 flex-1", w.done && "line-through")}>
                        <p className="font-medium">
                          {teamName.get(w.teamId) ?? "Équipe"} · {null === w.coachId ? <span className="italic text-muted-foreground">coach dé-attribué</span> : (coachName.get(w.coachId) ?? "Coach")}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {0 === w.slotsWanted ? "Aucun créneau souhaité" : `${w.slotsWanted} créneau${w.slotsWanted > 1 ? "x" : ""} souhaité${w.slotsWanted > 1 ? "s" : ""}`}
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
                          aria-label="Modifier"
                          onClick={() => {
                            setEditing(w);
                            setFormOpen(true);
                          }}
                        >
                          <Pencil className="size-4" />
                        </Button>
                        <Button type="button" size="icon" variant="ghost" className="size-7 text-destructive" aria-label="Supprimer" disabled={deleteWish.isPending} onClick={() => deleteWish.mutate(w.id)}>
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
    </Modal>
  );
}
