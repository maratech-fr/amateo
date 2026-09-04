import { describe, expect, it } from "vitest";

import cases from "./holidayWorkweek.parity.json";
import { holidayCoversWorkweek } from "./holidayWorkweek";

/**
 * CÔTÉ FRONT de la parité mécanique de la règle « une semaine est-elle de vacances ? ».
 * Le MÊME fichier de cas alimente `HolidayWorkweekMirrorParityTest.php` (backend) : chaque
 * `expected` est le verdict attendu des deux implémentations. Changer la règle ici sans porter
 * le cas partagé rougit ce test ; l'inverse rougit le test PHP. Ce module figure au registre
 * `FrontRederivationRegistryTest`.
 */
describe("holidayCoversWorkweek — parité mécanique avec HolidayWorkweekRule (PHP)", () => {
  for (const c of cases.cases) {
    it(c.label, () => {
      expect(holidayCoversWorkweek(c.monday, c.holidayStart, c.holidayEnd, c.seasonStart, c.seasonEnd)).toBe(c.expected);
    });
  }
});
