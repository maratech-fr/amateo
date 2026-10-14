/**
 * Textes centralisés de l'assistant (wizard) — fondation PR7 (vague 1).
 * Une clé par intention ; valeur = copie byte-identique du libellé rendu par l'écran.
 * JAMAIS dans une valeur : jour (→ shared/lib/days), heure/durée (→ time/duration),
 * marque (→ product), identifiant interne. Interpolation = fonction typée (pluriels compris).
 */
export const WIZARD_TEXTS = {
  toValidate: "à valider",
  noTeamAvailable: "Aucune équipe disponible (toutes ont atteint leur nombre de créneaux, sont déjà sur ce créneau, ou s'entraînent uniquement en groupe).",
  outOfGroup: "hors groupe",
  allSlotsPlaced: "Tous ses créneaux sont placés",
  ruleMandatory: "Obligatoire",
  ruleIndicative: "Indicatif",
  assistantNeverBlocks: "un adjoint indisponible ne bloque jamais une séance",
  rulePreferred: "Préféré",
  toFixBeforeGenerating: "À corriger avant de générer",
  teams: "Équipes",
  /** « reste N créneau(x) » — résidu solo d'une équipe (pluriel au-delà de 1). */
  soloResidual: (n: number) => `reste ${n} créneau${n > 1 ? "x" : ""}`,
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
