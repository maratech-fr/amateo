import { type CoachGender, playerWord } from "@/shared/lib/coachWording";

import type { ConflictSideRole, ConflictType } from "../api";
import type { Kind } from "./consultFilter";

/**
 * PR-2a — maison UNIQUE du libellé « famille de conflit » affiché dans les chips
 * de l'onglet Consulter. PRÉSENTATION pure : rien ici ne décide d'un COMPORTEMENT,
 * c'est un mot montré à l'utilisateur pour cocher/décocher une famille.
 *
 * Une TABLE, jamais un ternaire ni un `switch` (doctrine `fixtureStatusLabel.ts`,
 * `.claude/rules/frontend.md` : un `switch` sur un enum métier partagé est un
 * décideur interdit ; ici c'est une classification vers un LIBELLÉ, donc une table).
 * TypeScript exige les 12 clés de `ConflictType` — aucune famille ne peut être
 * oubliée (garde `conflictLabels.test.ts`, exhaustif). Distinct de
 * `ConflictRadar.conflictTitle` (phrase longue par conflit, non touchée) : ici un
 * NOM COURT de famille, pour une chip.
 */
export const CONFLICT_FAMILY_LABEL: Record<ConflictType, string> = {
  VENUE_OVERLAP: "Collision de gymnase",
  LEAGUE_WINDOW_VIOLATION: "Hors fenêtre ligue",
  CLUB_RULE_VIOLATION: "Hors règle du club",
  TEAM_VENUE_FORBIDDEN: "Gymnase interdit",
  MATCH_MATCH: "Personne en double",
  MATCH_TRAINING: "Match × entraînement",
  MATCH_COACH_UNAVAILABLE: "Coach principal indisponible",
  ACCESS_WINDOW_LOST: "Hors accès match",
  COMPETITION_INCOMPLETE: "Calendrier incomplet",
  VENUE_UNAVAILABLE: "Gymnase indisponible",
  VENUE_TRAINING_OVERLAP: "Entraînement dans le gymnase",
  AWAY_NO_FOOTPRINT: "Extérieur sans heure",
  FRIENDLY_ON_MATCH_SLOT: "Amical sur créneau match",
  TEAM_LEVEL_MISSING: "Niveau non renseigné",
};

/** Les 12 familles, dans l'ordre de la table (ordre des chips). */
export const CONFLICT_FAMILIES = Object.keys(CONFLICT_FAMILY_LABEL) as ConflictType[];

/**
 * Le libellé d'un TYPE de compétition (chips « Types » du Calendrier). PRÉSENTATION pure
 * (une table clé→libellé, jamais un décideur de comportement) — maison déplacée ici depuis
 * `CalendarControls` (P4-356) pour que la composition du contexte de signalement
 * (`feedbackContext.ts`) la partage sans dépendre d'un composant.
 */
export const KIND_LABEL: Record<Kind, string> = { amical: "Amical", championnat: "Championnat", coupe: "Coupe", brassage: "Brassage" };

/**
 * Le mot d'un rôle PAR CÔTÉ, pour annoter une équipe dans le résumé d'un conflit
 * personne-en-double (« SF2 (coach) et SM2 (joueuse) »). PRÉSENTATION pure, jamais un
 * décideur de comportement (`.claude/rules/frontend.md`). P4-311 : seul « joueur »
 * désigne la personne et s'accorde au genre (le coach du conflit, `conflict.coachId`,
 * est le MÊME sur les deux côtés) ; « coach » et « assistant » restent tels quels.
 */
const SIDE_ROLE_FIXED: Record<Exclude<ConflictSideRole, "PLAYER">, string> = {
  MAIN: "coach",
  ASSISTANT: "assistant",
};

export function sideRoleWord(role: ConflictSideRole, gender: CoachGender): string {
  return "PLAYER" === role ? playerWord(gender) : SIDE_ROLE_FIXED[role];
}
