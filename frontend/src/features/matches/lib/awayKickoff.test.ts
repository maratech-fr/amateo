import { describe, expect, it } from "vitest";

import type { Fixture, TeamMatchHabit } from "../api";
import { awayHour, awayTimeline } from "./awayKickoff";

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

describe("awayTimeline (foyer unique du trajet aller-retour dessiné/affiché)", () => {
  it("trajet connu : le bloc couvre départ → retour (échauffement + aller avant, aller après = ce que compte le radar)", () => {
    // 15:30 = 930, match 105 → fin 17:15 = 1035 ; échauffement 30 + aller 45 → départ 14:15 = 855,
    // retour 18:00 = 1080 (P4-240 ③ : l'échauffement compte AVANT le départ extérieur).
    expect(awayTimeline(930, 105, 30, 45)).toEqual({
      departureMin: 855,
      kickoffMin: 930,
      matchEndMin: 1035,
      returnMin: 1080,
      oneWayMinutes: 45,
    });
  });

  it("trajet inconnu (null) : pas d'aller — le départ reste coup d'envoi − échauffement", () => {
    // 930 − échauffement 30 = 900 ; retour = fin du match (pas d'aller).
    expect(awayTimeline(930, 105, 30, null)).toEqual({
      departureMin: 900,
      kickoffMin: 930,
      matchEndMin: 1035,
      returnMin: 1035,
      oneWayMinutes: null,
    });
  });
});
