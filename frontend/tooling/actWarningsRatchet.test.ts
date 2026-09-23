import { describe, expect, it } from "vitest";

import { actWarningsVerdict, isActWarning } from "./actWarningsRatchet";

/**
 * FRT-34 — cœur PUR du cliquet des avertissements React « not wrapped in act ».
 * Le branchement Vitest (comptage réel via `onUserConsoleLog`, code retour) est exercé de
 * bout en bout par la falsification manuelle décrite dans la PR ; ici on garde la LOGIQUE :
 * détection du motif, comparaison au plafond, fil de détente, et le régime « run partiel »
 * (indicatif seulement — le verdict et l'invitation ne valent que sur une suite complète).
 */
describe("isActWarning — détection du motif", () => {
  it("reconnaît le message React exact", () => {
    expect(
      isActWarning("An update to Foo inside a test was not wrapped in act(...)."),
    ).toBe(true);
  });

  it("ignore une ligne de console quelconque", () => {
    expect(isActWarning("Warning: validateDOMNesting(...): <div> cannot appear")).toBe(false);
    expect(isActWarning("")).toBe(false);
  });
});

describe("actWarningsVerdict — comparaison au plafond", () => {
  it("ROUGE quand le compte dépasse le plafond, en nommant le delta", () => {
    const v = actWarningsVerdict(135, 134);
    expect(v.ok).toBe(false);
    expect(v.message).toContain("135");
    expect(v.message).toContain("+1");
  });

  it("vert au plafond exact", () => {
    expect(actWarningsVerdict(134, 134).ok).toBe(true);
  });

  it("vert sous le plafond, avec invitation à l'abaisser", () => {
    const v = actWarningsVerdict(31, 134);
    expect(v.ok).toBe(true);
    expect(v.message).toContain("abaisse le plafond à 31");
  });
});

describe("actWarningsVerdict — fil de détente (capture cassée)", () => {
  it("ROUGE quand le compte tombe à 0 alors que le plafond est > 0", () => {
    // Sans ce fil, le jour où la capture casse (motif changé, API onUserConsoleLog rompue),
    // le compte tombe à 0, le cliquet se croit vert et meurt en silence.
    const v = actWarningsVerdict(0, 134);
    expect(v.ok).toBe(false);
    expect(v.message).toMatch(/cass/i);
  });

  it("mais 0 est LÉGITIME quand le plafond est lui aussi 0 (cliquet soldé)", () => {
    expect(actWarningsVerdict(0, 0).ok).toBe(true);
  });

  it("le fil de détente NE tire QUE sur une suite complète (pas sur un partiel à 0)", () => {
    // Un fichier propre joué seul rend 0 : c'est LÉGITIME, pas une capture cassée. Le fil de
    // détente ne doit se déclencher que quand la suite ENTIÈRE rend 0 face à un plafond > 0.
    expect(actWarningsVerdict(0, 134, true).ok).toBe(false);
    expect(actWarningsVerdict(0, 134, false).ok).toBe(true);
  });
});

describe("actWarningsVerdict — run PARTIEL (filtré) : indicatif, jamais de verdict", () => {
  it("un fichier propre joué seul (compte 0) NE rougit PAS et n'annonce aucune panne", () => {
    // Reproduction #1 du défaut : `vitest run clock.test.ts` sortait en ERREUR « capture cassée ».
    const v = actWarningsVerdict(0, 30, false);
    expect(v.ok).toBe(true);
    expect(v.message).not.toMatch(/cass/i);
    expect(v.message).toMatch(/partiel/i);
  });

  it("un fichier qui émet des avertissements N'INVITE PAS à abaisser le plafond", () => {
    // Reproduction #2 du défaut : `vitest run AuthGuard.test.tsx` conseillait « abaisse le
    // plafond à 1 » — un ordre qui rougirait la CI pour toujours.
    const v = actWarningsVerdict(1, 30, false);
    expect(v.ok).toBe(true);
    expect(v.message).not.toContain("abaisse le plafond");
    expect(v.message).toMatch(/indicatif/i);
  });

  it("même au-dessus du plafond, un partiel ne tranche pas rouge", () => {
    // Un sous-ensemble peut concentrer beaucoup d'avertissements sans que la suite entière
    // dépasse : un partiel ne doit jamais rougir.
    expect(actWarningsVerdict(999, 30, false).ok).toBe(true);
  });
});
