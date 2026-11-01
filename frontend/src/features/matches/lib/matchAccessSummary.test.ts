import { describe, expect, it } from "vitest";

import type { VenueMatchWindow } from "../api";
import { formatMatchAccessWindows } from "./matchAccessSummary";

const win = (over: Partial<VenueMatchWindow> & { startTime: string; endTime: string }): VenueMatchWindow => ({
  id: `${over.dayOfWeek ?? "holiday"}-${over.startTime}`,
  venueId: "v1",
  dayOfWeek: null,
  appliesOnPublicHolidays: false,
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

/** D21 : une ouverture sort des groupes par jour, elle se compte en suffixe (jamais dans « fériés »). */
describe("formatMatchAccessWindows — ouvertures exceptionnelles", () => {
  const opening = (date: string): VenueMatchWindow => ({ id: date, venueId: "v1", dayOfWeek: null, appliesOnPublicHolidays: false, openingDate: date, label: null, startTime: "14:00", endTime: "20:00" });
  const saturday: VenueMatchWindow = { id: "s", venueId: "v1", dayOfWeek: 6, appliesOnPublicHolidays: false, openingDate: null, label: null, startTime: "14:00", endTime: "18:00" };

  it("une : suffixe au singulier", () => {
    expect(formatMatchAccessWindows([saturday, opening("2026-11-11")])).toBe("sam. 14:00–18:00 · 1 ouverture exceptionnelle");
  });

  it("plusieurs : suffixe au pluriel", () => {
    expect(formatMatchAccessWindows([saturday, opening("2026-11-11"), opening("2026-11-18"), opening("2026-11-25")])).toBe("sam. 14:00–18:00 · 3 ouvertures exceptionnelles");
  });

  it("seules des ouvertures : le compte, sans groupe vide", () => {
    expect(formatMatchAccessWindows([opening("2026-11-11"), opening("2026-11-18")])).toBe("2 ouvertures exceptionnelles");
  });
});
