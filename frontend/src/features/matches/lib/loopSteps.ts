import type { Conflict, Fixture, TeamMatchHabit } from "../api";
import { isoWeekday } from "./envelope";

/**
 * **La boucle guidée du module matchs — dérivation PURE des 5 états** (RMM-1 PR3,
 * cadrage §6quater L3). Le rail ne STOCKE aucune progression : il LIT la semaine
 * affichée (fixtures + habitudes + radar) et en dérive les 5 étapes à chaque
 * rendu. Zéro état nouveau, zéro backend.
 *
 * ⚠ Ce module ne DÉCIDE d'aucun comportement métier — pas de verdict, pas de
 * garde. Il calcule des états de PROGRESSION (des `done` bool) et des libellés à
 * partir de données déjà servies par le backend. Ce n'est pas une redérivation de
 * règle (`.claude/rules/frontend.md`) : il ne rejoue pas le solveur ni le radar,
 * il compte ce qu'ils ont produit. Les formules sont VALIDÉES fondateur (§6quater).
 */

export type LoopStepId = "batch" | "model" | "disputes" | "homeSlots" | "fbiEntry";

export interface LoopStep {
  id: LoopStepId;
  /** Libellé FR, comptes INCLUS (« Litiges (3) ») — le rail ne porte pas de badge à part. */
  label: string;
  done: boolean;
}

export interface LoopStepsInput {
  /** W — les fixtures de la SEMAINE affichée (déjà bucketées par la page). */
  weekFixtures: Fixture[];
  habits: TeamMatchHabit[];
  /** Le radar entier ; le rattachement hebdo est calculé ici. */
  conflicts: Conflict[];
}

const teamHasHabit = (teamId: string, habits: TeamMatchHabit[]): boolean => habits.some((h) => h.teamId === teamId);

/**
 * Écart au modèle d'un domicile PLACÉ (jour / heure / gymnase divergeant de la
 * `TeamMatchHabit`). C'est un SIGNAL affiché, JAMAIS un `done` : « c'est un signal,
 * c'est pas bloquant » (verbatim fondateur). Sans habitude sur l'équipe il n'y a
 * pas de modèle de référence — donc pas d'écart.
 */
export function isOffModel(fixture: Fixture, habits: TeamMatchHabit[]): boolean {
  if ("HOME" !== fixture.homeAway || "UNPLACED" === fixture.status) {
    return false;
  }
  const teamHabits = habits.filter((h) => h.teamId === fixture.teamId);
  if (0 === teamHabits.length) {
    return false;
  }
  const habit = teamHabits.find((h) => h.dayOfWeek === isoWeekday(fixture.matchDate)) ?? null;
  if (null === habit) {
    return true; // placé un jour non habituel
  }
  if (null !== fixture.kickoffTime && fixture.kickoffTime !== habit.kickoffTime) {
    return true; // heure divergente
  }
  return null !== habit.venueId && null !== fixture.venueId && fixture.venueId !== habit.venueId; // gymnase divergent
}

export const offModelCount = (weekFixtures: Fixture[], habits: TeamMatchHabit[]): number => weekFixtures.filter((f) => isOffModel(f, habits)).length;

/** Les fixtureIds qu'un conflit référence (0, 1 ou 2) — un conflit sans fixture est « sans date ». */
function conflictFixtureIds(conflict: Conflict): string[] {
  return [conflict.left?.fixtureId, conflict.right?.fixtureId, conflict.fixture?.fixtureId].filter((v): v is string => undefined !== v);
}

/**
 * Conflits SANS date (aucun fixture référencé — ex. COMPETITION_INCOMPLETE). Ils
 * sortent du compte hebdo et s'affichent en BANDEAU GLOBAL au-dessus du rail
 * (décision fondateur), jamais dans une étape de semaine.
 */
export const datelessConflicts = (conflicts: Conflict[]): Conflict[] => conflicts.filter((c) => 0 === conflictFixtureIds(c).length);

/** Conflits du radar rattachés à un fixture de la semaine affichée. */
function weekConflictCount(conflicts: Conflict[], weekFixtureIds: Set<string>): number {
  return conflicts.filter((c) => conflictFixtureIds(c).some((id) => weekFixtureIds.has(id))).length;
}

export function deriveLoopSteps({ weekFixtures, habits, conflicts }: LoopStepsInput): LoopStep[] {
  const weekFixtureIds = new Set(weekFixtures.map((f) => f.id));
  const home = weekFixtures.filter((f) => "HOME" === f.homeAway);
  const homeUnplaced = home.filter((f) => "UNPLACED" === f.status);
  const homeUnplacedWithHabit = homeUnplaced.filter((f) => teamHasHabit(f.teamId, habits));
  const conflictCount = weekConflictCount(conflicts, weekFixtureIds);
  const submitted = home.filter((f) => "SUBMITTED" === f.status || "VALIDATED" === f.status);

  return [
    { id: "batch", label: "Batch importé", done: weekFixtures.length > 0 },
    { id: "model", label: "Placés au modèle", done: 0 === homeUnplacedWithHabit.length },
    { id: "disputes", label: `Litiges (${conflictCount})`, done: 0 === conflictCount },
    { id: "homeSlots", label: "Domiciles posés", done: 0 === homeUnplaced.length },
    { id: "fbiEntry", label: `Saisi dans FBI (${submitted.length}/${home.length})`, done: home.length > 0 && submitted.length === home.length },
  ];
}

/**
 * L'étape sélectionnée par DÉFAUT (store `railStep === null`) = le PREMIER TROU :
 * la première étape non-done de la semaine affichée. Tout done (état « veille »
 * entre deux rafales) → la dernière étape (on est au bout, rien à traiter).
 */
export function defaultLoopStep(steps: LoopStep[]): LoopStepId {
  return (steps.find((s) => !s.done) ?? steps[steps.length - 1]).id;
}
