import { describe, expect, it } from "vitest";

import cases from "./weekSegmentation.parity.json";
import { weekSegments } from "./weekSegmentation";

/**
 * CÔTÉ FRONT de la parité mécanique du découpage début·milieu·fin.
 * Le MÊME fichier de cas alimente `WeekSegmentationMirrorParityTest.php` (backend) : chaque
 * `expected` est le découpage attendu des deux implémentations. Changer la règle ici sans porter
 * le cas partagé rougit ce test ; l'inverse rougit le test PHP. Ce module figure au registre
 * `FrontRederivationRegistryTest`.
 */
describe("weekSegments — parité mécanique avec WeekSegmentationRule (PHP)", () => {
  for (const c of cases.cases) {
    it(c.label, () => {
      const actual = weekSegments(c.offered, c.eventStart, c.eventEnd).map((s) => ({
        monday: s.monday,
        startDate: s.startDate,
        endDate: s.endDate,
        kind: s.kind,
        weeks: s.weeks.map((w) => w.monday),
      }));
      expect(actual).toEqual(c.expected);
    });
  }
});
