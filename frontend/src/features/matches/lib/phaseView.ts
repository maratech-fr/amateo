import type { Competition, Conflict, Fixture, Team } from "../api";
import { weekendKeyOf } from "./weekendGrid";

/**
 * PR-2b (temporalité PHASE de l'onglet Consulter) — dérivations PURES d'une PHASE =
 * une `Competition` APPARIÉE à la FFBB. Aucune règle métier inventée
 * (🔴 `.claude/rules/frontend.md`) : la complétude préfère le verdict serveur
 * (conflit `COMPETITION_INCOMPLETE`) et ne retombe sur un comptage de présentation
 * (`count(fixtures)/expectedMatchdays`) qu'à défaut.
 */

/** Une compétition est APPARIÉE dès qu'elle porte une référence de compétition FFBB. */
export function isPairedCompetition(competition: Competition): boolean {
  return null !== competition.ffbbCompetitionId && undefined !== competition.ffbbCompetitionId;
}

export interface PhaseOption {
  competitionId: string;
  /** « {nom de compétition} — {équipe} ». */
  label: string;
}

/** Les phases (compétitions appariées) en options « {compétition} — {équipe} », triées. */
export function listPhases(competitions: Competition[], teams: Team[]): PhaseOption[] {
  const teamName = new Map(teams.map((t) => [t.id, t.name]));
  return competitions
    .filter(isPairedCompetition)
    .map((competition) => ({ competitionId: competition.id, label: `${competition.name} — ${teamName.get(competition.teamId) ?? "Équipe ?"}` }))
    .sort((a, b) => a.label.localeCompare(b.label, "fr"));
}

/** Coup d'envoi croissant, « heure non publiée » en dernier (même règle que la vue Mois). */
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

/** Rencontres de la compétition groupées par week-end (journées), triées. */
export function phaseFixtures(fixtures: Fixture[], competitionId: string): Array<{ weekend: string; fixtures: Fixture[] }> {
  const byWeekend = new Map<string, Fixture[]>();
  for (const fixture of fixtures) {
    if (fixture.competitionId !== competitionId) {
      continue;
    }
    const key = weekendKeyOf(fixture.matchDate);
    const bucket = byWeekend.get(key) ?? [];
    bucket.push(fixture);
    byWeekend.set(key, bucket);
  }
  return [...byWeekend.keys()].sort().map((weekend) => ({ weekend, fixtures: [...(byWeekend.get(weekend) ?? [])].sort(byKickoff) }));
}

/**
 * Complétude d'une phase : le conflit serveur `COMPETITION_INCOMPLETE` de cette
 * compétition s'il existe (`imported`/`expected`), sinon un comptage de PRÉSENTATION
 * `count(fixtures) / expectedMatchdays`. `expected` vaut `null` quand la compétition
 * n'attend aucun nombre de journées (une COUPE, P4-195 : `expectedMatchdays` null et
 * aucun conflit serveur) — l'appelant rend alors le compte SANS dénominateur.
 */
export function phaseCompleteness(competition: Competition, fixtures: Fixture[], conflicts: Conflict[]): { imported: number; expected: number | null } {
  const incomplete = conflicts.find((c) => "COMPETITION_INCOMPLETE" === c.type && c.competitionId === competition.id);
  if (undefined !== incomplete && undefined !== incomplete.imported && undefined !== incomplete.expected) {
    return { imported: incomplete.imported, expected: incomplete.expected };
  }
  return { imported: fixtures.length, expected: competition.expectedMatchdays ?? null };
}

/**
 * Restreint les conflits à la phase : ceux référençant une fixture de la phase, ou le
 * `COMPETITION_INCOMPLETE` de cette compétition. `competitionId` null ⇒ pass-through
 * (MÊME référence).
 */
export function scopeConflictsToPhase(conflicts: Conflict[], competitionId: string | null, fixtureIds: string[]): Conflict[] {
  if (null === competitionId) {
    return conflicts;
  }
  const ids = new Set(fixtureIds);
  const references = (conflict: Conflict): boolean => {
    if (undefined !== conflict.left && ids.has(conflict.left.fixtureId)) return true;
    if (undefined !== conflict.right && ids.has(conflict.right.fixtureId)) return true;
    if (undefined !== conflict.fixture && ids.has(conflict.fixture.fixtureId)) return true;
    return false;
  };
  return conflicts.filter((conflict) => {
    if ("COMPETITION_INCOMPLETE" === conflict.type && conflict.competitionId === competitionId) {
      return true;
    }
    return references(conflict);
  });
}
