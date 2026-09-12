import { describe, expect, it } from "vitest";

import type { Fixture, FixtureReviewState } from "../api";
import { buildReviewQueue, isUnattachedHome, pendingReviewCount } from "./reviewQueue";

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
    suggestedVenueId: null,
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

  it("unattachedCount compte les domiciles sans salle à libellé (ouvertes ET traitées), AWAY exclu", () => {
    const fixtures = [
      // Domicile importé sans gymnase, encore à traiter (NEW) → compte.
      fx("t1", "NEW", "2026-11-01", { fbiVenueLabel: "GYMNASE MATEO" }),
      // Domicile importé sans gymnase, DÉJÀ traité (REVIEWED) → compte aussi (confondues).
      fx("t1", "REVIEWED", "2026-11-02", { fbiVenueLabel: "COUBERTIN" }),
      // AWAY à libellé → jamais compté (pas un domicile à rattacher).
      fx("t1", "NEW", "2026-11-03", { homeAway: "AWAY", fbiVenueLabel: "HALLE X" }),
      // Domicile SANS libellé → rien à rattacher.
      fx("t1", "NEW", "2026-11-04", { fbiVenueLabel: null }),
      // Domicile DÉJÀ rattaché (venueId posé) → exclu.
      fx("t1", "NEW", "2026-11-05", { fbiVenueLabel: "MATEO", venueId: "v1" }),
    ];
    const [q] = buildReviewQueue(fixtures, ["t1"]);
    expect(q.unattachedCount).toBe(2);
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

  it("une REVIEWED à écart AUTO-APPLIQUÉ reste dans open (pas rangée en traitée) et compte comme à valider", () => {
    // P4-199 — un extérieur / domicile in-window dont la source a fait foi : traité,
    // mais porteur d'une alerte « Pris en compte » → visible sans « Afficher les traitées ».
    const auto = fx("t1", "REVIEWED", "2026-11-02", {
      pendingDeviations: [{ field: "date", appValue: "2026-11-02", sourceValue: "2026-11-09", channel: "FBI_XLSX", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: true }],
    });
    const plain = fx("t1", "REVIEWED", "2026-11-03");
    const [q] = buildReviewQueue([auto, plain], ["t1"]);
    expect(q.open.map((f) => f.id)).toEqual([auto.id]);
    expect(q.treated.map((f) => f.id)).toEqual([plain.id]);
    expect(q.toValidate).toBe(1);
    expect(q.deviationCount).toBe(0);
  });

  it("une équipe hors teamOrder (dérive) échoue en fin de liste plutôt que de disparaître", () => {
    const fixtures = [fx("t9", "NEW", "2026-11-01"), fx("t1", "NEW", "2026-11-02")];
    const queue = buildReviewQueue(fixtures, ["t1"]);
    expect(queue.map((q) => q.teamId)).toEqual(["t1", "t9"]);
  });
});

describe("pendingReviewCount (le badge)", () => {
  it("compte NEW + OUT_OF_SYNC + REVIEWED à alerte auto-appliquée ; jamais une REVIEWED sans alerte", () => {
    const fixtures = [
      fx("t1", "NEW", "2026-11-01"),
      fx("t2", "OUT_OF_SYNC", "2026-11-02"),
      fx("t3", "REVIEWED", "2026-11-03"),
      fx("t4", "REVIEWED", "2026-11-04", {
        pendingDeviations: [{ field: "date", appValue: "2026-11-04", sourceValue: "2026-11-11", channel: "FFBB_API", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: true }],
      }),
    ];
    expect(pendingReviewCount(fixtures)).toBe(3);
  });

  it("zéro quand tout est traité sans alerte", () => {
    expect(pendingReviewCount([fx("t1", "REVIEWED", "2026-11-01")])).toBe(0);
  });
});

describe("isUnattachedHome (le domicile importé sans gymnase)", () => {
  it("HOME sans venueId AVEC libellé → true", () => {
    expect(isUnattachedHome(fx("t1", "NEW", "2026-11-01", { fbiVenueLabel: "GYMNASE MATEO" }))).toBe(true);
  });

  it("AWAY (même avec libellé) → false", () => {
    expect(isUnattachedHome(fx("t1", "NEW", "2026-11-01", { homeAway: "AWAY", fbiVenueLabel: "GYMNASE MATEO" }))).toBe(false);
  });

  it("libellé null → false", () => {
    expect(isUnattachedHome(fx("t1", "NEW", "2026-11-01", { fbiVenueLabel: null }))).toBe(false);
  });

  it("venueId déjà posé → false (déjà rattaché)", () => {
    expect(isUnattachedHome(fx("t1", "NEW", "2026-11-01", { fbiVenueLabel: "MATEO", venueId: "v1" }))).toBe(false);
  });
});
