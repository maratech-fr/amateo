import { describe, expect, it } from "vitest";

import { stalenessBadge, stalenessMessage } from "./staleness";

const NONE = { manuallyEdited: false, constraintsChanged: false, resourcesChanged: false, structureDiverged: false, readOnly: false };

describe("stalenessMessage", () => {
  it("returns null when nothing is stale (no banner)", () => {
    expect(stalenessMessage(NONE)).toBeNull();
  });

  it("names the manual edit as the cause", () => {
    const msg = stalenessMessage({ ...NONE, manuallyEdited: true });
    expect(msg).toContain("modifié à la main");
    expect(msg).toContain("Régénérez");
  });

  it("names the constraint change as the cause — périmé, not faux", () => {
    const msg = stalenessMessage({ ...NONE, constraintsChanged: true });
    expect(msg).toContain("une contrainte a changé");
    expect(msg).toContain("périmé");
    // Le mot est choisi : on régénère pour SAVOIR, pas parce que c'est invalide.
    expect(msg).toContain("pas forcément faux");
  });

  it("names a resource change (club data) as the cause — P4-87", () => {
    const msg = stalenessMessage({ ...NONE, resourcesChanged: true });
    expect(msg).toContain("les données du club ont changé");
    expect(msg).toContain("gymnases");
    expect(msg).toContain("périmé");
  });

  it("names a structure divergence (teams added/removed) in the SAME banner, not a separate one", () => {
    const msg = stalenessMessage({ ...NONE, structureDiverged: true });
    expect(msg).toContain("des équipes ont été ajoutées ou retirées");
    expect(msg).toContain("périmé");
  });

  it("names EVERY active cause in a single message (unified banner)", () => {
    const msg = stalenessMessage({
      manuallyEdited: true,
      constraintsChanged: true,
      resourcesChanged: true,
      structureDiverged: true,
      readOnly: false,
    });
    expect(msg).toContain("modifié à la main");
    expect(msg).toContain("une contrainte a changé");
    expect(msg).toContain("les données du club ont changé");
    expect(msg).toContain("des équipes ont été ajoutées ou retirées");
    // Une seule phrase : les causes énumérées, jamais deux bannières.
    expect((msg?.match(/périmé/g) ?? []).length).toBe(1);
  });

  it("joins two causes with « et »", () => {
    const msg = stalenessMessage({ ...NONE, manuallyEdited: true, constraintsChanged: true });
    expect(msg).toContain("il a été modifié à la main et une contrainte a changé");
  });

  it("offers reopen-then-regenerate on a read-only (validated / in-force) plan, never a bare regenerate", () => {
    const msg = stalenessMessage({ ...NONE, constraintsChanged: true, readOnly: true });
    // Un planning validé est en lecture seule : « Régénérer » seul l'enverrait dans un 409.
    expect(msg).toContain("Rouvrez ce planning");
    expect(msg).not.toMatch(/^Régénérez/);
  });
});

const CLEAN = { manuallyEdited: false, constraintsChanged: false, resourcesChanged: false };

describe("stalenessBadge (forme courte du cockpit)", () => {
  it("returns null when nothing is stale (no pill)", () => {
    expect(stalenessBadge(CLEAN)).toBeNull();
  });

  it("prefixes « À régénérer — » and names the single cause", () => {
    expect(stalenessBadge({ ...CLEAN, constraintsChanged: true })).toBe("À régénérer — une contrainte a changé");
  });

  it("names a manual edit in short form", () => {
    expect(stalenessBadge({ ...CLEAN, manuallyEdited: true })).toBe("À régénérer — modifié à la main");
  });

  it("names a club-data change in short form", () => {
    expect(stalenessBadge({ ...CLEAN, resourcesChanged: true })).toBe("À régénérer — les données du club ont changé");
  });

  it("joins two causes with « et »", () => {
    expect(stalenessBadge({ ...CLEAN, manuallyEdited: true, constraintsChanged: true })).toBe(
      "À régénérer — modifié à la main et une contrainte a changé",
    );
  });

  it("joins three causes with commas then « et » (single label, no structureDiverged)", () => {
    expect(stalenessBadge({ manuallyEdited: true, constraintsChanged: true, resourcesChanged: true })).toBe(
      "À régénérer — modifié à la main, une contrainte a changé et les données du club ont changé",
    );
  });
});
