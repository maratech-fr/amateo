import type { Conflict, Fixture, MatchSlotRotation, TeamMatchHabit } from "../api";
import { isOpenConflict } from "./conflictResolution";
import { isoWeekday } from "./envelope";

/**
 * **Les signaux de la SEMAINE affichée du module matchs — dérivation PURE** (PR 3b
 * « Calendrier unique »). Depuis la fusion Semaine⇄Consulter, le module n'a plus de
 * rail : la barre `WeekCounters` lit la semaine affichée (fixtures + radar) et en
 * dérive trois compteurs (à placer · conflits · à saisir dans FBI), tandis que les
 * signaux « hors modèle » / « même week-end » restent les mêmes qu'avant.
 *
 * ⚠ Ce module ne DÉCIDE d'aucun comportement métier — pas de verdict, pas de garde.
 * Il COMPTE ce que le solveur/le radar ont déjà produit (des `done`/des totaux),
 * jamais une redérivation de règle (`.claude/rules/frontend.md`). Les formules sont
 * VALIDÉES fondateur (§6quater), reprises telles quelles du rail supprimé.
 */

/**
 * Écart au modèle d'un domicile PLACÉ (jour / heure / gymnase divergeant de la
 * référence du jour). C'est un SIGNAL affiché, JAMAIS un `done` : « c'est un signal,
 * c'est pas bloquant » (verbatim fondateur). Sans référence sur l'équipe (ni habitude
 * ni rotation) il n'y a pas de modèle — donc pas d'écart.
 *
 * RMM-5 PR-4 — pour un MEMBRE de rotation, le modèle de référence du jour du créneau
 * EST le créneau de rotation (jour/heure/gymnase), pas son habitude : cohérent avec la
 * suppléance backend (l'habitude même-jour d'un membre est retirée du payload de
 * placement). La rotation du même jour PRIME donc sur l'habitude.
 */
export function isOffModel(fixture: Fixture, habits: TeamMatchHabit[], rotations: MatchSlotRotation[] = []): boolean {
  if ("HOME" !== fixture.homeAway || "UNPLACED" === fixture.status) {
    return false;
  }
  const teamHabits = habits.filter((h) => h.teamId === fixture.teamId);
  const teamRotations = rotations.filter((r) => r.teamIds.includes(fixture.teamId));
  if (0 === teamHabits.length && 0 === teamRotations.length) {
    return false; // aucun modèle de référence
  }
  const day = isoWeekday(fixture.matchDate);

  // Suppléance : la rotation du même jour est la référence du jour (jamais l'habitude).
  const rotation = teamRotations.find((r) => r.dayOfWeek === day) ?? null;
  if (null !== rotation) {
    if (null !== fixture.kickoffTime && fixture.kickoffTime !== rotation.kickoffTime) {
      return true; // heure divergente du créneau partagé
    }
    return null !== fixture.venueId && fixture.venueId !== rotation.venueId; // gymnase divergent (le créneau a TOUJOURS un gymnase)
  }

  const habit = teamHabits.find((h) => h.dayOfWeek === day) ?? null;
  if (null === habit) {
    return true; // placé un jour non habituel (ni habitude ni rotation ce jour-là)
  }
  if (null !== fixture.kickoffTime && fixture.kickoffTime !== habit.kickoffTime) {
    return true; // heure divergente
  }
  return null !== habit.venueId && null !== fixture.venueId && fixture.venueId !== habit.venueId; // gymnase divergent
}

export const offModelCount = (weekFixtures: Fixture[], habits: TeamMatchHabit[], rotations: MatchSlotRotation[] = []): number =>
  weekFixtures.filter((f) => isOffModel(f, habits, rotations)).length;

/**
 * RMM-5 PR-4 — le compteur « même week-end » : combien de créneaux partagés voient
 * DEUX de leurs membres (ou plus, distincts) recevoir À DOMICILE le même week-end
 * affiché. L'alternance dit qu'un seul membre reçoit par week-end sur le créneau ;
 * deux domiciles la contredisent. SIGNAL neutre (pilule), jamais un blocage — comme
 * l'écart au modèle, il ne pèse dans AUCUN compteur.
 */
export function sameWeekendRotationCount(weekFixtures: Fixture[], rotations: MatchSlotRotation[]): number {
  const homeTeams = new Set(weekFixtures.filter((f) => "HOME" === f.homeAway).map((f) => f.teamId));
  return rotations.filter((r) => r.teamIds.filter((t) => homeTeams.has(t)).length >= 2).length;
}

/** Les fixtureIds qu'un conflit référence (0, 1 ou 2) — un conflit sans fixture est « sans date ». */
function conflictFixtureIds(conflict: Conflict): string[] {
  return [conflict.left?.fixtureId, conflict.right?.fixtureId, conflict.fixture?.fixtureId].filter((v): v is string => undefined !== v);
}

/**
 * Conflits SANS date (aucun fixture référencé — ex. COMPETITION_INCOMPLETE). Ils
 * sortent du compte hebdo et s'affichent en BANDEAU GLOBAL sur le Calendrier
 * (décision fondateur), en lien vers l'onglet Conflits, jamais dans la semaine.
 */
export const datelessConflicts = (conflicts: Conflict[]): Conflict[] => conflicts.filter((c) => 0 === conflictFixtureIds(c).length);

/**
 * Conflits du radar rattachés à un fixture de la semaine affichée, À TRAITER seulement
 * (P4-207) : un conflit annoté (dérogation demandée, réglé en interne, sans solution)
 * reste listé partout, mais ne compte plus dans « conflits (n) » de la barre.
 * PR 3b — EXPORTÉ (devient `openConflictCount` sur les conflits de la semaine),
 * cohérent avec le badge de l'onglet Conflits.
 */
export function weekConflictCount(conflicts: Conflict[], weekFixtureIds: Set<string>): number {
  return conflicts.filter((c) => isOpenConflict(c) && conflictFixtureIds(c).some((id) => weekFixtureIds.has(id))).length;
}

/**
 * PR 3b — les compteurs de la barre « Semaine affichée » (`WeekCounters`).
 * « à saisir dans FBI » a QUITTÉ cette barre : le « FBI à faire » est désormais un
 * compteur GLOBAL (toutes semaines) servi par le backend (`fbiTodo`), hors du groupe
 * « Semaine affichée » — ces deux-là restent bornés à la semaine.
 */
export interface WeekCounts {
  /** Domiciles encore UNPLACED de la semaine (« à placer »). */
  unplaced: number;
  /** Conflits À TRAITER rattachés à la semaine (`weekConflictCount`). */
  conflicts: number;
}

/**
 * PR 3b — dérive les compteurs de la semaine affichée (à placer · conflits), reprenant
 * EXACTEMENT les formules du rail supprimé. Zéro état, zéro backend : compte ce qui est
 * déjà servi. Le « à saisir/à corriger dans FBI » est global, il vit ailleurs (`fbiTodo`).
 */
export function deriveWeekCounters(weekFixtures: Fixture[], conflicts: Conflict[]): WeekCounts {
  const weekFixtureIds = new Set(weekFixtures.map((f) => f.id));
  const home = weekFixtures.filter((f) => "HOME" === f.homeAway);
  const homeUnplaced = home.filter((f) => "UNPLACED" === f.status);
  return {
    unplaced: homeUnplaced.length,
    conflicts: weekConflictCount(conflicts, weekFixtureIds),
  };
}
