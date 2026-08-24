/**
 * RMM-4 — la fraîcheur du dernier dépôt FBI, formulée en relatif pour un humain.
 * Le « aujourd'hui » vient TOUJOURS du front (`todayISO`, l'ancrage démo compris) —
 * les composants le passent, cette lib reste pure et testable.
 */

/** Au-delà de ce nombre de jours, la carte de fraîcheur escalade en warning. */
export const STALE_DAYS = 30;

/** Jours calendaires entre le dépôt (datetime ATOM) et aujourd'hui (Y-m-d). */
export function depositDaysAgo(depositedAtIso: string, todayIso: string): number {
  // La part DATE du dépôt suffit (on compte des jours, pas des heures).
  const depositDay = depositedAtIso.slice(0, 10);
  const [dy, dm, dd] = depositDay.split("-").map(Number);
  const [ty, tm, td] = todayIso.split("-").map(Number);
  const deposit = Date.UTC(dy, dm - 1, dd);
  const today = Date.UTC(ty, tm - 1, td);
  return Math.round((today - deposit) / 86_400_000);
}

/** « aujourd'hui » (0 ou futur) · « hier » (1) · « il y a N jours » (≥ 2). */
export function relativeDepositLabel(days: number): string {
  if (days <= 0) {
    return "aujourd'hui";
  }
  if (1 === days) {
    return "hier";
  }
  return `il y a ${days} jours`;
}
