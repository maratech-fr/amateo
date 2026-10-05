import { describe, expect, it } from "vitest";

import type { VenueMatchWindow } from "../api";
import { dateInsideClosure, kickoffInsideWindow } from "./matchAccess";
import cases from "./matchAccess.parity.json";

/**
 * P4-88 — CÔTÉ FRONT de la parité mécanique du prédicat d'accès match. Le MÊME fichier de
 * cas alimente `MatchAccessMirrorParityTest.php` (backend,
 * `MatchConflictDetector::kickoffInsideWindow`). Changer l'algèbre d'appartenance d'un seul
 * côté rougit ce côté-là.
 */
describe("kickoffInsideWindow — parité mécanique avec MatchConflictDetector (PHP)", () => {
  for (const c of cases.cases) {
    it(c.name, () => {
      expect(kickoffInsideWindow(c.venueId, c.day, c.kickoff, c.windows as VenueMatchWindow[])).toBe(c.inside);
    });
  }
});

describe("dateInsideClosure — parité mécanique avec MatchConflictDetector (PHP)", () => {
  for (const c of cases.closureCases) {
    it(c.name, () => {
      expect(dateInsideClosure(c.matchDate, c.startDate, c.endDate)).toBe(c.inside);
    });
  }
});
