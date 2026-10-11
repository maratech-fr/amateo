import { describe, expect, it } from "vitest";

import type { Slot, VenueTrainingSlot } from "../api";

import cases from "./emptySlots.parity.json";
import { computeEmptySlots } from "./emptySlots";

/**
 * CÔTÉ FRONT de la parité mécanique de la règle « un créneau de grille est-il OCCUPÉ ? »
 * (une séance du même gymnase+jour le CHEVAUCHE dans le temps). Le MÊME fichier de cas alimente
 * `EmptySlotOverlapMirrorParityTest.php` (backend, `App\Service\VenueWindowOccupancy::windowIsFilled`,
 * l'export PDF/XLSX). Chaque `expectedFilled` est le verdict attendu des deux implémentations :
 * changer la règle ici sans porter le cas partagé rougit ce test ; l'inverse rougit le test PHP.
 * Ce module figure au registre `FrontRederivationRegistryTest`.
 */
describe("computeEmptySlots — parité mécanique avec VenueWindowOccupancy (PHP)", () => {
  for (const c of cases.cases) {
    it(c.label, () => {
      const window: VenueTrainingSlot = { id: "w", venueId: c.window.venueId, dayOfWeek: c.window.dayOfWeek, startTime: c.window.startTime, durationMinutes: c.window.durationMinutes, capacity: 1 };
      const placements: Slot[] = c.placements.map((p, i) => ({ id: `p${i}`, scheduleId: "sc", teamId: `t${i}`, venueId: p.venueId, coachId: null, dayOfWeek: p.dayOfWeek, startTime: p.startTime, durationMinutes: p.durationMinutes, lockLevel: "NONE", lockOrigin: null, sharedTrainingBlockId: null }));

      // « filled » (occupé) ⇔ computeEmptySlots ne produit AUCUNE case vide pour cette fenêtre.
      const filled = 0 === computeEmptySlots([window], placements, "sc").length;
      expect(filled).toBe(c.expectedFilled);
    });
  }
});
