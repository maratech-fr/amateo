import { describe, expect, it } from "vitest";

import { frDateWeekdayNoYear } from "@/shared/lib/date";

import type { PendingDeviation } from "../api";
import { autoAppliedPhrase } from "./autoAppliedPhrase";

function dev(field: PendingDeviation["field"], appValue: string | null, sourceValue: string | null): PendingDeviation {
  return { field, appValue, sourceValue, channel: "FBI_XLSX", seenAt: "2026-10-01T00:00:00+00:00", autoApplied: true };
}

describe("autoAppliedPhrase — les valeurs imposées d'office, en UNE phrase", () => {
  it("date ET heure : le match est reprogrammé (jour → jour à heure)", () => {
    const oldDate = frDateWeekdayNoYear("2026-12-12");
    const newDate = frDateWeekdayNoYear("2026-12-10");
    expect(
      autoAppliedPhrase([dev("date", "2026-12-12", "2026-12-10"), dev("kickoff", "20:00", "21:00")], "FBI"),
    ).toBe(`FBI a déplacé ce match : ${oldDate} → ${newDate} à 21:00.`);
  });

  it("date seule : « (date) : ancienne → nouvelle » (dates formatées, jamais l'ISO brut)", () => {
    const oldDate = frDateWeekdayNoYear("2026-12-12");
    const newDate = frDateWeekdayNoYear("2026-12-10");
    expect(autoAppliedPhrase([dev("date", "2026-12-12", "2026-12-10")], "FBI")).toBe(
      `FBI a déplacé ce match (date) : ${oldDate} → ${newDate}`,
    );
  });

  it("heure seule : « (heure) : 20:00 → 21:00 »", () => {
    expect(autoAppliedPhrase([dev("kickoff", "20:00", "21:00")], "FBI")).toBe("FBI a déplacé ce match (heure) : 20:00 → 21:00");
  });

  it("salle seule : « (salle) : X → Y » — brut, la source est nommée", () => {
    expect(autoAppliedPhrase([dev("venue", "Gymnase A", "Gymnase B")], "API FFBB")).toBe(
      "API FFBB a déplacé ce match (salle) : Gymnase A → Gymnase B",
    );
  });

  it("aucune déviation → null (rien à afficher)", () => {
    expect(autoAppliedPhrase([], "FBI")).toBeNull();
  });

  it("combinaison rare (salle + date) : un seul bloc, aucune information perdue", () => {
    const phrase = autoAppliedPhrase([dev("date", "2026-12-12", "2026-12-10"), dev("venue", "A", "B")], "FBI");
    expect(phrase).toContain("FBI a déplacé ce match —");
    expect(phrase).toContain("salle : A → B");
    expect(phrase).toContain(frDateWeekdayNoYear("2026-12-10"));
  });
});
