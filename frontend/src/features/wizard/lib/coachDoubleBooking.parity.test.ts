import { describe, expect, it } from "vitest";

import { type Booking, bookingsCollide, holdersFor, lateArrivalTolerated, teamsShareMainCoach } from "./coachDoubleBooking";
import cases from "./coachDoubleBooking.parity.json";

/**
 * P4-88 — CÔTÉ FRONT de la parité mécanique de la règle de dédoublement de coach.
 * Le MÊME fichier de cas alimente `CoachDoubleBookingMirrorParityTest.php` (backend) :
 * chaque `collide` est le verdict attendu des deux implémentations. Changer la règle
 * ici sans porter le cas partagé rougit ce test ; l'inverse rougit le test PHP.
 */
describe("bookingsCollide — parité mécanique avec CoachDoubleBookingDetector (PHP)", () => {
  for (const c of cases.cases) {
    it(c.name, () => {
      expect(bookingsCollide(c.a as Booking, c.b as Booking)).toBe(c.collide);
      // Symétrique : l'ordre des deux séances ne change jamais le verdict.
      expect(bookingsCollide(c.b as Booking, c.a as Booking)).toBe(c.collide);
    });
  }
});

/**
 * Chantier H — CÔTÉ FRONT de la parité de la TOLÉRANCE d'arrivée tardive.
 * `lateArrivalTolerated(a, b, toleranceMinutes, baremeMinutes)` doit coïncider avec
 * `CoachDoubleBookingDetector::lateArrivalTolerated` (PHP) sur `toleranceCases` :
 * retard = max(0, fin(a) + barème − début(b)), toléré ssi 1 <= tolérance <= 30, début(b)
 * STRICTEMENT après début(a), et retard <= tolérance (plafond 30). La disponibilité de
 * l'adjoint reste HORS de cette règle pure (vérifiée serveur).
 */
describe("lateArrivalTolerated — parité mécanique de la tolérance (PHP)", () => {
  for (const c of cases.toleranceCases) {
    it(c.name, () => {
      expect(lateArrivalTolerated(c.a as Booking, c.b as Booking, c.toleranceMinutes, c.baremeMinutes)).toBe(c.tolerated);
    });
  }
});

/**
 * Chantier H / P4-333 — CÔTÉ FRONT de la parité du partage de coach principal.
 * `teamsShareMainCoach(coachIdsA, coachIdsB)` doit coïncider avec
 * `CoachDoubleBookingDetector::teamsShareMainCoach` sur `multiCoachCases` (exemple
 * roadmap Camille/Dorian : deux équipes se dédoublent dès qu'elles partagent un principal).
 */
describe("teamsShareMainCoach — parité mécanique multi-coach P4-333 (PHP)", () => {
  for (const c of cases.multiCoachCases) {
    it(c.name, () => {
      expect(teamsShareMainCoach(c.coachIdsA, c.coachIdsB)).toBe(c.shares);
      expect(teamsShareMainCoach(c.coachIdsB, c.coachIdsA)).toBe(c.shares);
    });
  }
});

/**
 * « Tant qu'un coach tient la séance, c'est OK » — CÔTÉ FRONT de la parité de l'affectation des
 * tenues : `holdersFor` doit rendre EXACTEMENT les tenues de `holdCases`, ou null si une séance n'a
 * personne. Jumeau : `CoachDoubleBookingMirrorParityTest::testBackendHoldersForMatchesTheSharedCases`.
 */
describe("holdersFor — parité mécanique de l'affectation des tenues (PHP)", () => {
  for (const c of cases.holdCases) {
    it(c.name, () => {
      const blocked = ("blockedByCoach" in c ? c.blockedByCoach : {}) as Parameters<typeof holdersFor>[2];
      const playing = ("playingByCoach" in c ? c.playingByCoach : {}) as Parameters<typeof holdersFor>[3];

      const groups = "groups" in c ? (c.groups as string[][]) : [];

      expect(holdersFor(c.sessions, c.mainCoachesByTeam as Record<string, string[]>, blocked, playing, groups)).toEqual(c.holders);
    });
  }
});
