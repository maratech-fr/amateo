/**
 * Minutes depuis minuit → « HH:MM ». Foyer UNIQUE (audit D-20, 2026-08-08).
 *
 * Il en existait **trois** implémentations, et une seule clampait :
 *  - `matches/lib/weekendGrid.ts` clampait modulo 1440 ;
 *  - `planning/lib/grid.ts` et `wizard/lib/days.ts` ne clampaient pas.
 *
 * Conséquence : un horaire dépassant minuit s'affichait « 01:15 » côté matchs et
 * **« 25:15 »** côté planning et wizard. Ce n'est pas une hypothèse — c'est l'incident
 * nommément décrit dans `wizard/lib/slotOverlap.ts` (« ressortait en 25:15 partout où une
 * fin d'horaire s'affiche »), corrigé à l'époque par une garde de SAISIE ; les formateurs,
 * eux, sont restés discordants.
 *
 * ⚑ Le clamp n'est pas cosmétique : au-delà de minuit, « 25:15 » n'est pas une heure. Dans
 * le régime normal (< 1440) il ne change strictement rien — la garde de saisie empêche déjà
 * un créneau de franchir minuit — il ne se voit donc que là où quelque chose a déjà dérapé,
 * et il choisit alors d'afficher une heure lisible plutôt qu'un nombre impossible.
 */
export function formatMinutes(total: number): string {
  // Double modulo : `%` garde le signe en JS, donc -30 rendrait « -1:-30 » sans le second tour.
  const clamped = ((total % 1440) + 1440) % 1440;

  return `${String(Math.floor(clamped / 60)).padStart(2, "0")}:${String(clamped % 60).padStart(2, "0")}`;
}

/**
 * « Heure-ish » (`"18:00"`, `"18:00:00"`, un ISO datetime) → minutes depuis minuit.
 * **`null` quand la valeur est illisible** — l'échec est explicite, jamais déguisé.
 *
 * ⚑ Il en existait CINQ implémentations, avec **quatre** comportements d'échec différents
 * (`NaN`, `0`, `0`, `null`, `0`) et deux regex divergentes (`\d{2}` contre `\d{1,2}`, donc
 * « 9:00 » lu par les unes et pas par les autres). Sur une heure illisible, le wizard
 * **bloquait la pose** pendant que le planning et les matchs la traitaient comme **minuit**
 * et posaient le bloc en haut de grille, sans un mot (audit D-21).
 *
 * ⚠ **Le repli reste le choix de l'appelant, et c'est délibéré.** Le `NaN` de
 * `wizard/lib/days.toMinutes` est LOAD-BEARING : `slotOverlap` s'en sert
 * (`!Number.isFinite(...)`) pour refuser une heure vide avec un message qui nomme le champ —
 * l'écraser en `0` rendrait la garde muette et laisserait partir une écriture que l'API
 * rejette par un 422 générique. Unifier la LECTURE ne veut pas dire unifier la RÉACTION.
 */
export function parseTime(value: string | null | undefined): number | null {
  const match = value?.match(/(\d{1,2}):(\d{2})/);
  if (null == match) {
    return null;
  }

  const hours = Number(match[1]);
  const minutes = Number(match[2]);

  return Number.isFinite(hours) && Number.isFinite(minutes) ? hours * 60 + minutes : null;
}
