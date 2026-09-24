import { describe, expect, it } from "vitest";

import { buildTagTeamIds, excludeTagNames, intersectMinusExclude, targetTagNames } from "./tagTeamIds";
import cases from "./tagTeamIds.parity.json";

/**
 * P4-88 — CÔTÉ FRONT de la parité mécanique du groupement tag → équipes. Le MÊME fichier
 * de cas alimente `TagTeamIdsMirrorParityTest.php` (backend,
 * `TeamTagResolver::teamIdsByTagName`). Changer le groupement d'un seul côté rougit ce
 * côté-là. Le foyer est partagé par `applicableConstraints` et `PeriodConstraints`.
 */
describe("buildTagTeamIds — parité mécanique avec TeamTagResolver::teamIdsByTagName (PHP)", () => {
  for (const c of cases.cases) {
    it(c.name, () => {
      const map = buildTagTeamIds(c.tags, c.assignments);
      const actual: Record<string, string[]> = {};
      for (const [name, ids] of map) {
        actual[name] = [...ids].sort();
      }
      const expected: Record<string, string[]> = {};
      for (const [name, ids] of Object.entries(c.expected)) {
        expected[name] = [...(ids as string[])].sort();
      }
      expect(actual).toEqual(expected);
    });
  }
});

/**
 * P2-29 (lot tags PR 3) — la lecture des clés de ciblage d'un config : `targetTag` legacy ≡
 * `targetTags:[x]`, `excludeTags` en union soustraite. Miroir de
 * `TeamTagResolver::targetTagNames` / `excludeTagNames` (mêmes cas partagés).
 */
describe("targetTagNames / excludeTagNames — parité avec TeamTagResolver (PHP)", () => {
  for (const c of cases.tagNameCases) {
    it(c.name, () => {
      expect(targetTagNames(c.config)).toEqual(c.expectedTargets);
      expect(excludeTagNames(c.config)).toEqual(c.expectedExcludes);
    });
  }
});

/**
 * P2-29 (lot tags PR 3) — l'algèbre « (∩ targetSets) − (∪ excludeSets) », foyer pur miroir de
 * `TeamTagResolver::intersectMinusExclude` (mêmes cas partagés). Le tri final fait partie du
 * contrat (il ordonne l'expansion par équipe du payload backend).
 */
describe("intersectMinusExclude — parité avec TeamTagResolver::intersectMinusExclude (PHP)", () => {
  for (const c of cases.intersectCases) {
    it(c.name, () => {
      expect(intersectMinusExclude(c.targetSets, c.excludeSets)).toEqual([...c.expected].sort());
    });
  }
});
