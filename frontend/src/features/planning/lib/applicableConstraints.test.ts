import { describe, expect, it } from "vitest";

import type { Constraint, Slot } from "../api";
import { applicableConstraints, buildTagTeamIds, isClubWide } from "./applicableConstraints";

const slot = (over: Partial<Slot> = {}): Slot => ({
  id: "s1",
  scheduleId: "sch1",
  teamId: "team-A",
  venueId: "venue-1",
  coachId: "coach-X",
  dayOfWeek: 2,
  startTime: "18:00",
  durationMinutes: 90,
  lockLevel: "NONE",
  lockOrigin: null,
  sharedTrainingBlockId: null,
  ...over,
});

const constraint = (over: Partial<Constraint>): Constraint => ({
  id: "c1",
  name: "Contrainte",
  scope: "CLUB",
  scopeTargetId: null,
  family: "TIME",
  ruleType: "HARD",
  config: {},
  isActive: true,
  ...over,
});

describe("applicableConstraints", () => {
  it("garde une contrainte CLUB pour tout créneau", () => {
    const club = constraint({ id: "club", scope: "CLUB", scopeTargetId: null });
    expect(applicableConstraints(slot(), [club]).map((c) => c.id)).toEqual(["club"]);
  });

  it("garde une contrainte TEAM seulement pour SON équipe", () => {
    const mine = constraint({ id: "mine", scope: "TEAM", scopeTargetId: "team-A" });
    const other = constraint({ id: "other", scope: "TEAM", scopeTargetId: "team-B" });
    expect(applicableConstraints(slot(), [mine, other]).map((c) => c.id)).toEqual(["mine"]);
  });

  it("garde une contrainte FACILITY seulement pour SON gymnase", () => {
    const here = constraint({ id: "here", scope: "FACILITY", scopeTargetId: "venue-1" });
    const elsewhere = constraint({ id: "elsewhere", scope: "FACILITY", scopeTargetId: "venue-9" });
    expect(applicableConstraints(slot(), [here, elsewhere]).map((c) => c.id)).toEqual(["here"]);
  });

  it("garde une contrainte COACH seulement pour SON coach (jamais si le créneau n'a pas de coach)", () => {
    const coach = constraint({ id: "coach", scope: "COACH", scopeTargetId: "coach-X" });
    expect(applicableConstraints(slot(), [coach]).map((c) => c.id)).toEqual(["coach"]);
    expect(applicableConstraints(slot({ coachId: null }), [coach])).toEqual([]);
  });

  it("écarte les contraintes inactives", () => {
    const off = constraint({ id: "off", scope: "CLUB", isActive: false });
    expect(applicableConstraints(slot(), [off])).toEqual([]);
  });
});

describe("applicableConstraints — CLUB ciblant un tag (miroir de ScheduleConstraintBuilder)", () => {
  // team-A porte le tag REGIONAL dans la saison ; team-Z ne le porte pas. Le backend
  // ÉCLATE une contrainte CLUB+targetTag en N contraintes TEAM (les seules équipes
  // taguées) — le solveur ne voit jamais une règle « tout le club » quand un tag est posé.
  const tagged = buildTagTeamIds([{ id: "tag-reg", name: "REGIONAL" }], [{ teamId: "team-A", tagId: "tag-reg" }]);

  it("affiche une contrainte CLUB+tag sur une équipe QUI PORTE le tag", () => {
    const reg = constraint({ id: "reg", scope: "CLUB", config: { targetTag: "REGIONAL" } });
    expect(applicableConstraints(slot({ teamId: "team-A" }), [reg], tagged).map((c) => c.id)).toEqual(["reg"]);
  });

  it("MASQUE une contrainte CLUB+tag sur une équipe qui NE porte PAS le tag", () => {
    // ⚠ FALSIFICATION : remettre `case \"CLUB\": return true` dans applies() fait rougir
    // CE test — c'est exactement le mensonge d'affichage qu'on répare.
    const reg = constraint({ id: "reg", scope: "CLUB", config: { targetTag: "REGIONAL" } });
    expect(applicableConstraints(slot({ teamId: "team-Z" }), [reg], tagged)).toEqual([]);
  });

  it("affiche PARTOUT une contrainte CLUB sans tag (elle concerne vraiment tout le club)", () => {
    const club = constraint({ id: "club", scope: "CLUB", config: {} });
    expect(applicableConstraints(slot({ teamId: "team-Z" }), [club], tagged).map((c) => c.id)).toEqual(["club"]);
  });

  it("MASQUE partout une contrainte CLUB+tag dont le tag ne résout AUCUNE équipe (NO-OP backend)", () => {
    // Faute de frappe, ou tags non réappliqués après bascule de saison : le backend saute
    // la contrainte entièrement (l.855-862). Le front doit faire pareil — nulle part.
    const ghost = constraint({ id: "ghost", scope: "CLUB", config: { targetTag: "FANTOME" } });
    expect(applicableConstraints(slot({ teamId: "team-A" }), [ghost], tagged)).toEqual([]);
    expect(applicableConstraints(slot({ teamId: "team-Z" }), [ghost], tagged)).toEqual([]);
  });

  it("sans données de tags, une contrainte CLUB+tag ne s'affiche nulle part (fail-closed)", () => {
    // Tant que tags/assignations ne sont pas lus, ne PAS sur-afficher : montrer partout
    // serait re-mentir. Une contrainte CLUB sans tag, elle, reste affichée.
    const reg = constraint({ id: "reg", scope: "CLUB", config: { targetTag: "REGIONAL" } });
    const club = constraint({ id: "club", scope: "CLUB", config: {} });
    expect(applicableConstraints(slot({ teamId: "team-A" }), [reg, club]).map((c) => c.id)).toEqual(["club"]);
  });
});

describe("applicableConstraints — CLUB ciblant plusieurs tags / en excluant (P2-29)", () => {
  // team-A : REGIONAL + COMPETITION ; team-B : REGIONAL ; team-C : COMPETITION.
  const tagged = buildTagTeamIds(
    [{ id: "t-reg", name: "REGIONAL" }, { id: "t-comp", name: "COMPETITION" }],
    [
      { teamId: "team-A", tagId: "t-reg" },
      { teamId: "team-A", tagId: "t-comp" },
      { teamId: "team-B", tagId: "t-reg" },
      { teamId: "team-C", tagId: "t-comp" },
    ],
  );

  it("targetTags = INTERSECTION : seule l'équipe qui porte TOUS les tags", () => {
    const both = constraint({ id: "both", scope: "CLUB", config: { targetTags: ["REGIONAL", "COMPETITION"] } });
    expect(applicableConstraints(slot({ teamId: "team-A" }), [both], tagged).map((c) => c.id)).toEqual(["both"]);
    expect(applicableConstraints(slot({ teamId: "team-B" }), [both], tagged)).toEqual([]);
    expect(applicableConstraints(slot({ teamId: "team-C" }), [both], tagged)).toEqual([]);
  });

  it("excludeTags = union soustraite : la cible MOINS les exclus", () => {
    // cible REGIONAL (A, B) sauf COMPETITION (A, C) → seulement B.
    const c = constraint({ id: "sub", scope: "CLUB", config: { targetTags: ["REGIONAL"], excludeTags: ["COMPETITION"] } });
    expect(applicableConstraints(slot({ teamId: "team-B" }), [c], tagged).map((x) => x.id)).toEqual(["sub"]);
    expect(applicableConstraints(slot({ teamId: "team-A" }), [c], tagged)).toEqual([]);
  });

  it("legacy targetTag se comporte comme targetTags:[x]", () => {
    const c = constraint({ id: "leg", scope: "CLUB", config: { targetTag: "REGIONAL" } });
    expect(applicableConstraints(slot({ teamId: "team-B" }), [c], tagged).map((x) => x.id)).toEqual(["leg"]);
    expect(applicableConstraints(slot({ teamId: "team-C" }), [c], tagged)).toEqual([]);
  });
});

describe("isClubWide — ce qui concerne le club ENTIER vs l'équipe", () => {
  it("CLUB sans tag = club entier", () => {
    expect(isClubWide(constraint({ scope: "CLUB", config: {} }))).toBe(true);
  });
  it("CLUB avec tag = concerne l'équipe (taguée), pas le club entier", () => {
    expect(isClubWide(constraint({ scope: "CLUB", config: { targetTag: "REGIONAL" } }))).toBe(false);
  });
  it("CLUB avec targetTags/excludeTags = un sous-ensemble, pas le club entier (P2-29)", () => {
    expect(isClubWide(constraint({ scope: "CLUB", config: { targetTags: ["REGIONAL", "COMPETITION"] } }))).toBe(false);
    expect(isClubWide(constraint({ scope: "CLUB", config: { excludeTags: ["COMPETITION"] } }))).toBe(false);
  });
  it("TEAM/FACILITY/COACH ne sont jamais club entier", () => {
    expect(isClubWide(constraint({ scope: "TEAM", scopeTargetId: "team-A" }))).toBe(false);
    expect(isClubWide(constraint({ scope: "FACILITY", scopeTargetId: "venue-1" }))).toBe(false);
    expect(isClubWide(constraint({ scope: "COACH", scopeTargetId: "coach-X" }))).toBe(false);
  });
});
