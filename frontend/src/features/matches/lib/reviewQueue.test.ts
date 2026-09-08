import { describe, expect, it } from "vitest";

import type { Fixture, FixtureReviewState } from "../api";
import { buildReviewQueue, pendingReviewCount } from "./reviewQueue";

let seq = 0;
function fx(teamId: string, reviewState: FixtureReviewState, matchDate: string, extra: Partial<Fixture> = {}): Fixture {
  seq += 1;
  return {
    id: `fx-${seq}`,
    teamId,
    seasonId: "s1",
    competitionId: null,
    matchDate,
    homeAway: "HOME",
    opponentLabel: "ADV",
    status: "PLACED",
    venueId: null,
    kickoffTime: null,
    externalRef: null,
    fbiVenueLabel: null,
    placementSource: null,
    unplacedReason: null,
    reviewState,
    reviewedAt: null,
    pendingDeviations: [],
    ffbbRencontreId: null,
    ...extra,
  };
}

describe("buildReviewQueue (PR-3b — la file de traitement)", () => {
  it("groupe par équipe, une équipe sans rencontre est absente, et suit teamOrder", () => {
    const fixtures = [fx("t2", "NEW", "2026-11-01"), fx("t1", "NEW", "2026-11-02")];
    const queue = buildReviewQueue(fixtures, ["t1", "t2", "t3"]);
    expect(queue.map((q) => q.teamId)).toEqual(["t1", "t2"]); // t3 (aucune rencontre) absente
  });

  it("open = NEW|OUT_OF_SYNC triées matchDate ASC ; treated = REVIEWED", () => {
    const fixtures = [
      fx("t1", "REVIEWED", "2026-10-10"),
      fx("t1", "OUT_OF_SYNC", "2026-12-05"),
      fx("t1", "NEW", "2026-11-01"),
    ];
    const [q] = buildReviewQueue(fixtures, ["t1"]);
    expect(q.open.map((f) => f.matchDate)).toEqual(["2026-11-01", "2026-12-05"]);
    expect(q.treated.map((f) => f.reviewState)).toEqual(["REVIEWED"]);
  });

  it("toValidate compte les NEW ; deviationCount compte les rencontres OUT_OF_SYNC (une = une unité)", () => {
    const fixtures = [
      fx("t1", "NEW", "2026-11-01"),
      fx("t1", "NEW", "2026-11-02"),
      // OUT_OF_SYNC avec DEUX champs d'écart — compte pour UNE rencontre.
      fx("t1", "OUT_OF_SYNC", "2026-11-03", {
        pendingDeviations: [
          { field: "date", appValue: "2026-11-03", sourceValue: "2026-11-10", channel: "FBI_XLSX", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: false },
          { field: "venue", appValue: "A", sourceValue: "B", channel: "FBI_XLSX", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: false },
        ],
      }),
      fx("t1", "REVIEWED", "2026-11-04"),
    ];
    const [q] = buildReviewQueue(fixtures, ["t1"]);
    expect(q.toValidate).toBe(2);
    expect(q.deviationCount).toBe(1);
  });

  it("une équipe hors teamOrder (dérive) échoue en fin de liste plutôt que de disparaître", () => {
    const fixtures = [fx("t9", "NEW", "2026-11-01"), fx("t1", "NEW", "2026-11-02")];
    const queue = buildReviewQueue(fixtures, ["t1"]);
    expect(queue.map((q) => q.teamId)).toEqual(["t1", "t9"]);
  });
});

describe("pendingReviewCount (le badge)", () => {
  it("compte NEW + OUT_OF_SYNC, jamais REVIEWED", () => {
    const fixtures = [
      fx("t1", "NEW", "2026-11-01"),
      fx("t2", "OUT_OF_SYNC", "2026-11-02"),
      fx("t3", "REVIEWED", "2026-11-03"),
    ];
    expect(pendingReviewCount(fixtures)).toBe(2);
  });

  it("zéro quand tout est traité", () => {
    expect(pendingReviewCount([fx("t1", "REVIEWED", "2026-11-01")])).toBe(0);
  });
});
