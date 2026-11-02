import { describe, expect, it } from "vitest";

import { defaultExportRange } from "./exportRange";

/**
 * Promesse : la fenêtre d'export s'ouvre sur ce que le gestionnaire regarde — sa semaine, son mois,
 * sa phase, l'équipe qu'il a filtrée — sinon sur le mois en cours. Le 14/11/2026 est un samedi : sa
 * semaine va du lundi 09/11 au dimanche 15/11.
 */
const base = {
  temporality: "semaine" as const,
  activeWeekend: null,
  activeMonth: null,
  phase: null,
  filterTeamId: null,
  today: "2026-11-05",
};

describe("defaultExportRange", () => {
  it("vue Semaine : du lundi au dimanche du week-end affiché", () => {
    expect(defaultExportRange({ ...base, activeWeekend: "2026-11-14" })).toEqual({ teamId: null, from: "2026-11-09", to: "2026-11-15" });
  });

  it("vue Semaine à cheval sur deux mois : les vraies bornes", () => {
    expect(defaultExportRange({ ...base, activeWeekend: "2026-10-31" })).toEqual({ teamId: null, from: "2026-10-26", to: "2026-11-01" });
  });

  it("vue Mois : du premier au dernier jour du mois affiché", () => {
    expect(defaultExportRange({ ...base, temporality: "mois", activeMonth: "2026-11" })).toEqual({ teamId: null, from: "2026-11-01", to: "2026-11-30" });
    expect(defaultExportRange({ ...base, temporality: "mois", activeMonth: "2027-02" })).toEqual({ teamId: null, from: "2027-02-01", to: "2027-02-28" });
  });

  it("vue Phase : l'équipe de la phase, du premier au dernier match", () => {
    const phase = { teamId: "team-15", firstDate: "2026-10-03", lastDate: "2027-01-23" };
    expect(defaultExportRange({ ...base, temporality: "phase", phase })).toEqual({ teamId: "team-15", from: "2026-10-03", to: "2027-01-23" });
  });

  it("vue Phase sans match : l'équipe de la phase, le mois en cours", () => {
    const phase = { teamId: "team-15", firstDate: null, lastDate: null };
    expect(defaultExportRange({ ...base, temporality: "phase", phase })).toEqual({ teamId: "team-15", from: "2026-11-01", to: "2026-11-30" });
  });

  it("une seule équipe filtrée : elle est choisie, la plage reste celle de la vue", () => {
    expect(defaultExportRange({ ...base, activeWeekend: "2026-11-14", filterTeamId: "team-13" })).toEqual({ teamId: "team-13", from: "2026-11-09", to: "2026-11-15" });
  });

  it("rien d'affiché : tout le club, le mois en cours", () => {
    expect(defaultExportRange(base)).toEqual({ teamId: null, from: "2026-11-01", to: "2026-11-30" });
    expect(defaultExportRange({ ...base, temporality: "mois" })).toEqual({ teamId: null, from: "2026-11-01", to: "2026-11-30" });
    expect(defaultExportRange({ ...base, temporality: "phase" })).toEqual({ teamId: null, from: "2026-11-01", to: "2026-11-30" });
  });
});
