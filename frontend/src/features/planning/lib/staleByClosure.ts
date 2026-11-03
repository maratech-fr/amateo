import type { ClosureGoverningPlan } from "@/features/cockpit/api";

import { PLANNING_TEXTS } from "../texts";

/** « 10/3 » — jour/mois sans zéro de tête. */
const dayMonth = (iso: string): string => `${Number(iso.slice(8, 10))}/${Number(iso.slice(5, 7))}`;

/**
 * LA phrase « périmé par une fermeture » d'un plan de période — la même sur la bannière de l'écran du
 * plan et sur la carte du radar. `venueNameOf` résout le gymnase fermé (lu dans l'impact servi).
 */
export function staleByClosureMessage(plan: ClosureGoverningPlan, venueNameOf: (venueId: string) => string): string {
  const dates = [...new Set(plan.sessions.map((s) => s.date))].sort();
  const teams = [...new Set(plan.sessions.map((s) => s.teamName))];
  const venue = venueNameOf(plan.sessions[0]?.venueId ?? "");

  return PLANNING_TEXTS.staleByClosure(venue, dayMonth(dates[0] ?? ""), dayMonth(dates[dates.length - 1] ?? ""), teams, plan.sessions.length);
}
