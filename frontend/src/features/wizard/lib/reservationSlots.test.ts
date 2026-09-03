import { describe, expect, it } from "vitest";

import type { PriorityTier, Reservation, Team, TeamSoloBudget, VenueTrainingSlot } from "../api";
import { assignableTeams, effectiveSlotCapacity, reservedTeamsBySlot, sharedSlotStatuses, splitCascadePreview, teamReservationCount } from "./reservationSlots";

const slot = (id: string, venueId: string, dayOfWeek: number, startTime: string, capacity = 1): VenueTrainingSlot =>
  ({ id, venueId, dayOfWeek, startTime, durationMinutes: 90, capacity }) as VenueTrainingSlot;

const resa = (teamId: string, venueId: string, dayOfWeek: number, startTime: string): Reservation =>
  ({ id: `${teamId}-${venueId}-${dayOfWeek}-${startTime}`, teamId, venueId, dayOfWeek, startTime, durationMinutes: 90 }) as Reservation;

const team = (id: string, name: string, priorityTierId: number, sessionsPerWeek: number, tierOrder = 0): Team =>
  ({ id, name, priorityTierId, tierOrder, sessionsPerWeek, sportCategoryId: "c" }) as Team;

const TIERS: PriorityTier[] = [
  { id: 1, label: "S", name: "Fanion", color: null },
  { id: 5, label: "D", name: "Bonus", color: null },
];

const NON_SPLIT = new Map([["v1", false]]);
const SPLIT = new Map([["v1", true]]);

/** Budget solo servi par le backend (P2-60) — R(T), posées, appartenance à un bloc. */
const soloBudget = (teamId: string, residual: number, individualUsed = 0, inBlock = false): TeamSoloBudget =>
  ({ teamId, schedulePlanId: null, effectiveSessions: residual, blockSessions: 0, residual, individualUsed, inBlock });
const budgets = (...bs: TeamSoloBudget[]): Map<string, TeamSoloBudget> => new Map(bs.map((b) => [b.teamId, b]));

describe("effectiveSlotCapacity", () => {
  it("caps a known non-divisible gym at 1, else trusts slot.capacity", () => {
    expect(effectiveSlotCapacity(slot("s", "v1", 2, "18:00", 2), NON_SPLIT)).toBe(1);
    expect(effectiveSlotCapacity(slot("s", "v1", 2, "18:00", 2), SPLIT)).toBe(2);
    expect(effectiveSlotCapacity(slot("s", "v1", 2, "18:00", 2), new Map())).toBe(2); // venue not loaded
  });
});

describe("splitCascadePreview (v2 — décocher terrain divisible)", () => {
  it("liste les créneaux à capacité ≥ 2 du gymnase et compte leurs réservations", () => {
    const slots = [slot("a", "v1", 1, "17:30", 2), slot("b", "v1", 3, "20:00", 2), slot("c", "v1", 2, "18:00", 1), slot("d", "v2", 1, "17:30", 2)];
    const reservations = [resa("t1", "v1", 1, "17:30"), resa("t2", "v1", 1, "17:30"), resa("t3", "v1", 3, "20:00"), resa("t4", "v1", 2, "18:00")];
    const preview = splitCascadePreview(slots, reservations, "v1");
    expect(preview.slots.map((s) => s.id)).toEqual(["a", "b"]); // ni le créneau à 1 (c), ni l'autre gymnase (d)
    expect(preview.reservationCount).toBe(3); // deux sur a, une sur b ; celle du créneau à 1 (c) ne compte pas
  });

  it("est vide quand aucun créneau du gymnase n'accueille 2 équipes (pas de modale)", () => {
    const slots = [slot("c", "v1", 2, "18:00", 1)];
    const preview = splitCascadePreview(slots, [resa("t1", "v1", 2, "18:00")], "v1");
    expect(preview.slots).toEqual([]);
    expect(preview.reservationCount).toBe(0);
  });
});

describe("reservedTeamsBySlot / teamReservationCount", () => {
  it("groups teams per slot and counts per team", () => {
    const reservations = [resa("a", "v1", 2, "18:00"), resa("b", "v1", 2, "18:00"), resa("a", "v1", 4, "18:00")];
    expect(reservedTeamsBySlot(reservations).get("v1|2|18:00")).toEqual(["a", "b"]);
    expect(teamReservationCount(reservations).get("a")).toBe(2);
    expect(teamReservationCount(reservations).get("b")).toBe(1);
  });
});

describe("assignableTeams", () => {
  const teams = [team("d1", "Alpha", 5, 2), team("s1", "Zoulou", 1, 2)]; // Alpha=D, Zoulou=S(fanion)

  it("orders by rank (fanion first), not alphabetically, and carries N = résidu − posées", () => {
    const res = assignableTeams(teams, TIERS, slot("s", "v1", 2, "18:00"), [], NON_SPLIT, budgets(soloBudget("d1", 2), soloBudget("s1", 2)), []);
    expect(res.map((a) => a.team.id)).toEqual(["s1", "d1"]);
    expect(res.map((a) => a.remaining)).toEqual([2, 2]);
  });

  it("excludes a team already reserved on the slot", () => {
    const reservations = [resa("s1", "v1", 2, "18:00")];
    // capacity 1 → slot full after one team → nothing offered
    expect(assignableTeams(teams, TIERS, slot("s", "v1", 2, "18:00", 1), reservations, NON_SPLIT, budgets(soloBudget("d1", 2), soloBudget("s1", 2)), [])).toEqual([]);
  });

  it("keeps the free seat of a divisible slot for the OTHER team", () => {
    const reservations = [resa("s1", "v1", 2, "18:00")];
    expect(assignableTeams(teams, TIERS, slot("s", "v1", 2, "18:00", 2), reservations, SPLIT, budgets(soloBudget("d1", 2), soloBudget("s1", 2)), []).map((a) => a.team.id)).toEqual(["d1"]);
  });

  it("retire une équipe dont le budget solo est épuisé (résidu − posées ≤ 0)", () => {
    // Zoulou : résidu 2 mais 2 réservations individuelles déjà posées (backend) → budget nul, retiré.
    expect(assignableTeams(teams, TIERS, slot("s", "v1", 2, "18:00"), [], NON_SPLIT, budgets(soloBudget("d1", 2), soloBudget("s1", 2, 2)), []).map((a) => a.team.id)).toEqual(["d1"]);
  });

  it("retire un membre de bloc à résidu nul (residual 0 && inBlock) — proposé via son bloc, pas individuellement", () => {
    expect(assignableTeams(teams, TIERS, slot("s", "v1", 2, "18:00"), [], NON_SPLIT, budgets(soloBudget("d1", 2), soloBudget("s1", 0, 0, true)), []).map((a) => a.team.id)).toEqual(["d1"]);
  });

  it("décrémente N des ajouts du brouillon, et retire l'équipe quand le brouillon épuise le résidu", () => {
    const one = assignableTeams(teams, TIERS, slot("s", "v1", 2, "18:00"), [], NON_SPLIT, budgets(soloBudget("d1", 2), soloBudget("s1", 2)), ["s1"]);
    expect(one.find((a) => a.team.id === "s1")?.remaining).toBe(1);
    const none = assignableTeams(teams, TIERS, slot("s", "v1", 2, "18:00"), [], NON_SPLIT, budgets(soloBudget("d1", 2), soloBudget("s1", 2)), ["s1", "s1"]);
    expect(none.map((a) => a.team.id)).toEqual(["d1"]);
  });

  it("porte dans N le résidu servi par le backend (override de période inclus via effective)", () => {
    // Un override de période a relevé S(T), donc le résidu, à 3 : N reflète ce résidu, pas sessionsPerWeek (2).
    const res = assignableTeams(teams, TIERS, slot("s", "v1", 2, "18:00"), [], NON_SPLIT, budgets(soloBudget("d1", 2), soloBudget("s1", 3)), []);
    expect(res.find((a) => a.team.id === "s1")?.remaining).toBe(3);
  });

  it("n'offre pas une équipe sans ligne de budget (fail-closed sur une dérive)", () => {
    expect(assignableTeams(teams, TIERS, slot("s", "v1", 2, "18:00"), [], NON_SPLIT, budgets(soloBudget("d1", 2)), []).map((a) => a.team.id)).toEqual(["d1"]);
  });

  it("offers nothing once the slot is full", () => {
    const reservations = [resa("a", "v1", 2, "18:00"), resa("b", "v1", 2, "18:00")];
    expect(assignableTeams(teams, TIERS, slot("s", "v1", 2, "18:00", 2), reservations, SPLIT, budgets(soloBudget("d1", 2), soloBudget("s1", 2)), [])).toEqual([]);
  });

  it("matches an ISO/seconds slot start against an HH:MM reservation (prod shape)", () => {
    // Slots carry an ISO datetime in prod; reservations store HH:MM. They must
    // collide so a team already pinned isn't re-offered on the same physical slot.
    const isoSlot = slot("s", "v1", 2, "1970-01-01T20:30:00+00:00", 1);
    const reservations = [resa("s1", "v1", 2, "20:30")];
    expect(assignableTeams(teams, TIERS, isoSlot, reservations, NON_SPLIT, budgets(soloBudget("d1", 2), soloBudget("s1", 2)), [])).toEqual([]); // slot full via the HH:MM reservation
  });
});

/**
 * Décision P3-8 (2026-08-04) — le récap AVERTIT sur les créneaux partagés, sans bloquer.
 * Deux cas distincts parce que leurs conséquences diffèrent : non réservé = le système
 * choisit les équipes (information) ; PARTIELLEMENT réservé = ALIGN-07 ferme le créneau
 * entier au système, la place restante est PERDUE (avertissement).
 */
describe("sharedSlotStatuses — les avertissements du récap sur créneau partagé", () => {
  it("signale « unreserved » un créneau capacité 2 sans réservation", () => {
    const statuses = sharedSlotStatuses([slot("s", "v1", 2, "18:00", 2)], [], SPLIT);
    expect(statuses).toHaveLength(1);
    expect(statuses[0]).toMatchObject({ kind: "unreserved", capacity: 2, reservedTeamIds: [] });
  });

  it("signale « partial » un créneau capacité 2 réservé par UNE seule équipe", () => {
    const statuses = sharedSlotStatuses([slot("s", "v1", 2, "18:00", 2)], [resa("s1", "v1", 2, "18:00")], SPLIT);
    expect(statuses).toHaveLength(1);
    expect(statuses[0]).toMatchObject({ kind: "partial", reservedTeamIds: ["s1"] });
  });

  it("se tait sur un créneau plein (2/2) comme sur un créneau capacité 1", () => {
    const full = [resa("a", "v1", 2, "18:00"), resa("b", "v1", 2, "18:00")];
    expect(sharedSlotStatuses([slot("s", "v1", 2, "18:00", 2)], full, SPLIT)).toEqual([]);
    expect(sharedSlotStatuses([slot("s", "v1", 2, "18:00", 1)], [], SPLIT)).toEqual([]);
  });

  it("ignore la capacité 2 STOCKÉE d'un gymnase non divisible (effectiveSlotCapacity force 1)", () => {
    expect(sharedSlotStatuses([slot("s", "v1", 2, "18:00", 2)], [], NON_SPLIT)).toEqual([]);
  });

  it("croise un start ISO côté créneau avec le HH:MM d'une réservation (forme prod)", () => {
    // Sans la normalisation, la réservation ne serait pas vue et le créneau crierait
    // « sans réservation » alors qu'il est plein.
    const isoSlot = slot("s", "v1", 2, "1970-01-01T20:30:00+00:00", 2);
    const full = [resa("a", "v1", 2, "20:30"), resa("b", "v1", 2, "20:30")];
    expect(sharedSlotStatuses([isoSlot], full, SPLIT)).toEqual([]);
  });
});
