/**
 * « Dernière activité » de la console en JOURS CALENDAIRES Europe/Paris — le même fuseau que le
 * throttle d'écriture côté serveur (`LoginSuccessListener`, un jour Paris par utilisateur).
 * Patron : `coach-wishes/SollicitationTab.tsx` (jour Paris de l'instant lu, comparé à `todayISO`).
 */

const PARIS_DAY = new Intl.DateTimeFormat("fr-CA", { timeZone: "Europe/Paris", year: "numeric", month: "2-digit", day: "2-digit" });

/** Le jour Europe/Paris (Y-m-d) d'un instant RFC 3339. */
export function parisDayOf(iso: string): string {
  return PARIS_DAY.format(new Date(iso));
}

const utcMidnight = (ymd: string): number => {
  const [y = 0, m = 1, d = 1] = ymd.split("-").map(Number);
  return Date.UTC(y, m - 1, d);
};

/** Jours calendaires entre le jour Paris de `fromISO` et `todayISO` (Y-m-d) ; ≥ 0 (un instant futur vaut 0). */
export function calendarDaysBetween(fromISO: string, todayISO: string): number {
  const days = Math.round((utcMidnight(todayISO) - utcMidnight(parisDayOf(fromISO))) / 86_400_000);
  return Math.max(0, days);
}
