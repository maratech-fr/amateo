/**
 * Textes des crédits de l'offre Découverte (pastille `CreditBadge.tsx`, bandeau `CreditsBanner.tsx`).
 * La pastille et le bandeau nomment les mêmes gestes payants : génération, comblement, placement, export.
 */
export const CREDITS_TEXTS = {
  /** Explication de la pastille, au survol (`title`) et à la fin de l'annonce du lien. */
  badgeTooltip: "Chaque génération, comblement de planning de période, placement de matchs ou export consomme 1 crédit ; ajuster et consulter restent gratuits.",
  /** Bandeau d'urgence (1 à 3 crédits restants), compteur accordé. */
  urgencyBanner: (remaining: number): string =>
    `Il ne vous reste que ${remaining} ${remaining > 1 ? "crédits gratuits" : "crédit gratuit"} : chaque génération, comblement de planning de période, placement de matchs ou export en consomme un.`,
} as const satisfies Record<string, string | ((...args: never[]) => string)>;
