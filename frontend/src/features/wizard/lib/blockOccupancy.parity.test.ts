import { describe, expect, it } from "vitest";

import { completeBlockOnCase } from "./blockOccupancy";
import cases from "./blockOccupancy.parity.json";

/**
 * #12 — CÔTÉ FRONT de la parité mécanique de « cette case est-elle BLOC-COMPLÈTE ? ».
 * Le MÊME fichier de cas (`blockOccupancy.parity.json`) alimente
 * `ReservationBlockOccupancyMirrorParityTest.php` (backend, `ReservationGroupOccupancy::matchingBlock`,
 * règle (b)). `expectedCompleteBlockId` est LE verdict attendu des deux implémentations : changer la
 * règle ici sans porter le cas partagé rougit ce test ; l'inverse rougit le test PHP.
 *
 * Module au registre `FrontRederivationRegistryTest` (miroir déclaré + parité).
 */
describe("completeBlockOnCase — parité mécanique avec ReservationGroupOccupancy::matchingBlock (PHP)", () => {
  for (const c of cases.cases) {
    it(c.name, () => {
      const matched = completeBlockOnCase(c.reservedTeamIds, c.blocks);
      expect(matched?.id ?? null).toBe(c.expectedCompleteBlockId);
    });
  }
});
