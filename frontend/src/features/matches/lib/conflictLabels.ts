import type { ConflictType } from "../api";

/**
 * PR-2a — maison UNIQUE du libellé « famille de conflit » affiché dans les chips
 * de l'onglet Consulter. PRÉSENTATION pure : rien ici ne décide d'un COMPORTEMENT,
 * c'est un mot montré à l'utilisateur pour cocher/décocher une famille.
 *
 * Une TABLE, jamais un ternaire ni un `switch` (doctrine `fixtureStatusLabel.ts`,
 * `.claude/rules/frontend.md` : un `switch` sur un enum métier partagé est un
 * décideur interdit ; ici c'est une classification vers un LIBELLÉ, donc une table).
 * TypeScript exige les 10 clés de `ConflictType` — aucune famille ne peut être
 * oubliée (garde `conflictLabels.test.ts`, exhaustif). Distinct de
 * `ConflictRadar.conflictTitle` (phrase longue par conflit, non touchée) : ici un
 * NOM COURT de famille, pour une chip.
 */
export const CONFLICT_FAMILY_LABEL: Record<ConflictType, string> = {
  VENUE_OVERLAP: "Collision de gymnase",
  LEAGUE_WINDOW_VIOLATION: "Hors fenêtre ligue",
  MATCH_MATCH: "Coach en double",
  MATCH_TRAINING: "Match × entraînement",
  TEAM_LINK_OVERLAP: "Passerelle",
  ACCESS_WINDOW_LOST: "Placement fragilisé",
  COMPETITION_INCOMPLETE: "Calendrier incomplet",
  VENUE_UNAVAILABLE: "Gymnase indisponible",
  AWAY_NO_FOOTPRINT: "Extérieur sans heure",
  FRIENDLY_ON_MATCH_SLOT: "Amical sur créneau match",
};

/** Les 10 familles, dans l'ordre de la table (ordre des chips). */
export const CONFLICT_FAMILIES = Object.keys(CONFLICT_FAMILY_LABEL) as ConflictType[];
