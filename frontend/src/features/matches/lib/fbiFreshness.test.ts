import { describe, expect, it } from "vitest";

import { STALE_DAYS, depositDaysAgo, relativeDepositLabel } from "./fbiFreshness";

/**
 * RMM-4 — la FRAÎCHEUR relative, formulée pour un humain (aujourd'hui / hier / il y a
 * N jours). Le « aujourd'hui » vient du front (`todayISO`), l'ancrage démo compris.
 */
describe("fbiFreshness (RMM-4)", () => {
  it("depositDaysAgo compte les jours calendaires entre le dépôt et aujourd'hui", () => {
    expect(depositDaysAgo("2026-08-24T10:30:00+00:00", "2026-08-24")).toBe(0);
    expect(depositDaysAgo("2026-08-23T23:00:00+00:00", "2026-08-24")).toBe(1);
    expect(depositDaysAgo("2026-08-19T08:00:00+00:00", "2026-08-24")).toBe(5);
  });

  it("relativeDepositLabel : aujourd'hui / hier / il y a N jours", () => {
    expect(relativeDepositLabel(0)).toBe("aujourd'hui");
    expect(relativeDepositLabel(1)).toBe("hier");
    expect(relativeDepositLabel(5)).toBe("il y a 5 jours");
    // Un dépôt « dans le futur » (horloge décalée) ne ment pas : jamais négatif.
    expect(relativeDepositLabel(-2)).toBe("aujourd'hui");
  });

  it("STALE_DAYS — le seuil d'escalade en warning est 30 jours", () => {
    expect(STALE_DAYS).toBe(30);
  });
});
