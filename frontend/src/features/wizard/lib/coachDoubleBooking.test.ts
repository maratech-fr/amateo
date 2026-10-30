import { describe, expect, it } from "vitest";

import type { Reservation, TeamCoach } from "../api";
import { conflictingReservation, mainCoachByTeam } from "./coachDoubleBooking";

/**
 * ⚠️ TEST DE PARITÉ — les six premiers cas sont le miroir de
 * `backend/tests/Security/CoachDoubleBookingTest.php` (P2-9 PR B), un pour un. Deux
 * implémentations de la règle existent forcément (la modale doit répondre sans
 * aller-retour réseau) ; elles ne restent alignées que parce que les deux suites exercent
 * les mêmes situations. **Modifier une RÈGLE d'un seul côté** laisse la modale autoriser
 * ce que le récap refusera (ou l'inverse, pire : interdire un geste légitime) — c'est le
 * motif de dérive que le cadrage P2-9 interdit.
 *
 * Les cas au-delà des six couvrent des spécificités du CLIENT que le backend ne rencontre
 * pas (il reçoit un périmètre déjà filtré). Ils sont donc légitimement asymétriques — mais
 * ils ne doivent jamais porter sur une règle partagée : dans ce cas, le jumeau est
 * obligatoire.
 */

const COACH_MAXIME = "coach-maxime";
const links: TeamCoach[] = [
  { id: "l1", teamId: "teamA", coachId: COACH_MAXIME, role: "MAIN" },
  { id: "l2", teamId: "teamB", coachId: COACH_MAXIME, role: "MAIN" },
];

function reservation(over: Partial<Reservation> & Pick<Reservation, "teamId" | "venueId">): Reservation {
  return { id: "r-" + over.teamId, schedulePlanId: null, dayOfWeek: 2, startTime: "17:00", durationMinutes: 90, ...over };
}

const candidate = { teamId: "teamB", venueId: "venue2", dayOfWeek: 2, startTime: "17:30", durationMinutes: 90 };

describe("conflictingReservation (parité avec CoachDoubleBookingDetector)", () => {
  it("cas 1 — deux gymnases, créneaux qui se chevauchent sans débuter à la même heure", () => {
    // 17h00-18h30 déjà posé, on tente 17h30-19h00 : un test sur l'égalité des heures de
    // début laisserait passer, d'où la règle « intervalles réels ».
    const existing = [reservation({ teamId: "teamA", venueId: "venue1" })];

    expect(conflictingReservation(candidate, existing, mainCoachByTeam(links))?.teamId).toBe("teamA");
  });

  it("cas 2 — même gymnase : mutualisation, jamais un conflit", () => {
    const existing = [reservation({ teamId: "teamA", venueId: "venue2" })];

    expect(conflictingReservation({ ...candidate, startTime: "17:00" }, existing, mainCoachByTeam(links))).toBeNull();
  });

  it("cas 3 — créneaux adjacents : 18h30 n'est pas dans [17h00, 18h30[", () => {
    const existing = [reservation({ teamId: "teamA", venueId: "venue1" })];

    expect(conflictingReservation({ ...candidate, startTime: "18:30" }, existing, mainCoachByTeam(links))).toBeNull();
  });

  it("cas 4 — jours différents", () => {
    const existing = [reservation({ teamId: "teamA", venueId: "venue1", dayOfWeek: 3 })];

    expect(conflictingReservation(candidate, existing, mainCoachByTeam(links))).toBeNull();
  });

  it("cas 5 — un ASSISTANT ne bloque pas (le solveur ne le traite pas en ressource exclusive)", () => {
    const assistantLinks: TeamCoach[] = [links[0]!, { id: "l2", teamId: "teamB", coachId: COACH_MAXIME, role: "ASSISTANT" }];
    const existing = [reservation({ teamId: "teamA", venueId: "venue1" })];

    expect(conflictingReservation(candidate, existing, mainCoachByTeam(assistantLinks))).toBeNull();
  });

  it("cas 6 — deux coachs MAIN distincts : personne ne se dédouble", () => {
    const distinct: TeamCoach[] = [links[0]!, { id: "l2", teamId: "teamB", coachId: "coach-nicolas", role: "MAIN" }];
    const existing = [reservation({ teamId: "teamA", venueId: "venue1" })];

    expect(conflictingReservation(candidate, existing, mainCoachByTeam(distinct))).toBeNull();
  });

  it("une équipe sans coach MAIN n'a personne à dédoubler", () => {
    const existing = [reservation({ teamId: "teamA", venueId: "venue1" })];

    expect(conflictingReservation({ ...candidate, teamId: "teamOrphan" }, existing, mainCoachByTeam(links))).toBeNull();
  });
});

describe("conflictingReservation — tant qu'un coach tient la séance, c'est OK", () => {
  const twoCoaches: TeamCoach[] = [...links, { id: "l3", teamId: "teamB", coachId: "coach-lea", role: "MAIN" }];
  const existing = [reservation({ teamId: "teamA", venueId: "venue1" })];

  it("un second coach principal tient la séance : le geste est légitime", () => {
    expect(conflictingReservation(candidate, existing, mainCoachByTeam(twoCoaches))).toBeNull();
  });

  it("l'ordre des liens ne change rien : aucun coach principal n'écrase l'autre", () => {
    expect(conflictingReservation(candidate, existing, mainCoachByTeam([...twoCoaches].reverse()))).toBeNull();
  });

  it("le coach seul reste refusé, et la réservation fautive est nommée", () => {
    expect(conflictingReservation(candidate, existing, mainCoachByTeam(links))?.teamId).toBe("teamA");
  });

  it("les deux coachs déjà pris par deux séances : plus personne pour tenir, le geste est refusé", () => {
    // La tenue se compte sur TOUTES les réservations : teamA prend Maxime, teamD prend Léa. Une
    // garde qui jugerait chaque paire isolément verrait toujours « un autre coach ».
    const busy: TeamCoach[] = [...twoCoaches, { id: "l4", teamId: "teamD", coachId: "coach-lea", role: "MAIN" }];
    const both = [reservation({ teamId: "teamA", venueId: "venue1" }), reservation({ teamId: "teamD", venueId: "venue3" })];

    expect(conflictingReservation(candidate, both, mainCoachByTeam(busy))).not.toBeNull();
  });
});
