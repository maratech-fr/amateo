import { intersectMinusExclude, targetsTags, targetTagNames, excludeTagNames } from "@/shared/lib/tagTeamIds";

import type { Constraint, Slot } from "../api";
// FOYER UNIQUE de la résolution tag→équipes (P4-88) : partagé avec `wizard/steps/PeriodConstraints.tsx`.
// Ré-exporté ici pour les consommateurs planning (aucun changement d'import chez eux).
export { buildTagTeamIds } from "@/shared/lib/tagTeamIds";

/**
 * La contrainte concerne-t-elle le club ENTIER (et non un sous-ensemble d'équipes) ? Vrai
 * seulement pour une CLUB SANS aucune clé de tag : dès qu'un tag est ciblé OU exclu (P2-29 :
 * `targetTag` legacy, `targetTags`, `excludeTags`), le backend l'éclate en contraintes TEAM —
 * elle concerne alors les équipes résolues, pas tout le club.
 */
export function isClubWide(constraint: Constraint): boolean {
  return "CLUB" === constraint.scope && !targetsTags(constraint.config);
}

/**
 * Les contraintes du club qui S'APPLIQUENT à un créneau, composées côté client depuis
 * `GET /api/constraints` (F1) — aucun champ calculé serveur. Une contrainte s'applique selon
 * son scope : CLUB partout SAUF si elle cible un tag (alors seulement les équipes taguées,
 * miroir de l'éclatement backend en contraintes TEAM), TEAM à son équipe, FACILITY à son
 * gymnase, COACH à son coach (et jamais si le créneau n'a pas de coach). Les contraintes
 * inactives sont écartées.
 *
 * `tagTeamIds` = la résolution tag→équipes (cf. `buildTagTeamIds`). Absente ou incomplète
 * (données pas encore lues, tag introuvable, tag sans équipe) → une CLUB+tag ne s'affiche
 * NULLE PART : sur-afficher serait re-mentir sur ce que le solveur applique.
 *
 * ⚠️ MIROIR DÉCLARÉ (régime 2, P4-88) — le `switch (scope)` d'`applies` reflète l'expansion
 * du payload par `App\Service\ScheduleConstraintBuilder` (une CLUB ciblant un ou des tags est
 * ÉCLATÉE en N contraintes TEAM par équipe RÉSOLUE ; TEAM→son équipe, FACILITY→son gymnase,
 * COACH→son coach). C'est la redérivation qui a ouvert P4-88 (le `case "CLUB": return true`
 * d'origine ignorait `targetTag`). P2-29 : la cible peut désormais être « (∩ targetTags) −
 * (∪ excludeTags) » — on passe par le foyer `resolveConstraintTeamIds`/`intersectMinusExclude`
 * (`@/shared/lib/tagTeamIds`), pinné mécaniquement par `TagTeamIdsMirrorParityTest` (foyer
 * partagé) ; la portée d'un tag l'est par `TeamTagScopeTest` (blocking). Ce module figure au
 * registre `FrontRederivationRegistryTest`.
 */
export function applicableConstraints(
  slot: Slot,
  constraints: Constraint[],
  tagTeamIds: ReadonlyMap<string, ReadonlySet<string>> = new Map(),
): Constraint[] {
  return constraints.filter((c) => c.isActive && applies(c, slot, tagTeamIds));
}

function applies(constraint: Constraint, slot: Slot, tagTeamIds: ReadonlyMap<string, ReadonlySet<string>>): boolean {
  switch (constraint.scope) {
    case "CLUB": {
      const targets = targetTagNames(constraint.config);
      const excludes = excludeTagNames(constraint.config);
      if (0 === targets.length && 0 === excludes.length) {
        return true; // CLUB nu : concerne vraiment tout le club
      }
      // Exclusion SANS cible (D8, base = toutes les équipes de la saison) : non produite par
      // ce wizard (le raffinage exige un tag cible). Sans le référentiel des équipes de la
      // saison ici, on la traite comme club-wide — comportement conservé.
      if (0 === targets.length) {
        return true;
      }
      const resolve = (name: string): string[] => [...(tagTeamIds.get(name) ?? [])];

      return intersectMinusExclude(targets.map(resolve), excludes.map(resolve)).includes(slot.teamId);
    }
    case "TEAM":
      return constraint.scopeTargetId === slot.teamId;
    case "FACILITY":
      return constraint.scopeTargetId === slot.venueId;
    case "COACH":
      return null !== slot.coachId && constraint.scopeTargetId === slot.coachId;
    default:
      return false;
  }
}
