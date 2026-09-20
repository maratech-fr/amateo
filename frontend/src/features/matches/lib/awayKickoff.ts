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
