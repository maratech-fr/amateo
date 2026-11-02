import { describe, expect, it } from "vitest";

import { initialLinksTab } from "./linksTab";

const link = (teamAId: string, teamBId: string) => ({ teamAId, teamBId });
const group = (...teamIds: string[]) => ({ teamIds });

/** La modale Liens s'ouvre là où l'équipe a quelque chose à voir : passerelles d'abord, mutualisation si elle n'a qu'un groupe. */
describe("initialLinksTab", () => {
  it("aucun lien ni groupe : Passerelles", () => {
    expect(initialLinksTab("t1", [], [])).toBe("passerelles");
  });

  it("une passerelle seule : Passerelles, que l'équipe soit à gauche ou à droite du lien", () => {
    expect(initialLinksTab("t1", [link("t1", "t2")], [])).toBe("passerelles");
    expect(initialLinksTab("t1", [link("t2", "t1")], [])).toBe("passerelles");
  });

  it("un groupe et aucune passerelle : Mutualisation", () => {
    expect(initialLinksTab("t1", [], [group("t1", "t2")])).toBe("mutualisation");
  });

  it("une passerelle et un groupe : Passerelles", () => {
    expect(initialLinksTab("t1", [link("t1", "t2")], [group("t1", "t2")])).toBe("passerelles");
  });

  it("les liens et groupes des autres équipes ne comptent pas", () => {
    expect(initialLinksTab("t1", [link("t2", "t3")], [group("t2", "t3")])).toBe("passerelles");
    expect(initialLinksTab("t1", [link("t2", "t3")], [group("t1", "t3")])).toBe("mutualisation");
  });
});
