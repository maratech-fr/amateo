import { describe, expect, it } from "vitest";

import type { SharedTrainingGroup, Team, TeamPeriodOverride } from "../api";
import {
  alreadyGroupedTeamIds,
  effectiveSessionsPerWeek,
  filterCandidates,
  groupContainingTeam,
  maxCommonSessions,
  mutualisedTeammateLabel,
  sharedGroupLabel,
  splitByLinks,
  teamsLinkedTo,
} from "./sharedTraining";

const team = (over: Partial<Team> & Pick<Team, "id" | "name">): Team => ({
  sportCategoryId: "cat",
  priorityTierId: 1,
  tierOrder: 0,
  gender: null,
  level: null,
  sessionsPerWeek: 2,
  isActive: true,
  ...over,
});

const group = (id: string, teamIds: string[], commonSessions = 1, schedulePlanId: string | null = null): SharedTrainingGroup => ({
  id,
  version: 1,
  createdAt: "2026-08-17T00:00:00+00:00",
  updatedAt: "2026-08-17T00:00:00+00:00",
  schedulePlanId,
  teamIds,
  commonSessions,
});

describe("effectiveSessionsPerWeek — the period override is priority (mirror of the processor)", () => {
  const t = team({ id: "t1", name: "SM1", sessionsPerWeek: 3 });

  it("uses the seasonal value when no override applies", () => {
    expect(effectiveSessionsPerWeek(t, undefined)).toBe(3);
  });

  it("prefers the override's sessionsPerWeek over the seasonal one", () => {
    const override: TeamPeriodOverride = { id: "o", schedulePlanId: "p", teamId: "t1", isActive: true, sessionsPerWeek: 1 };
    expect(effectiveSessionsPerWeek(t, override)).toBe(1);
  });

  it("falls back to the seasonal value when the override carries no session count", () => {
    const override: TeamPeriodOverride = { id: "o", schedulePlanId: "p", teamId: "t1", isActive: true, sessionsPerWeek: null };
    expect(effectiveSessionsPerWeek(t, override)).toBe(3);
  });
});

describe("maxCommonSessions — the smallest effective sessionsPerWeek of the selection", () => {
  it("is the minimum across the selected teams (seasonal)", () => {
    const teams = [team({ id: "t1", name: "A", sessionsPerWeek: 3 }), team({ id: "t2", name: "B", sessionsPerWeek: 2 })];
    expect(maxCommonSessions(teams, new Map())).toBe(2);
  });

  it("honours the period override when computing the floor", () => {
    const teams = [team({ id: "t1", name: "A", sessionsPerWeek: 3 }), team({ id: "t2", name: "B", sessionsPerWeek: 3 })];
    const overrides = new Map<string, TeamPeriodOverride>([["t2", { id: "o", schedulePlanId: "p", teamId: "t2", isActive: true, sessionsPerWeek: 1 }]]);
    expect(maxCommonSessions(teams, overrides)).toBe(1);
  });

  it("is 0 for an empty selection", () => {
    expect(maxCommonSessions([], new Map())).toBe(0);
  });
});

describe("alreadyGroupedTeamIds / groupContainingTeam — a team belongs to at most one group of a scope", () => {
  const groups = [group("g1", ["t1", "t2"]), group("g2", ["t3", "t4"])];

  it("collects every grouped team of the scope", () => {
    expect(alreadyGroupedTeamIds(groups, null)).toEqual(new Set(["t1", "t2", "t3", "t4"]));
  });

  it("excludes the group currently being edited (its members stay free)", () => {
    expect(alreadyGroupedTeamIds(groups, "g1")).toEqual(new Set(["t3", "t4"]));
  });

  it("finds the OTHER group a team belongs to, for a reason label", () => {
    expect(groupContainingTeam(groups, "t3", null)?.id).toBe("g2");
    expect(groupContainingTeam(groups, "t3", "g2")).toBeUndefined();
  });
});

describe("sharedGroupLabel — « SM1 + SM2 — 1 séance commune » (plural handled)", () => {
  const name = (id: string): string => ({ t1: "SM1", t2: "SM2", t3: "SM3" })[id] ?? "?";

  it("joins the team names and singularises one common session", () => {
    expect(sharedGroupLabel(["t1", "t2"], 1, name)).toBe("SM1 + SM2 — 1 séance commune");
  });

  it("pluralises several common sessions and names three teams", () => {
    expect(sharedGroupLabel(["t1", "t2", "t3"], 2, name)).toBe("SM1 + SM2 + SM3 — 2 séances communes");
  });
});

describe("teamsLinkedTo — the OTHER end of every declared bridge naming the anchor (server-served)", () => {
  const links = [
    { teamAId: "a", teamBId: "b" },
    { teamAId: "c", teamBId: "a" },
    { teamAId: "d", teamBId: "e" },
  ];

  it("collects the partner from links naming the anchor, whichever side it sits on", () => {
    expect(teamsLinkedTo(links, "a")).toEqual(new Set(["b", "c"]));
  });

  it("is empty when no bridge names the anchor", () => {
    expect(teamsLinkedTo(links, "z")).toEqual(new Set());
    expect(teamsLinkedTo([], "a")).toEqual(new Set());
  });
});

describe("splitByLinks — a DISPLAY split « liées » (bridged to the anchor) then the rest, never a permission", () => {
  const anchor = team({ id: "a", name: "SM1" });
  const linked = team({ id: "b", name: "SF1" });
  const other = team({ id: "e", name: "U11" });

  it("with no anchor, nothing is « liée » — the whole list stays flat (far)", () => {
    const { near, far } = splitByLinks([anchor, linked, other], null, new Set(), new Set(["b"]));
    expect(near).toEqual([]);
    expect(far.map((t) => t.id)).toEqual(["a", "b", "e"]);
  });

  it("puts bridged teams first, the rest in far — a declared passerelle lifts the linked team to the top", () => {
    const { near, far } = splitByLinks([linked, other], anchor, new Set(["a"]), new Set(["b"]));
    expect(near.map((t) => t.id)).toEqual(["b"]);
    expect(far.map((t) => t.id)).toEqual(["e"]);
  });

  it("with no bridge for the anchor, « liées » holds only the checked teams (nothing else lifted)", () => {
    const { near, far } = splitByLinks([anchor, linked, other], anchor, new Set(["a"]), new Set());
    expect(near.map((t) => t.id)).toEqual(["a"]);
    expect(far.map((t) => t.id)).toEqual(["b", "e"]);
  });

  it("keeps a checked team in « liées » so it can always be unchecked, even if not bridged", () => {
    const { near, far } = splitByLinks([linked, other], anchor, new Set(["a", "e"]), new Set());
    expect(near.map((t) => t.id)).toContain("e");
    expect(far.map((t) => t.id)).not.toContain("e");
  });
});

describe("filterCandidates — recherche texte insensible casse+accents, coché exempté", () => {
  const teams = [
    team({ id: "t1", name: "U15F2" }),
    team({ id: "t2", name: "U15M1" }),
    team({ id: "t3", name: "SM1" }),
    team({ id: "t4", name: "Séniors" }),
  ];

  it("requête vide (ou blanche) ne filtre rien — tous les candidats reviennent", () => {
    expect(filterCandidates(teams, "", new Set()).map((t) => t.id)).toEqual(["t1", "t2", "t3", "t4"]);
    expect(filterCandidates(teams, "   ", new Set()).map((t) => t.id)).toEqual(["t1", "t2", "t3", "t4"]);
  });

  it("garde les correspondances, masque le reste", () => {
    const ids = filterCandidates(teams, "U15F2", new Set()).map((t) => t.id);
    expect(ids).toEqual(["t1"]);
  });

  it("est insensible à la casse", () => {
    expect(filterCandidates(teams, "sm1", new Set()).map((t) => t.id)).toEqual(["t3"]);
  });

  it("est insensible aux accents (dans la requête ET dans le nom)", () => {
    expect(filterCandidates(teams, "seniors", new Set()).map((t) => t.id)).toEqual(["t4"]);
    expect(filterCandidates(teams, "sénior", new Set()).map((t) => t.id)).toEqual(["t4"]);
  });

  it("une équipe COCHÉE survit au filtre même si son nom ne correspond pas", () => {
    // On cherche « U15 » : SM1 ne correspond pas, mais elle est cochée → elle reste.
    const ids = filterCandidates(teams, "U15", new Set(["t3"])).map((t) => t.id);
    expect(ids).toEqual(["t1", "t2", "t3"]);
  });

  it("préserve l'ordre des candidats", () => {
    const ids = filterCandidates(teams, "U15", new Set()).map((t) => t.id);
    expect(ids).toEqual(["t1", "t2"]);
  });
});

describe("mutualisedTeammateLabel — la sous-ligne fusionne groupe K ET bloc (P2-51 PR-6)", () => {
  const nameOf = (id: string): string => ({ t1: "SM1", t2: "SM2", t3: "U13F2", t4: "U13F3" })[id] ?? "?";

  it("nomme la co-équipière du GROUPE historique", () => {
    const groups = [{ teamIds: ["t1", "t2"] }];
    expect(mutualisedTeammateLabel("t1", groups, [], nameOf)).toBe("Mutualisée avec SM2");
  });

  it("nomme la co-équipière du BLOC nouveau modèle (source neuve, seule)", () => {
    const blocks = [{ teamIds: ["t3", "t4"] }];
    expect(mutualisedTeammateLabel("t3", [], blocks, nameOf)).toBe("Mutualisée avec U13F3");
  });

  it("fusionne les deux sources SANS doublon (partenaire commune nommée une seule fois)", () => {
    // t1 est dans un groupe {t1,t2} ET dans un bloc {t1,t2,t3} : t2 ne doit pas être doublée.
    const groups = [{ teamIds: ["t1", "t2"] }];
    const blocks = [{ teamIds: ["t1", "t2", "t3"] }];
    expect(mutualisedTeammateLabel("t1", groups, blocks, nameOf)).toBe("Mutualisée avec SM2, U13F2");
  });

  it("rend null quand l'équipe n'est ni dans un groupe ni dans un bloc", () => {
    expect(mutualisedTeammateLabel("t9", [{ teamIds: ["t1", "t2"] }], [{ teamIds: ["t3", "t4"] }], nameOf)).toBeNull();
  });
});
