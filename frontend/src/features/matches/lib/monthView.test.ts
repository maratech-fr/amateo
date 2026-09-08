import { describe, expect, it } from "vitest";

import type { Conflict, Fixture } from "../api";
import { conflictsByFixture, groupByDay, listMonths, resolveActiveMonth, scopeConflictsToMonth } from "./monthView";

function fx(partial: Partial<Fixture> & Pick<Fixture, "id" | "matchDate">): Fixture {
  return {
    teamId: "t",
    seasonId: "s",
    competitionId: null,
    homeAway: "HOME",
    opponentLabel: "Adv",
    status: "PLACED",
    venueId: null,
    kickoffTime: null,
    externalRef: null,
    fbiVenueLabel: null,
    placementSource: null,
    unplacedReason: null,
    reviewState: "NEW" as const,
    reviewedAt: null,
    pendingDeviations: [],
    ffbbRencontreId: null,
    ...partial,
  };
}

describe("listMonths", () => {
  it("renvoie les mois YYYY-MM distincts, triés", () => {
    const fixtures = [fx({ id: "a", matchDate: "2026-10-31" }), fx({ id: "b", matchDate: "2026-09-12" }), fx({ id: "c", matchDate: "2026-10-03" })];
    expect(listMonths(fixtures)).toEqual(["2026-09", "2026-10"]);
  });

  it("liste vide ⇒ []", () => {
    expect(listMonths([])).toEqual([]);
  });
});

describe("resolveActiveMonth (même règle que resolveActiveWeekend)", () => {
  const months = ["2026-09", "2026-10", "2026-11"];
  it("sélection listée ⇒ elle", () => {
    expect(resolveActiveMonth(months, "2026-10", "2026-08")).toBe("2026-10");
  });
  it("sélection absente ⇒ premier mois ≥ courant", () => {
    expect(resolveActiveMonth(months, null, "2026-10")).toBe("2026-10");
    expect(resolveActiveMonth(months, "ghost", "2026-09")).toBe("2026-09");
  });
  it("tous passés ⇒ le dernier", () => {
    expect(resolveActiveMonth(months, null, "2027-01")).toBe("2026-11");
  });
  it("aucun mois ⇒ null", () => {
    expect(resolveActiveMonth([], null, "2026-10")).toBeNull();
  });
});

describe("groupByDay", () => {
  it("groupe par jour du mois, jours triés, coup d'envoi croissant, heure non publiée en dernier", () => {
    const fixtures = [
      fx({ id: "d2-18h", matchDate: "2026-10-04", kickoffTime: "18:00" }),
      fx({ id: "d1-noneA", matchDate: "2026-10-03", kickoffTime: null }),
      fx({ id: "d1-16h", matchDate: "2026-10-03", kickoffTime: "16:00" }),
      fx({ id: "autre-mois", matchDate: "2026-11-01", kickoffTime: "10:00" }),
    ];
    const groups = groupByDay(fixtures, "2026-10");
    expect(groups.map((g) => g.date)).toEqual(["2026-10-03", "2026-10-04"]);
    // Jour 1 : 16h puis heure non publiée (null) en dernier.
    expect(groups[0].fixtures.map((f) => f.id)).toEqual(["d1-16h", "d1-noneA"]);
    expect(groups[1].fixtures.map((f) => f.id)).toEqual(["d2-18h"]);
  });
});

describe("scopeConflictsToMonth (même contrat que scopeConflictsToWeek)", () => {
  const inMonth: Conflict = {
    type: "VENUE_OVERLAP",
    severity: 1,
    left: { fixtureId: "x", teamId: "t", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" },
  };
  const nextMonth: Conflict = {
    type: "VENUE_UNAVAILABLE",
    severity: 1,
    fixture: { fixtureId: "y", teamId: "t", homeAway: "HOME", matchDate: "2026-11-02", kickoffTime: "16:00", status: "PLACED" },
  };
  const dateless: Conflict = { type: "COMPETITION_INCOMPLETE", severity: 6, competitionId: "c", imported: 1, expected: 10 };

  it("garde ceux du mois, écarte ceux d'un autre mois, garde toujours les sans-date", () => {
    const kept = scopeConflictsToMonth([inMonth, nextMonth, dateless], "2026-10");
    expect(kept).toContain(inMonth);
    expect(kept).not.toContain(nextMonth);
    expect(kept).toContain(dateless);
  });

  it("mois null ⇒ pass-through (MÊME référence)", () => {
    const all = [inMonth, nextMonth];
    expect(scopeConflictsToMonth(all, null)).toBe(all);
  });
});

describe("conflictsByFixture", () => {
  it("rattache un conflit à chaque fixture référencée (left/right/fixture), sans doublon", () => {
    const overlap: Conflict = {
      type: "VENUE_OVERLAP",
      severity: 1,
      left: { fixtureId: "fa", teamId: "t", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" },
      right: { fixtureId: "fb", teamId: "u", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", windowStart: "", windowEnd: "" },
    };
    const unavail: Conflict = {
      type: "VENUE_UNAVAILABLE",
      severity: 1,
      fixture: { fixtureId: "fa", teamId: "t", homeAway: "HOME", matchDate: "2026-10-03", kickoffTime: "16:00", status: "PLACED" },
    };
    const map = conflictsByFixture([overlap, unavail]);
    expect(map.get("fa")).toEqual([overlap, unavail]);
    expect(map.get("fb")).toEqual([overlap]);
  });
});
