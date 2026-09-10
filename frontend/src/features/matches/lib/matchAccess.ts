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

/** Le geste de placement est-il refusé (error) ou seulement signalé (warning) ? */
export interface VenueAccessIssue {
  level: "error" | "warning";
  message: string;
}

/**
 * The capacity guard of the placement gesture (cadrage P1-4 §5) — the CLUB's own
 * declarations, no mapping ambiguity (contrary to the league envelope). Returns
 * the issue to show, or null when the placement is fully clean.
 *
 * Two severities since P4-193, driven by `isFriendly`:
 * - a venue UNAVAILABLE on the match date is ALWAYS an `error` (a friendly can no
 *   more sit in a closed gym than a competition match);
 * - « no window that day » and « kickoff outside every window » are an `error`
 *   for a COMPETITION match, but only a `warning` for a FRIENDLY — a friendly is
 *   free to be placed off any match slot (the solver no longer places it, the
 *   radar alerts). The warning carries a single neutral message.
 *
 * Rules, in order:
 * 1. venue unavailable on the match date (all-circumstances closure) → error;
 * 2. the club declares match windows but this venue has none on that day;
 * 3. a kickoff outside every window of (venue, day).
 * A club with NO window anywhere has not adopted the data → nothing to enforce.
 */
export function venueAccessError(
  venueId: string,
  venueName: string,
  matchDate: string,
  kickoff: string | null,
  windows: VenueMatchWindow[],
  unavailabilities: VenueUnavailability[],
  isFriendly: boolean,
): VenueAccessIssue | null {
  for (const unavailability of unavailabilities) {
    if (unavailability.venueId === venueId && matchDate >= unavailability.startDate && matchDate <= unavailability.endDate) {
      const label = null !== unavailability.label ? ` (${unavailability.label})` : "";
      return {
        level: "error",
        message: `${venueName} est indisponible du ${frDateShortNoYear(unavailability.startDate)} au ${frDateShortNoYear(unavailability.endDate)}${label}.`,
      };
    }
  }

  if (0 === windows.length) {
    return null; // the club has not adopted match windows — nothing to enforce
  }

  // Un amical hors créneau match n'est pas bloqué : placement libre, on signale.
  const friendlyOffSlot: VenueAccessIssue = { level: "warning", message: "Amical hors créneau match — placement libre." };

  const day = isoDayOf(matchDate);
  const dayWindows = windows.filter((w) => w.venueId === venueId && w.dayOfWeek === day);
  if (0 === dayWindows.length) {
    return isFriendly ? friendlyOffSlot : { level: "error", message: `Pas d'accès match le ${DAY_LABELS[day] ?? "?"} à ${venueName}.` };
  }
  if (null !== kickoff && "" !== kickoff && !kickoffInsideWindow(venueId, day, kickoff, windows)) {
    const ranges = dayWindows.map((w) => `${w.startTime}–${w.endTime}`).join(", ");
    return isFriendly ? friendlyOffSlot : { level: "error", message: `Hors fenêtre d'accès match (${ranges}).` };
  }

  return null;
}

