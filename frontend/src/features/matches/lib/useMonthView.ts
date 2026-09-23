import { useMemo } from "react";

import { todayISO } from "@/shared/lib/clock";

import type { Conflict, ConflictType, Fixture } from "../api";
import { applyFamilyFilter, countByFamily } from "./consultFilter";
import { conflictsByFixture, groupByDay, listMonths, resolveActiveMonth, scopeConflictsToMonth } from "./monthView";

/** En-tête de groupe d'un JOUR (Mois), ex. « sam. 3 oct. » avec l'initiale capitalisée. */
function dayHeaderLabel(dateIso: string): string {
  const label = new Date(`${dateIso}T12:00:00Z`).toLocaleDateString("fr-FR", { weekday: "long", day: "numeric", month: "long" });
  return label.charAt(0).toUpperCase() + label.slice(1);
}

/**
 * Temporalité MOIS du Calendrier : les 5 mémos du mois + le mois actif et son index.
 * Déplacement pur ; le helper privé `dayHeaderLabel` l'accompagne. `monthConflicts` et
 * `monthFamilyCounts` SORTENT : les chips de familles de la page les consomment.
 */
export function useMonthView(visibleFixtures: Fixture[], consultMonth: string | null, kindConflicts: Conflict[], effectiveFamilies: ConflictType[]) {
  const months = useMemo(() => listMonths(visibleFixtures), [visibleFixtures]);
  const activeMonth = resolveActiveMonth(months, consultMonth, todayISO().slice(0, 7));
  const monthConflicts = useMemo(() => scopeConflictsToMonth(kindConflicts, activeMonth), [kindConflicts, activeMonth]);
  const monthFamilyCounts = useMemo(() => countByFamily(monthConflicts), [monthConflicts]);
  const monthCbf = useMemo(() => conflictsByFixture(applyFamilyFilter(monthConflicts, effectiveFamilies)), [monthConflicts, effectiveFamilies]);
  const monthGroups = useMemo(
    () => (null === activeMonth ? [] : groupByDay(visibleFixtures, activeMonth).map((g) => ({ key: g.date, label: dayHeaderLabel(g.date), fixtures: g.fixtures }))),
    [visibleFixtures, activeMonth],
  );
  const monthIndex = null === activeMonth ? -1 : months.indexOf(activeMonth);

  return { months, activeMonth, monthConflicts, monthFamilyCounts, monthCbf, monthGroups, monthIndex };
}
