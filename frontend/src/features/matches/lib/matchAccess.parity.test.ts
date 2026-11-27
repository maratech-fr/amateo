import { describe, expect, it } from "vitest";

import type { Fixture, VenueMatchWindow } from "../api";
import { isFriendlyFixture } from "./envelope";
import { applicableWindows, dateInsideClosure, hasWeeklyAccess, kickoffInsideAccess, kickoffInsideHolidayWindow, kickoffInsideWindow, venueAccessError } from "./matchAccess";
import cases from "./matchAccess.parity.json";

/**
 * Lot 7 PR 4a — un match d'une compétition amicale (« Amicaux » d'office comme amical de la
 * fédération) se pose hors des accès match, signalé ; tout autre est refusé. La nature vient de
 * `fixture.competitionType` par `isFriendlyFixture`, jamais d'un `competitionId` nul. Le MÊME
 * fichier de cas (`friendlyAccessCases`) alimente `MatchAccessMirrorParityTest.php` (backend,
 * `CompetitionType::isFriendly`) : exempter autre chose d'un seul côté rougit ce côté-là.
 */
describe("venueAccessError — un amical hors accès est signalé, jamais refusé (parité avec le backend)", () => {
  const windows: VenueMatchWindow[] = [{ venueId: "v1", dayOfWeek: 6, startTime: "14:00", endTime: "18:00", appliesOnPublicHolidays: false, openingDate: null } as VenueMatchWindow];
  for (const c of cases.friendlyAccessCases) {
    it(c.name, () => {
      const fixture = { id: "fx", teamId: "team-1", competitionId: c.competitionId, competitionType: c.competitionType } as unknown as Fixture;
      const issue = venueAccessError("v1", "Gymnase", "2026-10-03", "20:00", windows, isFriendlyFixture(fixture));
      expect(issue?.level).toBe(c.exempt ? "warning" : "error");
    });
  }
});

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
