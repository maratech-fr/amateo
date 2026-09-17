import type { Competition, Conflict, ConflictType, Fixture } from "../api";
import { isOpenConflict } from "./conflictResolution";
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

/**
 * Les types affichés PAR DÉFAUT (décision fondateur) : les amicaux sont masqués tant
 * qu'on ne les coche pas. `consultKinds === null` (store) signifie « les DÉFAUTS »,
 * d'où `effectiveKinds = consultKinds ?? DEFAULT_KINDS` côté page.
 */
export const DEFAULT_KINDS: Kind[] = ["championnat", "coupe", "brassage"];

/**
 * Normalise une sélection de types vers le store : `null` quand elle égale EXACTEMENT
 * les DÉFAUTS (championnat+coupe+brassage), sinon la liste explicite dans l'ordre de
 * `KINDS` (y compris les 4). Maison unique du toggle des puces ET des levées de masque.
 */
export function normalizeKinds(selected: readonly Kind[]): Kind[] | null {
  const next = KINDS.filter((k) => selected.includes(k));
  const isDefault = next.length === DEFAULT_KINDS.length && DEFAULT_KINDS.every((k) => next.includes(k));
  return isDefault ? null : next;
}

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
  return conflicts.filter((conflict) => {
    if (dateless.has(conflict)) {
      return true;
    }
    const date = dateOf(conflict);
    return null === date || (date >= from && date <= to);
  });
}

/**
 * La date (Y-m-d) d'un conflit : `start` (tronqué au jour) quand il est présent,
 * sinon la `matchDate` d'un côté référencé (left → right → fixture). `null` = aucune
 * date portée (conflit « sans date », ex. COMPETITION_INCOMPLETE). Maison unique du
 * datage, partagée par le scoping calendaire ET le pivot « par journée » (onglet Conflits).
 */
export function dateOf(conflict: Conflict): string | null {
  if (undefined !== conflict.start) {
    return conflict.start.slice(0, 10);
  }
  return conflict.left?.matchDate ?? conflict.right?.matchDate ?? conflict.fixture?.matchDate ?? null;
}

/** La famille d'un conflit — son `type` (présentation, pas un verdict). */
export function familyOf(conflict: Conflict): ConflictType {
  return conflict.type;
}

/**
 * Compteur par famille — À TRAITER seulement (P4-207) : un conflit annoté reste listé
 * dans son entrée, mais ne pèse plus sur le compteur de la chip famille (Consulter ET
 * Conflits). Un compteur ne compte que ce qu'il reste à faire.
 */
export function countByFamily(conflicts: Conflict[]): Map<ConflictType, number> {
  const counts = new Map<ConflictType, number>();
  for (const conflict of conflicts) {
    if (!isOpenConflict(conflict)) {
      continue;
    }
    const family = familyOf(conflict);
    counts.set(family, (counts.get(family) ?? 0) + 1);
  }
  return counts;
}

/**
 * Les familles AYANT au moins un conflit (traité ou non) — la maison de la VISIBILITÉ
 * des chips (P4-207), sœur de `countByFamily` (qui, lui, ne compte que l'à traiter).
 * Une famille toute traitée reste PRÉSENTE (chip visible « · 0 » en sourdine) ; une
 * famille sans AUCUN conflit reste absente (chip masquée).
 */
export function familiesPresent(conflicts: Conflict[]): Set<ConflictType> {
  return new Set(conflicts.map((conflict) => conflict.type));
}

/**
 * Filtre « famille de conflit ». Toutes cochées (les 10 `ConflictType`) ⇒ MÊME
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

/**
 * Un conflit a-t-il un CÔTÉ à domicile (left / right / fixture) ? Générique — sert le
 * filtre « Seulement avec un match à domicile » (onglet Conflits). Conséquence assumée :
 * un conflit SANS côté (COMPETITION_INCOMPLETE) et un côté purement extérieur
 * (AWAY_NO_FOOTPRINT) sont masqués quand la case est cochée. `left`/`right`/`fixture`
 * portent tous `homeAway` (ConflictFixtureView / ConflictUnavailableFixtureView).
 */
export function hasHomeSide(conflict: Conflict): boolean {
  return [conflict.left, conflict.right, conflict.fixture].some((side) => undefined !== side && "HOME" === side.homeAway);
}

/**
 * A8 — les masques à LEVER pour que des rencontres cibles apparaissent sur le Calendrier :
 * un côté extérieur exige l'interrupteur Extérieurs (`away`), un type hors de la sélection
 * effective exige que sa puce soit cochée (`kinds` à ajouter). PUR : la page applique
 * (`setConsultAway`, `setConsultKinds` via `normalizeKinds`).
 */
export function revealPlan(fixtures: Fixture[], effectiveKinds: readonly Kind[], competitionsById: Map<string, Competition>): { away: boolean; kinds: Kind[] } {
  const active = new Set(effectiveKinds);
  let away = false;
  const add = new Set<Kind>();
  for (const fixture of fixtures) {
    if ("AWAY" === fixture.homeAway) {
      away = true;
    }
    const kind = competitionKind(fixture, competitionsById);
    if (!active.has(kind)) {
      add.add(kind);
    }
  }
  return { away, kinds: KINDS.filter((k) => add.has(k)) };
}

export interface HiddenWeekBreakdown {
  /** Total masqué (extérieurs + types), pour la phrase « N matchs masqués ». */
  total: number;
  /** Extérieurs masqués par l'interrupteur (jamais ceux déjà retirés par leur type). */
  away: number;
  /** Masqués par un type décoché, par type (le type PRIME : un extérieur d'un type
   *  décoché compte UNE fois ici, pas dans `away`). */
  byKind: Map<Kind, number>;
}

/**
 * Rencontres de la semaine (déjà filtrées PR-1) retirées par les Types OU l'interrupteur
 * Extérieurs — l'indice « masqués ». Priorité au TYPE : un extérieur d'un type décoché
 * est compté une seule fois, côté type. PRÉSENTATION, aucune règle métier.
 */
export function hiddenWeekBreakdown(weekFixtures: Fixture[], effectiveKinds: readonly Kind[], showAway: boolean, competitionsById: Map<string, Competition>): HiddenWeekBreakdown {
  const active = new Set(effectiveKinds);
  const byKind = new Map<Kind, number>();
  let away = 0;
  for (const fixture of weekFixtures) {
    const kind = competitionKind(fixture, competitionsById);
    if (!active.has(kind)) {
      byKind.set(kind, (byKind.get(kind) ?? 0) + 1);
    } else if ("AWAY" === fixture.homeAway && !showAway) {
      away += 1;
    }
  }
  const total = away + [...byKind.values()].reduce((sum, n) => sum + n, 0);
  return { total, away, byKind };
}

/** Singulier/pluriel du décompte par type, pour la phrase de l'indice « masqués ». */
const KIND_COUNT_LABEL: Record<Kind, [string, string]> = {
  amical: ["amical", "amicaux"],
  championnat: ["championnat", "championnats"],
  coupe: ["coupe", "coupes"],
  brassage: ["brassage", "brassages"],
};

/**
 * Les segments « N extérieurs », « N amicaux »… d'un indice de masquage — extérieurs
 * d'abord, puis les types dans l'ordre de `KINDS`. PRÉSENTATION pure.
 */
export function hiddenBreakdownParts(breakdown: HiddenWeekBreakdown): string[] {
  const parts: string[] = [];
  if (breakdown.away > 0) {
    parts.push(`${breakdown.away} extérieur${breakdown.away > 1 ? "s" : ""}`);
  }
  for (const kind of KINDS) {
    const n = breakdown.byKind.get(kind) ?? 0;
    if (n > 0) {
      const [singular, plural] = KIND_COUNT_LABEL[kind];
      parts.push(`${n} ${n > 1 ? plural : singular}`);
    }
  }
  return parts;
}
