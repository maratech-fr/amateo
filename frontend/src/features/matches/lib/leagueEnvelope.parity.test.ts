import { describe, expect, it } from "vitest";

import type { Fixture } from "../api";
import { CUP_KICKOFF_MAX, CUP_KICKOFF_MIN, cupKickoffInside, isCupFixture, isFriendlyFixture, kickoffInsideLeagueWindow } from "./envelope";
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

/**
 * Lot 7 PR 4a — un amical (et une coupe) a UNE définition à l'écran comme au serveur : la nature
 * de sa compétition (`fixture.competitionType`), jamais un `competitionId` nul ni le libellé. Le
 * MÊME fichier de cas (`natureCases`) alimente `LeagueEnvelopeMirrorParityTest.php` (backend,
 * `CompetitionType::isFriendly`) : lire la nature autrement d'un seul côté rougit ce côté-là.
 */
describe("isFriendlyFixture / isCupFixture — parité de nature avec CompetitionType (PHP)", () => {
  for (const c of cases.natureCases) {
    it(c.name, () => {
      const fixture = { id: "fx", teamId: "team-1", competitionId: c.competitionId, competitionType: c.competitionType } as unknown as Fixture;
      expect(isFriendlyFixture(fixture)).toBe(c.friendly);
      expect(isCupFixture(fixture)).toBe(c.cup);
    });
  }
});

/**
 * Lot 7 PR 4b — une coupe se joue entre 08:00 et 21:00, bornes comprises, quel que soit le jour. Le MÊME fichier
 * (`cupBounds`, `cupCases`) alimente `LeagueEnvelopeMirrorParityTest.php` (backend, `App\Matches\CupKickoffRule`) :
 * déplacer une borne, ou la rendre exclusive, d'un seul côté rougit ce côté-là.
 */
describe("cupKickoffInside — parité de la règle des coupes avec CupKickoffRule (PHP)", () => {
  it("porte les mêmes bornes que le serveur", () => {
    expect({ kickoffMin: CUP_KICKOFF_MIN, kickoffMax: CUP_KICKOFF_MAX }).toEqual(cases.cupBounds);
  });
  for (const c of cases.cupCases) {
    it(c.name, () => {
      expect(cupKickoffInside(c.kickoff)).toBe(c.inside);
    });
  }
});
