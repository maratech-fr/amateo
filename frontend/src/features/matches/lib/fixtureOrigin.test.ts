import { describe, expect, it } from "vitest";

import type { Fixture } from "../api";
import { isEditableAway, isImportedFixture } from "./fixtureOrigin";

function fx(over: Partial<Fixture>): Fixture {
  return {
    id: "fx",
    teamId: "t",
    seasonId: "s",
    competitionId: null,
    matchDate: "2026-10-03",
    homeAway: "AWAY",
    opponentLabel: "Adv",
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
    fbiEcho: null,
    awayTravel: null,
    ...over,
  };
}

describe("isImportedFixture", () => {
  it("externalRef présent (FBI) ⇒ importé", () => {
    expect(isImportedFixture(fx({ externalRef: "12345" }))).toBe(true);
  });
  it("ffbbRencontreId présent (canal API) ⇒ importé", () => {
    expect(isImportedFixture(fx({ ffbbRencontreId: "r-9" }))).toBe(true);
  });
  it("les deux clés à null ⇒ saisie manuelle", () => {
    expect(isImportedFixture(fx({ externalRef: null, ffbbRencontreId: null }))).toBe(false);
  });
});

describe("isEditableAway", () => {
  it("extérieur SAISI À LA MAIN (amical) ⇒ modifiable", () => {
    expect(isEditableAway(fx({ homeAway: "AWAY", externalRef: null, ffbbRencontreId: null }))).toBe(true);
  });
  it("extérieur IMPORTÉ ⇒ lecture seule (non modifiable)", () => {
    expect(isEditableAway(fx({ homeAway: "AWAY", externalRef: "77" }))).toBe(false);
  });
  it("un domicile n'est jamais concerné par ce chemin", () => {
    expect(isEditableAway(fx({ homeAway: "HOME", externalRef: null }))).toBe(false);
  });
});
