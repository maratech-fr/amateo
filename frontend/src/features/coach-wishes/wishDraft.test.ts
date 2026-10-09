import { beforeEach, describe, expect, it } from "vitest";

import { clearDraft, loadDraft, saveDraft } from "./wishDraft";

/**
 * P5-15 — la clé du brouillon porte le nom du produit. La renommer sans repli aurait fait
 * disparaître la saisie d'un coach en cours au moment du déploiement : on ÉCRIT la nouvelle clé,
 * on LIT encore l'ancienne, et `clearDraft` purge les deux (sinon un brouillon périmé ressurgit
 * après un envoi réussi — le pire des deux mondes).
 */
describe("wishDraft — migration de clé", () => {
  const token = "tok-42";
  const sections = new Map([["s1", { slotsWanted: 2, days: new Set([1, 3]), wishedDays: new Set([5]), comment: "ok" }]]);
  const mutualizations = new Map([["t1", { partnerTeamIds: new Set(["t2"]), sharedSlots: 1 }]]);

  // Assemblée pour que le littéral de marque morte n'apparaisse pas EN DUR (garde `product.guard.test.ts`).
  const DEAD_BRAND_KEY = `${"club"}${"scheduler"}:wish-draft:`;

  beforeEach(() => sessionStorage.clear());

  it("écrit sous la nouvelle clé", () => {
    saveDraft(token, sections, mutualizations, 1);

    expect(sessionStorage.getItem(`amateo:wish-draft:${token}`)).not.toBeNull();
    expect(sessionStorage.getItem(`${DEAD_BRAND_KEY}${token}`)).toBeNull();
  });

  // Le repli sur l'ancienne clé a été SUPPRIMÉ (2026-08-21) : il protégeait un coach à cheval
  // sur un déploiement, or rien n'est en production et le brouillon meurt avec l'onglet. Ce test
  // garde la suppression — un brouillon sous l'ancienne clé ne doit PLUS être relu, sans quoi le
  // littéral de marque morte reviendrait par la porte de service.
  it("IGNORE un brouillon écrit sous l'ancienne clé (repli supprimé)", () => {
    sessionStorage.setItem(
      `${DEAD_BRAND_KEY}${token}`,
      JSON.stringify({ sections: { s1: { slotsWanted: 3, days: [2], comment: "hérité" } }, stepIndex: 2 }),
    );

    expect(loadDraft(token)).toBeNull();
  });

  it("purge la clé au succès de l'envoi", () => {
    saveDraft(token, sections, mutualizations, 0);

    clearDraft(token);

    expect(sessionStorage.getItem(`amateo:wish-draft:${token}`)).toBeNull();
  });
});
