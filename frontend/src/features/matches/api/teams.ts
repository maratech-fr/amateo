import { api } from "@/shared/api/client";
import { collection, collectionAll } from "@/shared/api/collection";
import type { Gender, TeamLevel } from "@/shared/lib/teamIdentity";

/** Team reference row — carries the axes the league envelope maps on. */
export interface Team {
  id: string;
  name: string;
  sportCategoryId: string;
  level: TeamLevel | null;
  gender: Gender | null;
  // Priority tier (S/A/B/C/D) — used to group teams in selectors, same
  // découpage as the wizard's teams step.
  priorityTierId: number;
  tierOrder: number;
}

export interface PriorityTier {
  id: number;
  label: string;
  name: string;
  color: string | null;
}

export interface Category {
  id: string;
  name: string;
}

/**
 * P2-54 RMM-9 — la durée de match d'une catégorie. `matchMinutes`/`warmupMinutes`
 * sont l'override propre (null = héritée) ; `defaultMatchMinutes`/`defaultWarmupMinutes`
 * sont le défaut de FAMILLE, RÉSOLU par le serveur (MatchDurationResolver) — le front
 * les AFFICHE (placeholder, en-tête de groupe), il ne les recalcule jamais
 * (🔴 `.claude/rules/frontend.md`).
 */
export interface SportCategoryDuration {
  id: string;
  sportId: string;
  name: string;
  matchMinutes: number | null;
  warmupMinutes: number | null;
  defaultMatchMinutes: number;
  defaultWarmupMinutes: number;
}

export interface SportCategoryDurationInput {
  matchMinutes: number | null;
  warmupMinutes: number | null;
}

export interface Coach {
  id: string;
  firstName: string;
  lastName: string;
}

export const getTeams = (): Promise<Team[]> => collectionAll<Team>("teams");
// Tiers are a tiny fixed set (S/A/B/C/D) and their id is numeric, so use the
// unpaginated `collection` (collectionAll constrains T to a string id).
export const getPriorityTiers = (): Promise<PriorityTier[]> => collection<PriorityTier>("priority_tiers");
export const getCategories = (): Promise<Category[]> => collectionAll<Category>("sport_categories");
export const getCoaches = (): Promise<Coach[]> => collectionAll<Coach>("coaches");

export const getSportCategoryDurations = (): Promise<SportCategoryDuration[]> => collectionAll<SportCategoryDuration>("sport_categories");

/**
 * PUT re-sends sportId + name (tous deux NotBlank côté serveur) avec les deux durées.
 * NULL = « revient au défaut de famille » — le serveur vide la colonne (jamais 0).
 */
export const updateSportCategoryDuration = (category: SportCategoryDuration, input: SportCategoryDurationInput): Promise<SportCategoryDuration> =>
  api
    .put(`sport_categories/${category.id}`, { json: { sportId: category.sportId, name: category.name, matchMinutes: input.matchMinutes, warmupMinutes: input.warmupMinutes } })
    .json<SportCategoryDuration>();
// ── Preferences layer (P1-4 PR C) ────────────────────────────────────────────

/** A team's habitual match window — one per weekday, venue optional. */
export interface TeamMatchHabit {
  id: string;
  teamId: string;
  /** ISO 1..7 */
  dayOfWeek: number;
  /** HH:MM — an instant, not a range. */
  kickoffTime: string;
  venueId: string | null;
}

export type TeamLinkType = "NOT_SIMULTANEOUS" | "BACK_TO_BACK";

/**
 * Intensité d'une passerelle CÔTÉ ENTRAÎNEMENT (lot PASSERELLES, arbitrage fondateur n°1).
 * Miroir de `App\Enum\TeamLinkIntensity`. Ne gouverne QUE le solveur d'entraînement — le rail
 * matchs garde sa pénalité SOFT historique (`linkType`), insensible à ce réglage.
 * `PREFERRED` (défaut) : le solveur préfère éviter le chevauchement des séances. `MANDATORY` :
 * il l'interdit (contrainte dure — peut rendre le planning infaisable si trop contraint).
 */
export type TeamLinkIntensity = "PREFERRED" | "MANDATORY";

/** A declared team bridge — symmetric (teamAId < teamBId), one per couple. */
export interface TeamLink {
  id: string;
  teamAId: string;
  teamBId: string;
  linkType: TeamLinkType;
  /** Training-only intensity (PREFERRED default). Never governs matches. */
  trainingIntensity: TeamLinkIntensity;
}

export const getTeamMatchHabits = (): Promise<TeamMatchHabit[]> =>
  (async () => (await collectionAll<TeamMatchHabit>("team_match_habits")).map((h) => ({ ...h, venueId: h.venueId ?? null })))();

export const createTeamMatchHabit = (input: { teamId: string; dayOfWeek: number; kickoffTime: string; venueId?: string }): Promise<TeamMatchHabit> =>
  api.post("team_match_habits", { json: input }).json<TeamMatchHabit>();

export const deleteTeamMatchHabit = (id: string): Promise<void> => api.delete(`team_match_habits/${id}`).then(() => undefined);

export const getTeamLinks = (): Promise<TeamLink[]> => collectionAll<TeamLink>("team_links");

export const createTeamLink = (input: { teamAId: string; teamBId: string; linkType: TeamLinkType; trainingIntensity?: TeamLinkIntensity }): Promise<TeamLink> =>
  api.post("team_links", { json: input }).json<TeamLink>();

/**
 * Edit an existing bridge — PUT is a full replace in this API, so the identity
 * (teams + matches linkType) is echoed and only the changed axis moves. Today the
 * one editable axis is the training intensity (matches linkType stays as declared).
 */
export const updateTeamLink = (link: TeamLink, input: { linkType?: TeamLinkType; trainingIntensity?: TeamLinkIntensity }): Promise<TeamLink> =>
  api
    .put(`team_links/${link.id}`, {
      json: {
        teamAId: link.teamAId,
        teamBId: link.teamBId,
        linkType: input.linkType ?? link.linkType,
        trainingIntensity: input.trainingIntensity ?? link.trainingIntensity,
      },
    })
    .json<TeamLink>();

export const deleteTeamLink = (id: string): Promise<void> => api.delete(`team_links/${id}`).then(() => undefined);

// ── Rotation A/B — shared match slots (RMM-5) ────────────────────────────────

/**
 * A shared match slot (venue + day + kickoff) and its ORDERED teams, alternating
 * A/B/C. `teamIds` order is FICTIONAL — it draws the alternation on screen and
 * drives no calendar (founder decision, spec §8). Read open to Member, write
 * management-gated (backend rail default).
 */
export interface MatchSlotRotation {
  id: string;
  venueId: string;
  /** ISO 1..7 */
  dayOfWeek: number;
  /** HH:MM */
  kickoffTime: string;
  /** Ordered members (position ASC) — the order IS the A/B/C drawing, nothing more. */
  teamIds: string[];
}

export interface MatchSlotRotationInput {
  venueId: string;
  dayOfWeek: number;
  kickoffTime: string;
  teamIds: string[];
}

export const getMatchSlotRotations = (): Promise<MatchSlotRotation[]> => collectionAll<MatchSlotRotation>("match_slot_rotations");

export const createMatchSlotRotation = (input: MatchSlotRotationInput): Promise<MatchSlotRotation> =>
  api.post("match_slot_rotations", { json: input }).json<MatchSlotRotation>();

/** PUT is a full replace: the whole slot + ordered roster is re-sent (backend rewrites members). */
export const updateMatchSlotRotation = (id: string, input: MatchSlotRotationInput): Promise<MatchSlotRotation> =>
  api.put(`match_slot_rotations/${id}`, { json: input }).json<MatchSlotRotation>();

export const deleteMatchSlotRotation = (id: string): Promise<void> => api.delete(`match_slot_rotations/${id}`).then(() => undefined);
