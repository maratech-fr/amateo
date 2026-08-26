import { describe, expect, it } from "vitest";

import type { EditFixtureInput, Fixture } from "../api";
import { editFixtureBody } from "../api";

const placed: Fixture = {
  id: "fx-1",
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2026-10-03",
  homeAway: "HOME",
  opponentLabel: "Voisins",
  status: "PLACED",
  venueId: "venue-1",
  kickoffTime: "16:00",
  externalRef: null,
  fbiVenueLabel: null,
  placementSource: "MANUAL",
  unplacedReason: null,};

const edit = (over: Partial<EditFixtureInput> = {}): EditFixtureInput => ({
  matchDate: placed.matchDate,
  homeAway: placed.homeAway,
  opponentLabel: placed.opponentLabel,
  competitionId: placed.competitionId,
  ...over,
});

describe("editFixtureBody (P1-4 PR E1)", () => {
  it("keeps the placement on a manual date change — the manager IS the decision", () => {
    const body = editFixtureBody(placed, edit({ matchDate: "2026-10-10" }));
    expect(body.matchDate).toBe("2026-10-10");
    expect(body.status).toBe("PLACED");
    expect(body.venueId).toBe("venue-1");
    expect(body.kickoffTime).toBe("16:00");
  });

  it("frees the slot when the match switches HOME → AWAY (our venue makes no sense away)", () => {
    const body = editFixtureBody(placed, edit({ homeAway: "AWAY" }));
    expect(body.status).toBe("UNPLACED");
    expect(body.venueId).toBe("");
    expect(body.kickoffTime).toBe("");
  });

  it("does not unplace an AWAY match edited while staying AWAY", () => {
    const away: Fixture = { ...placed, homeAway: "AWAY", venueId: null, status: "UNPLACED" };
    const body = editFixtureBody(away, edit({ homeAway: "AWAY", opponentLabel: "Autres" }));
    expect(body.status).toBe("UNPLACED");
    expect(body.opponentLabel).toBe("Autres");
  });
});
