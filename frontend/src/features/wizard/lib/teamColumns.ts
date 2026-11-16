/**
 * **La maison unique des largeurs de colonnes de l'étape Équipes** (P4-107, 4ᵉ tranche).
 *
 * Trois endroits rendent les MÊMES colonnes — l'en-tête du formulaire d'ajout, le formulaire
 * lui-même, et l'en-tête + les lignes de la liste. Ils portaient chacun leurs classes, et ils
 * avaient DÉJÀ divergé : le Genre valait `w-24` dans l'en-tête du formulaire et `w-20` dans la
 * liste, si bien que les deux tableaux ne s'alignaient pas.
 *
 * ⚠ **Le défaut que ces valeurs corrigent n'est pas esthétique.** À 1920, le nom prenait ~1050 px
 * pour afficher « SM1 » pendant que les sélecteurs coupaient LEUR PROPRE VALEUR : on lisait
 * « Homn » pour « Homme », « Femm » pour « Femme ». Une valeur sélectionnée tronquée est
 * nommément un DON'T du corpus de design (« Overflow or cut off ») — et contrairement à un
 * libellé, elle n'a aucune issue : un `<select>` n'a pas de « voir plus ».
 *
 * Les largeurs sont donc dimensionnées sur **la valeur la plus longue du catalogue** :
 * « Départemental » pour le niveau, « Féminin » pour le genre, « Senior »/« U21 »… pour la
 * catégorie. Le nom, lui, est PLAFONNÉ : au-delà, il ne montre rien de plus et vole la place des
 * autres.
 *
 * ⚑ Ce que ces constantes NE garantissent pas : qu'une valeur tienne réellement. jsdom n'a aucun
 * moteur de mise en page — le test unitaire ne peut vérifier que la PARITÉ entre les trois sites.
 * Qu'aucune valeur ne soit coupée n'a plus de mesure automatique (l'e2e de calibration est retiré).
 */
export const TEAM_COLUMNS = {
  /** Nom : occupe la place restante, mais PLAFONNÉE — « SM1 » n'a pas besoin de 1050 px. */
  name: "min-w-0 flex-1 max-w-xl",
  /**
   * Catégorie. ⚠ Dimensionnée sur son **placeholder** « — catégorie — » (~103 px), plus long
   * que toutes ses valeurs — c'est lui qu'un club NEUF a sous les yeux, et `w-36` le coupait.
   * Attrapé par la CI, pas en local : la marge y était de 100 px offerts contre ~101 demandés,
   * un écart de rendu de police suffisait à faire basculer. Une borne au rasoir n'est pas une
   * borne.
   */
  category: "w-40",
  /** Genre : « Homme », « Femme », « Mixte » — 80 px les coupaient. */
  gender: "w-28",
  /** Niveau de jeu : « Départemental » est la plus longue du catalogue. */
  level: "w-44",
  /** Séances/semaine : un ou deux chiffres. */
  sessions: "w-16",
  /** Rang : présent dans le formulaire d'ajout seulement (la liste le rend en badge). */
  tier: "w-32",
} as const;

export type TeamColumn = keyof typeof TEAM_COLUMNS;
