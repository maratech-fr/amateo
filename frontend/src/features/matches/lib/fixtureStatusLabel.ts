import type { FixtureStatus } from "../api";

/**
 * Maison UNIQUE du libellé français d'un statut de match (RMM-1 PR 1) —
 * PRÉSENTATION pure, aucun verdict : rien ici ne décide d'un COMPORTEMENT, ce
 * n'est qu'un mot montré à l'utilisateur (le fondateur lui-même a dû demander ce
 * que « SUBMITTED » voulait dire).
 *
 * Une TABLE, jamais un ternaire. Un `"SUBMITTED" === x ? … : …` est une BRANCHE
 * sur un enum métier partagé, et le garde anti-redérivation l'attrape à raison
 * (mordu trois fois). Une table rend en plus la valeur inconnue impossible à
 * oublier — TypeScript exige les quatre clés. Même patron que `teamLinkLabel.ts`.
 *
 * Vit dans `lib/` et non dans le composant : un fichier qui exporte autre chose
 * que des composants casse le Fast Refresh (`react-refresh/only-export-components`).
 */
/**
 * UXC-24 — `UNPLACED` se dit « Sans créneau », plus « Importé ». La table de statut
 * raconte une PROGRESSION (`Placé → Saisi dans FBI → Attesté FBI`) ; « Importé »
 * détonnait — il nommait la PROVENANCE, pas où l'on en est, et ne disait rien de ce
 * qu'il reste à faire. « Sans créneau » dit l'ÉTAT (le match n'a pas encore de place)
 * sans se confondre avec « Placé ».
 *
 * ⚠ Les deux autres mots du module RESTENT, c'est une décision, pas un oubli — trois
 * mots, trois métiers : « À placer » (titre de la liste `WeekWorkbench`) dit l'ACTION,
 * « à confirmer » (légende de la grille `WeekendGridLegend`) dit qu'une PROPOSITION FBI
 * existe déjà, « Sans créneau » (ici) dit l'ÉTAT de la rencontre.
 */
export const FIXTURE_STATUS_LABEL: Record<FixtureStatus, string> = {
  UNPLACED: "Sans créneau",
  PLACED: "Placé",
  SUBMITTED: "Saisi dans FBI",
  VALIDATED: "Attesté FBI",
};
