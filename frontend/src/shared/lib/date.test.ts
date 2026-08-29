import { describe, expect, it } from "vitest";

import { frDateNumeric, frDateShort, frDateShortNoYear, frDateWeekdayNoYear } from "./date";

/**
 * Foyer unique du formatage de date FR (UXC-19). Ces goldens gardent l'AFFICHAGE : les
 * variantes descendues de cockpit et d'AwayList doivent rendre EXACTEMENT ce qu'elles
 * rendaient, et les quatre formats restent DISTINCTS (jamais fusionnés).
 */
describe("shared/lib/date — les quatre formats FR distincts", () => {
  // 2026-09-12 est un samedi.
  const iso = "2026-09-12";

  it("frDateNumeric : jj-mm-aaaa", () => {
    expect(frDateNumeric("2026-10-17")).toBe("17-10-2026");
  });

  it("frDateShort : « 19 déc. 2026 » (jour, mois court, année)", () => {
    expect(frDateShort("2026-12-19")).toBe("19 déc. 2026");
  });

  it("frDateShortNoYear : « 19 déc. » (sans année)", () => {
    expect(frDateShortNoYear("2026-12-19")).toBe("19 déc.");
  });

  it("frDateWeekdayNoYear : « sam. 12 sept. » (jour de semaine, sans année)", () => {
    expect(frDateWeekdayNoYear(iso)).toBe("sam. 12 sept.");
  });

  it("les quatre formats d'une même date diffèrent (aucune fusion)", () => {
    const rendered = [frDateNumeric(iso), frDateShort(iso), frDateShortNoYear(iso), frDateWeekdayNoYear(iso)];
    expect(new Set(rendered).size).toBe(4);
  });
});
