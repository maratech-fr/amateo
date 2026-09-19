import { describe, expect, it } from "vitest";

import type { Fixture, OpponentTravel, TeamMatchHabit } from "../api";
import { awayHour, awayTravelByKey, awayTravelKey } from "./awayKickoff";

const away = (over: Partial<Fixture> = {}): Fixture => ({
  id: "fx-away",
  teamId: "team-1",
  seasonId: "s",
  competitionId: null,
  matchDate: "2026-10-03", // Saturday (ISO weekday 6)
  homeAway: "AWAY",
  opponentLabel: "Grenoble",
  status: "UNPLACED",
  venueId: null,
  kickoffTime: null,
  externalRef: null,
  fbiVenueLabel: null,
  placementSource: null,
  unplacedReason: null,
  reviewState: "NEW",
  reviewedAt: null,
  pendingDeviations: [],
  ffbbRencontreId: null,
  suggestedVenueId: null,
  opponentOrganismeCode: null,
  opponentTeamKey: null,
  ...over,
});

const habit = (over: Partial<TeamMatchHabit> = {}): TeamMatchHabit => ({
  id: "h-1",
  teamId: "team-1",
  dayOfWeek: 6, // Saturday
  kickoffTime: "20:30",
  venueId: null,
  ...over,
});

const travelEntry = (over: Partial<OpponentTravel> = {}): OpponentTravel => ({
  opponentOrganismeCode: "C1",
  opponentTeamKey: "GRENOBLE-1",
  opponentLabel: "Grenoble",
  located: true,
  hasLogo: false,
  precision: "VENUE",
  locationName: "Halle Y",
  city: null,
  postalCode: null,
  travelMinutes: 22,
  approximated: false,
  source: "AUTO",
  scope: "CLUB",
  overrideVenueLabel: null,
  travelStatus: "done",
  ...over,
});

describe("awayHour (règle d'affichage extraite d'AwayList — témoin)", () => {
  it("l'heure réelle prime et n'est JAMAIS marquée estimée", () => {
    expect(awayHour(away({ kickoffTime: "15:30" }), [habit()])).toEqual({ hour: "15:30", estimated: false });
  });

  it("sans heure réelle mais avec habitude du jour : l'habitude, marquée estimée", () => {
    expect(awayHour(away(), [habit()])).toEqual({ hour: "20:30", estimated: true });
  });

  it("sans heure ni habitude du bon jour : null (« heure inconnue »)", () => {
    // Habitude un samedi, match un dimanche → aucune habitude ce jour-là.
    expect(awayHour(away({ matchDate: "2026-10-04" }), [habit()])).toEqual({ hour: null, estimated: false });
  });
});

describe("awayTravelKey / awayTravelByKey (jointure (code, teamKey))", () => {
  it("null sans code fédéral, sinon « code teamKey »", () => {
    expect(awayTravelKey(null, "GRENOBLE-1")).toBeNull();
    expect(awayTravelKey("C1", "GRENOBLE-1")).toBe("C1 GRENOBLE-1");
    expect(awayTravelKey("C1", null)).toBe("C1 ");
  });

  it("indexe les trajets par clé et saute ceux sans code", () => {
    const map = awayTravelByKey([travelEntry(), travelEntry({ opponentOrganismeCode: null, opponentTeamKey: "X" })]);
    expect(map.get("C1 GRENOBLE-1")).toBeDefined();
    expect(map.size).toBe(1);
  });
});
