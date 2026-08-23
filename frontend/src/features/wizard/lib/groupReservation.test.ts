import { describe, expect, it } from "vitest";

import type { Reservation, Team } from "../api";
import { completedGroupCaseCount, type GroupLike, offerableGroups, postedGroupOnSlot } from "./groupReservation";

const team = (id: string, name: string, sessionsPerWeek = 2): Team => ({ id, name, priorityTierId: 1, tierOrder: 0, sessionsPerWeek, sportCategoryId: "c" }) as Team;

const resa = (teamId: string, venueId: string, dayOfWeek: number, startTime: string): Reservation =>
  ({ id: `${teamId}-${venueId}-${dayOfWeek}-${startTime}`, teamId, venueId, dayOfWeek, startTime, durationMinutes: 90 }) as Reservation;

const group = (id: string, teamIds: string[], commonSessions = 1): GroupLike => ({ id, teamIds, commonSessions });

const TEAMS = [team("a", "SM1"), team("b", "SM2"), team("c", "SM3")];

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
    const result = offerableGroups([group("g", ["a", "b"])], TEAMS, [], true);
    expect(result.offerable.map((o) => o.id)).toEqual(["g"]);
    expect(result.offerable[0]?.label).toBe("SM1 + SM2 — 1 séance commune");
    expect(result.blocked).toEqual([]);
  });

  it("offers NOTHING when the draft slot is not empty (rule a — needs a free slot)", () => {
    const result = offerableGroups([group("g", ["a", "b"])], TEAMS, [], false);
    expect(result.offerable).toEqual([]);
    expect(result.blocked).toEqual([]);
  });

  it("does NOT offer a group that already reached its K common sessions — with a named reason", () => {
    const g = group("g", ["a", "b"], 1);
    // One case already complete for {a,b} → K(1) reached elsewhere in scope.
    const reservations = [resa("a", "v1", 1, "18:00"), resa("b", "v1", 1, "18:00")];
    const result = offerableGroups([g], TEAMS, reservations, true);
    expect(result.offerable).toEqual([]);
    expect(result.blocked.map((b) => b.id)).toEqual(["g"]);
    expect(result.blocked[0]?.reason).toContain("séance");
  });

  it("does NOT offer a group whose member is paused — named reason", () => {
    const result = offerableGroups([group("g", ["a", "b"])], TEAMS, [], true, new Set(["b"]));
    expect(result.offerable).toEqual([]);
    expect(result.blocked[0]?.reason).toContain("SM2");
  });

  it("does NOT offer a group whose member already has all its weekly sessions", () => {
    const g = group("g", ["a", "b"]);
    // team a has sessionsPerWeek 2 and already 2 reservations elsewhere → maxed.
    const reservations = [resa("a", "v1", 2, "17:00"), resa("a", "v1", 4, "17:00")];
    const result = offerableGroups([g], [team("a", "SM1", 2), team("b", "SM2", 2)], reservations, true);
    expect(result.offerable).toEqual([]);
    expect(result.blocked[0]?.reason).toContain("SM1");
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
