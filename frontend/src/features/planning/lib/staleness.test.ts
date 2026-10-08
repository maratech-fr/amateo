import { describe, expect, it } from "vitest";

import { stalenessMessage } from "./staleness";

const NONE = { manuallyEdited: false, structureChanged: false, readOnly: false };

describe("stalenessMessage", () => {
  it("returns null when nothing is stale (no banner)", () => {
    expect(stalenessMessage(NONE)).toBeNull();
  });

  it("names the manual edit as the cause", () => {
    const msg = stalenessMessage({ ...NONE, manuallyEdited: true });
    expect(msg).toContain("modifié à la main");
    expect(msg).toContain("Régénérez");
  });

  it("names a structure change (empreinte diverge) as the cause — périmé, not faux", () => {
    const msg = stalenessMessage({ ...NONE, structureChanged: true });
    expect(msg).toContain("vos données ont changé");
    expect(msg).toContain("périmé");
    // Le mot est choisi : on régénère pour SAVOIR, pas parce que c'est invalide.
    expect(msg).toContain("pas forcément faux");
  });

  it("names BOTH active causes in a single message (unified banner)", () => {
    const msg = stalenessMessage({ manuallyEdited: true, structureChanged: true, readOnly: false });
    expect(msg).toContain("il a été modifié à la main et vos données ont changé");
    // Une seule phrase : les causes énumérées, jamais deux bannières.
    expect((msg?.match(/périmé/g) ?? []).length).toBe(1);
  });

  it("is MUTE on a validated / in-force (read-only) plan — no « à régénérer » at all (P4-266)", () => {
    // Décision fondateur : un planning validé ne porte aucun signal, quelle que soit la cause.
    expect(stalenessMessage({ manuallyEdited: true, structureChanged: true, readOnly: true })).toBeNull();
    expect(stalenessMessage({ ...NONE, structureChanged: true, readOnly: true })).toBeNull();
  });
});
