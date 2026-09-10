import { describe, expect, it } from "vitest";

import type { VenueMatchWindow, VenueUnavailability } from "../api";
import { venueAccessError } from "./matchAccess";

// Une fenêtre d'accès match le samedi (jour ISO 6), 14:00–18:00, sur venue-1.
const windows: VenueMatchWindow[] = [{ id: "w1", venueId: "venue-1", dayOfWeek: 6, startTime: "14:00", endTime: "18:00" }];
const SATURDAY = "2026-10-03";
const SUNDAY = "2026-10-04";

/**
 * P4-193 — `venueAccessError` renvoie `{ level, message }` : `error` refuse le
 * geste (rail synchrone), `warning` le signale sans bloquer. Un amical (isFriendly)
 * assouplit « hors créneau » en warning ; le gymnase indisponible reste un refus
 * dur pour tous. `kickoffInsideWindow` et la parité mécanique ne bougent pas.
 */
describe("venueAccessError — error dure vs warning amical", () => {
  it("gymnase indisponible : error, même pour un amical", () => {
    const unavail: VenueUnavailability[] = [{ id: "u1", venueId: "venue-1", startDate: "2026-10-01", endDate: "2026-10-05", label: "travaux" }];

    expect(venueAccessError("venue-1", "Alpha", SATURDAY, "14:00", windows, unavail, false)?.level).toBe("error");
    const friendly = venueAccessError("venue-1", "Alpha", SATURDAY, "14:00", windows, unavail, true);
    expect(friendly?.level).toBe("error");
    expect(friendly?.message).toMatch(/indisponible/);
  });

  it("coup d'envoi hors fenêtre : error pour une compétition, warning pour un amical", () => {
    expect(venueAccessError("venue-1", "Alpha", SATURDAY, "20:00", windows, [], false)).toEqual({
      level: "error",
      message: expect.stringMatching(/Hors fenêtre d'accès match \(14:00–18:00\)/) as unknown as string,
    });
    expect(venueAccessError("venue-1", "Alpha", SATURDAY, "20:00", windows, [], true)).toEqual({
      level: "warning",
      message: "Amical hors créneau match — placement libre.",
    });
  });

  it("aucune fenêtre ce jour-là : error compétition, warning amical", () => {
    // La fenêtre est un samedi ; on interroge un dimanche.
    expect(venueAccessError("venue-1", "Alpha", SUNDAY, "14:00", windows, [], false)?.level).toBe("error");
    expect(venueAccessError("venue-1", "Alpha", SUNDAY, "14:00", windows, [], true)).toEqual({
      level: "warning",
      message: "Amical hors créneau match — placement libre.",
    });
  });

  it("club sans AUCUNE fenêtre : null (donnée non adoptée), amical ou non", () => {
    expect(venueAccessError("venue-1", "Alpha", SATURDAY, "20:00", [], [], false)).toBeNull();
    expect(venueAccessError("venue-1", "Alpha", SATURDAY, "20:00", [], [], true)).toBeNull();
  });

  it("dans la fenêtre : null (rien à signaler), amical ou non", () => {
    expect(venueAccessError("venue-1", "Alpha", SATURDAY, "15:00", windows, [], false)).toBeNull();
    expect(venueAccessError("venue-1", "Alpha", SATURDAY, "15:00", windows, [], true)).toBeNull();
  });
});
