import { groupTeamsByTier, type TierLike } from "@/shared/lib/teamTiers";

import type { Reservation, Team, TeamSoloBudget, VenueTrainingSlot } from "../api";
import { hhmm } from "./days";

/** A reservation and a slot refer to the same physical time-slot when venue +
 *  day + start (normalised to HH:MM) match. Reservations store start as HH:MM;
 *  slots may carry seconds or an ISO datetime, so normalise both sides. */
export const slotKey = (venueId: string, dayOfWeek: number, startTime: string): string => `${venueId}|${dayOfWeek}|${hhmm(startTime)}`;

/** Lot 4bis — un créneau LIBRE est une réservation SANS équipe (`teamId` null, `label` posé). */
export const isFreeSlot = (r: Reservation): boolean => null === r.teamId;

/**
 * Teams reserved on each slot (by slotKey → list of teamIds, insertion order).
 * Drives the grid badges and tells the modal who is already on a slot.
 *
 * Lot 4bis — les créneaux LIBRES (sans équipe) ne sont PAS des équipes : exclus ici (le front
 * n'invente pas la règle — ils comptent dans la CAPACITÉ via {@link freeSlotsBySlot}, jamais dans
 * la logique d'équipe : budget solo, bloc, double-booking coach).
 */
export function reservedTeamsBySlot(reservations: Reservation[]): Map<string, string[]> {
  const map = new Map<string, string[]>();
  for (const r of reservations) {
    if (null === r.teamId) {
      continue;
    }
    const key = slotKey(r.venueId, r.dayOfWeek, r.startTime);
    map.set(key, [...(map.get(key) ?? []), r.teamId]);
  }
  return map;
}

/** Lot 4bis — les CRÉNEAUX LIBRES par slotKey (ordre d'insertion). Sert la grille (case nommée),
 *  la modale (lister/retirer) et la capacité (un créneau libre occupe une place). */
export function freeSlotsBySlot(reservations: Reservation[]): Map<string, Reservation[]> {
  const map = new Map<string, Reservation[]>();
  for (const r of reservations) {
    if (null !== r.teamId) {
      continue;
    }
    const key = slotKey(r.venueId, r.dayOfWeek, r.startTime);
    map.set(key, [...(map.get(key) ?? []), r]);
  }
  return map;
}

/**
 * Lot 4bis — les OCCUPANTS affichés de chaque case (slotKey → libellés, ordre d'insertion) : noms
 * d'équipes PUIS libellés de créneaux libres. Sert la grille « Réserver » (compte + libellés) :
 * un créneau libre occupe une place, nommée, comme une équipe.
 */
export function occupantsBySlot(reservations: Reservation[], teamNameOf: (teamId: string) => string): Map<string, string[]> {
  const map = new Map<string, string[]>();
  for (const r of reservations) {
    const key = slotKey(r.venueId, r.dayOfWeek, r.startTime);
    const label = null === r.teamId ? (r.label ?? "Créneau réservé") : teamNameOf(r.teamId);
    map.set(key, [...(map.get(key) ?? []), label]);
  }
  return map;
}

/** How many reservations each team currently holds (its progress toward its ceiling). */
export function teamReservationCount(reservations: Reservation[]): Map<string, number> {
  const counts = new Map<string, number>();
  for (const r of reservations) {
    if (null === r.teamId) {
      continue; // lot 4bis — un créneau libre n'est pas une séance d'équipe.
    }
    counts.set(r.teamId, (counts.get(r.teamId) ?? 0) + 1);
  }
  return counts;
}

/**
 * How many DISTINCT teams a slot accepts: `capacity` on a divisible gym, else 1.
 * When the venue is not (yet) loaded we trust `slot.capacity` — the backend forces
 * it to 1 for non-divisible gyms — so a lagging venues query can't hide a seat.
 */
export function effectiveSlotCapacity(slot: VenueTrainingSlot, venueCanSplit: Map<string, boolean>): number {
  return false === venueCanSplit.get(slot.venueId) ? 1 : slot.capacity;
}

/** Ce que la modale de confirmation « décocher terrain divisible » doit montrer et vider. */
export interface SplitCascadePreview {
  /** Les créneaux du gymnase à capacité ≥ 2 — ceux qui repasseront à 1 équipe. */
  slots: VenueTrainingSlot[];
  /** Combien de réservations posées sur ces créneaux seront vidées. */
  reservationCount: number;
}

/**
 * v2 cohérence canSplit — décocher « terrain divisible » sur un gymnase dont des créneaux
 * accueillent 2 équipes ou plus est une cascade destructive : ces créneaux repassent à 1 et
 * leurs réservations sont vidées (backend `VenueStateProcessor`). La modale AVERTIT avec ces
 * deux nombres AVANT de confirmer. Fonction pure : le test la falsifie sans monter l'écran.
 * Vide (`slots: []`) ⇒ décocher est cohérent, pas de modale.
 */
export function splitCascadePreview(slots: VenueTrainingSlot[], reservations: Reservation[], venueId: string): SplitCascadePreview {
  const affected = slots.filter((s) => s.venueId === venueId && s.capacity >= 2);
  const keys = new Set(affected.map((s) => slotKey(s.venueId, s.dayOfWeek, s.startTime)));
  const reservationCount = reservations.filter((r) => keys.has(slotKey(r.venueId, r.dayOfWeek, r.startTime))).length;
  return { slots: affected, reservationCount };
}

/** Un créneau partagé et l'état de ses réservations, pour les avertissements du récap. */
export interface SharedSlotStatus {
  slot: VenueTrainingSlot;
  /** Équipes réservées sur le créneau (ordre d'insertion). */
  reservedTeamIds: string[];
  /** Capacité EFFECTIVE (canSplit appliqué) — toujours ≥ 2 ici. */
  capacity: number;
  /**
   * `unreserved` — personne n'a choisi : le système associera les équipes lui-même
   * (information, pas un défaut). `partial` — réservé en dessous de la capacité :
   * ALIGN-07 fait qu'une réservation ferme le créneau ENTIER au système, la ou les
   * places restantes resteront VIDES (avertissement, la conséquence est une perte).
   */
  kind: "unreserved" | "partial";
}

/**
 * Les créneaux partagés (capacité effective ≥ 2) qui méritent un mot au récap : non
 * réservés ou PARTIELLEMENT réservés. Un créneau plein n'apparaît pas. Règle extraite en
 * fonction pure (§7.2) : le test la falsifie sans monter l'écran.
 */
export function sharedSlotStatuses(
  slots: VenueTrainingSlot[],
  reservations: Reservation[],
  venueCanSplit: Map<string, boolean>,
): SharedSlotStatus[] {
  const bySlot = reservedTeamsBySlot(reservations);
  const statuses: SharedSlotStatus[] = [];
  for (const slot of slots) {
    const capacity = effectiveSlotCapacity(slot, venueCanSplit);
    // Contrôle POSITIF `>= 2` (plutôt que `< 2`) : fail-closed sur une capacité absente — un
    // créneau sans capacité connue n'est pas « partagé », et on ne lit alors pas plus loin.
    if (!(capacity >= 2)) {
      continue;
    }
    const reservedTeamIds = bySlot.get(slotKey(slot.venueId, slot.dayOfWeek, slot.startTime)) ?? [];
    if (reservedTeamIds.length >= capacity) {
      continue;
    }
    statuses.push({ slot, reservedTeamIds, capacity, kind: 0 === reservedTeamIds.length ? "unreserved" : "partial" });
  }
  return statuses;
}

/** Une équipe offrable individuellement, avec N — son résidu solo encore réservable (P2-60, D2). */
export interface AssignableTeam {
  team: Team;
  /** N = R(T) − réservations individuelles posées − ajouts du brouillon. Toujours > 0 ici. */
  remaining: number;
}

/**
 * Teams the manager may still assign to `slot` INDIVIDUALLY, in canonical rank order (fanion
 * S → A → B → C → D), each carrying N — its remaining solo budget.
 *
 * 🔴 Le front n'INVENTE pas la règle : le budget R(T) (résidu solo) vient du backend
 * (`budgetByTeam`, `GET /api/team_solo_budgets`). La seule arithmétique locale est le retrait des
 * ajouts NON sauvés du brouillon (`draftAdded`, état client pur). L'ancien plafond
 * `< sessionsPerWeek` — une redérivation silencieuse — a DISPARU.
 *
 * Exclut : les équipes déjà sur la case, et toute équipe dont N = R(T) − posées − brouillon ≤ 0 —
 * membre de bloc à résidu épuisé compris (D1 : elle reste proposée via son bloc, jamais ici). Une
 * équipe sans ligne de budget n'est pas offerte (fail-closed sur une dérive). Renvoie [] quand la
 * case est pleine.
 */
export function assignableTeams(
  teams: Team[],
  tiers: TierLike[],
  slot: VenueTrainingSlot,
  reservations: Reservation[],
  venueCanSplit: Map<string, boolean>,
  budgetByTeam: Map<string, TeamSoloBudget>,
  draftAdded: string[],
): AssignableTeam[] {
  const key = slotKey(slot.venueId, slot.dayOfWeek, slot.startTime);
  const onSlot = new Set(reservedTeamsBySlot(reservations).get(key) ?? []);
  // Lot 4bis — un créneau LIBRE occupe une place : il compte dans la capacité, pas comme une équipe.
  const freeOnSlot = (freeSlotsBySlot(reservations).get(key) ?? []).length;
  if (onSlot.size + freeOnSlot >= effectiveSlotCapacity(slot, venueCanSplit)) {
    return [];
  }
  const draftAddedCount = new Map<string, number>();
  for (const teamId of draftAdded) {
    draftAddedCount.set(teamId, (draftAddedCount.get(teamId) ?? 0) + 1);
  }
  const rankOrdered = groupTeamsByTier(teams, tiers).flatMap((group) => group.teams);
  const assignable: AssignableTeam[] = [];
  for (const team of rankOrdered) {
    if (onSlot.has(team.id)) {
      continue;
    }
    const budget = budgetByTeam.get(team.id);
    if (undefined === budget) {
      continue;
    }
    const remaining = budget.residual - budget.individualUsed - (draftAddedCount.get(team.id) ?? 0);
    if (remaining <= 0) {
      continue;
    }
    assignable.push({ team, remaining });
  }
  return assignable;
}
