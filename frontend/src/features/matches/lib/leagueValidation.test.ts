import { describe, expect, it } from "vitest";

import { ENTRY_DEADLINES_PATH, leagueValidationBody } from "./leagueValidation";

describe("LeagueValidation — le corps chiffré de la confirmation (lot L)", () => {
  it("annonce le nombre ET ce qui va changer, avec l'accord singulier", () => {
    const body = leagueValidationBody(1);
    expect(body).toMatch(/^1 rencontre importée porte déjà/);
    // Ce qui change : « validé ligue » + le verrouillage/ancre du solveur.
    expect(body).toMatch(/« validé ligue »/);
    expect(body).toMatch(/ancres/);
    // Refuser ne change rien (refaisable plus tard).
    expect(body).toMatch(/refuser ne change rien/);
  });

  it("accorde au pluriel au-delà de 1", () => {
    expect(leagueValidationBody(3)).toMatch(/^3 rencontres importées portent déjà/);
  });

  it("le renvoi « Échéances de saisie » est un deep-link ?section=echeances", () => {
    expect(ENTRY_DEADLINES_PATH).toBe("/matchs/configuration?section=echeances");
  });
});
