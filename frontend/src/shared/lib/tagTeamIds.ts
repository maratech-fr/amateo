/**
 * tag NAME → les équipes qui le portent dans la saison de travail. Miroir CLIENT de
 * `TeamTagResolver::tagTeamIds` (backend) : on résout un `name` (via `TeamTag`) en la
 * liste des `TeamTagAssignment.teamId`. Les assignations lues par le front sont DÉJÀ
 * filtrées à la saison courante côté serveur (filtre Doctrine de saison — cf. entité
 * `TeamTagAssignment`).
 *
 * ⚠ Un tag SANS aucune assignation n'apparaît PAS dans la map (pas de clé) : la résolution
 * est alors « aucune équipe », comme le NO-OP du backend (`ScheduleConstraintBuilder` saute
 * la contrainte quand `resolveTagToTeamIds` rend une liste vide).
 *
 * FOYER UNIQUE (P4-88) : cette résolution existait en DEUX exemplaires côté front —
 * `applicableConstraints.buildTagTeamIds` (panneau planning) et une copie inline dans
 * `wizard/steps/PeriodConstraints.tsx` (masquage d'une CLUB+tag sans équipe active),
 * commentée « mirrored server-side » sans garde. Deux copies du même calcul = la dérive
 * garantie ; elle ne vit plus qu'ici. `PeriodConstraints` compose PAR-DESSUS un filtre
 * « équipes actives cette période » — un raffinement d'overlay, pas une seconde résolution.
 *
 * P2-29 (lot tags PR 3) — une contrainte CLUB peut cibler PLUSIEURS tags (`targetTags`,
 * INTERSECTION) ou en exclure (`excludeTags`, union soustraite) ; le legacy `targetTag`
 * singulier vaut `targetTags: [x]`. La résolution FINALE « (∩ targetTags) − (∪ excludeTags) »
 * ({@link resolveConstraintTeamIds}) repose sur le foyer pur {@link intersectMinusExclude},
 * miroir de `TeamTagResolver::intersectMinusExclude`. Les clés de config sont lues par
 * {@link targetTagNames} / {@link excludeTagNames} / {@link targetsTags}, miroirs des statiques
 * PHP du même nom (le legacy équivaut au pluriel des deux côtés).
 *
 * ⚠️ MIROIR DÉCLARÉ (régime 2) — parité MÉCANIQUE avec `App\Service\TeamTagResolver`
 * (`teamIdsByTagName` pour le groupement, `intersectMinusExclude` pour l'algèbre d'ensembles),
 * cas partagés `tagTeamIds.parity.json`, gardée par `TagTeamIdsMirrorParityTest`. Ce foyer, et
 * les modules qui le consomment (applicableConstraints, PeriodConstraints), figurent au registre
 * `FrontRederivationRegistryTest`.
 */
export function buildTagTeamIds(
  tags: readonly { id: string; name: string }[],
  assignments: readonly { teamId: string; tagId: string }[],
): Map<string, Set<string>> {
  const nameByTagId = new Map(tags.map((t) => [t.id, t.name]));
  const map = new Map<string, Set<string>>();
  for (const assignment of assignments) {
    const name = nameByTagId.get(assignment.tagId);
    if (undefined === name) {
      continue;
    }
    const set = map.get(name) ?? new Set<string>();
    set.add(assignment.teamId);
    map.set(name, set);
  }

  return map;
}

/**
 * Une liste de libellés de tags NON vides, normalisée depuis une valeur brute de config
 * (ignore ce qui n'est pas une chaîne non vide) — miroir de `TeamTagResolver::tagList`.
 */
function tagList(value: unknown): string[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return value.filter((item): item is string => "string" === typeof item && "" !== item.trim());
}

/** Le `targetTag` singulier (legacy) s'il est une chaîne non vide — miroir de `singularTag`. */
function singularTag(config: Record<string, unknown>): string | null {
  const tag = config.targetTag;

  return "string" === typeof tag && "" !== tag.trim() ? tag : null;
}

/**
 * Les noms de tags de CIBLE d'un config : `targetTags` s'il est non vide, sinon le
 * `targetTag` singulier (legacy, ≡ `targetTags: [x]`), sinon `[]`. Miroir de
 * `TeamTagResolver::targetTagNames`.
 */
export function targetTagNames(config: Record<string, unknown>): string[] {
  const plural = tagList(config.targetTags);
  if (plural.length > 0) {
    return plural;
  }
  const singular = singularTag(config);

  return null !== singular ? [singular] : [];
}

/** Les noms de tags EXCLUS d'un config (union soustraite) — miroir de `excludeTagNames`. */
export function excludeTagNames(config: Record<string, unknown>): string[] {
  return tagList(config.excludeTags);
}

/**
 * Ce config cible-t-il un ou plusieurs groupes (tags) ou en exclut-il ? Miroir de
 * `TeamTagResolver::targetsTags` — vrai dès qu'une cible OU une exclusion est posée.
 */
export function targetsTags(config: Record<string, unknown>): boolean {
  return targetTagNames(config).length > 0 || excludeTagNames(config).length > 0;
}

/**
 * Le foyer pur « (∩ targetSets) − (∪ excludeSets) » — miroir MÉCANIQUE de
 * `TeamTagResolver::intersectMinusExclude` (cas partagés `tagTeamIds.parity.json`). Le tri
 * final fait partie du contrat côté backend (il ordonne l'expansion par équipe du payload) ;
 * on le reproduit pour que la parité tienne.
 */
export function intersectMinusExclude(
  targetSets: readonly (readonly string[])[],
  excludeSets: readonly (readonly string[])[],
): string[] {
  const [first = [], ...rest] = targetSets;
  let intersection = new Set<string>(first);
  for (const set of rest) {
    const other = new Set(set);
    intersection = new Set([...intersection].filter((id) => other.has(id)));
  }

  const excluded = new Set<string>();
  for (const set of excludeSets) {
    for (const id of set) {
      excluded.add(id);
    }
  }

  const result = [...intersection].filter((id) => !excluded.has(id));
  result.sort();

  return result;
}

/**
 * L'ensemble d'équipes FINAL que vise une contrainte CLUB ciblée par tag(s) :
 * « (∩ targetTags) − (∪ excludeTags) », le legacy `targetTag` valant `targetTags: [x]`.
 * Exclusion sans cible → base = toutes les équipes fournies (`seasonTeamIds`), moins les
 * exclus (D8). Miroir de `TeamTagResolver::resolveConstraintTeamIds` : chaque tag est
 * résolu par la map `tagTeamIds` (foyer {@link buildTagTeamIds}), puis passé au foyer pur
 * {@link intersectMinusExclude}.
 */
export function resolveConstraintTeamIds(
  config: Record<string, unknown>,
  tagTeamIds: ReadonlyMap<string, ReadonlySet<string>>,
  seasonTeamIds: readonly string[],
): string[] {
  const targets = targetTagNames(config);
  const excludes = excludeTagNames(config);
  const resolve = (name: string): string[] => [...(tagTeamIds.get(name) ?? [])];
  const targetSets = targets.length > 0 ? targets.map(resolve) : [[...new Set(seasonTeamIds)]];
  const excludeSets = excludes.map(resolve);

  return intersectMinusExclude(targetSets, excludeSets);
}
