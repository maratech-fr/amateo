import type { Reservation, VenueTrainingSlot } from "../api";
import { slotKey } from "./reservationSlots";

/**
 * P4-44 — les réservations qui ne retombent sur AUCUN créneau de la grille.
 *
 * Une réservation désigne son créneau par le TRIPLET (gymnase, jour, heure), jamais par
 * son id : déplacer ou supprimer un créneau pouvait la laisser sur un horaire mort.
 *
 * Pourquoi cette règle vit ICI et pas seulement au serveur : l'écran « Réserver » ne
 * peut pas montrer ces lignes — sa grille boucle sur les CRÉNEAUX, donc une réservation
 * hors grille n'a aucune case où s'afficher. Le récap est le seul endroit qui les liste
 * (il boucle sur les réservations), c'est donc là que le geste correctif doit vivre.
 *
 * Fonction PURE : la règle se teste pour elle-même, pas à travers un écran.
 *
 * ⚠️ MIROIR DÉCLARÉ (régime 2, P4-88) — DEUX prédicats, tous deux en parité MÉCANIQUE avec
 * `App\Service\OrphanPinGuard` (cas partagés `orphanReservations.parity.json`, gardés par
 * `OrphanReservationsMirrorParityTest`). Ce module figure au registre `FrontRederivationRegistryTest`.
 *
 *  - {@link orphanReservationIds} — prédicat ÉTROIT « triplet ∉ grille » (miroir de
 *    `OrphanPinGuard::orphanTripletIds`). Sert l'écran « Réserver » (une réservation dont le
 *    créneau a bougé n'a plus de case).
 *  - {@link unservedReservationIds} — prédicat LARGE « réservation NON SERVIE » (P2-37 D5,
 *    miroir de `OrphanPinGuard::unservedReservationIds`) : triplet ∉ grille OU gymnase hors
 *    service (désactivé / entièrement fermé) OU jour fermé. Sert le récap, qui ALERTE le
 *    gestionnaire (décision fondateur : on n'efface rien passivement, on alerte). Le backend
 *    reste la vérité : il REFUSE la réservation à la source (D3) et l'escamote du payload.
 */
export function orphanReservationIds(reservations: Reservation[], slots: VenueTrainingSlot[]): Set<string> {
  const grid = new Set(slots.map((s) => slotKey(s.venueId, s.dayOfWeek, s.startTime)));

  return new Set(
    reservations
      .filter((r) => !grid.has(slotKey(r.venueId, r.dayOfWeek, r.startTime)))
      .map((r) => r.id),
  );
}

/**
 * P2-37 D5 — les réservations qu'AUCUNE génération de la période ne servira. Union de trois
 * causes : gymnase hors service (`disabledVenueIds` = désactivés ET entièrement fermés sur la
 * fenêtre) · couple (gymnase, jour) fermé (`closedWeekdaysByVenue`, jours ISO 1..7) · triplet
 * ∉ grille. Miroir MÉCANIQUE de `OrphanPinGuard::unservedReservationIds` (parité).
 */
export function unservedReservationIds(
  reservations: Reservation[],
  slots: VenueTrainingSlot[],
  disabledVenueIds: string[],
  closedWeekdaysByVenue: Record<string, number[]>,
): Set<string> {
  const grid = new Set(slots.map((s) => slotKey(s.venueId, s.dayOfWeek, s.startTime)));
  const disabled = new Set(disabledVenueIds);

  return new Set(
    reservations
      .filter(
        (r) =>
          disabled.has(r.venueId) ||
          (closedWeekdaysByVenue[r.venueId] ?? []).includes(r.dayOfWeek) ||
          !grid.has(slotKey(r.venueId, r.dayOfWeek, r.startTime)),
      )
      .map((r) => r.id),
  );
}
