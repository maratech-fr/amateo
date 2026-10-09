import type { Reservation, TeamCoach } from "../api";
import { parseTime } from "@/shared/lib/time";

/**
 * P2-9 PR C — « affecter cette équipe ici mettrait-il son coach à deux endroits ? »
 *
 * ⚠️ MIROIR DÉCLARÉ (régime 2, P4-88) — PARITÉ MÉCANIQUE avec le backend
 * `App\Service\CoachDoubleBookingDetector::bookingsCollide` (P2-9 PR B), qui applique
 * EXACTEMENT les mêmes règles pour BLOQUER la génération au récap. Ici on prévient au clic ;
 * là-bas on refuse. Deux implémentations sont inévitables (la modale doit répondre sans
 * aller-retour réseau) ; elles ne restaient alignées que par des suites JUMELLES
 * (`coachDoubleBooking.test.ts` ⇄ `CoachDoubleBookingTest`), ce qui ne garantit rien. La
 * règle PURE `bookingsCollide` est désormais gardée par la parité MÉCANIQUE
 * `CoachDoubleBookingMirrorParityTest` : les mêmes cas (`coachDoubleBooking.parity.json`)
 * traversent les DEUX implémentations. Ce module figure au registre `FrontRederivationRegistryTest`.
 *
 * Les trois règles (fondateur, 2026-07-31) :
 *  1. chevauchement sur INTERVALLES RÉELS `[début, début + durée[` — 17h00-18h30 et
 *     17h30-19h00 dédoublent bien le coach, l'égalité des débuts ne suffit pas ;
 *  2. gymnases DIFFÉRENTS seulement — même gymnase = mutualisation voulue (`canSplit`) ;
 *  3. coachs `MAIN` seulement — un `ASSISTANT` est optionnel pour le solveur, l'interdire
 *     refuserait une réservation légitime.
 */

/**
 * Minutes depuis minuit — `startTime` peut porter les secondes selon la source.
 * Rend `null` si l'heure est illisible : un `?? 0` ne protégerait de rien (`Number("x")`
 * vaut NaN, pas `undefined`) et toute comparaison avec NaN étant fausse, la garde
 * s'éteindrait en silence. L'appelant traite explicitement ce cas.
 */
function minutes(time: string): number | null {
  // D-21 : lecture partagée — ce site rendait DÉJÀ null, la réaction la plus stricte des
  // cinq. Elle est conservée telle quelle (l'appelant traite explicitement ce cas).
  return parseTime(time);
}

/** teamId → coachId du coach MAIN (les ASSISTANT sont ignorés, règle 3). */
export function mainCoachByTeam(links: TeamCoach[]): Map<string, string> {
  const map = new Map<string, string>();
  for (const link of links) {
    if ("MAIN" === link.role) {
      map.set(link.teamId, link.coachId);
    }
  }

  return map;
}

interface Candidate {
  teamId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
  durationMinutes: number;
}

/** Une séance pré-résolue (coach MAIN inclus, heure en minutes) — l'entrée de la règle pure. */
export interface Booking {
  teamId: string;
  coachId: string;
  venueId: string;
  dayOfWeek: number;
  startMinutes: number;
  durationMinutes: number;
}

/**
 * LA règle pure de dédoublement de coach — miroir MÉCANIQUE de
 * `CoachDoubleBookingDetector::bookingsCollide` (PHP). Les MÊMES cas la traversent des
 * deux côtés (`coachDoubleBooking.parity.json`, gardé par `CoachDoubleBookingMirrorParityTest`) :
 * changer une règle d'un seul côté rougit la parité. Les trois règles (fondateur 2026-07-31) :
 *  3. même coach MAIN des deux côtés (sinon rien à dédoubler) et équipes DIFFÉRENTES ;
 *  2. gymnases DIFFÉRENTS (même gymnase = mutualisation voulue) ;
 *  1. intervalles réels demi-ouverts `[début, début+durée[`.
 */
export function bookingsCollide(a: Booking, b: Booking): boolean {
  return (
    a.coachId === b.coachId &&
    a.teamId !== b.teamId &&
    a.venueId !== b.venueId &&
    a.dayOfWeek === b.dayOfWeek &&
    a.startMinutes < b.startMinutes + b.durationMinutes &&
    b.startMinutes < a.startMinutes + a.durationMinutes
  );
}

/**
 * La réservation DÉJÀ posée qui empêche `candidate`, ou null si le geste est légitime.
 * Rendre la réservation fautive (et non un booléen) permet à l'appelant de nommer
 * l'équipe, le gymnase et l'heure — « Emerick coache déjà les U15F à 17h à Debarros ».
 */
export function conflictingReservation(candidate: Candidate, reservations: Reservation[], coachByTeam: Map<string, string>): Reservation | null {
  const coachId = coachByTeam.get(candidate.teamId);
  if (undefined === coachId) {
    return null; // pas de coach MAIN : personne à dédoubler
  }
  const start = minutes(candidate.startTime);
  if (null === start) {
    return null; // heure illisible : on ne peut rien affirmer, le récap tranchera (PR B)
  }
  const here: Booking = { ...candidate, coachId, startMinutes: start };

  return (
    reservations.find((other) => {
      // Lot 4bis — un créneau libre (teamId null) n'a pas de coach : jamais un double-booking.
      if (null === other.teamId) {
        return false;
      }
      const otherCoach = coachByTeam.get(other.teamId);
      const otherStart = minutes(other.startTime);
      if (undefined === otherCoach || null === otherStart) {
        return false;
      }

      return bookingsCollide(here, { ...other, teamId: other.teamId, coachId: otherCoach, startMinutes: otherStart });
    }) ?? null
  );
}
