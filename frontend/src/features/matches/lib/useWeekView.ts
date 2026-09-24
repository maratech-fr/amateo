import { useMemo } from "react";

import { todayISO } from "@/shared/lib/clock";

import type { Competition, Conflict, ConflictType, Fixture } from "../api";
import { applyFamilyFilter, countByFamily, hiddenWeekBreakdown, scopeConflictsToWeek } from "./consultFilter";
import type { Kind } from "./consultFilter";
import { deriveWeekCounters } from "./loopSteps";
import type { MatchFilterResult } from "./matchFilter";
import { listWeekends, resolveActiveWeekend, weekendKeyOf } from "./weekendGrid";

/**
 * Temporalité SEMAINE du Calendrier : les 9 mémos de la semaine + la semaine active et son
 * index. Déplacement pur depuis la page, entrées en paramètres individuels. `weekAllFilteredFixtures`
 * reste INTERNE (il ne sert que le calcul de l'indice « masqués ») — jamais exposé.
 */
export function useWeekView(
  visibleFixtures: Fixture[],
  selectedWeekend: string | null,
  kindFixtures: Fixture[],
  kindConflicts: Conflict[],
  effectiveFamilies: ConflictType[],
  filtered: MatchFilterResult,
  effectiveKinds: Kind[],
  consultAway: boolean,
  competitionsMap: Map<string, Competition>,
) {
  const weekends = useMemo(() => listWeekends(visibleFixtures), [visibleFixtures]);
  const activeWeekend = resolveActiveWeekend(weekends, selectedWeekend, weekendKeyOf(todayISO()));
  const weekendIndex = null === activeWeekend ? -1 : weekends.indexOf(activeWeekend);
  // Rencontres AFFICHÉES de la semaine (extérieurs masqués si l'interrupteur est éteint).
  const weekendFixtures = useMemo(
    () => (null === activeWeekend ? [] : visibleFixtures.filter((f) => weekendKeyOf(f.matchDate) === activeWeekend)),
    [visibleFixtures, activeWeekend],
  );
  // Rencontres de la semaine, extérieurs INCLUS — sert les COMPTEURS (identiques quel que
  // soit l'interrupteur, NR) et la modale « À recopier dans FBI ».
  const weekendFixturesAll = useMemo(
    () => (null === activeWeekend ? [] : kindFixtures.filter((f) => weekendKeyOf(f.matchDate) === activeWeekend)),
    [kindFixtures, activeWeekend],
  );
  const weekConflicts = useMemo(() => scopeConflictsToWeek(kindConflicts, activeWeekend), [kindConflicts, activeWeekend]);
  const weekFamilyCounts = useMemo(() => countByFamily(weekConflicts), [weekConflicts]);
  const radarConflicts = useMemo(() => applyFamilyFilter(weekConflicts, effectiveFamilies), [weekConflicts, effectiveFamilies]);
  const weekCounts = useMemo(() => deriveWeekCounters(weekendFixturesAll, kindConflicts), [weekendFixturesAll, kindConflicts]);
  // Indice « masqués » : rencontres de la semaine (filtrées PR-1) retirées par les Types OU
  // l'interrupteur Extérieurs — dérivé sur la semaine RÉELLEMENT affichée.
  const weekAllFilteredFixtures = useMemo(
    () => (null === activeWeekend ? [] : filtered.fixtures.filter((f) => weekendKeyOf(f.matchDate) === activeWeekend)),
    [filtered.fixtures, activeWeekend],
  );
  const weekHiddenBreakdown = useMemo(
    () => hiddenWeekBreakdown(weekAllFilteredFixtures, effectiveKinds, consultAway, competitionsMap),
    [weekAllFilteredFixtures, effectiveKinds, consultAway, competitionsMap],
  );

  return { weekends, activeWeekend, weekendIndex, weekendFixtures, weekendFixturesAll, weekConflicts, weekFamilyCounts, radarConflicts, weekCounts, weekHiddenBreakdown };
}
