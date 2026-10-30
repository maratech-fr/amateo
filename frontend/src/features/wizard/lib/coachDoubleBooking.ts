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
 *
 * « Tant qu'un coach tient la séance, c'est OK » (fondateur 2026-10-30) : un coach partagé ne refuse
 * le geste que si une séance n'a plus personne pour la tenir ({@link holdersFor}).
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

/** teamId → coachs MAIN, dans l'ordre des liens (les ASSISTANT sont ignorés, règle 3). */
export function mainCoachByTeam(links: TeamCoach[]): Map<string, string[]> {
  const map = new Map<string, string[]>();
  for (const link of links) {
    if ("MAIN" === link.role && !(map.get(link.teamId) ?? []).includes(link.coachId)) {
      map.set(link.teamId, [...(map.get(link.teamId) ?? []), link.coachId]);
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
 * Chantier H — LA règle pure de TOLÉRANCE d'arrivée tardive, miroir MÉCANIQUE de
 * `App\Service\CoachDoubleBookingDetector::lateArrivalTolerated` (PHP). Les MÊMES cas la traversent
 * des deux côtés (`coachDoubleBooking.parity.json::toleranceCases`, gardé par
 * `CoachDoubleBookingMirrorParityTest`). `a` = la séance que le coach QUITTE, `b` = celle qu'il
 * rejoint EN RETARD ; `baremeMinutes` = le trajet entre les deux gymnases. Le retard =
 * `max(0, fin(a) + barème − début(b))` ; une paire géométriquement dédoublante (déjà prouvée par
 * {@link bookingsCollide}) est TOLÉRÉE ssi `1 <= toleranceMinutes <= 30` (plafond métier souverain),
 * le début de `b` est STRICTEMENT après celui de `a`, et le retard ne dépasse pas la tolérance. La
 * DISPONIBILITÉ de l'adjoint n'est PAS dans cette règle pure (vérifiée serveur).
 */
export function lateArrivalTolerated(a: Booking, b: Booking, toleranceMinutes: number, baremeMinutes: number): boolean {
  if (toleranceMinutes < 1 || toleranceMinutes > 30) {
    return false;
  }
  if (b.startMinutes <= a.startMinutes) {
    return false;
  }
  const lateness = Math.max(0, a.startMinutes + a.durationMinutes + baremeMinutes - b.startMinutes);

  return lateness <= toleranceMinutes;
}

/**
 * Chantier H / P4-333 — deux équipes co-réservées partagent-elles AU MOINS un coach PRINCIPAL ?
 * Miroir MÉCANIQUE de `App\Service\CoachDoubleBookingDetector::teamsShareMainCoach` (cas partagés
 * `coachDoubleBooking.parity.json::multiCoachCases`). Le bloqueur ne regardait que le PREMIER
 * principal par équipe (P4-319) ; il doit regarder TOUS les principaux : deux équipes se dédoublent
 * dès qu'elles partagent un principal (exemple roadmap Camille/Dorian).
 */
export function teamsShareMainCoach(coachIdsA: string[], coachIdsB: string[]): boolean {
  const first = new Set(coachIdsA);

  return coachIdsB.some((id) => first.has(id));
}

/** Une séance à tenir — l'entrée de {@link holdersFor}. */
export type HoldSession = Omit<Booking, "coachId"> & { id: string };

type PlayingBooking = Omit<Booking, "coachId">;

function overlaps(a: PlayingBooking, b: PlayingBooking): boolean {
  return a.dayOfWeek === b.dayOfWeek && a.startMinutes < b.startMinutes + b.durationMinutes && b.startMinutes < a.startMinutes + a.durationMinutes;
}

function sameGroup(teamA: string, teamB: string, groups: string[][]): boolean {
  return teamA === teamB || groups.some((group) => group.includes(teamA) && group.includes(teamB));
}

/**
 * Les coachs de l'équipe, puis ceux des autres équipes de son groupe DÉCLARÉ sur la MÊME case.
 * Même case sans groupe = chacun ses coachs (décision fondateur 2026-10-30).
 */
function holdCandidates(session: HoldSession, sessions: HoldSession[], coaches: Record<string, string[]>, groups: string[][]): string[] {
  const candidates = [...(coaches[session.teamId] ?? [])];
  for (const other of sessions) {
    if (other.venueId === session.venueId && other.dayOfWeek === session.dayOfWeek && other.startMinutes === session.startMinutes && sameGroup(session.teamId, other.teamId, groups)) {
      candidates.push(...(coaches[other.teamId] ?? []));
    }
  }

  return [...new Set(candidates)];
}

/**
 * « Tant qu'un coach tient la séance, c'est OK » — LA règle pure de l'affectation des tenues, miroir
 * MÉCANIQUE de `App\Service\CoachDoubleBookingDetector::holdersFor` (PHP) : séance → coach, ou null si
 * une séance n'a personne. Déterministe : séances dans l'ordre donné, candidats dans l'ordre des
 * listes, retour arrière, la PREMIÈRE solution. Cas partagés et règle complète :
 * `coachDoubleBooking.parity.json::holdCases`, gardé par `CoachDoubleBookingMirrorParityTest`.
 */
export function holdersFor(
  sessions: HoldSession[],
  mainCoachesByTeam: Record<string, string[]>,
  blockedByCoach: Record<string, [number, number, number][]> = {},
  playingByCoach: Record<string, PlayingBooking[]> = {},
  groups: string[][] = [],
): Record<string, string> | null {
  const required: HoldSession[] = [];
  const candidates = new Map<string, string[]>();
  for (const session of sessions) {
    const own = holdCandidates(session, sessions, mainCoachesByTeam, groups);
    if (0 === own.length) {
      continue; // aucun coach principal : la séance n'exige personne.
    }
    const available = own.filter(
      (coachId) =>
        !(blockedByCoach[coachId] ?? []).some(
          // Même règle que le moteur : l'indisponibilité se teste au DÉBUT de la séance.
          ([day, from, until]) => day === session.dayOfWeek && from <= session.startMinutes && session.startMinutes < until,
        ),
    );
    const free = 0 === available.length ? own : available; // indisponible mais seul : avertissement, jamais bloqueur.
    required.push(session);
    candidates.set(
      session.id,
      // Même règle que le moteur : jouer occupe, même gymnase compris (sauf dans sa propre équipe).
      free.filter((coachId) => !(playingByCoach[coachId] ?? []).some((game) => game.teamId !== session.teamId && overlaps(session, game))),
    );
  }

  const holders: Record<string, string> = {};
  const assign = (index: number): boolean => {
    const session = required[index];
    if (undefined === session) {
      return true;
    }
    for (const coachId of candidates.get(session.id) ?? []) {
      const taken = required
        .slice(0, index)
        .some((other) => holders[other.id] === coachId && bookingsCollide({ ...session, coachId }, { ...other, coachId }));
      if (taken) {
        continue;
      }
      holders[session.id] = coachId;
      if (assign(index + 1)) {
        return true;
      }
      delete holders[session.id];
    }

    return false;
  };

  return assign(0) ? holders : null;
}

/**
 * La réservation DÉJÀ posée qui empêche `candidate`, ou null si le geste est légitime.
 * Rendre la réservation fautive (et non un booléen) permet à l'appelant de nommer
 * l'équipe, le gymnase et l'heure — « Emerick coache déjà les U15F à 17h à Debarros ».
 * Le geste n'est refusé que si, autour de lui, une séance n'a plus personne pour la tenir.
 */
export function conflictingReservation(candidate: Candidate, reservations: Reservation[], coachByTeam: Map<string, string[]>, context: HoldContext = {}): Reservation | null {
  const coachIds = coachByTeam.get(candidate.teamId);
  if (undefined === coachIds || 0 === coachIds.length) {
    return null; // pas de coach MAIN : personne à dédoubler
  }
  const start = minutes(candidate.startTime);
  if (null === start) {
    return null; // heure illisible : on ne peut rien affirmer, le récap tranchera (PR B)
  }
  const here: HoldSession = { ...candidate, id: "candidate", startMinutes: start };
  const groups = context.groups ?? [];

  const others: { reservation: Reservation; session: HoldSession }[] = [];
  const playingByCoach: Record<string, PlayingBooking[]> = {};
  for (const player of context.playersByTeam?.get(candidate.teamId) ?? []) {
    (playingByCoach[player] ??= []).push(here);
  }
  reservations.forEach((reservation, index) => {
    const otherStart = minutes(reservation.startTime);
    // Lot 4bis — un créneau libre (teamId null) n'a pas de coach : jamais un double-booking.
    if (null === reservation.teamId || null === otherStart) {
      return;
    }
    const session: HoldSession = { ...reservation, id: String(index), teamId: reservation.teamId, startMinutes: otherStart };
    for (const player of context.playersByTeam?.get(reservation.teamId) ?? []) {
      (playingByCoach[player] ??= []).push(session);
    }
    if (coachByTeam.has(reservation.teamId)) {
      others.push({ reservation, session });
    }
  });

  // Seules les séances qui peuvent se disputer une personne avec le geste se jugent avec lui.
  const coachesOf = (session: HoldSession) => coachByTeam.get(session.teamId) ?? [];
  const cluster = [here];
  for (let index = 0; index < cluster.length; index += 1) {
    const current = cluster[index] as HoldSession;
    for (const { session } of others) {
      const linked = teamsShareMainCoach(coachesOf(current), coachesOf(session)) || (current.venueId === session.venueId && current.startMinutes === session.startMinutes && sameGroup(current.teamId, session.teamId, groups));
      if (!cluster.includes(session) && overlaps(current, session) && linked) {
        cluster.push(session);
      }
    }
  }
  if (null !== holdersFor(cluster, Object.fromEntries(coachByTeam), context.blockedByCoach ?? {}, playingByCoach, groups)) {
    return null;
  }

  return (
    others.find(({ session }) => teamsShareMainCoach(coachIds, coachesOf(session)) && bookingsCollide({ ...here, coachId: "" }, { ...session, coachId: "" }))?.reservation ?? null
  );
}

/** Ce que la modale sait en plus des liens, pour juger comme le récap et le radar. */
export interface HoldContext {
  blockedByCoach?: Record<string, [number, number, number][]>;
  playersByTeam?: Map<string, string[]>;
  groups?: string[][];
}

/** coachId → intervalles bloqués (jour, de, à). Miroir de `CoachDoubleBookingDetector::blockedIntervalsByCoach`. */
export function blockedIntervalsByCoach(constraints: { family: string; isActive: boolean; scopeTargetId: string | null; config: Record<string, unknown> }[]): Record<string, [number, number, number][]> {
  const toMinutes = (value: unknown, fallback: number): number => ("string" === typeof value ? (parseTime(value) ?? fallback) : fallback);
  const days = (value: unknown): number[] => (Array.isArray(value) ? value.map(Number).filter((day) => !Number.isNaN(day)) : []);
  const blocked: Record<string, [number, number, number][]> = {};
  for (const constraint of constraints) {
    if ("COACH_AVAILABILITY" !== constraint.family || !constraint.isActive || null === constraint.scopeTargetId) {
      continue;
    }
    const intervals = (blocked[constraint.scopeTargetId] ??= []);
    const from = toMinutes(constraint.config.fromTime, 0);
    const until = toMinutes(constraint.config.untilTime, 1440);
    for (const day of days(constraint.config.unavailableDays)) {
      intervals.push([day, from, until]);
    }
    const available = days(constraint.config.availableDays);
    if (0 < available.length) {
      for (let day = 1; day <= 7; day += 1) {
        if (!available.includes(day)) {
          intervals.push([day, 0, 1440]);
          continue;
        }
        if (from > 0) {
          intervals.push([day, 0, from]);
        }
        if (until < 1440) {
          intervals.push([day, until, 1440]);
        }
      }
    }
  }

  return blocked;
}
