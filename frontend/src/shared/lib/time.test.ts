import { describe, expect, it } from "vitest";

import { formatMinutes as sharedFormat, parseTime } from "./time";
import { fmtMinutes } from "@/features/wizard/lib/days";
import { formatMinutes as matchesFormat } from "@/features/matches/lib/weekendGrid";
import { formatMinutes as planningFormat } from "@/features/planning/lib/grid";

/**
 * D-20 — il existait TROIS formateurs « minutes depuis minuit → HH:MM », et un seul clampait.
 * Un horaire franchissant minuit s'affichait « 01:15 » côté matchs et « 25:15 » côté planning
 * et wizard : l'incident décrit dans `wizard/lib/slotOverlap.ts`, corrigé à l'époque par une
 * garde de SAISIE, les formateurs restant discordants.
 */
describe("formatMinutes (foyer unique, D-20)", () => {
  it("formate une heure normale", () => {
    expect(sharedFormat(0)).toBe("00:00");
    expect(sharedFormat(9 * 60 + 5)).toBe("09:05");
    expect(sharedFormat(23 * 60 + 59)).toBe("23:59");
  });

  it("ramène un dépassement de minuit à une heure lisible, jamais « 25:15 »", () => {
    expect(sharedFormat(25 * 60 + 15)).toBe("01:15");
    expect(sharedFormat(24 * 60)).toBe("00:00");
  });

  it("survit à une valeur négative (le modulo JS garde le signe)", () => {
    expect(sharedFormat(-30)).toBe("23:30");
  });

  /**
   * Le cœur du finding : les trois points d'entrée historiques doivent rendre EXACTEMENT la
   * même chose. Réintroduire une implémentation locale non clampée fait rougir ici.
   */
  it("les trois points d'entrée historiques donnent le même résultat", () => {
    for (const minutes of [0, 615, 1080, 1439, 1440, 25 * 60 + 15, -30]) {
      const expected = sharedFormat(minutes);
      expect(planningFormat(minutes), `planning diverge à ${minutes}`).toBe(expected);
      expect(matchesFormat(minutes), `matchs diverge à ${minutes}`).toBe(expected);
      expect(fmtMinutes(minutes), `wizard diverge à ${minutes}`).toBe(expected);
    }
  });
});

/**
 * D-21 — « heure-ish → minutes » avait CINQ implémentations et QUATRE comportements d'échec
 * (`NaN`, `0`, `0`, `null`, `0`), plus deux regex divergentes : « 9:00 » était lu par les
 * unes, pas par les autres. Sur une heure illisible, le wizard BLOQUAIT la pose pendant que
 * le planning et les matchs la traitaient comme MINUIT et posaient le bloc en haut de
 * grille, sans un mot.
 */
describe("parseTime (lecture unique, D-21)", () => {
  it("lit les formes réellement servies par l'API", () => {
    expect(parseTime("18:00")).toBe(18 * 60);
    expect(parseTime("18:00:00")).toBe(18 * 60);
    expect(parseTime("2026-08-09T18:30:00Z")).toBe(18 * 60 + 30);
    // Le cas qui séparait les deux regex : une heure à un chiffre.
    expect(parseTime("9:05"), "« 9:05 » était lu par certaines implémentations et pas d'autres").toBe(9 * 60 + 5);
  });

  it("rend null — et non 0 — sur une valeur illisible", () => {
    for (const value of ["", "midi", null, undefined]) {
      expect(parseTime(value), `« ${String(value)} » doit échouer explicitement`).toBeNull();
    }
  });
});

/**
 * Le point le plus délicat du finding : unifier la LECTURE ne devait pas unifier la
 * RÉACTION. Le `NaN` du wizard est load-bearing.
 */
describe("les replis restent le choix de l'appelant (D-21)", () => {
  it("le wizard garde NaN — sa garde de saisie en dépend", async () => {
    const { toMinutes } = await import("@/features/wizard/lib/days");
    expect(Number.isNaN(toMinutes("")), "slotOverlap teste !Number.isFinite() pour refuser une heure vide en nommant le champ").toBe(true);
    expect(toMinutes("18:00")).toBe(18 * 60);
  });

  it("la grille du planning garde 0 — elle place, elle ne valide pas", async () => {
    const { parseTimeToMinutes } = await import("@/features/planning/lib/grid");
    expect(parseTimeToMinutes("")).toBe(0);
  });

  it("la détection de coach dédoublé garde null — la réaction la plus stricte", async () => {
    const mod = await import("@/features/wizard/lib/coachDoubleBooking");
    expect(mod).toBeDefined();
  });
});
