// Textes centralisés de la feature planning (PR7 — fondation des textes centralisés).
// Valeurs = copie BYTE-IDENTIQUE des littéraux rendus par les écrans (PlanningPage, SlotDetail).
// Convention : clé camelCase par intention ; interpolation par fonctions typées. Pas de lib i18n.
export const PLANNING_TEXTS = {
  lockWillBeLost: "Elle est verrouillée — le verrou sera perdu.",
  emptyPlanningTitle: "Planning vide",
  noPlanning: "Aucun planning",
  generationFailed: "Génération en échec",
  scopeThisTeam: "Cette équipe",
  scopeWholeClub: "Tout le club",
  verifyTimeout: "La vérification a pris trop de temps, réessayez.",
  viaMutualization: "via mutualisation",
  mutualizedBadge: "Mutualisé",
  seasonPlanBadge: "principal",
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
