import type { TeamLinkIntensity } from "../api";

/**
 * Maison UNIQUE du libellé d'intensité de passerelle (P2-45) — PRÉSENTATION pure,
 * aucun verdict : le solveur reste seul juge de ce qu'une intensité FAIT.
 *
 * Une TABLE, jamais un ternaire. Un `"PREFERRED" === x ? … : …` est une BRANCHE sur
 * un enum métier, et le garde anti-redérivation l'attrape à raison (mordu le
 * 2026-08-22 sur les deux hôtes de la sous-ligne d'équipe). Une table rend en plus
 * la valeur inconnue impossible à oublier — TypeScript exige les deux clés.
 *
 * Vit dans `lib/` et non dans le composant : un fichier qui exporte autre chose que
 * des composants casse le Fast Refresh (règle `react-refresh/only-export-components`).
 */
export const INTENSITY_LABEL: Record<TeamLinkIntensity, string> = { PREFERRED: "Préféré", MANDATORY: "Obligatoire" };
