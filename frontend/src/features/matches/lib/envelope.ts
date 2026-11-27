import type { Fixture, LeagueWindow } from "../api";

/** Time-ish string ("18:00", "18:00:00", ISO) → minutes since midnight. */
export function timeToMinutes(time: string | null | undefined): number {
  // D-21 : lecture partagée, repli 0 explicite (une enveloppe se calcule, elle ne valide pas).
  return parseTime(time) ?? 0;
}

// D-30 : cette version calculait le jour à MINUIT LOCAL — elle basculait d'un jour hors
// fuseau français, là où `matchAccess` (midi UTC) restait juste. Foyer unique partagé.
import { isoDayOf as isoWeekday } from "@/shared/lib/days";
import { parseTime } from "@/shared/lib/time";

export { isoWeekday };

/**
 * ⚠️ MIROIR DÉCLARÉ (régime 2, FrontRederivationRegistryTest) — parité MÉCANIQUE avec la
 * MÊME algèbre côté backend, `App\Service\MatchConflictDetector::kickoffInsideLeagueWindow`
 * (branche LEAGUE_WINDOW_VIOLATION). Depuis P4-272 ① le front ne BLOQUE PLUS la pose manuelle
 * hors fenêtre : il l'AVERTIT (via `isInEnvelope`, `EnvelopeHint`), le backend DIAGNOSTIQUE
 * après coup (radar), et le solveur garde la ligue en HARD. Ils DIVERGENT par conception sur
 * (a) l'exemption AMICAL — le front assouplit un amical (`PlacementPanel` : `!isFriendly`), le
 * backend saute les matchs d'une compétition amicale (`leagueWindowViolations`) — et (b) la résolution
 * équipe↔fenêtre, déjà serveur (`resolvedTeamWindows`, servie depuis la COPIE club). Ils
 * partagent CE prédicat d'appartenance, la seule algèbre qui peut dériver en silence :
 * intervalle FERMÉ `[kickoffMin, kickoffMax]` (les deux bornes incluses), filtré sur le JOUR.
 * Cas partagés `leagueEnvelope.parity.json`, gardés par `LeagueEnvelopeMirrorParityTest`.
 */
export function kickoffInsideLeagueWindow(
  day: number,
  kickoff: string,
  windows: { dayOfWeek: number; kickoffMin: string; kickoffMax: string }[],
): boolean {
  const min = timeToMinutes(kickoff);
  return windows.some((w) => w.dayOfWeek === day && min >= timeToMinutes(w.kickoffMin) && min <= timeToMinutes(w.kickoffMax));
}

/**
 * ⚠️ MIROIR DÉCLARÉ (régime 2) — un amical est un match d'une compétition AMICALE : la nature
 * servie sur le match (`fixture.competitionType`), jamais un `competitionId` nul ni un libellé.
 * Même question que `App\Enum\CompetitionType::isFriendly` (backend). Cas partagés
 * `leagueEnvelope.parity.json` (`natureCases`), gardés par `LeagueEnvelopeMirrorParityTest`.
 */
export function isFriendlyFixture(fixture: Fixture): boolean {
  return "FRIENDLY" === fixture.competitionType;
}

/** Une coupe : la nature servie sur le match (même miroir, mêmes cas que {@link isFriendlyFixture}). */
export function isCupFixture(fixture: Fixture): boolean {
  return "CUP" === fixture.competitionType;
}

/**
 * ⚠️ MIROIR DÉCLARÉ (régime 2) — la règle d'une coupe (lot 7, PR 4b) : pas de fenêtre de ligue, un coup
 * d'envoi entre 08:00 et 21:00, intervalle FERMÉ, quel que soit le jour (férié compris). Mêmes bornes et même
 * prédicat que `App\Matches\CupKickoffRule` (backend, lu par le radar). Cas partagés
 * `leagueEnvelope.parity.json` (`cupBounds`, `cupCases`), gardés par `LeagueEnvelopeMirrorParityTest`.
 */
export const CUP_KICKOFF_MIN = "08:00";
export const CUP_KICKOFF_MAX = "21:00";

export function cupKickoffInside(kickoff: string): boolean {
  const min = timeToMinutes(kickoff);
  return min >= timeToMinutes(CUP_KICKOFF_MIN) && min <= timeToMinutes(CUP_KICKOFF_MAX);
}

export interface EnvelopeResult {
  /** True when a rule is active: the team maps to at least one league window, or the match is a cup. */
  mapped: boolean;
  /** Une coupe : sa règle est l'horaire 08:00–21:00, jamais la fenêtre de ligue (`windows` est vide). */
  cup?: boolean;
  /** The windows that apply to the fixture's team (empty when unmapped). */
  windows: LeagueWindow[];
  /** Whether the fixture's DATE falls on an allowed day (only meaningful when mapped). */
  dayOk: boolean;
  /** Whether the kickoff falls inside an allowed window (only meaningful when mapped && dayOk). */
  timeOk: (kickoff: string) => boolean;
  /** La date est fériée : la ligue a posé ce jour exprès, sa fenêtre du jour de semaine ne s'applique pas. */
  holiday: boolean;
}

/**
 * Resolve the league-envelope windows that apply to a fixture's team and expose
 * day/time validators.
 *
 * P1-4 PR E2 (dette iv): the team↔window join lives on the SERVER
 * (`LeagueEnvelopeResolver`, the same resolution the solver enforces as HARD) —
 * the client only looks up `resolvedTeamWindows` from GET /api/league-match-windows.
 * The old client-side tolerant join is gone: two implementations of the same
 * join had already started to diverge. An unmapped team ([] or absent) keeps
 * the PR-2 degradation: advisory reference, never a block.
 *
 * Lot 7, PR 4b — la nature du match (`competitionType`) décide de sa règle : un amical n'a aucune enveloppe ; une
 * coupe a une enveloppe ACTIVE bornée par {@link cupKickoffInside}, tout jour, férié compris ; un championnat ou
 * un brassage, la fenêtre de ligue de son équipe.
 */
export function resolveEnvelope(
  fixture: Fixture,
  resolvedTeamWindows: Record<string, string[]>,
  windows: LeagueWindow[],
  holidays: string[],
): EnvelopeResult {
  // Miroir de `RuleWindowConflicts::leagueWindowViolations` : un férié est dans l'enveloppe.
  const holiday = holidays.includes(fixture.matchDate);
  if (isFriendlyFixture(fixture)) {
    return { mapped: false, cup: false, windows: [], dayOk: true, timeOk: () => true, holiday };
  }
  if (isCupFixture(fixture)) {
    return { mapped: true, cup: true, windows: [], dayOk: true, timeOk: cupKickoffInside, holiday };
  }

  const ids = new Set(resolvedTeamWindows[fixture.teamId] ?? []);
  const matched = windows.filter((w) => ids.has(w.id));

  const day = isoWeekday(fixture.matchDate);
  const dayWindows = matched.filter((w) => w.dayOfWeek === day);

  return {
    mapped: matched.length > 0,
    cup: false,
    windows: matched,
    dayOk: holiday || dayWindows.length > 0,
    // Miroir déclaré ci-dessus : le prédicat d'appartenance à l'enveloppe ligue vit
    // dans `kickoffInsideLeagueWindow` (filtré sur le jour), gardé en parité avec le backend.
    timeOk: (kickoff: string) => holiday || kickoffInsideLeagueWindow(day, kickoff, matched),
    holiday,
  };
}

/**
 * Whether a placement (date already fixed, kickoff being chosen) sits inside the
 * envelope. Unmapped teams are never blocked (advisory only) → always in-envelope.
 */
export function isInEnvelope(envelope: EnvelopeResult, kickoff: string): boolean {
  if (!envelope.mapped) {
    return true;
  }
  return envelope.dayOk && envelope.timeOk(kickoff);
}
