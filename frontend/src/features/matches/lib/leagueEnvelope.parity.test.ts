import { describe, expect, it } from "vitest";

import { kickoffInsideLeagueWindow } from "./envelope";
import cases from "./leagueEnvelope.parity.json";

/**
 * FRT-32 — CÔTÉ FRONT de la parité mécanique du prédicat d'enveloppe ligue. Le MÊME fichier
 * de cas alimente `LeagueEnvelopeMirrorParityTest.php` (backend,
 * `MatchConflictDetector::kickoffInsideLeagueWindow`). Changer l'algèbre d'appartenance d'un
 * seul côté (p. ex. rendre une borne exclusive) rougit ce côté-là.
 */
describe("kickoffInsideLeagueWindow — parité mécanique avec MatchConflictDetector (PHP)", () => {
  for (const c of cases.cases) {
    it(c.name, () => {
      expect(kickoffInsideLeagueWindow(c.day, c.kickoff, c.windows)).toBe(c.inside);
    });
  }
});
