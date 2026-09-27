import type { Conflict } from "../api";

/**
 * Correctif 2 (retour terrain 2026-09-27) — le FOCUS d'un conflit sur le Calendrier :
 * au lieu de SÉLECTIONNER un seul match (ce qui ouvrait le panneau de placement), on
 * met en évidence LES DEUX rencontres du conflit et on filtre sur le coach concerné.
 *
 * Fonctions PURES qui font le pont entre un `Conflict` et les `fixtureId` à surligner
 * (portés dans l'URL par `conflit=<a>,<b>` — `lib/urlState.ts`). Aucune règle métier :
 * on LIT les côtés déjà servis (🔴 `.claude/rules/frontend.md`).
 */

/**
 * Les `fixtureId` d'un conflit — les rencontres à surligner. MATCH_MATCH / VENUE_OVERLAP
 * portent `left`/`right` ; MATCH_TRAINING / LEAGUE_WINDOW_VIOLATION / … portent `fixture`
 * (l'entraînement n'a pas de `fixtureId`). Dédoublonné, ordre stable.
 */
export function conflictFixtureIds(conflict: Conflict): string[] {
  const ids = [conflict.left?.fixtureId, conflict.right?.fixtureId, conflict.fixture?.fixtureId].filter(
    (id): id is string => undefined !== id && "" !== id,
  );
  return [...new Set(ids)];
}

/**
 * Un conflit peut-il être FOCALISÉ ? (a-t-il au moins une rencontre datée à viser ?)
 * Un « Calendrier incomplet » (aucun côté) ne l'est pas.
 */
export function isFocusableConflict(conflict: Conflict): boolean {
  return conflictFixtureIds(conflict).length > 0;
}

/**
 * Retrouve le conflit FOCALISÉ à partir des `fixtureId` surlignés (le bandeau de focus
 * du Calendrier a besoin du conflit lui-même pour se rendre). Match EXACT du jeu de
 * `fixtureId` ; `null` si rien ne correspond (jeu vide, ou conflit disparu du flux).
 */
export function findFocusedConflict(conflicts: Conflict[], highlightedFixtureIds: string[]): Conflict | null {
  if (0 === highlightedFixtureIds.length) {
    return null;
  }
  const target = [...highlightedFixtureIds].sort().join(",");
  return conflicts.find((c) => conflictFixtureIds(c).slice().sort().join(",") === target) ?? null;
}
