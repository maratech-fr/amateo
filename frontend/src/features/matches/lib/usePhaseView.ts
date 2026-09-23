import { useMemo } from "react";

import type { Competition, Conflict, ConflictType, Fixture, Team } from "../api";
import { applyFamilyFilter, countByFamily } from "./consultFilter";
import { conflictsByFixture } from "./monthView";
import { listPhases, phaseCompleteness, phaseFixtures, scopeConflictsToPhase } from "./phaseView";
import { weekLabel } from "./weekendGrid";

/**
 * Temporalité PHASE du Calendrier : les 10 mémos de la phase + la compétition active.
 * Déplacement pur. `phaseConflicts`/`phaseFamilyCounts` SORTENT (chips familles) ;
 * `phaseGroupsAll`/`phaseAllFixtures`/`phaseGroupsRaw` restent internes.
 */
export function usePhaseView(
  competitions: { data: Competition[] | undefined },
  teams: { data: Team[] | undefined },
  consultPhaseId: string | null,
  kindFixtures: Fixture[],
  visibleFixtures: Fixture[],
  kindConflicts: Conflict[],
  effectiveFamilies: ConflictType[],
  competitionsMap: Map<string, Competition>,
) {
  const phases = useMemo(() => listPhases(competitions.data ?? [], teams.data ?? []), [competitions.data, teams.data]);
  const activePhaseId = useMemo(() => {
    if (0 === phases.length) {
      return null;
    }
    return phases.some((p) => p.competitionId === consultPhaseId) ? consultPhaseId : phases[0].competitionId;
  }, [phases, consultPhaseId]);
  // Complétude + conflits + scoping = toute la compétition (extérieurs INCLUS).
  const phaseGroupsAll = useMemo(() => (null === activePhaseId ? [] : phaseFixtures(kindFixtures, activePhaseId)), [kindFixtures, activePhaseId]);
  const phaseAllFixtures = useMemo(() => phaseGroupsAll.flatMap((g) => g.fixtures), [phaseGroupsAll]);
  // Table d'AFFICHAGE (extérieurs masqués si l'interrupteur est éteint).
  const phaseGroupsRaw = useMemo(() => (null === activePhaseId ? [] : phaseFixtures(visibleFixtures, activePhaseId)), [visibleFixtures, activePhaseId]);
  const phaseConflicts = useMemo(
    () => scopeConflictsToPhase(kindConflicts, activePhaseId, phaseAllFixtures.map((f) => f.id)),
    [kindConflicts, activePhaseId, phaseAllFixtures],
  );
  const phaseFamilyCounts = useMemo(() => countByFamily(phaseConflicts), [phaseConflicts]);
  const phaseCbf = useMemo(() => conflictsByFixture(applyFamilyFilter(phaseConflicts, effectiveFamilies)), [phaseConflicts, effectiveFamilies]);
  const phaseGroups = useMemo(() => phaseGroupsRaw.map((g) => ({ key: g.weekend, label: weekLabel(g.weekend), fixtures: g.fixtures })), [phaseGroupsRaw]);
  const activePhaseCompetition = null === activePhaseId ? undefined : competitionsMap.get(activePhaseId);
  const completeness = useMemo(
    () => (undefined === activePhaseCompetition ? null : phaseCompleteness(activePhaseCompetition, phaseAllFixtures, kindConflicts)),
    [activePhaseCompetition, phaseAllFixtures, kindConflicts],
  );

  return { phases, activePhaseId, phaseConflicts, phaseFamilyCounts, phaseCbf, phaseGroups, activePhaseCompetition, completeness };
}
