import { describe, expect, it } from "vitest";

import { FIELD_LABEL, fieldConsequence, isDeposited } from "./deviationConsequence";

/**
 * RMM-4 — la CONSÉQUENCE d'un choix est de la PRÉSENTATION (le back décide vraiment).
 * On épingle ici ce que l'écran promet AVANT que le gestionnaire ne tranche : prendre
 * la date/salle du fichier libère le créneau (dé-placement), prendre l'heure le garde,
 * et un match saisi dans FBI mérite le signalement renforcé.
 */
describe("deviationConsequence (RMM-4, présentation pure)", () => {
  it("prendre la date du fichier LIBÈRE le créneau (dé-placement)", () => {
    const c = fieldConsequence("date");
    expect(c.releasesSlot).toBe(true);
    expect(c.takeFile).toMatch(/libère le créneau/i);
  });

  it("prendre la salle du fichier LIBÈRE aussi le créneau", () => {
    const c = fieldConsequence("venue");
    expect(c.releasesSlot).toBe(true);
    expect(c.takeFile).toMatch(/libère le créneau/i);
  });

  it("prendre l'heure du fichier GARDE le placement", () => {
    const c = fieldConsequence("kickoff");
    expect(c.releasesSlot).toBe(false);
    expect(c.takeFile).toMatch(/garde le placement/i);
  });

  it("garder l'app n'écrit rien — une trace jusqu'au prochain dépôt", () => {
    expect(fieldConsequence("date").keepApp).toMatch(/prochain dépôt/i);
    expect(fieldConsequence("kickoff").keepApp).toMatch(/prochain dépôt/i);
  });

  it("FIELD_LABEL est une TABLE FR (jamais un code montré)", () => {
    expect(FIELD_LABEL).toEqual({ date: "Date", kickoff: "Heure", venue: "Salle" });
  });

  it("isDeposited : un match saisi/validé FBI est déposé, un placé/importé ne l'est pas", () => {
    expect(isDeposited("SUBMITTED")).toBe(true);
    expect(isDeposited("VALIDATED")).toBe(true);
    expect(isDeposited("PLACED")).toBe(false);
    expect(isDeposited("UNPLACED")).toBe(false);
  });
});
