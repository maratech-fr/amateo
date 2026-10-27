import { frDateWeekdayNoYear } from "@/shared/lib/date";

import type { LeagueToTreatFixture, LeagueToTreatReason, MaturedCompetition } from "../api";

/**
 * Lot O — « validé ligue » piloté par l'échéance : le VOCABULAIRE et les libellés PURS
 * (aucun rendu, aucune règle métier — le backend décide QUOI est proposé, le front met en
 * mots). Vivent en `lib/` pour rester testables sans monter de composant, et pour que
 * `LeagueValidation.tsx` n'exporte que des composants (fast-refresh).
 */

/** Deep-link vers la section « Échéances de saisie » de la configuration matchs. */
export const ENTRY_DEADLINES_PATH = "/matchs/configuration?section=echeances";

/** L'ancre de la file de traitement sur l'écran Importer — cible du renvoi « à traiter ». */
export const REVIEW_QUEUE_ANCHOR = "file-traitement";

export const LEAGUE_VALIDATION_CONFIRM_LABEL = "Marquer « déjà dans FBI »";

/** « rencontre(s) » accordé. */
function plural(count: number): string {
  return `rencontre${count > 1 ? "s" : ""}`;
}

/**
 * L'intro chiffrée de la confirmation : annonce le total ET ce que la validation change.
 * Le DÉTAIL par championnat est rendu par le composant (liste), pas ici.
 */
export function leagueValidationIntro(total: number): string {
  const verb = total > 1 ? "portent" : "porte";
  return (
    `${total} ${plural(total)} de championnats commencés ${verb} déjà leur date, leur heure et leur gymnase dans FBI — ` +
    `la fédération les connaît. Les marquer « déjà dans FBI » verrouille leur placement : le solveur les traite comme des ancres. ` +
    `Refuser ne change rien.`
  );
}

/**
 * Le libellé d'un championnat commencé dans la confirmation — le motif de démarrage décide :
 * « PNM — échéance mar. 10 nov. — 12 à valider » (échéance passée) ou « PNM — 1er match joué le
 * sam. 19 sept. — 12 à valider » (premier match joué, sans échéance renseignée).
 *
 * M6 (retours Nico, BCCL 2026-10-27) — « jour partout » : les deux bornes de date portent leur
 * jour de la semaine (`frDateWeekdayNoYear`) — le premier match joué EST une rencontre, et
 * l'échéance de saisie FBI prend aussi le jour (arbitrage fondateur).
 */
export function maturedLabel(competition: MaturedCompetition): string {
  const anchor =
    "firstMatchPlayed" === competition.maturedBy && null !== competition.firstMatchDate
      ? `1er match joué le ${frDateWeekdayNoYear(competition.firstMatchDate)}`
      : `échéance ${frDateWeekdayNoYear(competition.deadline ?? "")}`;
  return `${competition.name} — ${anchor} — ${competition.validatableCount} à valider`;
}

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
