import { describe, expect, it } from "vitest";

import type { VenueMatchWindow } from "../api";
import { applicableWindows, dateInsideClosure, hasWeeklyAccess, kickoffInsideAccess, kickoffInsideHolidayWindow, kickoffInsideWindow } from "./matchAccess";
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

/**
 * PR2 « jours fériés » — CÔTÉ FRONT de la parité mécanique du prédicat d'accès match UN JOUR
 * FÉRIÉ : le coup d'envoi tombe-t-il dans une ligne « Jours fériés » (`appliesOnPublicHolidays`)
 * du gymnase ? Même algèbre DEMI-OUVERTE `[start, end[` que `kickoffInsideWindow`, mais filtrée sur
 * le DRAPEAU férié (jamais un jour de semaine). Le MÊME fichier de cas alimente
 * `MatchAccessMirrorParityTest.php` (backend, `MatchConflictDetector::kickoffInsideHolidayWindow`) :
 * changer l'algèbre férié d'un seul côté rougit ce côté-là.
 */
describe("kickoffInsideHolidayWindow — parité mécanique avec MatchConflictDetector (PHP), PR2", () => {
  for (const c of cases.holidayCases) {
    it(c.name, () => {
      expect(kickoffInsideHolidayWindow(c.venueId, c.kickoff, c.windows as VenueMatchWindow[])).toBe(c.inside);
    });
  }
});

/**
 * Ouverture datée — CÔTÉ FRONT du prédicat unique (D2) : plages applicables (gymnase, date) =
 * ouvertures de ce jour ∪ (férié ? lignes « Jours fériés » : lignes du jour de semaine). Jumeaux :
 * `MatchAccessMirrorParityTest.php` (backend) et `MatchAccessEngineParityTest.php` (moteur).
 */
describe("kickoffInsideAccess — parité avec le backend et le moteur", () => {
  for (const c of cases.accessCases) {
    it(c.name, () => {
      expect(kickoffInsideAccess(c.venueId, c.matchDate, c.isHoliday, c.kickoff, c.windows as VenueMatchWindow[])).toBe(c.inside);
    });
  }
});

describe("applicableWindows — parité avec le backend", () => {
  for (const c of cases.accessCases) {
    it(c.name, () => {
      const starts = applicableWindows(c.venueId, c.matchDate, c.isHoliday, c.windows as VenueMatchWindow[]).map((w) => w.startTime).sort();
      expect(starts).toEqual(c.applicableStarts);
    });
  }
});

/** D1bis — « le club a adopté les accès » : au moins une ligne NON datée. */
describe("hasWeeklyAccess — parité avec le backend", () => {
  for (const c of cases.adoptionCases) {
    it(c.name, () => {
      expect(hasWeeklyAccess(c.windows as VenueMatchWindow[])).toBe(c.adopted);
    });
  }
});
