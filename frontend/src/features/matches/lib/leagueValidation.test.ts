import { describe, expect, it } from "vitest";

import type { LeagueToTreatFixture } from "../api";
import { ENTRY_DEADLINES_PATH, REVIEW_QUEUE_ANCHOR, leagueValidationIntro, maturedLabel, reasonLabel, toTreatLabel } from "./leagueValidation";

describe("LeagueValidation — vocabulaire piloté par l'échéance (lot O)", () => {
  it("l'intro annonce le total ET ce que la validation change, accordée", () => {
    const one = leagueValidationIntro(1);
    expect(one).toMatch(/^1 rencontre de championnats échus porte déjà/);
    expect(one).toMatch(/ancres/);
    expect(one).toMatch(/Refuser ne change rien/);
    expect(leagueValidationIntro(3)).toMatch(/^3 rencontres de championnats échus portent déjà/);
  });

  it("le libellé d'un championnat échu porte son nom, son échéance et son compte", () => {
    const label = maturedLabel("PNM", "2026-11-10", 12);
    expect(label).toMatch(/^PNM — échéance /);
    expect(label).toMatch(/nov\./);
    expect(label).toMatch(/— 12 à valider$/);
  });

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
  });

  it("le renvoi « Échéances de saisie » est un deep-link ?section=echeances, la file une ancre nommée", () => {
    expect(ENTRY_DEADLINES_PATH).toBe("/matchs/configuration?section=echeances");
    expect(REVIEW_QUEUE_ANCHOR).toBe("file-traitement");
  });
});
