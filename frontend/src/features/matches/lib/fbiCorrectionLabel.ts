import { frDateWeekdayNoYear } from "@/shared/lib/date";

import { depositDaysAgo } from "./fbiFreshness";

/**
 * Libellés PURS de la liste « FBI — à faire » — présentation seule, aucun seuil ni
 * verdict (le front n'invente aucune règle : `.claude/rules/frontend.md`). Le
 * « aujourd'hui » vient toujours du composant (`todayISO`, ancrage démo compris).
 */

/** Le libellé humain d'un champ d'écart. */
export const FIELD_LABEL: Record<string, string> = { date: "Date", kickoff: "Heure", venue: "Salle" };

/** « aujourd'hui » (0/futur) · « il y a N j » (≥ 1) — AUCUN seuil d'alerte, juste du relatif. */
export function seenAgoLabel(days: number): string {
  return days <= 0 ? "aujourd'hui" : `il y a ${days} j`;
}

/** « vu dans FBI le sam. 14 sept. (il y a 6 j) », ou null si jamais re-vu (`lastSeenInFbiAt` null). */
export function seenInFbiLabel(lastSeenIso: string | null, todayIso: string): string | null {
  if (null === lastSeenIso) {
    return null;
  }
  const days = depositDaysAgo(lastSeenIso, todayIso);
  return `vu dans FBI le ${frDateWeekdayNoYear(lastSeenIso.slice(0, 10))} (${seenAgoLabel(days)})`;
}
