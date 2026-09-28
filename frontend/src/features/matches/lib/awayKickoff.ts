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
 * 🔴 MIROIR DÉCLARÉ (régime 2, `.claude/rules/frontend.md`) — `awayTimeline` réplique la
 * règle AWAY de `App\Service\MatchFootprint::personConflictOccupancy` : la fenêtre PERSONNE
 * d'un match à l'extérieur va de `[coup d'envoi − échauffement − aller, coup d'envoi + match
 * + aller]`. C'est une redérivation ASSUMÉE (réactivité sans aller-retour réseau), gardée par
 * un test de parité MÉCANIQUE — voir `FrontRederivationRegistryTest` (registre) et
 * `awayTimeline.parity.test.ts` ⇄ `AwayTimelineMirrorParityTest.php` (cas partagés
 * `awayTimeline.parity.json`). Changer l'algèbre d'un seul côté rougit ce côté-là.
 *
 * Chronologie DESSINÉE/AFFICHÉE d'un extérieur à heure connue (correctif 10, échauffement
 * P4-240 ③). Le bloc s'étend de l'échauffement AVANT le coup d'envoi (on doit être au gymnase
 * adverse échauffé, décision C) et, quand le trajet ALLER SIMPLE (`awayTravel.oneWayMinutes`)
 * est connu, du trajet de CHAQUE côté (`travelOut` = aller-retour/2 = aller simple côté
 * backend). Trajet inconnu (`null`) ⇒ le départ reste coup d'envoi − échauffement (le retour se
 * réduit à la fin du match). Foyer UNIQUE, partagé par la colonne de grille (`lib/awayColumn.ts`)
 * et la fiche lecture seule (`AwayFixtureCard.tsx`). Minutes depuis minuit.
 */
export interface AwayTimeline {
  departureMin: number;
  kickoffMin: number;
  matchEndMin: number;
  returnMin: number;
  /** Aller simple servi, `null` quand le trajet est inconnu (retour = fin du match). */
  oneWayMinutes: number | null;
}

export function awayTimeline(
  kickoffMin: number,
  matchMinutes: number,
  warmupMinutes: number,
  oneWayMinutes: number | null,
): AwayTimeline {
  const matchEndMin = kickoffMin + matchMinutes;
  const oneWay = oneWayMinutes ?? 0;
  return {
    departureMin: kickoffMin - warmupMinutes - oneWay,
    kickoffMin,
    matchEndMin,
    returnMin: matchEndMin + oneWay,
    oneWayMinutes,
  };
}
