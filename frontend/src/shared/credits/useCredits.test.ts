import { describe, expect, it } from "vitest";

import type { ClubEntitlements } from "@/shared/session/api";

import { deriveCredits } from "./useCredits";

const base: ClubEntitlements = {
  planCode: "decouverte",
  planName: "Découverte",
  tierCode: null,
  maxTeams: null,
  teamsUsed: 4,
  creditsMax: 10,
  creditsUsed: 2,
  canGenerate: true,
  canPlaceMatches: true,
  canExportPdf: true,
  seasonTransition: false,
};

describe("deriveCredits", () => {
  it("rend null quand il n'y a pas d'entitlements (pas encore résolus)", () => {
    expect(deriveCredits(undefined)).toBeNull();
  });

  it("rend null hors Découverte bridée (creditsMax null = payant/bêta/démo)", () => {
    expect(deriveCredits({ ...base, planCode: "essentiel", planName: "Essentiel", maxTeams: 20, creditsMax: null })).toBeNull();
  });

  it("projette le solde et son reste (max - used) en Découverte bridée", () => {
    expect(deriveCredits(base)).toEqual({ max: 10, used: 2, remaining: 8, canGenerate: true, canPlaceMatches: true, canExportPdf: true });
  });

  it("borne le reste à 0 (jamais négatif) et reprend les verdicts serveur tels quels", () => {
    // Le serveur peut compter used > max (débit concurrent) : l'affichage reste 0,
    // et ce sont les booléens can* — pas ce calcul — qui disent « plus de sortie ».
    const view = deriveCredits({ ...base, creditsUsed: 12, canGenerate: false, canPlaceMatches: false, canExportPdf: false });
    expect(view).toEqual({ max: 10, used: 12, remaining: 0, canGenerate: false, canPlaceMatches: false, canExportPdf: false });
  });
});
