import type { CoachPlayerMembership, TeamCoach } from "@/features/planning/api";

import type { Conflict, Fixture } from "../api";

/**
 * PR-1 (filtres de la vue Semaine du module Matchs) — dérivation PURE de « ce qui
 * appartient au filtre courant ». Aucune règle métier n'est inventée ici (🔴
 * `.claude/rules/frontend.md`) : on filtre des lignes déjà calculées par le
 * backend (fixtures, conflits) sur trois axes d'AFFICHAGE. La seule dérivation est
 * l'expansion coach → équipes, elle-même adossée aux jointures serveur
 * (`team_coaches` + `coach_player_memberships`).
 */
export type MatchFilterMode = "equipe" | "coach" | "gymnase";

/** Rôle d'un coach vis-à-vis d'une équipe, pour l'affichage en vue coach. */
export type CoachTeamRole = "principal" | "assistant" | "joueur";

// Précédence quand un coach porte plusieurs rôles sur la même équipe (principal
// l'emporte sur assistant, qui l'emporte sur joueur).
const ROLE_RANK: Record<CoachTeamRole, number> = { principal: 0, assistant: 1, joueur: 2 };

export interface MatchFilterInput {
  mode: MatchFilterMode;
  /** Ressources cochées : équipes, coachs ou gymnases selon `mode`. */
  ids: string[];
  /** Toutes les rencontres de la saison (le lookup gymnase se fait dessus). */
  fixtures: Fixture[];
  conflicts: Conflict[];
  teamCoaches: TeamCoach[];
  coachPlayers: CoachPlayerMembership[];
}

export interface MatchFilterResult {
  fixtures: Fixture[];
  conflicts: Conflict[];
  /** Rôle par équipe des coachs sélectionnés — `null` hors vue coach. */
  coachTeamRoles: Map<string, CoachTeamRole> | null;
}

/**
 * Équipes T(c) d'un ensemble de coachs, avec le rôle par équipe : équipes où le
 * coach est MAIN/ASSISTANT (`team_coaches`) ∪ équipes où il est joueur ACTIF
 * (`coach_player_memberships`). Le meilleur rôle l'emporte (principal > assistant
 * > joueur), même patron que `PlanningPage` (teamCoach + teamPlayerCoaches).
 */
export function expandCoachTeams(coachIds: string[], teamCoaches: TeamCoach[], coachPlayers: CoachPlayerMembership[]): Map<string, CoachTeamRole> {
  const wanted = new Set(coachIds);
  const roles = new Map<string, CoachTeamRole>();
  const assign = (teamId: string, role: CoachTeamRole): void => {
    const current = roles.get(teamId);
    if (undefined === current || ROLE_RANK[role] < ROLE_RANK[current]) {
      roles.set(teamId, role);
    }
  };
  for (const link of teamCoaches) {
    if (wanted.has(link.coachId)) {
      assign(link.teamId, "MAIN" === link.role ? "principal" : "assistant");
    }
  }
  for (const link of coachPlayers) {
    if (link.isActive && wanted.has(link.coachId)) {
      assign(link.teamId, "joueur");
    }
  }
  return roles;
}

/** teamIds portés par un conflit (acteurs de match + entraînement + agrégat). */
function conflictTeamIds(conflict: Conflict): string[] {
  const ids: string[] = [];
  if (undefined !== conflict.left) ids.push(conflict.left.teamId);
  if (undefined !== conflict.right) ids.push(conflict.right.teamId);
  if (undefined !== conflict.fixture) ids.push(conflict.fixture.teamId);
  if (undefined !== conflict.training) ids.push(conflict.training.teamId);
  if (undefined !== conflict.teamId) ids.push(conflict.teamId);
  return ids;
}

/** fixtureIds référencés par un conflit (côtés match seulement). */
function conflictFixtureRefs(conflict: Conflict): string[] {
  const refs: string[] = [];
  if (undefined !== conflict.left) refs.push(conflict.left.fixtureId);
  if (undefined !== conflict.right) refs.push(conflict.right.fixtureId);
  if (undefined !== conflict.fixture) refs.push(conflict.fixture.fixtureId);
  return refs;
}

function conflictInTeams(conflict: Conflict, teamIds: ReadonlySet<string>): boolean {
  return conflictTeamIds(conflict).some((id) => teamIds.has(id));
}

function conflictInVenues(conflict: Conflict, venueIds: ReadonlySet<string>, fixturesById: Map<string, Fixture>): boolean {
  if (undefined !== conflict.venueId && venueIds.has(conflict.venueId)) return true;
  if (undefined !== conflict.training && venueIds.has(conflict.training.venueId)) return true;
  return conflictFixtureRefs(conflict).some((fid) => {
    const venueId = fixturesById.get(fid)?.venueId ?? null;
    return null !== venueId && venueIds.has(venueId);
  });
}

/**
 * Applique le filtre. Filtre VIDE ⇒ renvoie les MÊMES références de tableaux
 * (pass-through) : la vue Semaine sans filtre reste byte-identique.
 */
export function applyMatchFilter(input: MatchFilterInput): MatchFilterResult {
  const { mode, ids, fixtures, conflicts, teamCoaches, coachPlayers } = input;
  if (0 === ids.length) {
    return { fixtures, conflicts, coachTeamRoles: null };
  }

  if ("gymnase" === mode) {
    const venueIds = new Set(ids);
    const fixturesById = new Map(fixtures.map((f) => [f.id, f]));
    return {
      // Les extérieurs (venueId null) sont exclus d'un filtre gymnase (assumé).
      fixtures: fixtures.filter((f) => null !== f.venueId && venueIds.has(f.venueId)),
      conflicts: conflicts.filter((c) => conflictInVenues(c, venueIds, fixturesById)),
      coachTeamRoles: null,
    };
  }

  const coachTeamRoles = "coach" === mode ? expandCoachTeams(ids, teamCoaches, coachPlayers) : null;
  const teamIds = "coach" === mode ? new Set(coachTeamRoles?.keys()) : new Set(ids);
  const coachIds = "coach" === mode ? new Set(ids) : new Set<string>();

  return {
    fixtures: fixtures.filter((f) => teamIds.has(f.teamId)),
    conflicts: conflicts.filter((c) => conflictInTeams(c, teamIds) || (undefined !== c.coachId && coachIds.has(c.coachId))),
    coachTeamRoles,
  };
}
