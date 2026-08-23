import { describe, expect, it } from "vitest";

import type { TeamLink } from "../api";
import { linkableBTeams } from "./linkableTeams";

const link = (teamAId: string, teamBId: string): Pick<TeamLink, "teamAId" | "teamBId"> => ({ teamAId, teamBId });

const t = (id: string) => ({ id, name: id.toUpperCase() });
const TEAMS = [t("t1"), t("t2"), t("t3"), t("t4")];

describe("linkableBTeams — équipe B proposable d'une nouvelle passerelle ancrée à A", () => {
  it("retire A elle-même de la liste", () => {
    const ids = linkableBTeams(TEAMS, "t1", []).map((x) => x.id);
    expect(ids).not.toContain("t1");
    expect(ids).toEqual(["t2", "t3", "t4"]);
  });

  it("retire une équipe DÉJÀ liée à A (lien A côté teamAId) et garde les autres", () => {
    // Lien t1↔t2 déclaré → t2 absente, t3 présente.
    const ids = linkableBTeams(TEAMS, "t1", [link("t1", "t2")]).map((x) => x.id);
    expect(ids).not.toContain("t2");
    expect(ids).toContain("t3");
    expect(ids).toEqual(["t3", "t4"]);
  });

  it("retire une équipe liée à A même quand A est côté teamBId (lien symétrique)", () => {
    // Lien t3↔t1 : A (t1) est en teamBId → t3 doit disparaître.
    const ids = linkableBTeams(TEAMS, "t1", [link("t3", "t1")]).map((x) => x.id);
    expect(ids).not.toContain("t3");
    expect(ids).toEqual(["t2", "t4"]);
  });

  it("un lien qui ne nomme PAS A ne retire personne", () => {
    const ids = linkableBTeams(TEAMS, "t1", [link("t2", "t3")]).map((x) => x.id);
    expect(ids).toEqual(["t2", "t3", "t4"]);
  });

  it("préserve l'ordre des équipes", () => {
    const ids = linkableBTeams(TEAMS, "t2", [link("t2", "t4")]).map((x) => x.id);
    expect(ids).toEqual(["t1", "t3"]);
  });
});
