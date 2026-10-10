/**
 * Le nom affiché d'un coach, et le libellé de son absence — foyer unique (D-33).
 *
 * ⚑ Trois formatages coexistaient, dont deux SANS `.trim()` : un coach sans nom de famille
 * (`lastName` est optionnel) s'affichait « Emerick » dans le wizard et « Emerick␣ » sur le
 * planning et le radar de conflits — espace final visible en badge et en infobulle. Et trois
 * replis différents désignaient le même vide (« Coach ? », « Coach », `null`).
 */
export const COACH_UNKNOWN = "Coach ?";

interface CoachLike {
  firstName?: string | null;
  lastName?: string | null;
}

/** « Prénom Nom », sans espace parasite quand une des deux moitiés manque. */
export const coachFullName = (coach: CoachLike | null | undefined): string =>
  null == coach ? COACH_UNKNOWN : `${coach.firstName ?? ""} ${coach.lastName ?? ""}`.trim() || COACH_UNKNOWN;

/**
 * Les deux moitiés du nom, trimmées (D5) — la tuile du planning coupe le nom du coach sur deux
 * lignes (prénom puis nom plus petit) quand elle a la hauteur. Mêmes replis que `coachFullName` :
 * un coach absent ou totalement vide retombe sur `COACH_UNKNOWN` (dans `first`, `last` vide). La
 * mise en page (deux lignes ou une seule) est décidée par l'appelant, jamais ici.
 */
export const coachNameParts = (coach: CoachLike | null | undefined): { first: string; last: string } => {
  if (null == coach) {
    return { first: COACH_UNKNOWN, last: "" };
  }
  const first = (coach.firstName ?? "").trim();
  const last = (coach.lastName ?? "").trim();

  return "" === first && "" === last ? { first: COACH_UNKNOWN, last: "" } : { first, last };
};
