import { toISODate } from "@/shared/lib/clock";

/**
 * « Une semaine est-elle DE VACANCES ? » — décision fondateur 2026-09-04.
 *
 * ⚠️ MIROIR DÉCLARÉ (régime 2, cf. `.claude/rules/frontend.md`) — PARITÉ MÉCANIQUE avec le backend
 * `App\Service\HolidayWorkweekRule::covers`, qui applique EXACTEMENT la même règle pour GARDER le
 * POST d'une semaine-enfant de vacances (422 sinon). Ici on qualifie l'OFFRE (une semaine de
 * vacances s'offre en reprise et sort de l'offre fermeture/overlay ; une semaine de saison fait
 * l'inverse) ; là-bas on refuse une semaine-enfant de vacances qui n'en est pas une. Deux
 * implémentations sont inévitables (le cockpit doit qualifier une semaine sans aller-retour
 * réseau) ; elles sont tenues alignées par la parité MÉCANIQUE `HolidayWorkweekMirrorParityTest` :
 * les mêmes cas (`holidayWorkweek.parity.json`) traversent les DEUX implémentations. Ce module
 * figure au registre `FrontRederivationRegistryTest`.
 *
 * La règle (fondateur) : une semaine n'est « de vacances » que si la vacance couvre TOUT son
 * lundi→vendredi ; sinon c'est une semaine de saison. Deux nuances tenues des deux côtés :
 *  - le WEEK-END ne compte pas (samedi/dimanche jamais regardés) ;
 *  - un jour HORS SAISON compte comme couvert — tolérance qui ne joue qu'aux vacances d'été (seul
 *    moment où la saison change), quand le début de saison rogne une semaine à cheval.
 */

/** ISO date `n` jours après `iso` (foyer local pour éviter un import circulaire depuis date.ts). */
function addDaysISO(iso: string, n: number): string {
  const [y, m, d] = iso.split("-").map(Number);
  return toISODate(new Date(y, m - 1, d + n));
}

/**
 * La semaine calendaire dont `monday` est le lundi est-elle DE VACANCES ? Chaque jour lundi→vendredi
 * doit être DANS la vacance OU hors saison ; le week-end est ignoré. Comparaisons en dates Y-m-d
 * (ordre lexicographique sûr).
 */
export function holidayCoversWorkweek(monday: string, holidayStart: string, holidayEnd: string, seasonStart: string, seasonEnd: string): boolean {
  for (let offset = 0; offset < 5; offset += 1) {
    const day = addDaysISO(monday, offset);
    const inHoliday = day >= holidayStart && day <= holidayEnd;
    const outOfSeason = day < seasonStart || day > seasonEnd;
    if (!inHoliday && !outOfSeason) {
      return false;
    }
  }

  return true;
}
