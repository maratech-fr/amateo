import type { Conflict, Fixture } from "../api";
import { dateOf } from "./consultFilter";
import { groupBySeverity } from "./diagnostic";
import { conflictTeamIds } from "./matchFilter";
import { weekendKeyOf } from "./weekendGrid";

/**
 * PR A (onglet Conflits) — dérivation PURE du PIVOT des conflits de la saison sur
 * quatre axes (coach / équipe / gymnase / journée). PRÉSENTATION seule : aucun
 * conflit n'est calculé ni requalifié ici (le radar serveur reste souverain, 🔴
 * `.claude/rules/frontend.md`) — on RÉPARTIT en seaux des lignes déjà servies.
 *
 * La lib reste sur des IDS : les libellés (nom de coach / équipe / gymnase, étiquette
 * de week-end) se résolvent dans la page à partir des maps. Trois axes portent une
 * sentinelle pour les conflits sans ressource résolue ; l'axe équipe n'en a pas (un
 * conflit sans équipe — cas inexistant en pratique — ne peuple aucune entrée). Un
 * conflit à 2 équipes apparaît sous CHACUNE (somme > total, assumé, décision fondateur).
 */
export type ConflictPivotAxis = "coach" | "equipe" | "gymnase" | "journee";

export const PIVOT_AXES: ConflictPivotAxis[] = ["coach", "equipe", "gymnase", "journee"];

/**
 * La nature d'une entrée : une RESSOURCE réelle (coach/équipe/gymnase), un WEEK-END,
 * ou une des trois SENTINELLES (extérieur = sans gymnase, sansDate, sansCoach). La
 * page choisit le libellé sur ce champ ; les sentinelles vont toujours en dernier.
 */
export type ConflictPivotKind = "resource" | "weekend" | "exterieur" | "sansDate" | "sansCoach";

export interface ConflictPivotEntry {
  /** Clé stable de l'entrée : l'id de la ressource, le samedi du week-end, ou une clé de sentinelle. */
  key: string;
  kind: ConflictPivotKind;
  /** Les conflits de l'entrée, triés du plus grave (1) au plus doux. */
  conflicts: Conflict[];
}

export const EXTERIEUR_KEY = "__exterieur__";
export const SANS_DATE_KEY = "__sansDate__";
export const SANS_COACH_KEY = "__sansCoach__";

/** fixtureIds référencés par un conflit (côtés match seulement), même patron que `matchFilter.ts`. */
function conflictFixtureRefs(conflict: Conflict): string[] {
  const refs: string[] = [];
  if (undefined !== conflict.left) refs.push(conflict.left.fixtureId);
  if (undefined !== conflict.right) refs.push(conflict.right.fixtureId);
  if (undefined !== conflict.fixture) refs.push(conflict.fixture.fixtureId);
  return refs;
}

/**
 * Les gymnases qu'un conflit touche : `venueId` direct ∪ `training.venueId` ∪ le
 * gymnase des fixtures référencées (résolu via `fixturesById`, patron `conflictInVenues`).
 * Distincts ; vide = conflit « extérieur » (aucun gymnase résolu).
 */
function conflictVenueIds(conflict: Conflict, fixturesById: Map<string, Fixture>): string[] {
  const ids = new Set<string>();
  if (undefined !== conflict.venueId) {
    ids.add(conflict.venueId);
  }
  if (undefined !== conflict.training) {
    ids.add(conflict.training.venueId);
  }
  for (const ref of conflictFixtureRefs(conflict)) {
    const venueId = fixturesById.get(ref)?.venueId ?? null;
    if (null !== venueId) {
      ids.add(venueId);
    }
  }
  return [...ids];
}

/** Tri interne d'une entrée : du pire (gravité 1) au plus doux — réutilise `groupBySeverity`
 *  (une seule maison de la gravité, jamais re-triée à la main). */
function sortBySeverity(conflicts: Conflict[]): Conflict[] {
  return groupBySeverity(conflicts).flatMap((group) => group.conflicts);
}

/**
 * Répartit les conflits sur l'axe demandé. Entrées « ressource »/« week-end » d'abord
 * (dans l'ordre de première apparition), sentinelles ensuite ; le tri fin (compte
 * décroissant, départage par libellé / chronologie) se fait dans la page, qui connaît
 * les libellés. Une entrée à 0 conflit n'existe pas (on ne crée que ce qu'on rencontre).
 */
export function pivotConflicts(conflicts: Conflict[], axis: ConflictPivotAxis, fixturesById: Map<string, Fixture>): ConflictPivotEntry[] {
  const buckets = new Map<string, Conflict[]>();
  const kinds = new Map<string, ConflictPivotKind>();
  const order: string[] = [];
  const push = (key: string, kind: ConflictPivotKind, conflict: Conflict): void => {
    let bucket = buckets.get(key);
    if (undefined === bucket) {
      bucket = [];
      buckets.set(key, bucket);
      kinds.set(key, kind);
      order.push(key);
    }
    bucket.push(conflict);
  };

  for (const conflict of conflicts) {
    if ("coach" === axis) {
      if (undefined !== conflict.coachId) {
        push(conflict.coachId, "resource", conflict);
      } else {
        push(SANS_COACH_KEY, "sansCoach", conflict);
      }
    } else if ("equipe" === axis) {
      for (const teamId of new Set(conflictTeamIds(conflict))) {
        push(teamId, "resource", conflict);
      }
    } else if ("gymnase" === axis) {
      const venueIds = conflictVenueIds(conflict, fixturesById);
      if (0 === venueIds.length) {
        push(EXTERIEUR_KEY, "exterieur", conflict);
      } else {
        for (const venueId of venueIds) {
          push(venueId, "resource", conflict);
        }
      }
    } else {
      const date = dateOf(conflict);
      if (null === date) {
        push(SANS_DATE_KEY, "sansDate", conflict);
      } else {
        push(weekendKeyOf(date), "weekend", conflict);
      }
    }
  }

  const entries: ConflictPivotEntry[] = order.map((key) => ({
    key,
    kind: kinds.get(key) as ConflictPivotKind,
    conflicts: sortBySeverity(buckets.get(key) as Conflict[]),
  }));
  const isSentinel = (kind: ConflictPivotKind): boolean => "resource" !== kind && "weekend" !== kind;
  return [...entries.filter((e) => !isSentinel(e.kind)), ...entries.filter((e) => isSentinel(e.kind))];
}
