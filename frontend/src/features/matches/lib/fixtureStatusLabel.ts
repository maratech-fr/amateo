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
export const FIXTURE_STATUS_LABEL: Record<FixtureStatus, string> = {
  UNPLACED: "Importé",
  PLACED: "Placé",
  SUBMITTED: "Saisi dans FBI",
  VALIDATED: "Validé ligue",
};
