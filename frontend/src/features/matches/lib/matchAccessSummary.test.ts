import { describe, expect, it } from "vitest";

import type { VenueMatchWindow } from "../api";
import { formatMatchAccessWindows } from "./matchAccessSummary";

const win = (over: Partial<VenueMatchWindow> & { dayOfWeek: number; startTime: string; endTime: string }): VenueMatchWindow => ({
  id: `${over.dayOfWeek}-${over.startTime}`,
  venueId: "v1",
  ...over,
});

describe("formatMatchAccessWindows (PR 2a — les plages d'accès match d'un gymnase)", () => {
  it("sans fenêtre ⇒ « aucun accès match »", () => {
    expect(formatMatchAccessWindows([])).toBe("aucun accès match");
  });

  it("un jour ⇒ « sam. 12:00–23:00 » (abrégé minuscule, tiret demi-cadratin)", () => {
    expect(formatMatchAccessWindows([win({ dayOfWeek: 6, startTime: "12:00", endTime: "23:00" })])).toBe("sam. 12:00–23:00");
  });

  it("deux jours ⇒ tri jour ascendant, séparés par « · »", () => {
    // dimanche fourni AVANT samedi : le tri les remet dans l'ordre ISO.
    const windows = [win({ dayOfWeek: 7, startTime: "09:00", endTime: "20:00" }), win({ dayOfWeek: 6, startTime: "12:00", endTime: "23:00" })];
    expect(formatMatchAccessWindows(windows)).toBe("sam. 12:00–23:00 · dim. 09:00–20:00");
  });

  it("deux plages le MÊME jour ⇒ rejointes par une virgule, début ascendant", () => {
    const windows = [win({ dayOfWeek: 6, startTime: "16:00", endTime: "23:00" }), win({ dayOfWeek: 6, startTime: "12:00", endTime: "14:00" })];
    expect(formatMatchAccessWindows(windows)).toBe("sam. 12:00–14:00, 16:00–23:00");
  });
});
