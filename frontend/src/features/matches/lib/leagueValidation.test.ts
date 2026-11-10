import { describe, expect, it } from "vitest";

import type { LeagueToTreatFixture } from "../api";
import { ENTRY_DEADLINES_PATH, REVIEW_QUEUE_ANCHOR, reasonLabel, toTreatLabel } from "./leagueValidation";

// Un championnat commencé passe SEUL « Déjà dans FBI » : plus de confirmation, donc plus ni
// intro chiffrée ni libellé de geste ni détail par championnat. Restent les mots de ce qui
// demeure À TRAITER (nommé, jamais tu) et les renvois.
describe("LeagueValidation — les mots de ce qui reste à traiter", () => {
  it("nomme chaque raison de non-validabilité en clair", () => {
    expect(reasonLabel("NO_KICKOFF")).toBe("sans heure");
    expect(reasonLabel("NO_VENUE")).toBe("sans gymnase");
    expect(reasonLabel("PENDING_DEVIATION")).toBe("écart en attente");
  });

  it("le libellé d'une rencontre à traiter la NOMME (date, championnat, adversaire, raison)", () => {
    const fixture: LeagueToTreatFixture = { fixtureId: "f1", teamId: "t1", competitionName: "PNM", matchDate: "2026-10-04", opponentLabel: "Adv", reason: "NO_VENUE" };
    const label = toTreatLabel(fixture);
    expect(label).toMatch(/PNM vs Adv/);
    expect(label).toMatch(/sans gymnase/);
    // Une date de RENCONTRE porte son jour (« sam. 4 oct. », jamais « 4 oct. » nu) — l'angle que
    // portait le libellé du premier match joué, retiré avec la confirmation.
    expect(label).toMatch(/^\p{L}+\.? 4 oct\./u);
  });

  it("le renvoi « Échéances de saisie » est un deep-link ?section=echeances, la file une ancre nommée", () => {
    expect(ENTRY_DEADLINES_PATH).toBe("/matchs/configuration?section=echeances");
    expect(REVIEW_QUEUE_ANCHOR).toBe("file-traitement");
  });
});
