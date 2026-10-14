/**
 * Textes centralisés des matchs — fondation PR7 (vague 1), écran ConflictLine.
 * Clé par intention ; valeur = copie byte-identique du libellé rendu. Interpolation = fonction
 * typée. JAMAIS en valeur : jour (→ shared/lib/days), heure/durée (→ time/duration),
 * marque (→ product), identifiant interne (noms d'équipe/gymnase/adversaire restent des données).
 */
export const MATCHES_TEXTS = {
  venueOverlapTitle: "Deux matchs sur le même créneau",
  calendarToCheckTitle: "Calendrier à vérifier",
  calendarIncoherentTitle: "Calendrier incohérent",
  calendarIncompleteTitle: "Calendrier incomplet",
  accessWindowLostTitle: "Hors accès match",
  clubRuleViolationTitle: "Hors règle du club",
  newChip: "Nouveau",
  homeSide: "domicile",
  estimatedTimeTag: "heure estimée",
  versus: (opponent: string) => `vs ${opponent}`,
  awayAt: (city: string) => `extérieur à ${city}`,
  matchVsTrainingSummary: (match: string, training: string) => `Match ${match} × entraînement ${training}`,
  accessWindowLostPhrase: (venue: string, windows: string) => `Placé hors des accès match de ${venue} (${windows}) — déplacez le match ou ajustez l'accès dans Configuration.`,
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
