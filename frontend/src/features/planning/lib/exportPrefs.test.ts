import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { getShowCoaches, setShowCoaches } from "./exportPrefs";

/**
 * Chantier E — la préférence « Afficher le nom des coachs » est MÉMORISÉE dans le navigateur,
 * PAR CLUB (clé `export-show-coaches-<clubId>`), et DÉCOCHÉE par défaut. Elle survit à une page
 * dont le stockage est inaccessible (navigation privée, site data bloqué) : lecture et écriture
 * sont gardées try/catch — jamais une exception, toujours un défaut sûr (false).
 *
 * ⚠ API PROPOSÉE (option A, TANK écrit AVANT NEO) : `getShowCoaches(clubId)` /
 * `setShowCoaches(clubId, value)`. NEO peut adapter mécaniquement les noms ; la clé exacte et la
 * règle (défaut false, par club, try/catch) sont la promesse.
 */
describe("exportPrefs — mémorisation de « Afficher le nom des coachs »", () => {
  beforeEach(() => {
    localStorage.clear();
  });
  afterEach(() => {
    vi.restoreAllMocks();
    localStorage.clear();
  });

  it("vaut false par défaut quand rien n'est mémorisé (décoché)", () => {
    expect(getShowCoaches("club-1")).toBe(false);
  });

  it("mémorise un choix coché sous la clé par club et le relit", () => {
    setShowCoaches("club-1", true);

    expect(getShowCoaches("club-1")).toBe(true);
    // La clé exacte est une promesse (coordination avec le cockpit + la page planning).
    expect(localStorage.getItem("export-show-coaches-club-1")).not.toBeNull();
  });

  it("isole les clubs : un choix sur un club n'affecte pas l'autre", () => {
    setShowCoaches("club-1", true);

    expect(getShowCoaches("club-1")).toBe(true);
    expect(getShowCoaches("club-2")).toBe(false);
  });

  it("revient à false quand on décoche", () => {
    setShowCoaches("club-1", true);
    setShowCoaches("club-1", false);

    expect(getShowCoaches("club-1")).toBe(false);
  });

  it("ne lève jamais quand le stockage est inaccessible — défaut false en lecture, silence en écriture", () => {
    const throwing = (): never => {
      throw new Error("storage blocked");
    };
    vi.spyOn(Storage.prototype, "getItem").mockImplementation(throwing);
    vi.spyOn(Storage.prototype, "setItem").mockImplementation(throwing);

    expect(getShowCoaches("club-1")).toBe(false);
    expect(() => setShowCoaches("club-1", true)).not.toThrow();
  });
});
