import type { Fixture, OpponentTravel, TeamMatchHabit } from "../api";
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

/** Clé de jointure d'un trajet adverse : `(code, teamKey)` servis, `null` sans code
 *  fédéral résolu — plus de repli par libellé brut (P2-54 « adversaire multi-gymnases »
 *  PR-3, une AWAY sans code résolu reste sans trajet). */
export function awayTravelKey(code: string | null, teamKey: string | null): string | null {
  return null === code ? null : `${code} ${teamKey ?? ""}`;
}

/** Index des trajets adverses par `(code, teamKey)` — une AWAY se joint sans re-dériver. */
export function awayTravelByKey(travel: OpponentTravel[]): Map<string, OpponentTravel> {
  const byKey = new Map<string, OpponentTravel>();
  for (const t of travel) {
    const key = awayTravelKey(t.opponentOrganismeCode, t.opponentTeamKey);
    if (null !== key) {
      byKey.set(key, t);
    }
  }
  return byKey;
}
