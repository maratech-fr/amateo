import { coachFullName } from "@/shared/lib/coachName";
import { compareTeamsByRank, groupTeamsByTier } from "@/shared/lib/teamTiers";

import type { Coach, PriorityTier, Team } from "../api";

export interface RankedTeam {
  team: Team;
  /** 1-based global rank across all tiers (1 = most important). */
  globalNumber: number;
}

/**
 * Global order = tiers by importance (id asc: 1=S…5=D), then tierOrder within the
 * tier, then name. The global number lets selectors surface important teams first.
 */
export function orderedTeams(teams: Team[], tiers: PriorityTier[] = []): RankedTeam[] {
  // D-26 — la formule de tri était RECOPIÉE de `compareTeamsByRank`, et surtout la
  // numérotation ne suivait pas l'AFFICHAGE : l'écran range les équipes avec
  // `groupTeamsByTier`, qui route une équipe au rang inconnu (dérive de données) vers le
  // seau « Autres » en fin de liste — pendant que cette numérotation la triait par son id
  // brut. La colonne « # » se lisait alors 1, 2, 4, 5… 3. On numérote donc sur l'ordre
  // effectivement rendu, comme `RecapStep` le fait déjà.
  const sorted = tiers.length > 0
    ? groupTeamsByTier(teams, tiers).flatMap((group) => group.teams)
    : [...teams].sort(compareTeamsByRank);

  return sorted.map((team, index) => ({ team, globalNumber: index + 1 }));
}

/** Teams of a tier, ordered by tierOrder then name. */
export function teamsOfTier(teams: Team[], tierId: number): Team[] {
  // D-26 : l'ordre intra-rang vient du comparateur partagé (le rang est déjà filtré).
  return teams.filter((t) => t.priorityTierId === tierId).sort(compareTeamsByRank);
}


/** Which staffing bucket a coach falls into (drives the display order). */
export type CoachGroup = "salaried" | "player" | "other";

export interface RankedCoach {
  coach: Coach;
  group: CoachGroup;
}

const COACH_GROUP_RANK: Record<CoachGroup, number> = { salaried: 0, player: 1, other: 2 };

/**
 * The staffing bucket a coach falls into — single home for the taxonomy (salarié →
 * salaried, membre joueur actif → player, sinon other). `coachPlayerIds` is the set of
 * coach ids with an active player membership.
 */
export function coachGroupOf(coach: Coach, coachPlayerIds: Set<string>): CoachGroup {
  return coach.isEmployee ? "salaried" : coachPlayerIds.has(coach.id) ? "player" : "other";
}

/**
 * Display order for coaches: salaried employees first, then coach-players (a coach
 * with an active CoachPlayerMembership), then the rest — each bucket alphabetical.
 * `coachPlayerIds` is the set of coach ids with an active player membership.
 */
export function orderedCoaches(coaches: Coach[], coachPlayerIds: Set<string>): RankedCoach[] {
  // D-33 : formatage partagé (cette version était la seule à trimmer).
  const fullName = (c: Coach): string => coachFullName(c);
  return coaches
    .map((coach) => ({ coach, group: coachGroupOf(coach, coachPlayerIds) }))
    .sort((a, b) => COACH_GROUP_RANK[a.group] - COACH_GROUP_RANK[b.group] || fullName(a.coach).localeCompare(fullName(b.coach), "fr"));
}

/**
 * orderedCoaches split into its three buckets (each already ordered), for
 * section/optgroup rendering. `coachPlayerIds` = ids with an active player membership.
 */
export function groupedCoaches(coaches: Coach[], coachPlayerIds: Set<string>): Record<CoachGroup, Coach[]> {
  const groups: Record<CoachGroup, Coach[]> = { salaried: [], player: [], other: [] };
  for (const { coach, group } of orderedCoaches(coaches, coachPlayerIds)) {
    groups[group].push(coach);
  }
  return groups;
}

/** Coach staffing tags ("salarié · coach-joueur") — undefined when neither. */
export function coachMeta(isEmployee: boolean, isPlayer: boolean): string | undefined {
  return [isEmployee ? "salarié" : null, isPlayer ? "coach-joueur" : null].filter(Boolean).join(" · ") || undefined;
}
