import { describe, expect, it } from "vitest";

import type { Reservation, Team, TeamSoloBudget } from "../api";
import { completedGroupCaseCount, type GroupLike, offerableGroups, postedGroupOnSlot } from "./groupReservation";

const team = (id: string, name: string, sessionsPerWeek = 2): Team => ({ id, name, priorityTierId: 1, tierOrder: 0, sessionsPerWeek, sportCategoryId: "c" }) as Team;

const resa = (teamId: string, venueId: string, dayOfWeek: number, startTime: string): Reservation =>
  ({ id: `${teamId}-${venueId}-${dayOfWeek}-${startTime}`, teamId, venueId, dayOfWeek, startTime, durationMinutes: 90 }) as Reservation;

const group = (id: string, teamIds: string[], commonSessions = 1): GroupLike => ({ id, teamIds, commonSessions });

/** Budget solo servi par le backend (P2-60) — la garde bloc D4 lit `effectiveSessions` (override de période inclus). */
const soloBudget = (teamId: string, effectiveSessions: number, residual = effectiveSessions, individualUsed = 0, inBlock = false): TeamSoloBudget =>
  ({ teamId, schedulePlanId: null, effectiveSessions, blockSessions: effectiveSessions - residual, residual, individualUsed, inBlock });
const budgets = (...bs: TeamSoloBudget[]): Map<string, TeamSoloBudget> => new Map(bs.map((b) => [b.teamId, b]));

const TEAMS = [team("a", "SM1"), team("b", "SM2"), team("c", "SM3")];
/** Budgets larges par défaut : aucun membre au plafond effectif (les tests qui l'exercent passent le leur). */
const BUDGETS = budgets(soloBudget("a", 5), soloBudget("b", 5), soloBudget("c", 5));

describe("completedGroupCaseCount", () => {
  it("counts the scope slots whose reserved set is EXACTLY the group members", () => {
    const g = group("g", ["a", "b"], 2);
    // Monday 18:00 = {a,b} → complete ; Wednesday 20:00 = {a} → partial, not counted ;
    // Friday 18:00 = {a,b,c} → superset, not counted.
    const reservations = [resa("a", "v1", 1, "18:00"), resa("b", "v1", 1, "18:00"), resa("a", "v1", 3, "20:00"), resa("a", "v1", 5, "18:00"), resa("b", "v1", 5, "18:00"), resa("c", "v1", 5, "18:00")];
    expect(completedGroupCaseCount(g, reservations)).toBe(1);
  });
});

describe("offerableGroups", () => {
  it("offers a group on an EMPTY draft slot", () => {
    const result = offerableGroups([group("g", ["a", "b"])], TEAMS, [], true, BUDGETS);
    expect(result.offerable.map((o) => o.id)).toEqual(["g"]);
    expect(result.offerable[0]?.label).toBe("SM1 + SM2 — 1 séance commune");
    expect(result.blocked).toEqual([]);
  });

  it("offers NOTHING when the draft slot is not empty (rule a — needs a free slot)", () => {
    const result = offerableGroups([group("g", ["a", "b"])], TEAMS, [], false, BUDGETS);
    expect(result.offerable).toEqual([]);
    expect(result.blocked).toEqual([]);
  });

  it("does NOT offer a group that already reached its K common sessions — with a named reason", () => {
    const g = group("g", ["a", "b"], 1);
    // One case already complete for {a,b} → K(1) reached elsewhere in scope.
    const reservations = [resa("a", "v1", 1, "18:00"), resa("b", "v1", 1, "18:00")];
    const result = offerableGroups([g], TEAMS, reservations, true, BUDGETS);
    expect(result.offerable).toEqual([]);
    expect(result.blocked.map((b) => b.id)).toEqual(["g"]);
    expect(result.blocked[0]?.reason).toContain("séance");
  });

  it("does NOT offer a group whose member is paused — named reason", () => {
    const result = offerableGroups([group("g", ["a", "b"])], TEAMS, [], true, BUDGETS, new Set(["b"]));
    expect(result.offerable).toEqual([]);
    expect(result.blocked[0]?.reason).toContain("SM2");
  });

  it("D4 — n'offre PAS un groupe dont un membre a atteint ses séances EFFECTIVES (budget, pas sessionsPerWeek) — raison nommée", () => {
    const g = group("g", ["a", "b"]);
    // a : override de période à 2 séances effectives et déjà 2 réservations → au plafond effectif,
    // alors que son sessionsPerWeek d'équipe vaut 5 : c'est bien le budget qui tranche.
    const reservations = [resa("a", "v1", 2, "17:00"), resa("a", "v1", 4, "17:00")];
    const result = offerableGroups([g], [team("a", "SM1", 5), team("b", "SM2", 5)], reservations, true, budgets(soloBudget("a", 2), soloBudget("b", 5)));
    expect(result.offerable).toEqual([]);
    expect(result.blocked[0]?.reason).toContain("SM1");
  });

  it("D4 — offre un groupe quand un override de période RELÈVE les séances effectives au-dessus de sessionsPerWeek", () => {
    const g = group("g", ["a", "b"]);
    // a : sessionsPerWeek d'équipe = 1, mais override de période à 3 séances effectives → 2 réservations n'atteignent PAS le plafond.
    const reservations = [resa("a", "v1", 2, "17:00"), resa("a", "v1", 4, "17:00")];
    const result = offerableGroups([g], [team("a", "SM1", 1), team("b", "SM2", 1)], reservations, true, budgets(soloBudget("a", 3), soloBudget("b", 3)));
    expect(result.offerable.map((o) => o.id)).toEqual(["g"]);
  });
});

describe("postedGroupOnSlot", () => {
  it("recognises N reservations that are EXACTLY a group's members as one lot", () => {
    const onSlot = [resa("a", "v1", 1, "18:00"), resa("b", "v1", 1, "18:00")];
    const lot = postedGroupOnSlot(onSlot, [group("g", ["a", "b"])]);
    expect(lot?.group.id).toBe("g");
    expect(lot?.reservationIds).toEqual(["a-v1-1-18:00", "b-v1-1-18:00"]);
  });

  it("returns null when the reserved set is not exactly a group (subset/superset/foreign)", () => {
    expect(postedGroupOnSlot([resa("a", "v1", 1, "18:00")], [group("g", ["a", "b"])])).toBeNull();
    expect(postedGroupOnSlot([resa("a", "v1", 1, "18:00"), resa("b", "v1", 1, "18:00"), resa("c", "v1", 1, "18:00")], [group("g", ["a", "b"])])).toBeNull();
    expect(postedGroupOnSlot([], [group("g", ["a", "b"])])).toBeNull();
  });
});
