import type { Competition, Conflict, ConflictType, Fixture } from "../api";
import { datelessConflicts } from "./loopSteps";
import { weekBounds } from "./weekendGrid";

/**
 * PR-2a (onglet Consulter du module Matchs) — dérivations PURES du filtrage
 * « type de compétition » et « famille de conflit », dans la même veine que
 * `matchFilter.ts` (PR-1) : aucune règle métier inventée (🔴 `.claude/rules/frontend.md`),
 * on classe et on filtre des lignes déjà calculées par le backend.
 *
 * La chaîne, ordonnée, vit dans la page : `applyMatchFilter` (PR-1) → `applyKindFilter`
 * (type de compétition) → `countByFamily` (compteurs) → `applyFamilyFilter`.
 */

export type Kind = "amical" | "championnat" | "coupe" | "brassage";

/** Les 4 types, dans l'ordre des chips ; « tout coché » ⇔ contient les 4. */
export const KINDS: Kind[] = ["amical", "championnat", "coupe", "brassage"];

// Classification enum FFBB → libellé de type. Une TABLE (comme les libellés) :
// mapper `CompetitionType` vers un `Kind` de PRÉSENTATION n'est pas un décideur
// de comportement. Repli sur « championnat » quand la compétition manque ou porte
// une valeur inconnue — jamais un vide (le type est requis pour cocher/décocher).
const KIND_BY_COMPETITION_TYPE: Record<string, Kind> = {
  CHAMPIONSHIP: "championnat",
  CUP: "coupe",
  BRASSAGE: "brassage",
};

/**
 * Type de compétition d'une rencontre. `competitionId === null` (`Fixture`) = un
 * amical ; sinon selon `Competition.competitionType`.
 */
export function competitionKind(fixture: Fixture, competitionsById: Map<string, Competition>): Kind {
  if (null === fixture.competitionId) {
    return "amical";
  }
  const competition = competitionsById.get(fixture.competitionId);
  return (undefined === competition ? undefined : KIND_BY_COMPETITION_TYPE[competition.competitionType]) ?? "championnat";
}

/** fixtureIds référencés par un conflit (côtés match), même patron que `matchFilter.ts`. */
function conflictFixtureRefs(conflict: Conflict): string[] {
  const refs: string[] = [];
  if (undefined !== conflict.left) refs.push(conflict.left.fixtureId);
  if (undefined !== conflict.right) refs.push(conflict.right.fixtureId);
  if (undefined !== conflict.fixture) refs.push(conflict.fixture.fixtureId);
  return refs;
}

/**
 * Applique le filtre « type de compétition ». Tout coché (les 4 `KINDS`) ⇒ renvoie
 * les MÊMES références de tableaux (pass-through). Sinon : les fixtures sont filtrées
 * sur leur type ; un conflit SUIT ses fixtures référencées (`left`/`right`/`fixture`),
 * un `COMPETITION_INCOMPLETE` suit son `competitionId` ; un conflit sans fixture ni
 * compétition n'a aucun type à faire matcher et sort du set (il n'est visible que
 * dans le cas « tout coché », via le pass-through).
 */
export function applyKindFilter(
  fixtures: Fixture[],
  conflicts: Conflict[],
  kinds: readonly Kind[],
  competitionsById: Map<string, Competition>,
): { fixtures: Fixture[]; conflicts: Conflict[] } {
  const active = new Set(kinds);
  if (KINDS.every((k) => active.has(k))) {
    return { fixtures, conflicts };
  }

  const kindOf = (fixture: Fixture): Kind => competitionKind(fixture, competitionsById);
  const fixturesById = new Map(fixtures.map((f) => [f.id, f]));
  const competitionKinds = new Map<string, Kind>();
  for (const competition of competitionsById.values()) {
    competitionKinds.set(competition.id, KIND_BY_COMPETITION_TYPE[competition.competitionType] ?? "championnat");
  }

  const conflictMatches = (conflict: Conflict): boolean => {
    if (undefined !== conflict.competitionId) {
      const kind = competitionKinds.get(conflict.competitionId) ?? "championnat";
      if (active.has(kind)) {
        return true;
      }
    }
    return conflictFixtureRefs(conflict).some((fid) => {
      const fixture = fixturesById.get(fid);
      return undefined !== fixture && active.has(kindOf(fixture));
    });
  };

  return {
    fixtures: fixtures.filter((f) => active.has(kindOf(f))),
    conflicts: conflicts.filter(conflictMatches),
  };
}

/**
 * Restreint les conflits à la TEMPORALITÉ affichée (semaine Lun→Dim du week-end
 * actif) — étape insérée AVANT `countByFamily` : les compteurs ET le radar portent
 * sur la semaine, pas sur la saison (règle du plan « un seul compteur par famille,
 * sur la temporalité affichée »). `weekendKey` (le samedi du bucket) `null` ⇒
 * pass-through (MÊME référence). Un conflit daté (`start`, à défaut la `matchDate`
 * d'une fixture référencée `left`/`right`/`fixture`) est retenu ssi sa date tombe
 * dans la semaine ; un conflit SANS date ni fixture (`datelessConflicts` de
 * `loopSteps.ts`, réutilisé) est TOUJOURS retenu (COMPETITION_INCOMPLETE…).
 */
export function scopeConflictsToWeek(conflicts: Conflict[], weekendKey: string | null): Conflict[] {
  if (null === weekendKey) {
    return conflicts;
  }
  const { monday, sunday } = weekBounds(weekendKey);
  return scopeConflictsToRange(conflicts, monday, sunday);
}

/**
 * Restreint les conflits à une plage calendaire `[from, to]` (Y-m-d, inclusive) —
 * la dérivation générale derrière `scopeConflictsToWeek` (PR-2a) ET
 * `scopeConflictsToMonth` (PR-2b, `monthView.ts`). Même règle de datage : un conflit
 * daté (`start`, à défaut la `matchDate` d'une fixture référencée) est retenu ssi sa
 * date tombe dans la plage ; un conflit SANS date (`datelessConflicts`, ex.
 * COMPETITION_INCOMPLETE) est TOUJOURS retenu. Les dates ISO se comparent
 * lexicographiquement ; un conflit non « dateless » a toujours une date (le repli
 * `null` ne survient pas, on le garde plutôt que de le perdre en silence).
 */
export function scopeConflictsToRange(conflicts: Conflict[], from: string, to: string): Conflict[] {
  const dateless = new Set(datelessConflicts(conflicts));
  const dateOf = (conflict: Conflict): string | null => {
    if (undefined !== conflict.start) {
      return conflict.start.slice(0, 10);
    }
    return conflict.left?.matchDate ?? conflict.right?.matchDate ?? conflict.fixture?.matchDate ?? null;
  };
  return conflicts.filter((conflict) => {
    if (dateless.has(conflict)) {
      return true;
    }
    const date = dateOf(conflict);
    return null === date || (date >= from && date <= to);
  });
}

/** La famille d'un conflit — son `type` (présentation, pas un verdict). */
export function familyOf(conflict: Conflict): ConflictType {
  return conflict.type;
}

/** Compteur par famille (sur l'ensemble de conflits fourni). */
export function countByFamily(conflicts: Conflict[]): Map<ConflictType, number> {
  const counts = new Map<ConflictType, number>();
  for (const conflict of conflicts) {
    const family = familyOf(conflict);
    counts.set(family, (counts.get(family) ?? 0) + 1);
  }
  return counts;
}

/**
 * Filtre « famille de conflit ». Toutes cochées (les 9 `ConflictType`) ⇒ MÊME
 * référence (pass-through) ; sinon on garde les conflits dont le type est coché.
 */
export function applyFamilyFilter(conflicts: Conflict[], families: readonly ConflictType[]): Conflict[] {
  const active = new Set(families);
  const total = new Set<ConflictType>(conflicts.map((c) => c.type));
  // Pass-through si toutes les familles PRÉSENTES sont cochées (rien à retirer).
  if ([...total].every((family) => active.has(family))) {
    return conflicts;
  }
  return conflicts.filter((c) => active.has(c.type));
}
