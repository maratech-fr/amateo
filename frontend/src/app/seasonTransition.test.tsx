import { describe, expect, it } from "vitest";

import { seasonPrepWindow } from "./seasonTransition";

// `.test.tsx` (projet `ecran`) : `seasonTransition.ts` réexporte `shared/lib/clock`, qui teste la
// présence du navigateur — en node, la branche « pas de navigateur » répondrait à vide.

const current2526 = { startDate: "2025-08-01", endDate: "2026-07-15", isCurrent: true };

/**
 * La fenêtre « préparer la saison suivante » s'ouvre à la date SERVIE par `/api/me`
 * (`seasonPreparationOpensOn`, le serveur seul tient la borne basse) et se ferme, à l'écran
 * seulement, à la fin de la saison en cours. Jumeau serveur : `features/la-saison-suivante-se-
 * prepare-a-partir-du-1er-mai.feature` (le refus du serveur avant la date).
 */
describe("seasonPrepWindow — ouverture à la date servie", () => {
  it.each([
    ["2026-04-30", false],
    ["2026-05-01", true],
    ["2026-07-14", true],
  ])("saison 2025-2026, ouverture servie le 2026-05-01 : le %s, fenêtre ouverte = %s", (today, open) => {
    expect(seasonPrepWindow(today, [current2526], "2026-05-01").inWindow).toBe(open);
  });

  it("suit la date servie, pas une date recalculée à l'écran", () => {
    expect(seasonPrepWindow("2026-05-05", [current2526], "2026-05-10").inWindow).toBe(false);
    expect(seasonPrepWindow("2026-05-10", [current2526], "2026-05-10").inWindow).toBe(true);
  });

  // La borne haute reste à l'écran : la fin réelle de la saison en cours.
  it("se ferme après la fin réelle de la saison en cours", () => {
    const endsEarly = { ...current2526, endDate: "2026-06-30" };
    expect(seasonPrepWindow("2026-06-30", [endsEarly], "2026-05-01").inWindow).toBe(true);
    expect(seasonPrepWindow("2026-07-01", [endsEarly], "2026-05-01").inWindow).toBe(false);
  });

  // Club en retard : rien préparé avant le 15 juillet 2026 → le serveur annonce le 1er mai 2027.
  it("un club en retard attend le 1er mai suivant, servi par le serveur", () => {
    expect(seasonPrepWindow("2026-07-20", [current2526], "2027-05-01").inWindow).toBe(false);
    expect(seasonPrepWindow("2027-04-30", [current2526], "2027-05-01").inWindow).toBe(false);
    expect(seasonPrepWindow("2027-05-01", [current2526], "2027-05-01").inWindow).toBe(true);
  });

  it("signale un successeur déjà préparé sans fermer la fenêtre", () => {
    const next = { startDate: "2026-08-01", endDate: "2027-07-15", isCurrent: false };
    expect(seasonPrepWindow("2026-06-01", [current2526, next], "2026-05-01")).toMatchObject({ inWindow: true, successorExists: true });
  });
});
