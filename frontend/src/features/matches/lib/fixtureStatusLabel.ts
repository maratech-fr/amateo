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
 * Retours Nico (BCCL, 2026-10-27), M3 — wording VALIDÉ fondateur : la table d'états se dit
 * en mots de gestionnaire, pas en jargon fédéral. La progression raconte désormais ce qui
 * MANQUE ou ce qui RESTE à faire : « Sans date ni gymnase » (ce qui manque, plus l'absente
 * « Sans créneau »), « Placé — à saisir dans FBI » (l'action qui reste, plus le muet « Placé »),
 * « Saisi dans FBI » (inchangé), « Déjà dans FBI » (plus « Attesté FBI » — le mot fédéral
 * « Attesté » que même le fondateur a dû faire expliquer a cédé).
 */
export const FIXTURE_STATUS_LABEL: Record<FixtureStatus, string> = {
  UNPLACED: "Sans date ni gymnase",
  PLACED: "Placé — à saisir dans FBI",
  SUBMITTED: "Saisi dans FBI",
  VALIDATED: "Déjà dans FBI",
};
