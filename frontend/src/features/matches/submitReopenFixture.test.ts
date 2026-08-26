import { beforeEach, describe, expect, it, vi } from "vitest";

import type { Fixture } from "./api";
import { reopenFixture, submitFixture } from "./api";

// Mute the network: capture the PUT body without hitting ky. `.json()` resolves
// to whatever — these tests assert the REQUEST, not the response.
const { put } = vi.hoisted(() => ({
  put: vi.fn<(url: string, opts: { json: Record<string, unknown> }) => { json: () => Promise<unknown> }>(() => ({ json: () => Promise.resolve({}) })),
}));
vi.mock("@/shared/api/client", () => ({ api: { put } }));

const placed: Fixture = {
  id: "fx-1",
  teamId: "team-1",
  seasonId: "s",
  competitionId: "comp-9",
  matchDate: "2026-10-03",
  homeAway: "HOME",
  opponentLabel: "Voisins",
  status: "PLACED",
  venueId: "venue-1",
  kickoffTime: "16:00",
  externalRef: "FBI-42",
  fbiVenueLabel: null,
  placementSource: "MANUAL",
  unplacedReason: null,};

function lastBody(): Record<string, unknown> {
  const call = put.mock.calls.at(-1);
  if (undefined === call) {
    throw new Error("api.put was never called");
  }
  return call[1].json;
}

beforeEach(() => put.mockClear());

describe("submitFixture (RMM-1 PR 1)", () => {
  it("PUTs the SAME fixture, only status → SUBMITTED", async () => {
    await submitFixture(placed);

    expect(put).toHaveBeenCalledWith("fixtures/fx-1", expect.anything());
    const body = lastBody();
    expect(body.status).toBe("SUBMITTED");
    // Full-replace echo: EVERY identity/placement field is resent, or the PUT
    // would wipe it. Erasing any one of these must redden the test.
    expect(body.teamId).toBe("team-1");
    expect(body.matchDate).toBe("2026-10-03");
    expect(body.homeAway).toBe("HOME");
    expect(body.opponentLabel).toBe("Voisins");
    expect(body.competitionId).toBe("comp-9");
    expect(body.venueId).toBe("venue-1");
    expect(body.kickoffTime).toBe("16:00");
  });
});

describe("reopenFixture (RMM-1 PR 1)", () => {
  it("PUTs the SAME fixture, only status → PLACED (repair path out of SUBMITTED)", async () => {
    const submittedFixture: Fixture = { ...placed, status: "SUBMITTED" };
    await reopenFixture(submittedFixture);

    expect(put).toHaveBeenCalledWith("fixtures/fx-1", expect.anything());
    const body = lastBody();
    expect(body.status).toBe("PLACED");
    expect(body.teamId).toBe("team-1");
    expect(body.opponentLabel).toBe("Voisins");
    expect(body.competitionId).toBe("comp-9");
    expect(body.venueId).toBe("venue-1");
    expect(body.kickoffTime).toBe("16:00");
  });
});
