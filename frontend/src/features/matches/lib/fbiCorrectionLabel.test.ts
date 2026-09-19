import { describe, expect, it } from "vitest";

import { FIELD_LABEL, seenAgoLabel, seenInFbiLabel } from "./fbiCorrectionLabel";

describe("fbiCorrectionLabel — libellés purs du registre « FBI — à faire »", () => {
  it("FIELD_LABEL traduit chaque champ", () => {
    expect(FIELD_LABEL.date).toBe("Date");
    expect(FIELD_LABEL.kickoff).toBe("Heure");
    expect(FIELD_LABEL.venue).toBe("Salle");
  });

  it("seenAgoLabel : « aujourd'hui » (0/futur), « il y a N j » (≥ 1) — aucun seuil", () => {
    expect(seenAgoLabel(0)).toBe("aujourd'hui");
    expect(seenAgoLabel(-2)).toBe("aujourd'hui");
    expect(seenAgoLabel(1)).toBe("il y a 1 j");
    expect(seenAgoLabel(6)).toBe("il y a 6 j");
  });

  it("seenInFbiLabel : « vu dans FBI le … (il y a N j) », today injecté", () => {
    // 14 sept. → 20 sept. = 6 jours.
    expect(seenInFbiLabel("2026-09-14T10:00:00+02:00", "2026-09-20")).toMatch(/vu dans FBI le .*sept.* \(il y a 6 j\)/);
    // Le jour même.
    expect(seenInFbiLabel("2026-09-20T08:00:00+02:00", "2026-09-20")).toMatch(/\(aujourd'hui\)/);
  });

  it("seenInFbiLabel : null quand jamais re-vu (lastSeenInFbiAt null)", () => {
    expect(seenInFbiLabel(null, "2026-09-20")).toBeNull();
  });
});
