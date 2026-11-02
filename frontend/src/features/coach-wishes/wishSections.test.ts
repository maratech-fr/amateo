import { describe, expect, it } from "vitest";

import type { PublicWishContext } from "./publicApi";
import { buildInitialSections, cloneSections, isSectionDirty, type SectionState, toSubmission } from "./wishSections";

/** Promesse : l'état d'une section ne garde que le staff COURANT, et seule une vraie modification part. */
const context = (lines: { coachId: string; days: number[] }[]): PublicWishContext => ({
  coachFirstName: "Karim",
  periodTitle: "Toussaint",
  periodStart: "2026-02-16",
  periodEnd: "2026-03-01",
  deadline: "2027-06-30",
  weeks: ["2026-02-16"],
  teams: [{ id: "t1", name: "U15", staff: [{ coachId: "c1", firstName: "Karim", lastName: "Benali", role: "MAIN" }] }],
  partnerTeams: [],
  teamLinks: [],
  wishes: [{ teamId: "t1", weekStart: "2026-02-16", slotsWanted: 2, unavailableDays: [], wishedDays: [], comment: null, keepSeasonSlots: false, byAnotherCoach: false, staffUnavailability: lines }],
  absencesElsewhere: [],
  mutualizations: [],
  respondedAt: null,
});

const KEY = "t1|2026-02-16";
const section = (lines: { coachId: string; days: number[] }[]): SectionState => buildInitialSections(context(lines)).get(KEY) as SectionState;

describe("wishSections — staff", () => {
  it("ne garde que les personnes présentes dans le staff de l'équipe", () => {
    expect(section([{ coachId: "c1", days: [4] }, { coachId: "cGone", days: [2] }]).staff).toEqual(new Map([["c1", new Set([4])]]));
  });

  it("clone les jours : modifier la copie ne touche jamais l'original", () => {
    const original = new Map([[KEY, section([{ coachId: "c1", days: [4] }])]]);
    const copy = cloneSections(original);

    copy.get(KEY)?.staff.get("c1")?.add(5);

    expect(original.get(KEY)?.staff.get("c1")).toEqual(new Set([4]));
  });

  it("une absence ajoutée ou retirée rend la section modifiée ; un même état, non", () => {
    const base = section([{ coachId: "c1", days: [4] }]);

    expect(isSectionDirty(section([{ coachId: "c1", days: [4] }]), base)).toBe(false);
    expect(isSectionDirty(section([{ coachId: "c1", days: [4, 5] }]), base)).toBe(true);
    expect(isSectionDirty(section([]), base)).toBe(true);
  });

  it("une personne sans jour vaut une personne absente de la liste : pas de faux « modifié »", () => {
    const empty = section([]);
    const withEmptySet = { ...section([]), staff: new Map([["c1", new Set<number>()]]) };

    expect(isSectionDirty(withEmptySet, empty)).toBe(false);
  });

  it("le payload trie par personne puis par jour et omet les lignes vides", () => {
    const state: SectionState = {
      ...section([]),
      staff: new Map([
        ["c2", new Set([5, 2])],
        ["c0", new Set<number>()],
        ["c1", new Set([4])],
      ]),
    };

    expect(toSubmission(KEY, state).staffUnavailability).toEqual([
      { coachId: "c1", days: [4] },
      { coachId: "c2", days: [2, 5] },
    ]);
  });
});
