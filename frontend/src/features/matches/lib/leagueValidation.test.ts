import { describe, expect, it } from "vitest";

import type { LeagueToTreatFixture, MaturedCompetition } from "../api";
import { ENTRY_DEADLINES_PATH, REVIEW_QUEUE_ANCHOR, leagueValidationIntro, maturedLabel, reasonLabel, toTreatLabel } from "./leagueValidation";

describe("LeagueValidation — vocabulaire piloté par le démarrage du championnat (lot O)", () => {
  it("l'intro annonce le total ET ce que la validation change, accordée", () => {
    const one = leagueValidationIntro(1);
    expect(one).toMatch(/^1 rencontre de championnats commencés porte déjà/);
    expect(one).toMatch(/ancres/);
    expect(one).toMatch(/Refuser ne change rien/);
    expect(leagueValidationIntro(3)).toMatch(/^3 rencontres de championnats commencés portent déjà/);
  });

  it("championnat échu → le libellé porte son nom, son ÉCHÉANCE et son compte", () => {
    const label = maturedLabel({ competitionId: "c1", name: "PNM", deadline: "2026-11-10", deadlineSource: "club", maturedBy: "deadline", firstMatchDate: null, validatableCount: 12 });
    expect(label).toMatch(/^PNM — échéance /);
    expect(label).toMatch(/nov\./);
    expect(label).toMatch(/— 12 à valider$/);
  });

  it("championnat commencé par premier match joué → le libellé porte « 1er match joué le … », pas d'échéance", () => {
    const competition: MaturedCompetition = { competitionId: "c2", name: "RF3", deadline: null, deadlineSource: "club", maturedBy: "firstMatchPlayed", firstMatchDate: "2026-09-19", validatableCount: 4 };
    const label = maturedLabel(competition);
    expect(label).toMatch(/^RF3 — 1er match joué le /);
    expect(label).toMatch(/sept\./);
    expect(label).not.toMatch(/échéance/);
    expect(label).toMatch(/— 4 à valider$/);
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
