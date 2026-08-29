import type { VenueMatchWindow, VenueUnavailability } from "../api";

const DAY_LABELS = ["", "lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi", "dimanche"];

// D-30 : cette implémentation (midi UTC) était la plus défensive des trois — elle est
// devenue le foyer partagé.
import { frDateShortNoYear } from "@/shared/lib/date";
import { isoDayOf } from "@/shared/lib/days";

export { isoDayOf };

/** Venues that hold ≥ 1 match access window — the derived « match venue » flag. */
export function matchVenueIds(windows: VenueMatchWindow[]): Set<string> {
  return new Set(windows.map((w) => w.venueId));
}

/**
 * LE prédicat pur d'accès match : le coup d'envoi (HH:MM) tombe-t-il dans une fenêtre
 * d'accès de (gymnase, jour) ? Intervalle DEMI-OUVERT `[start, end[`.
 *
 * ⚠️ MIROIR DÉCLARÉ (régime 2, P4-88) — parité MÉCANIQUE avec la MÊME algèbre côté backend,
 * `App\Service\MatchConflictDetector::kickoffInsideWindow` (branche ACCESS_WINDOW_LOST). Le
 * front BLOQUE la pose (rail synchrone) ; le backend DIAGNOSTIQUE après coup. Ils divergent
 * sur l'ENVELOPPE (le front ajoute « aucune fenêtre ce jour → refus » et l'indisponibilité,
 * spécifiques à la pose ; le backend ne diagnostique que les HOME déjà posés) — mais ils
 * partagent CE prédicat d'appartenance, la seule algèbre qui peut dériver en silence. Cas
 * partagés `matchAccess.parity.json`, gardés par `MatchAccessMirrorParityTest`. Ce module
 * figure au registre `FrontRederivationRegistryTest`.
 */
export function kickoffInsideWindow(venueId: string, day: number, kickoff: string, windows: VenueMatchWindow[]): boolean {
  return windows.some((w) => w.venueId === venueId && w.dayOfWeek === day && kickoff >= w.startTime && kickoff < w.endTime);
}

/**
 * The capacity guard of the placement gesture (cadrage P1-4 §5) — HARD, no
 * degradation: these are the CLUB's own declarations, there is no mapping
 * ambiguity (contrary to the league envelope). Returns the human reason, or
 * null when the placement is allowed.
 *
 * Rules, in blocking order:
 * 1. venue unavailable on the match date (all-circumstances closure);
 * 2. the club declares match windows but this venue has none on that day;
 * 3. a kickoff outside every window of (venue, day).
 * A club with NO window anywhere has not adopted the data → nothing blocks.
 */
export function venueAccessError(
  venueId: string,
  venueName: string,
  matchDate: string,
  kickoff: string | null,
  windows: VenueMatchWindow[],
  unavailabilities: VenueUnavailability[],
): string | null {
  for (const unavailability of unavailabilities) {
    if (unavailability.venueId === venueId && matchDate >= unavailability.startDate && matchDate <= unavailability.endDate) {
      const label = null !== unavailability.label ? ` (${unavailability.label})` : "";
      return `${venueName} est indisponible du ${frDateShortNoYear(unavailability.startDate)} au ${frDateShortNoYear(unavailability.endDate)}${label}.`;
    }
  }

  if (0 === windows.length) {
    return null; // the club has not adopted match windows — nothing to enforce
  }

  const day = isoDayOf(matchDate);
  const dayWindows = windows.filter((w) => w.venueId === venueId && w.dayOfWeek === day);
  if (0 === dayWindows.length) {
    return `Pas d'accès match le ${DAY_LABELS[day] ?? "?"} à ${venueName}.`;
  }
  if (null !== kickoff && "" !== kickoff && !kickoffInsideWindow(venueId, day, kickoff, windows)) {
    const ranges = dayWindows.map((w) => `${w.startTime}–${w.endTime}`).join(", ");
    return `Hors fenêtre d'accès match (${ranges}).`;
  }

  return null;
}

