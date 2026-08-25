import { describe, expect, it } from "vitest";

import { daysUntilDeadline, deadlineDisplay } from "./deadlineLabel";

describe("deadlineLabel — la présentation d'une échéance (RMM-6 PR-2)", () => {
  describe("daysUntilDeadline — jours signés (bornes : aujourd'hui, demain, hier)", () => {
    it("aujourd'hui → 0", () => {
      expect(daysUntilDeadline("2026-09-10", "2026-09-10")).toBe(0);
    });
    it("demain → 1 (à venir, signe positif)", () => {
      expect(daysUntilDeadline("2026-09-11", "2026-09-10")).toBe(1);
    });
    it("hier → -1 (dépassée, signe négatif)", () => {
      expect(daysUntilDeadline("2026-09-09", "2026-09-10")).toBe(-1);
    });
    it("tolère un datetime ATOM en entrée (part DATE seule)", () => {
      expect(daysUntilDeadline("2026-09-13T00:00:00+00:00", "2026-09-10")).toBe(3);
    });
    it("franchit une frontière de mois sans se tromper", () => {
      expect(daysUntilDeadline("2026-10-02", "2026-09-30")).toBe(2);
    });
  });

  describe("deadlineDisplay — la phrase + le compte à rebours", () => {
    it("J-3 : à venir → « avant le … » + « J-3 », non dépassée", () => {
      const d = deadlineDisplay("2026-09-13", "2026-09-10");
      expect(d.label).toMatch(/^avant le /);
      expect(d.countdown).toBe("J-3");
      expect(d.overdue).toBe(false);
    });
    it("J0 : le jour même → « aujourd'hui », non dépassée", () => {
      const d = deadlineDisplay("2026-09-10", "2026-09-10");
      expect(d.label).toMatch(/^avant le /);
      expect(d.countdown).toBe("aujourd'hui");
      expect(d.overdue).toBe(false);
    });
    it("J+2 : dépassée → « échéance dépassée » + « J+2 », marquée overdue", () => {
      const d = deadlineDisplay("2026-09-08", "2026-09-10");
      expect(d.label).toBe("échéance dépassée");
      expect(d.countdown).toBe("J+2");
      expect(d.overdue).toBe(true);
    });
  });
});
