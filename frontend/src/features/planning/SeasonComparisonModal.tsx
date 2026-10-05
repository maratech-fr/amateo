import { useMemo } from "react";

import { Modal } from "@/shared/components/ui/modal";

import { buildGrid, type Lookups, slotGroupKey } from "./lib/grid";
import { useCoachPlayers, useCoaches, useSlots, useTeamCoaches, useTeams, useTrainingSlots, useVenues } from "./queries";
import type { ViewMode } from "./store";
import { WeekGrid } from "./WeekGrid";

interface SeasonComparisonModalProps {
  /** La version du plan de SAISON pointée (le socle transcrit) à consulter. */
  seasonScheduleId: string;
  /** La vue courante de la période, pour comparer à l'identique (colonnes = gymnases, etc.). */
  viewMode: ViewMode;
  onClose: () => void;
}

/**
 * P2-44 (PR-2) — « Comparer avec la saison » : une CONSULTATION en lecture seule du planning de
 * SAISON pointé (le socle), dans une modale, pour comparer d'un coup d'œil avec la période en cours
 * de génération. Réutilise la VUE planning (`buildGrid` + `WeekGrid`), SANS aucun geste (pas de
 * `onToggleLock`, pas de `targetMode`, pas de `closedWindows`) : c'est une consultation du socle
 * dans un contexte période, jamais une bascule de mode. A11y modale via `Modal`/`useModalA11y`.
 *
 * Les `Lookups` sont assemblés ici (câblage de PRÉSENTATION, pas une règle) plutôt que partagés
 * avec `PlanningPage` : on ne remanie pas ce composant de 1600 lignes pour ce lot.
 */
export function SeasonComparisonModal({ seasonScheduleId, viewMode, onClose }: SeasonComparisonModalProps) {
  const { data: slots = [] } = useSlots(seasonScheduleId);
  const { data: teams = [] } = useTeams();
  const { data: venues = [] } = useVenues();
  const { data: coaches = [] } = useCoaches();
  const { data: teamCoaches = [] } = useTeamCoaches();
  const { data: coachPlayers = [] } = useCoachPlayers();
  // Le socle : la grille de saison (schedulePlanId null).
  const { data: trainingSlots = [] } = useTrainingSlots(null);

  const lookups: Lookups = useMemo(() => {
    const teamCoach = new Map<string, string>();
    for (const link of teamCoaches) {
      if ("MAIN" === link.role && !teamCoach.has(link.teamId)) {
        teamCoach.set(link.teamId, link.coachId);
      }
    }
    const teamPlayerCoaches = new Map<string, string[]>();
    for (const link of coachPlayers) {
      if (link.isActive) {
        teamPlayerCoaches.set(link.teamId, [...(teamPlayerCoaches.get(link.teamId) ?? []), link.coachId]);
      }
    }
    const groupLabels = new Map<string, string>();
    for (const ts of trainingSlots) {
      const label = (ts.groupLabel ?? "").trim();
      if ("" !== label) {
        groupLabels.set(slotGroupKey(ts.venueId, ts.dayOfWeek, ts.startTime), label);
      }
    }
    return {
      teams: new Map(teams.map((t) => [t.id, t])),
      venues: new Map(venues.map((v) => [v.id, v])),
      coaches: new Map(coaches.map((c) => [c.id, c])),
      teamCoach,
      teamPlayerCoaches,
      groupLabels,
    };
  }, [teams, venues, coaches, teamCoaches, coachPlayers, trainingSlots]);

  const model = useMemo(() => buildGrid(slots, viewMode, lookups), [slots, viewMode, lookups]);

  return (
    <Modal label="Planning de saison (consultation)" title="Planning de saison" onClose={onClose} size="xl">
      <p className="mt-1 text-xs text-muted-foreground">Consultation en lecture seule du planning de saison, pour comparer avec la période.</p>
      <div className="mt-3 h-[70vh]">
        {/* Consultation : onSelectSlot inerte, aucun geste d'écriture ni mode cible. */}
        <WeekGrid model={model} selectedSlotId={null} onSelectSlot={() => undefined} />
      </div>
    </Modal>
  );
}
