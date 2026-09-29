import { api } from "@/shared/api/client";
import { collectionAll } from "@/shared/api/collection";

import type { MatchWeek } from "./teams";

/**
 * P4-272 ③ — les RÈGLES DE MATCH du club (section Club de l'écran des contraintes).
 * `ruleType` HARD (honorée par le solveur) | PREFERRED (pénalité, jamais un blocage).
 * `daysOfWeek` = jours ISO couverts ; `kickoffMin`/`kickoffMax` bornent le coup
 * d'envoi (HH:MM), chacune facultative. `scope` CLUB en ③.
 */
export type MatchRuleType = "HARD" | "PREFERRED";

export interface MatchConstraint {
  id: string;
  version: number;
  scope: string;
  scopeTargetId: string | null;
  ruleType: MatchRuleType;
  /** ISO 1..7, plusieurs jours par règle. */
  daysOfWeek: number[];
  /** HH:MM or null (open lower bound). */
  kickoffMin: string | null;
  /** HH:MM or null (open upper bound). */
  kickoffMax: string | null;
  venueId: string | null;
}

export interface MatchConstraintInput {
  scope?: string;
  scopeTargetId?: string | null;
  ruleType: MatchRuleType;
  daysOfWeek: number[];
  kickoffMin: string | null;
  kickoffMax: string | null;
  venueId?: string | null;
}

export const getMatchConstraints = (): Promise<MatchConstraint[]> => collectionAll<MatchConstraint>("match_constraints");

export const createMatchConstraint = (input: MatchConstraintInput): Promise<MatchConstraint> =>
  api.post("match_constraints", { json: input }).json<MatchConstraint>();

export const updateMatchConstraint = (id: string, input: MatchConstraintInput): Promise<MatchConstraint> =>
  api.put(`match_constraints/${id}`, { json: input }).json<MatchConstraint>();

export const deleteMatchConstraint = (id: string): Promise<void> => api.delete(`match_constraints/${id}`).then(() => undefined);

/**
 * P4-272 ③ (ajout fondateur) — l'ALERTE DE COHÉRENCE règles club ⇄ créneaux idéaux,
 * LECTURE SEULE et CALCULÉE côté serveur (rien stocké, rien bloqué). Deux projections
 * du MÊME ensemble de collisions : `byRule` (section Club) et `byHabit` (Semaine type).
 * Le front l'AFFICHE — la collision est calculée serveur (maison unique).
 */
export interface ClubRuleCoherenceHabit {
  teamId: string;
  teamName: string;
  week: MatchWeek;
  dayOfWeek: number;
  kickoff: string;
}

export interface ClubRuleCoherenceRuleRef {
  ruleId: string;
  ruleType: MatchRuleType;
  daysOfWeek: number[];
  kickoffMin: string | null;
  kickoffMax: string | null;
}

export interface MatchConstraintCoherence {
  byRule: { ruleId: string; habits: ClubRuleCoherenceHabit[] }[];
  byHabit: { habitId: string; rules: ClubRuleCoherenceRuleRef[] }[];
}

export const getMatchConstraintCoherence = (): Promise<MatchConstraintCoherence> =>
  api.get("match-constraints/coherence").json<MatchConstraintCoherence>();
