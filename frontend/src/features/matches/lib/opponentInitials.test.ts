import { describe, expect, it } from "vitest";

import { opponentInitials } from "./opponentInitials";

describe("opponentInitials", () => {
  // Table de cas — l'accent est conservé, les mots génériques retirés.
  it.each([
    ["BC Lyon", "LY"], // BC générique → « Lyon » seul → 2 premières
    ["Bron Basket Club", "BR"], // Basket + Club génériques → « Bron » seul
    ["AS Villeurbanne Basket", "VI"], // AS + Basket génériques → « Villeurbanne » seul
    ["Union Sportive Meyzieu", "ME"], // Union + Sportive génériques → « Meyzieu » seul
    ["Lyon Métropole", "LM"], // deux mots réels → 1ʳᵉ lettre de chacun
    ["École de Basket", "ÉD"], // Basket générique → « École » + « de », accent conservé
    ["Grenoble", "GR"], // un seul mot → ses 2 premières
    ["AS BB", "AS"], // que des génériques → repli sur le libellé brut
  ])("« %s » → « %s »", (label, expected) => {
    expect(opponentInitials(label)).toBe(expected);
  });
});
