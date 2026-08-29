/**
 * Le FOYER UNIQUE du formatage de date française (UXC-19, P4-135). Une date civile
 * ISO « Y-m-d » y devient une chaîne lisible, une seule fois, pour tout le front.
 *
 * Descendus ici le 2026-08-30 : les trois formateurs PURS de `features/cockpit/lib/date.ts`
 * (`frDateNumeric` / `frDateShort` / `frDateShortNoYear`) — le module cockpit les ré-exporte
 * pour ses appelants — et le `frDate` LOCAL de `features/matches/AwayList.tsx`
 * (`frDateWeekdayNoYear`). Le formatage était éclaté sans foyer partagé, et `features/wizard/`
 * l'important depuis cockpit (arête feature→feature). Même patron que `IN_FLIGHT` (P4-147) et
 * `Gender`/`TeamLevel` (P4-148), et que la règle ESLint `no-restricted-imports` : « ce qui est
 * partagé descend dans shared/ ».
 *
 * ⚠ CE SONT QUATRE FORMATS DISTINCTS — jamais à fusionner. Chacun porte un nom qui dit sa
 * forme et un exemple de rendu. Le choix de construction de chaque `Date` est CELUI DE SON
 * ORIGINE et ne doit pas changer (l'affichage rendu est identique au précédent) : les variantes
 * cockpit lisent la date en LOCAL (`new Date(y, m-1, d)`), la variante à jour de semaine la lit
 * à MIDI UTC (`T12:00:00Z`) — même parti que `shared/lib/days.ts` (une date civile n'est pas un
 * instant ; midi UTC met le calcul hors de portée de tout décalage horaire).
 */

/** Date française numérique jj-mm-aaaa (ordre de lecture FR, tirets), ex. « 2026-10-17 » → « 17-10-2026 ». */
export function frDateNumeric(iso: string): string {
  const [y, m, d] = iso.split("-");
  return `${d}-${m}-${y}`;
}

/** Date française courte pour la copie UI compacte, ex. « 2026-12-19 » → « 19 déc. 2026 ». */
export function frDateShort(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  return new Date(y, m - 1, d).toLocaleDateString("fr-FR", { day: "numeric", month: "short", year: "numeric" });
}

/** Date française courte SANS l'année, ex. « 2026-12-19 » → « 19 déc. » — pour les libellés dont
 *  la fenêtre est connue pour tenir dans la saison courante (l'année est alors du bruit). */
export function frDateShortNoYear(iso: string): string {
  const [y, m, d] = iso.split("-").map(Number);
  return new Date(y, m - 1, d).toLocaleDateString("fr-FR", { day: "numeric", month: "short" });
}

/** Date française avec JOUR DE SEMAINE, sans année, ex. « 2026-09-12 » → « sam. 12 sept. » —
 *  pour les jours de match, où le jour de la semaine porte du sens (quel jour du week-end). */
export function frDateWeekdayNoYear(iso: string): string {
  return new Date(`${iso}T12:00:00Z`).toLocaleDateString("fr-FR", { weekday: "short", day: "numeric", month: "short" });
}
