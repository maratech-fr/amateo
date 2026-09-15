import { describe, expect, it } from "vitest";

import { clubMatchesQuery, queryTokens, textMatchesQuery } from "./opponentSearch";

describe("opponentSearch (PR 2a — recherche d'un adversaire)", () => {
  it("queryTokens : découpe, minuscule, sans accents, sans vides", () => {
    expect(queryTokens("  Éléphant  BLEU ")).toEqual(["elephant", "bleu"]);
    expect(queryTokens("   ")).toEqual([]);
  });

  it("textMatchesQuery : insensible aux accents et à la casse", () => {
    expect(textMatchesQuery("Meyzieu Basket", queryTokens("meyzieu"))).toBe(true);
    expect(textMatchesQuery("VÉNISSIEUX", queryTokens("venissieux"))).toBe(true);
  });

  it("textMatchesQuery : tokens en ET — CHAQUE token doit apparaître", () => {
    expect(textMatchesQuery("Basket Ball 5eme", queryTokens("basket 5eme"))).toBe(true);
    expect(textMatchesQuery("Basket Ball 5eme", queryTokens("basket rugby"))).toBe(false);
  });

  it("requête vide ⇒ tout passe", () => {
    expect(textMatchesQuery("n'importe quoi", queryTokens(""))).toBe(true);
    expect(clubMatchesQuery("Club X", ["Club X - 1"], queryTokens(""))).toBe(true);
  });

  it("clubMatchesQuery : conservé si le LIBELLÉ DU CLUB matche (groupe entier)", () => {
    expect(clubMatchesQuery("Basket Ball 5eme", ["Basket Ball 5eme - 1", "Basket Ball 5eme - 2"], queryTokens("5eme"))).toBe(true);
  });

  it("clubMatchesQuery : conservé si UNE équipe matche, même si le libellé club non", () => {
    // Le libellé du club « Alliance » ne matche pas « seniors », mais une équipe si.
    expect(clubMatchesQuery("Alliance", ["Alliance Seniors", "Alliance U15"], queryTokens("seniors"))).toBe(true);
  });

  it("clubMatchesQuery : rejeté si ni le club ni aucune équipe ne matche", () => {
    expect(clubMatchesQuery("Alliance", ["Alliance Seniors"], queryTokens("meyzieu"))).toBe(false);
  });
});
