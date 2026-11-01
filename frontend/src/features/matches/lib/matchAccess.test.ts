import { describe, expect, it } from "vitest";

import type { VenueClosure, VenueMatchWindow, VenueUnavailability } from "../api";
import { venueAccessError } from "./matchAccess";

// Une fenêtre d'accès match le samedi (jour ISO 6), 14:00–18:00, sur venue-1.
const windows: VenueMatchWindow[] = [{ id: "w1", venueId: "venue-1", dayOfWeek: 6, appliesOnPublicHolidays: false, openingDate: null, label: null, startTime: "14:00", endTime: "18:00" }];
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
      reason: "outside_window",
      message: expect.stringMatching(/Hors des accès match de ce jour \(14:00 – 18:00\)/) as unknown as string,
    });
    expect(venueAccessError("venue-1", "Alpha", SATURDAY, "20:00", windows, [], true)).toEqual({
      level: "warning",
      reason: "friendly_off_slot",
      message: "Amical hors créneau match — placement libre.",
    });
  });

  it("aucune fenêtre ce jour-là : error compétition, warning amical", () => {
    // La fenêtre est un samedi ; on interroge un dimanche.
    expect(venueAccessError("venue-1", "Alpha", SUNDAY, "14:00", windows, [], false)?.level).toBe("error");
    expect(venueAccessError("venue-1", "Alpha", SUNDAY, "14:00", windows, [], true)).toEqual({
      level: "warning",
      reason: "friendly_off_slot",
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

/**
 * P4-300 — une FERMETURE de gymnase du calendrier à la date du match est un refus DUR, amical
 * compris (miroir du refus serveur D4). Hors de l'intervalle, elle ne gêne pas.
 */
describe("venueAccessError — fermeture du calendrier (P4-300)", () => {
  const closures: VenueClosure[] = [{ id: "c1", venueId: "venue-1", title: "Gymnase en travaux", startDate: "2026-08-31", endDate: "2026-10-16" }];

  it("gymnase fermé à la date du match : error nommée, même pour un amical", () => {
    const comp = venueAccessError("venue-1", "Alpha", SATURDAY, "15:00", windows, [], false, closures);
    expect(comp?.level).toBe("error");
    expect(comp?.message).toMatch(/Alpha est fermée du .* au .* — Gymnase en travaux\./);
    expect(venueAccessError("venue-1", "Alpha", SATURDAY, "15:00", windows, [], true, closures)?.level).toBe("error");
  });

  it("date hors de l'intervalle de fermeture : la fermeture ne gêne pas", () => {
    const closed: VenueClosure[] = [{ id: "c1", venueId: "venue-1", title: "T", startDate: "2026-11-01", endDate: "2026-11-30" }];
    expect(venueAccessError("venue-1", "Alpha", SATURDAY, "15:00", windows, [], false, closed)).toBeNull();
  });

  it("fermeture d'un AUTRE gymnase : sans effet sur celui-ci", () => {
    const elsewhere: VenueClosure[] = [{ id: "c2", venueId: "venue-2", title: "T", startDate: "2026-08-31", endDate: "2026-10-16" }];
    expect(venueAccessError("venue-1", "Alpha", SATURDAY, "15:00", windows, [], false, elsewhere)).toBeNull();
  });
});

/** Ouverture datée : la garde de pose lit le prédicat unique et nomme la RAISON du refus, seule à ouvrir le raccourci (D5). */
describe("venueAccessError — ouverture datée", () => {
  const WEDNESDAY = "2026-11-18";
  const HOLIDAY = "2026-11-11";
  const opening = (date: string, startTime = "18:00", endTime = "22:00", venueId = "venue-1"): VenueMatchWindow => ({ id: `o-${date}`, venueId, dayOfWeek: null, appliesOnPublicHolidays: false, openingDate: date, label: null, startTime, endTime });

  it("une ouverture rend la pose possible ce jour-là", () => {
    expect(venueAccessError("venue-1", "Alpha", WEDNESDAY, "19:00", [...windows, opening(WEDNESDAY)], [], false)).toBeNull();
  });

  it("sans plage ce jour : raison no_window et phrase datée", () => {
    const issue = venueAccessError("venue-1", "Alpha", WEDNESDAY, "19:00", windows, [], false);
    expect(issue?.reason).toBe("no_window");
    expect(issue?.message).toBe("Pas d'accès match le mer. 18 nov. à Alpha.");
  });

  it("un férié sans plage : la phrase le dit", () => {
    const issue = venueAccessError("venue-1", "Alpha", HOLIDAY, "19:00", windows, [], false, [], [HOLIDAY]);
    expect(issue?.reason).toBe("no_window");
    expect(issue?.message).toBe("Pas d'accès match le mer. 11 nov. (jour férié) à Alpha.");
  });

  it("hors plage : raison outside_window et toutes les plages de CE jour", () => {
    const line: VenueMatchWindow = { id: "h", venueId: "venue-1", dayOfWeek: null, appliesOnPublicHolidays: true, openingDate: null, label: null, startTime: "10:00", endTime: "12:00" };
    const issue = venueAccessError("venue-1", "Alpha", HOLIDAY, "13:00", [line, opening(HOLIDAY, "14:00", "18:00")], [], false, [], [HOLIDAY]);
    expect(issue?.reason).toBe("outside_window");
    expect(issue?.message).toBe("Hors des accès match de ce jour (10:00 – 12:00, 14:00 – 18:00).");
  });

  it("D1bis : un club qui n'a que des ouvertures n'a pas adopté les accès, le samedi reste libre", () => {
    expect(venueAccessError("venue-1", "Alpha", SATURDAY, "15:00", [opening(HOLIDAY)], [], false)).toBeNull();
  });

  it("une ouverture ne lève jamais une indisponibilité ni une fermeture, et leur raison les distingue", () => {
    const unavail: VenueUnavailability[] = [{ id: "u", venueId: "venue-1", startDate: WEDNESDAY, endDate: WEDNESDAY, label: null }];
    const closure: VenueClosure[] = [{ id: "c", venueId: "venue-1", startDate: WEDNESDAY, endDate: WEDNESDAY, title: "Mairie" } as VenueClosure];
    const open = [...windows, opening(WEDNESDAY)];
    expect(venueAccessError("venue-1", "Alpha", WEDNESDAY, "19:00", open, unavail, false)?.reason).toBe("unavailable");
    expect(venueAccessError("venue-1", "Alpha", WEDNESDAY, "19:00", open, [], false, closure)?.reason).toBe("closed");
  });
});
