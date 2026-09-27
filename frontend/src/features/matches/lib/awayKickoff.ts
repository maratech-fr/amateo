import type { Fixture, TeamMatchHabit } from "../api";
import { isoWeekday } from "./envelope";

/**
 * Foyer unique de la présentation d'un match À L'EXTÉRIEUR (lot 3 PR-3a). Extrait
 * d'`AwayList` — qui le consomme désormais — pour que la BANDE extérieur et la COLONNE
 * extérieur de la grille (`lib/awayColumn.ts`) affichent la MÊME chose sans dupliquer la
 * règle. Présentation pure : rien n'est re-dérivé, tout vient déjà servi du backend
 * (🔴 `.claude/rules/frontend.md`).
 */

/** L'heure d'AFFICHAGE d'un extérieur : l'heure réelle si connue, sinon l'habitude du
 *  jour (marquée « estimée »), sinon `null` (« heure inconnue »). C'est EXACTEMENT la
 *  règle d'estimation du radar (`AwayList` d'origine, dette (v) PR E2). */
export interface AwayHour {
  hour: string | null;
  estimated: boolean;
}

export function awayHour(fixture: Fixture, habits: TeamMatchHabit[]): AwayHour {
  const habit = habits.find((h) => h.teamId === fixture.teamId && h.dayOfWeek === isoWeekday(fixture.matchDate));
  const hour = fixture.kickoffTime ?? habit?.kickoffTime ?? null;
  return { hour, estimated: null === fixture.kickoffTime && null !== hour };
}

/**
 * Chronologie DESSINÉE/AFFICHÉE d'un extérieur à heure connue (correctif 10). Le bloc
 * couvre `[coup d'envoi − aller, coup d'envoi + match + aller]` quand le trajet ALLER SIMPLE
 * (`awayTravel.oneWayMinutes`) est connu — l'aller de CHAQUE côté, EXACTEMENT ce que le radar
 * serveur compte pour une personne (`MatchFootprint::personConflictOccupancy`, `travelOut` =
 * aller-retour/2 = aller simple). Trajet inconnu (`null`) ⇒ le bloc se réduit au match.
 * On ne recalcule AUCUNE règle métier : on POSITIONNE une donnée déjà servie
 * (🔴 `.claude/rules/frontend.md`). Foyer UNIQUE, partagé par la colonne de grille
 * (`lib/awayColumn.ts`) et la fiche lecture seule (`AwayFixtureCard.tsx`). Minutes depuis minuit.
 */
export interface AwayTimeline {
  departureMin: number;
  kickoffMin: number;
  matchEndMin: number;
  returnMin: number;
  /** Aller simple servi, `null` quand le trajet est inconnu (bloc = match seul). */
  oneWayMinutes: number | null;
}

export function awayTimeline(kickoffMin: number, matchMinutes: number, oneWayMinutes: number | null): AwayTimeline {
  const matchEndMin = kickoffMin + matchMinutes;
  const oneWay = oneWayMinutes ?? 0;
  return {
    departureMin: kickoffMin - oneWay,
    kickoffMin,
    matchEndMin,
    returnMin: matchEndMin + oneWay,
    oneWayMinutes,
  };
}
