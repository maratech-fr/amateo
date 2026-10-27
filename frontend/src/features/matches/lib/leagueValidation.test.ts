import { describe, expect, it } from "vitest";

import type { LeagueToTreatFixture, MaturedCompetition } from "../api";
import { ENTRY_DEADLINES_PATH, LEAGUE_VALIDATION_CONFIRM_LABEL, REVIEW_QUEUE_ANCHOR, leagueValidationIntro, maturedLabel, reasonLabel, toTreatLabel } from "./leagueValidation";

describe("LeagueValidation — vocabulaire piloté par le démarrage du championnat (lot O)", () => {
  it("l'intro annonce le total ET ce que la validation change, accordée", () => {
    const one = leagueValidationIntro(1);
    expect(one).toMatch(/^1 rencontre de championnats commencés porte déjà/);
    expect(one).toMatch(/ancres/);
    expect(one).toMatch(/Refuser ne change rien/);
    expect(leagueValidationIntro(3)).toMatch(/^3 rencontres de championnats commencés portent déjà/);
  });

  // Retours Nico (BCCL, 2026-10-27) — le rattrapage ne dit plus « validé ligue » (jargon fédéral
  // que même le fondateur a dû faire expliquer) : « Marquer « déjà dans FBI » ». Même mot que la
  // table d'états (VALIDATED = « Déjà dans FBI »).
  it("le libellé de confirmation dit « déjà dans FBI », jamais « validé ligue »", () => {
    expect(LEAGUE_VALIDATION_CONFIRM_LABEL).toBe("Marquer « déjà dans FBI »");
    expect(LEAGUE_VALIDATION_CONFIRM_LABEL).not.toMatch(/validé ligue/i);
  });

  // M3 — l'intro chiffrée renonce elle aussi à « validé ligue » (le verrouillage se dit « déjà
  // dans FBI ») ; le reste de son sens (ancres, « refuser ne change rien ») est inchangé.
  it("l'intro renonce à « validé ligue » au profit de « déjà dans FBI »", () => {
    const intro = leagueValidationIntro(2);
    expect(intro).not.toMatch(/validé ligue/i);
    expect(intro).toMatch(/déjà dans FBI/);
  });

  it("championnat échu → le libellé porte son nom, son ÉCHÉANCE et son compte", () => {
    const label = maturedLabel({ competitionId: "c1", name: "PNM", deadline: "2026-11-10", deadlineSource: "club", maturedBy: "deadline", firstMatchDate: null, validatableCount: 12 });
    expect(label).toMatch(/^PNM — échéance /);
    expect(label).toMatch(/nov\./);
    // Arbitrage fondateur 2026-10-27 (retours Nico, source §64) : « — N à valider » →
    // « — N déjà dans FBI à confirmer » (cohérent avec le wording cockpit validé M3). ROUGE tant
    // que leagueValidation.ts:52 n'a pas appliqué la prod (TANK est propriétaire de l'assertion).
    expect(label).toMatch(/— 12 déjà dans FBI à confirmer$/);
  });

  it("championnat commencé par premier match joué → le libellé porte « 1er match joué le … », pas d'échéance", () => {
    const competition: MaturedCompetition = { competitionId: "c2", name: "RF3", deadline: null, deadlineSource: "club", maturedBy: "firstMatchPlayed", firstMatchDate: "2026-09-19", validatableCount: 4 };
    const label = maturedLabel(competition);
    expect(label).toMatch(/^RF3 — 1er match joué le /);
    expect(label).toMatch(/sept\./);
    expect(label).not.toMatch(/échéance/);
    // Même arbitrage que ci-dessus (source §64) — ROUGE en attente de leagueValidation.ts:52.
    expect(label).toMatch(/— 4 déjà dans FBI à confirmer$/);
  });

  // M6 (retours Nico, BCCL 2026-10-27) — une date de RENCONTRE porte son JOUR (« sam. 19 sept. »,
  // pas « 19 sept. ») : le premier match joué EST une rencontre. `frDateShortNoYear` →
  // `frDateWeekdayNoYear` sur cette borne. (L'échéance de saisie, elle, n'est PAS une rencontre —
  // voir le rapport TANK : son jour reste à trancher, non figé ici.)
  it("la date du premier match joué (une rencontre) porte son jour de la semaine", () => {
    const competition: MaturedCompetition = { competitionId: "c2", name: "RF3", deadline: null, deadlineSource: "club", maturedBy: "firstMatchPlayed", firstMatchDate: "2026-09-19", validatableCount: 4 };
    // Un token de jour (lettres + point optionnel) précède « 19 sept. » — « 19 sept. » nu échoue.
    expect(maturedLabel(competition)).toMatch(/1er match joué le \p{L}+\.? 19 sept\./u);
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
