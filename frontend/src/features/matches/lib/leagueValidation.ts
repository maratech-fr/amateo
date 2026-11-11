import { frDateWeekdayNoYear } from "@/shared/lib/date";

import type { LeagueToTreatFixture, LeagueToTreatReason } from "../api";

/**
 * Lot O — « Déjà dans FBI » piloté par le démarrage du championnat : les libellés PURS de ce
 * qui reste à traiter
 * (aucun rendu, aucune règle métier — le backend décide QUOI est proposé, le front met en
 * mots). Vivent en `lib/` pour rester testables sans monter de composant, et pour que
 * `LeagueValidation.tsx` n'exporte que des composants (fast-refresh).
 */

/** Deep-link vers la section « Échéances de saisie » de la configuration matchs. */
export const ENTRY_DEADLINES_PATH = "/matchs/configuration?section=echeances";

/** L'ancre de la file de traitement sur l'écran Importer — cible du renvoi « à traiter ». */
export const REVIEW_QUEUE_ANCHOR = "file-traitement";

const REASON_LABELS: Record<LeagueToTreatReason, string> = {
  NO_KICKOFF: "sans heure",
  NO_VENUE: "sans gymnase",
  PENDING_DEVIATION: "écart en attente",
};

/** La raison, en clair, pour laquelle une rencontre échue reste à traiter. */
export function reasonLabel(reason: LeagueToTreatReason): string {
  return REASON_LABELS[reason];
}

/** Le libellé nommé d'une rencontre à traiter : « sam. 4 oct. · PNM vs Adversaire — sans gymnase ». */
export function toTreatLabel(fixture: LeagueToTreatFixture): string {
  return `${frDateWeekdayNoYear(fixture.matchDate)} · ${fixture.competitionName} vs ${fixture.opponentLabel} — ${reasonLabel(fixture.reason)}`;
}
