import { describe, expect, it } from "vitest";

import { calendarDaysBetween, parisDayOf } from "./relativeDays";

/**
 * La « Dernière activité » de la console compte en JOURS CALENDAIRES Europe/Paris : l'instant lu
 * (RFC 3339) est ramené à son jour Paris, puis compté jusqu'au « aujourd'hui » de l'écran — même
 * fuseau que le throttle d'écriture côté serveur (`LoginSuccessListenerTest`), sans effet des
 * changements d'heure. Jumeau écran : `AdminDashboardPage.test.tsx` (colonne Dernière activité).
 */
describe("parisDayOf — le jour Europe/Paris d'un instant", () => {
  it("23:30 UTC un soir d'hiver est déjà le lendemain à Paris (UTC+1)", () => {
    expect(parisDayOf("2026-11-09T23:30:00+00:00")).toBe("2026-11-10");
  });

  it("22:30 UTC un soir d'hiver est encore le jour même à Paris", () => {
    expect(parisDayOf("2026-11-09T22:30:00+00:00")).toBe("2026-11-09");
  });

  it("22:30 UTC un soir d'été est déjà le lendemain à Paris (UTC+2)", () => {
    expect(parisDayOf("2026-07-09T22:30:00+00:00")).toBe("2026-07-10");
  });
});

describe("calendarDaysBetween — jours calendaires Paris jusqu'à aujourd'hui", () => {
  it("le même jour Paris vaut 0", () => {
    expect(calendarDaysBetween("2026-11-10T08:00:00+00:00", "2026-11-10")).toBe(0);
  });

  it("00:30 Paris aujourd'hui (23:30 UTC la veille) vaut 0", () => {
    expect(calendarDaysBetween("2026-11-09T23:30:00+00:00", "2026-11-10")).toBe(0);
  });

  it("23:30 Paris la veille (22:30 UTC) vaut 1", () => {
    expect(calendarDaysBetween("2026-11-09T22:30:00+00:00", "2026-11-10")).toBe(1);
  });

  it("douze jours calendaires valent 12", () => {
    expect(calendarDaysBetween("2026-10-29T10:00:00+00:00", "2026-11-10")).toBe(12);
  });

  it("le passage à l'heure d'hiver (nuit du 24 au 25 oct.) ne décale pas le compte", () => {
    expect(calendarDaysBetween("2026-10-25T10:00:00+00:00", "2026-10-26")).toBe(1);
    expect(calendarDaysBetween("2026-10-24T21:30:00+00:00", "2026-10-26")).toBe(2);
  });

  it("été : 22:30 UTC la veille est déjà aujourd'hui à Paris (fenêtre de 2 h) → 0", () => {
    expect(calendarDaysBetween("2026-07-09T22:30:00+00:00", "2026-07-10")).toBe(0);
  });

  it("un instant futur (horloge décalée) vaut 0, jamais un négatif", () => {
    expect(calendarDaysBetween("2026-11-12T08:00:00+00:00", "2026-11-10")).toBe(0);
  });
});
