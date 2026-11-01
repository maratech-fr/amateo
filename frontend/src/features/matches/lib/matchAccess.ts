import type { VenueClosure, VenueMatchWindow, VenueUnavailability } from "../api";

// D-30 : cette implémentation (midi UTC) était la plus défensive des trois — elle est
// devenue le foyer partagé.
import { frDateShortNoYear, frDateWeekdayNoYear } from "@/shared/lib/date";
import { isoDayOf } from "@/shared/lib/days";
import { formatMinutes, parseTime } from "@/shared/lib/time";

export { isoDayOf };

/**
 * Venues that hold ≥ 1 NON-dated match access window — the derived « match venue » flag. Une
 * ouverture datée ne fait pas un gymnase de match : elle vaut un jour, elle ne vaut pas convention.
 */
export function matchVenueIds(windows: VenueMatchWindow[]): Set<string> {
  return new Set(windows.filter((w) => null == w.openingDate).map((w) => w.venueId));
}

/**
 * « Le club a adopté les accès match » : au moins une ligne NON datée. MIROIR DÉCLARÉ de
 * `App\Service\MatchConflictDetector::hasWeeklyAccess` (cas `adoptionCases` de `matchAccess.parity.json`).
 */
export function hasWeeklyAccess(windows: VenueMatchWindow[]): boolean {
  return windows.some((w) => null == w.openingDate);
}

/**
 * Les plages APPLICABLES à (gymnase, date) : ses ouvertures datées de ce jour ∪ (férié ? ses lignes
 * « Jours fériés » : ses lignes du jour de semaine). MIROIR DÉCLARÉ de
 * `App\Service\MatchConflictDetector::applicableWindows`, même union côté moteur (`candidates.py`) ;
 * cas `accessCases` de `matchAccess.parity.json`.
 */
export function applicableWindows(venueId: string, matchDate: string, isHoliday: boolean, windows: VenueMatchWindow[]): VenueMatchWindow[] {
  const day = isoDayOf(matchDate);
  return windows.filter((w) => {
    if (w.venueId !== venueId) {
      return false;
    }
    if (null != w.openingDate) {
      return w.openingDate === matchDate;
    }
    return isHoliday ? true === w.appliesOnPublicHolidays : !w.appliesOnPublicHolidays && w.dayOfWeek === day;
  });
}

/** Le coup d'envoi tombe-t-il dans une plage applicable ? `[start, end[`. Miroir de `MatchConflictDetector::kickoffInsideAccess`. */
export function kickoffInsideAccess(venueId: string, matchDate: string, isHoliday: boolean, kickoff: string, windows: VenueMatchWindow[]): boolean {
  return applicableWindows(venueId, matchDate, isHoliday, windows).some((w) => kickoff >= w.startTime && kickoff < w.endTime);
}

const OPENING_DEFAULT_START = 14 * 60;
const OPENING_MIN_SPAN = 4 * 60;
const OPENING_LATEST_END = 23 * 60;
const QUARTER = 15;

/**
 * La plage que propose le raccourci d'ouverture : assez large pour plusieurs matchs le même jour.
 * Début = coup d'envoi arrondi à l'heure inférieure (14:00 sans heure) ; fin = le plus tard de
 * (coup d'envoi + durée du match, arrondi au quart d'heure) et (début + 4 h), plafonné à 23:00.
 * Le moteur juge le match entier, la pose le coup d'envoi : c'est cette largeur qui évite l'écart.
 */
export function proposedOpeningRange(kickoff: string | null, matchMinutes: number): { start: string; end: string } {
  const kickoffMin = parseTime(kickoff);
  const start = null === kickoffMin ? OPENING_DEFAULT_START : Math.floor(kickoffMin / 60) * 60;
  const matchEnd = Math.round(((kickoffMin ?? start) + matchMinutes) / QUARTER) * QUARTER;
  return { start: formatMinutes(start), end: formatMinutes(Math.min(Math.max(matchEnd, start + OPENING_MIN_SPAN), OPENING_LATEST_END)) };
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
  // PR2 — une ligne « Jours fériés » (appliesOnPublicHolidays) ne sert JAMAIS un appel
  // jour-de-semaine, même si son dayOfWeek coïncidait : elle n'est lue qu'un jour férié par
  // `kickoffInsideHolidayWindow`.
  return windows.some((w) => !w.appliesOnPublicHolidays && w.venueId === venueId && w.dayOfWeek === day && kickoff >= w.startTime && kickoff < w.endTime);
}

/**
 * PR2 « jours fériés » — LE prédicat pur d'accès match UN JOUR FÉRIÉ : le coup d'envoi tombe-t-il
 * dans une ligne « Jours fériés » (`appliesOnPublicHolidays`) du gymnase ? Même algèbre
 * DEMI-OUVERTE `[start, end[` que {@link kickoffInsideWindow}, mais filtrée sur le DRAPEAU férié
 * (pas sur un jour de semaine). MIROIR MÉCANIQUE de
 * `App\Service\MatchConflictDetector::kickoffInsideHolidayWindow` (même fichier déclaré au registre
 * `FrontRederivationRegistryTest`).
 */
export function kickoffInsideHolidayWindow(venueId: string, kickoff: string, windows: VenueMatchWindow[]): boolean {
  return windows.some((w) => w.appliesOnPublicHolidays && w.venueId === venueId && kickoff >= w.startTime && kickoff < w.endTime);
}

/**
 * P4-300 — le prédicat PUR « la date tombe dans l'intervalle de fermeture [startDate, endDate] »,
 * bornes INCLUSES (dates `Y-m-d` zero-paddées → comparaison lexicographique = comparaison de dates).
 * MIROIR DÉCLARÉ avec `App\Service\MatchConflictDetector::dateInsideClosure` (le backend refuse 422 ;
 * le front REFUSE la pose sur le rail synchrone) — cas `closureCases` de `matchAccess.parity.json`,
 * gardés par `MatchAccessMirrorParityTest`. Ce module figure au registre `FrontRederivationRegistryTest`.
 */
export function dateInsideClosure(matchDate: string, startDate: string, endDate: string): boolean {
  return matchDate >= startDate && matchDate <= endDate;
}

/** Le geste de placement est-il refusé (error) ou seulement signalé (warning) ? */
export interface VenueAccessIssue {
  level: "error" | "warning";
  /** La RAISON : seuls `no_window` et `outside_window` ouvrent le raccourci d'ouverture. */
  reason: "unavailable" | "closed" | "no_window" | "outside_window" | "friendly_off_slot";
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
 * 1bis. venue closed by the CALENDAR on the match date (P4-300) → error, friendly included;
 * 2. the club declares match windows but this venue has none APPLICABLE on that date;
 * 3. a kickoff outside every applicable window.
 * A club with NO non-dated window has not adopted the data → nothing to enforce.
 *
 * Les plages applicables viennent de {@link applicableWindows} : une ouverture datée s'ajoute, un
 * jour férié (`holidays`, feed `GET public-holidays`) lit la ligne « Jours fériés », JAMAIS la fenêtre
 * du jour de semaine : la ligue a posé ce match ce jour férié exprès.
 */
export function venueAccessError(
  venueId: string,
  venueName: string,
  matchDate: string,
  kickoff: string | null,
  windows: VenueMatchWindow[],
  unavailabilities: VenueUnavailability[],
  isFriendly: boolean,
  closures: VenueClosure[] = [],
  holidays: string[] = [],
): VenueAccessIssue | null {
  for (const unavailability of unavailabilities) {
    if (unavailability.venueId === venueId && matchDate >= unavailability.startDate && matchDate <= unavailability.endDate) {
      const label = null !== unavailability.label ? ` (${unavailability.label})` : "";
      return {
        level: "error",
        reason: "unavailable",
        message: `${venueName} est indisponible du ${frDateShortNoYear(unavailability.startDate)} au ${frDateShortNoYear(unavailability.endDate)}${label}.`,
      };
    }
  }

  // P4-300 — une FERMETURE du calendrier à la date du match est un refus dur, amical compris
  // (miroir du refus serveur D4). `dateInsideClosure` est le prédicat-miroir déclaré.
  for (const closure of closures) {
    if (closure.venueId === venueId && dateInsideClosure(matchDate, closure.startDate, closure.endDate)) {
      return {
        level: "error",
        reason: "closed",
        message: `${venueName} est fermée du ${frDateShortNoYear(closure.startDate)} au ${frDateShortNoYear(closure.endDate)} — ${closure.title}.`,
      };
    }
  }

  if (!hasWeeklyAccess(windows)) {
    return null; // the club has not adopted match windows — nothing to enforce
  }

  // Un amical hors créneau match n'est pas bloqué : placement libre, on signale.
  const friendlyOffSlot: VenueAccessIssue = { level: "warning", reason: "friendly_off_slot", message: "Amical hors créneau match — placement libre." };

  const isHoliday = holidays.includes(matchDate);
  const relevantWindows = applicableWindows(venueId, matchDate, isHoliday, windows);
  if (0 === relevantWindows.length) {
    const when = `${frDateWeekdayNoYear(matchDate)}${isHoliday ? " (jour férié)" : ""}`;
    return isFriendly ? friendlyOffSlot : { level: "error", reason: "no_window", message: `Pas d'accès match le ${when} à ${venueName}.` };
  }
  if (null !== kickoff && "" !== kickoff && !kickoffInsideAccess(venueId, matchDate, isHoliday, kickoff, windows)) {
    const ranges = relevantWindows
      .slice()
      .sort((a, b) => a.startTime.localeCompare(b.startTime))
      .map((w) => `${w.startTime} – ${w.endTime}`)
      .join(", ");
    return isFriendly ? friendlyOffSlot : { level: "error", reason: "outside_window", message: `Hors des accès match de ce jour (${ranges}).` };
  }

  return null;
}

