import type { Conflict, Fixture } from "../api";
import { scopeConflictsToRange } from "./consultFilter";

/**
 * PR-2b (temporalité MOIS de l'onglet Consulter) — dérivations PURES du mois affiché,
 * dans la même veine que `weekendGrid.ts` (Semaine) : aucune règle métier inventée
 * (🔴 `.claude/rules/frontend.md`), on classe et on borne des lignes déjà calculées
 * par le backend. Mois = `YYYY-MM`.
 */

/** Les mois `YYYY-MM` distincts portés par les rencontres, triés. */
export function listMonths(fixtures: Fixture[]): string[] {
  return [...new Set(fixtures.map((f) => f.matchDate.slice(0, 7)))].sort();
}

/**
 * Le mois affiché — MÊME règle que `resolveActiveWeekend` : la sélection si elle est
 * listée, sinon le premier mois ≥ mois courant, sinon le dernier, sinon `null`.
 */
export function resolveActiveMonth(months: string[], selected: string | null, todayMonth: string): string | null {
  if (null !== selected && months.includes(selected)) {
    return selected;
  }
  if (0 === months.length) {
    return null;
  }
  return months.find((m) => m >= todayMonth) ?? months[months.length - 1];
}

/** Bornes calendaires (Y-m-d, inclusives) d'un mois `YYYY-MM`. */
export function monthBounds(month: string): { from: string; to: string } {
  const [year, m] = month.split("-").map(Number);
  const lastDay = new Date(year, m, 0).getDate(); // jour 0 du mois suivant = dernier jour de `m`
  return { from: `${month}-01`, to: `${month}-${String(lastDay).padStart(2, "0")}` };
}

/** Coup d'envoi croissant, « heure non publiée » (`kickoffTime` null) en dernier. */
function byKickoff(a: Fixture, b: Fixture): number {
  if (a.kickoffTime === b.kickoffTime) {
    return 0;
  }
  if (null === a.kickoffTime) {
    return 1;
  }
  if (null === b.kickoffTime) {
    return -1;
  }
  return a.kickoffTime.localeCompare(b.kickoffTime);
}

/**
 * Rencontres du mois groupées par JOUR (Y-m-d), jours triés, coup d'envoi croissant
 * dans chaque jour (heure non publiée en dernier).
 */
export function groupByDay(fixtures: Fixture[], month: string): Array<{ date: string; fixtures: Fixture[] }> {
  const byDate = new Map<string, Fixture[]>();
  for (const fixture of fixtures) {
    if (fixture.matchDate.slice(0, 7) !== month) {
      continue;
    }
    const bucket = byDate.get(fixture.matchDate) ?? [];
    bucket.push(fixture);
    byDate.set(fixture.matchDate, bucket);
  }
  return [...byDate.keys()].sort().map((date) => ({ date, fixtures: [...(byDate.get(date) ?? [])].sort(byKickoff) }));
}

/**
 * Restreint les conflits au mois affiché — même contrat que `scopeConflictsToWeek`
 * (délègue à `scopeConflictsToRange`, aucune duplication du datage). `month` null ⇒
 * pass-through (MÊME référence).
 */
export function scopeConflictsToMonth(conflicts: Conflict[], month: string | null): Conflict[] {
  if (null === month) {
    return conflicts;
  }
  const { from, to } = monthBounds(month);
  return scopeConflictsToRange(conflicts, from, to);
}

/**
 * Rattache les conflits à leurs fixtures référencées (`left`/`right`/`fixture`) :
 * `fixtureId` → conflits, pour peindre les pastilles de famille sur la ligne du match.
 * Dédoublonné par conflit (un VENUE_OVERLAP touche ses deux côtés, une seule fois chacun).
 */
export function conflictsByFixture(conflicts: Conflict[]): Map<string, Conflict[]> {
  const byFixture = new Map<string, Conflict[]>();
  for (const conflict of conflicts) {
    const refs = new Set<string>();
    if (undefined !== conflict.left) refs.add(conflict.left.fixtureId);
    if (undefined !== conflict.right) refs.add(conflict.right.fixtureId);
    if (undefined !== conflict.fixture) refs.add(conflict.fixture.fixtureId);
    for (const fixtureId of refs) {
      const bucket = byFixture.get(fixtureId) ?? [];
      bucket.push(conflict);
      byFixture.set(fixtureId, bucket);
    }
  }
  return byFixture;
}
