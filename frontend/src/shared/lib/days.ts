/**
 * Les sept jours de la semaine, en ISO (1 = lundi … 7 = dimanche). Foyer UNIQUE (D-22).
 *
 * ⚑ Ce tableau a une histoire, et c'est elle qui justifie ce fichier. Une de ses copies
 * **s'arrêtait au samedi** pendant que le backend acceptait `dayOfWeek=7`, que le solveur
 * plaçait la séance et que l'export serveur imprimait « Dimanche » : l'écran de travail
 * l'escamotait, le libellé rendait « ? », et le créneau devenait non déplaçable.
 * **Un planning à six colonnes se donnait pour complet.** Corrigé sur le TROISIÈME miroir
 * (P4-37) — il en restait neuf au moment de l'audit du 2026-08-08.
 *
 * L'ISO n'est pas un détail de forme : tout le stack le parle (payload, `Range(1,7)` des DTO,
 * tables de jours du moteur). Une copie indexée à 0 est un bug qui attend son dimanche.
 */
export const DAYS: { n: number; label: string }[] = [
  { n: 1, label: "Lun" },
  { n: 2, label: "Mar" },
  { n: 3, label: "Mer" },
  { n: 4, label: "Jeu" },
  { n: 5, label: "Ven" },
  { n: 6, label: "Sam" },
  { n: 7, label: "Dim" },
];

/** Libellés longs, indexés par jour ISO — l'index 0 est vide EXPRÈS (il n'existe pas). */
export const DAY_LABEL_LONG: readonly string[] = ["", "lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi", "dimanche"];

/** Le libellé long d'un jour ISO, ou une chaîne vide si le jour est hors bornes. */
export const dayLabelLong = (isoDay: number): string => DAY_LABEL_LONG[isoDay] ?? "";

/** Le libellé COURT d'un jour ISO (« Lun »…« Dim »), ou une chaîne vide hors bornes. Foyer unique (D-22). */
export const dayLabelShort = (isoDay: number): string => DAYS.find((d) => d.n === isoDay)?.label ?? "";

/**
 * Le libellé long CAPITALISÉ d'un jour ISO (« Lundi »…« Dimanche »), pour un DÉBUT de ligne ou de
 * cellule, ou une chaîne vide hors bornes. Même foyer que `dayLabelLong` — jamais une table locale.
 */
export const dayLabelLongCap = (isoDay: number): string => {
  const long = dayLabelLong(isoDay);

  return "" === long ? "" : long.charAt(0).toUpperCase() + long.slice(1);
};

/**
 * Jour ISO (1 = lundi … 7 = dimanche) d'une date civile « Y-m-d ». Foyer unique (D-30).
 *
 * ⚑ Il en existait TROIS stratégies, dont deux dans la même feature : `T00:00:00` local +
 * `getDay()`, `T12:00:00Z` + `getUTCDay()`, et `T00:00:00Z` + `getUTCDay()`. Alignées pour
 * un navigateur français, elles divergent hors fuseau — « pas d'accès match le vendredi »
 * sur un match que l'autre valide comme samedi.
 *
 * **Midi UTC est retenu délibérément** : une date civile n'est pas un instant, et se placer
 * au milieu de la journée met le calcul hors de portée de tout décalage horaire, dans les
 * deux sens. Minuit — local OU UTC — bascule d'un jour au premier fuseau venu.
 */
export function isoDayOf(dateStr: string): number {
  const day = new Date(`${dateStr}T12:00:00Z`).getUTCDay();

  return 0 === day ? 7 : day;
}
