import { describe, expect, it } from "vitest";

import type { Schedule } from "../api";
import { laterCompletedVersionId, liveContextScheduleId, overlayVersionLabels, versionLabels, visibleOverlayVersions, visibleSeasonPlans } from "./versions";

const plan = (over: Partial<Schedule>): Schedule => ({
  id: "id",
  name: "Plan",
  status: "COMPLETED",
  score: null,
  createdAt: "2026-07-01T10:00:00+00:00",
  updatedAt: "2026-07-01T10:00:00+00:00",
  planType: "SEASON",
  schedulePlanId: "season-plan",
  ...over,
});

// ADR-0002 C4 : un overlay = planType non-SEASON + le plan de sa période (schedulePlanId).
const overlayOf = (planId: string): Partial<Schedule> => ({ planType: "CLOSURE", schedulePlanId: planId });

describe("visibleSeasonPlans", () => {
  it("keeps season versions, hides overlays", () => {
    const list = [
      plan({ id: "a" }),
      plan({ id: "ov", ...overlayOf("ce1") }),
    ];
    expect(visibleSeasonPlans(list).map((s) => s.id)).toEqual(["a"]);
  });
});

describe("versionLabels", () => {
  it("numbers visible season plans chronologically (V1 oldest) with a date stamp", () => {
    const list = [
      plan({ id: "new", createdAt: "2026-07-10T14:32:00+00:00" }),
      plan({ id: "mid", createdAt: "2026-07-09T09:00:00+00:00" }),
      plan({ id: "old", createdAt: "2026-07-08T09:00:00+00:00" }),
    ];
    const labels = versionLabels(list);
    expect(labels.get("old")).toMatch(/^V1 — 8 juil\./);
    expect(labels.get("mid")).toMatch(/^V2 — /);
    expect(labels.get("new")).toMatch(/^V3 — 10 juil\./);
  });
});

describe("visibleOverlayVersions", () => {
  it("keeps only that period's overlay versions, chronological", () => {
    const list = [
      plan({ id: "season" }),
      plan({ id: "ov1", ...overlayOf("ce1"), createdAt: "2026-07-08T09:00:00+00:00" }),
      plan({ id: "ov2", ...overlayOf("ce1"), createdAt: "2026-07-10T09:00:00+00:00" }),
      plan({ id: "ovOther", ...overlayOf("ce2") }),
    ];
    expect(visibleOverlayVersions(list, "ce1").map((s) => s.id)).toEqual(["ov1", "ov2"]);
  });
});

describe("overlayVersionLabels", () => {
  it("numbers a period's overlay versions V{n}", () => {
    const list = [
      plan({ id: "ov2", ...overlayOf("ce1"), createdAt: "2026-07-10T14:32:00+00:00" }),
      plan({ id: "ov1", ...overlayOf("ce1"), createdAt: "2026-07-08T09:00:00+00:00" }),
      plan({ id: "other", ...overlayOf("ce2"), createdAt: "2026-07-09T09:00:00+00:00" }),
    ];
    const labels = overlayVersionLabels(list, "ce1");
    expect(labels.get("ov1")).toMatch(/^V1 — 8 juil\./);
    expect(labels.get("ov2")).toMatch(/^V2 — 10 juil\./);
    expect(labels.has("other")).toBe(false);
  });
});

describe("liveContextScheduleId — the ★ (latest generated, = live context)", () => {
  it("is the LATEST season version when no server pointer is set (fallback)", () => {
    const list = [
      plan({ id: "v1", createdAt: "2026-07-01T10:00:00+00:00" }),
      plan({ id: "v2", createdAt: "2026-07-02T10:00:00+00:00" }),
    ];
    // No isLiveContext pointer → fall back to the latest (V2).
    expect(liveContextScheduleId(list, null)).toBe("v2");
  });

  it("honors the server pointer (isLiveContext) over the latest — « Charger V1 » moves the ★", () => {
    const list = [
      plan({ id: "v1", createdAt: "2026-07-01T10:00:00+00:00", isLiveContext: true }),
      plan({ id: "v2", createdAt: "2026-07-02T10:00:00+00:00" }),
    ];
    // V1 is the loaded context even though V2 is newer.
    expect(liveContextScheduleId(list, null)).toBe("v1");
  });

  it("scopes to the selected overlay's own versions", () => {
    const list = [
      plan({ id: "s1", createdAt: "2026-07-05T10:00:00+00:00" }),
      plan({ id: "o1", ...overlayOf("p1"), createdAt: "2026-07-01T10:00:00+00:00" }),
      plan({ id: "o2", ...overlayOf("p1"), createdAt: "2026-07-02T10:00:00+00:00" }),
    ];
    expect(liveContextScheduleId(list, "p1")).toBe("o2");
    expect(liveContextScheduleId(list, null)).toBe("s1");
  });

  it("is null when there is no version", () => {
    expect(liveContextScheduleId([], null)).toBeNull();
  });
});

describe("laterCompletedVersionId (P4-98 — version antérieure)", () => {
  it("returns the newer COMPLETED season id when the displayed season version is an older one", () => {
    const older = plan({ id: "old", createdAt: "2026-07-01T10:00:00+00:00" });
    const newer = plan({ id: "new", createdAt: "2026-07-05T10:00:00+00:00" });
    expect(laterCompletedVersionId(older, [older, newer])).toBe("new");
  });

  it("is null when the displayed version IS the latest COMPLETED of its plan", () => {
    const older = plan({ id: "old", createdAt: "2026-07-01T10:00:00+00:00" });
    const newer = plan({ id: "new", createdAt: "2026-07-05T10:00:00+00:00" });
    expect(laterCompletedVersionId(newer, [older, newer])).toBeNull();
  });

  it("points at the newer COMPLETED even when the displayed one is the in-force (isChosen) but older", () => {
    // La raison d'être P4-98 : `pickLandingScheduleId` atterrit sur la version EN VIGUEUR
    // (isChosen), qui peut être plus ANCIENNE que la dernière COMPLETED du même plan.
    const chosenOld = plan({ id: "chosen", createdAt: "2026-07-01T10:00:00+00:00", isChosen: true });
    const newer = plan({ id: "new", createdAt: "2026-07-08T10:00:00+00:00" });
    expect(laterCompletedVersionId(chosenOld, [chosenOld, newer])).toBe("new");
  });

  it("scopes to the displayed overlay's OWN plan — a newer version of another plan does not count", () => {
    const displayedOverlay = plan({ id: "o1", ...overlayOf("p1"), createdAt: "2026-07-01T10:00:00+00:00" });
    const newerOverlaySamePlan = plan({ id: "o2", ...overlayOf("p1"), createdAt: "2026-07-04T10:00:00+00:00" });
    const newerOtherPlan = plan({ id: "x", ...overlayOf("p2"), createdAt: "2026-07-09T10:00:00+00:00" });
    const newerSeason = plan({ id: "s", createdAt: "2026-07-09T10:00:00+00:00" });
    expect(laterCompletedVersionId(displayedOverlay, [displayedOverlay, newerOverlaySamePlan, newerOtherPlan, newerSeason])).toBe("o2");
  });

  it("ignores a newer non-COMPLETED version — only a finished later one counts", () => {
    const older = plan({ id: "old", createdAt: "2026-07-01T10:00:00+00:00" });
    const runningNewer = plan({ id: "run", status: "GENERATING", createdAt: "2026-07-05T10:00:00+00:00" });
    expect(laterCompletedVersionId(older, [older, runningNewer])).toBeNull();
  });

  it("is null when the displayed version is the NEWEST overall (even if it failed) — not an earlier one", () => {
    const completedOld = plan({ id: "ok", createdAt: "2026-07-01T10:00:00+00:00" });
    const failedNew = plan({ id: "ko", status: "FAILED", createdAt: "2026-07-05T10:00:00+00:00" });
    expect(laterCompletedVersionId(failedNew, [completedOld, failedNew])).toBeNull();
  });

  it("is null when nothing is displayed", () => {
    expect(laterCompletedVersionId(null, [plan({ id: "a" })])).toBeNull();
  });
});
