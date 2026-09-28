import { describe, expect, it } from "vitest";

import { formatMinutes } from "@/shared/lib/time";

import { awayTimeline } from "./awayKickoff";
import cases from "./awayTimeline.parity.json";
import { timeToMinutes } from "./envelope";

/**
 * P4-240 ③ (décision C) — CÔTÉ FRONT de la parité mécanique de la fenêtre PERSONNE d'un
 * match à l'extérieur. Le MÊME fichier de cas alimente `AwayTimelineMirrorParityTest.php`
 * (backend, `MatchFootprint::personConflictOccupancy`, régime AWAY). `awayTimeline` est un
 * MIROIR DÉCLARÉ (régime 2, `FrontRederivationRegistryTest`) : retirer l'échauffement du
 * départ, ou l'un des allers, rougit ce côté-là.
 */
describe("awayTimeline — parité mécanique avec MatchFootprint (PHP), régime AWAY", () => {
  for (const c of cases.cases) {
    it(c.name, () => {
      const timeline = awayTimeline(timeToMinutes(c.kickoff), c.matchMinutes, c.warmupMinutes, c.oneWayMinutes);
      expect(formatMinutes(timeline.departureMin)).toBe(c.departure);
      expect(formatMinutes(timeline.matchEndMin)).toBe(c.matchEnd);
      expect(formatMinutes(timeline.returnMin)).toBe(c.return);
    });
  }
});
