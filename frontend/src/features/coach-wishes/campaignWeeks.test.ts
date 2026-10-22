import { describe, expect, it } from "vitest";

import { planDerivedWeeks } from "./campaignWeeks";

describe("planDerivedWeeks (P2-63 PR 4)", () => {
  const mother = "m1";

  it("aucun planning ⇒ aucune semaine (la collecte attend le planning)", () => {
    expect(planDerivedWeeks(mother, [], [])).toEqual([]);
  });

  it("période scindée : une semaine par planning d'enfant, ancrée au lundi", () => {
    const entries = [
      { id: "c1", parentEntryId: mother },
      { id: "c2", parentEntryId: mother },
    ];
    const plans = [
      { calendarEntryId: "c1", startDate: "2026-02-16" },
      { calendarEntryId: "c2", startDate: "2026-02-23" },
    ];
    expect(planDerivedWeeks(mother, entries, plans).map((w) => w.monday)).toEqual(["2026-02-16", "2026-02-23"]);
  });

  it("planning d'un bloc sur la mère : UNE semaine type, ancrée au lundi de la première semaine (D-c)", () => {
    // La mère démarre un mercredi : la semaine type est ancrée au lundi de CETTE semaine.
    const plans = [{ calendarEntryId: mother, startDate: "2026-02-18" }];
    expect(planDerivedWeeks(mother, [], plans).map((w) => w.monday)).toEqual(["2026-02-16"]);
  });

  it("ignore un planning d'une AUTRE période", () => {
    const entries = [{ id: "c1", parentEntryId: "autre" }];
    const plans = [{ calendarEntryId: "c1", startDate: "2026-02-16" }];
    expect(planDerivedWeeks(mother, entries, plans)).toEqual([]);
  });

  it("déduplique et trie les lundis", () => {
    const entries = [
      { id: "c1", parentEntryId: mother },
      { id: "c2", parentEntryId: mother },
    ];
    const plans = [
      { calendarEntryId: "c2", startDate: "2026-02-23" },
      { calendarEntryId: "c1", startDate: "2026-02-16" },
      { calendarEntryId: mother, startDate: "2026-02-16" }, // même lundi que c1
    ];
    expect(planDerivedWeeks(mother, entries, plans).map((w) => w.monday)).toEqual(["2026-02-16", "2026-02-23"]);
  });

  // La forme RÉELLEMENT servie aujourd'hui par le backend (SchedulePlanResource sérialise
  // un DateTimeImmutable en RFC 3339) doit retomber sur la vraie semaine, jamais sur NaN :
  // germe du bug « NaN/NaN/NaN » de l'onglet Réglages (retour terrain Rillieux, 2026-10-21).
  it("date servie en RFC 3339 : la semaine est normalisée au vrai lundi, jamais « NaN »", () => {
    const plans = [{ calendarEntryId: mother, startDate: "2026-12-28T00:00:00+00:00" }];
    const weeks = planDerivedWeeks(mother, [], plans);
    expect(weeks.map((w) => w.monday)).toEqual(["2026-12-28"]); // 28/12/2026 est déjà un lundi
    expect(JSON.stringify(weeks)).not.toContain("NaN");
  });

  it("date illisible : la semaine est ÉCARTÉE, jamais une semaine « NaN » rendue", () => {
    const plans = [{ calendarEntryId: mother, startDate: "pas-une-date" }];
    expect(planDerivedWeeks(mother, [], plans)).toEqual([]);
  });
});
