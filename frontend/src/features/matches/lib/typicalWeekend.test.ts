import { describe, expect, it } from "vitest";

import type { TeamMatchHabit } from "../api";
import { MATCH_MINUTES, WARMUP_MINUTES } from "./weekendGrid";
import { buildTypicalWeekend, hasAlternatingWeeks } from "./typicalWeekend";

const habit = (over: Partial<TeamMatchHabit> = {}): TeamMatchHabit => ({
  id: "h-1",
  teamId: "team-1",
  dayOfWeek: 6,
  kickoffTime: "15:30",
  venueId: "venue-1",
  week: "ALL",
  ...over,
});

describe("buildTypicalWeekend (P1-4 PR E2)", () => {
  // D-02 — ce cas épinglait 15:00→17:45, soit 2h45, sous un nom qui annonce « 2h15 » : il
  // verrouillait la divergence au lieu de la révéler. Le serveur fait foi (`MatchFootprint.php`,
  // 30 min avant le coup d'envoi + 105 après = 2h15) et la grille DATÉE le respectait déjà ;
  // seul le « week-end type » dessinait 2h45. Les bornes ci-dessous sont désormais dérivées des
  // mêmes constantes que le code, pour que la valeur ne puisse plus être recopiée de travers.
  it("lays a venue-anchored ideal slot as a 2h15 footprint in its day×venue column", () => {
    const model = buildTypicalWeekend([habit()]);
    const kickoffMin = 15 * 60 + 30;
    expect(model.empty).toBe(false);
    expect(model.columns).toEqual([{ key: "6:venue-1", dayOfWeek: 6, venueId: "venue-1" }]);
    expect(model.blocks[0]).toMatchObject({ startMin: kickoffMin - WARMUP_MINUTES, endMin: kickoffMin + MATCH_MINUTES, kickoff: "15:30" });
    // L'empreinte totale annoncée par le nom du test : 2h15.
    expect(WARMUP_MINUTES + MATCH_MINUTES).toBe(135);
  });

  it("keeps only weekend ideal slots and lists venue-less ones apart", () => {
    const model = buildTypicalWeekend([
      habit(),
      habit({ id: "h-2", teamId: "team-2", dayOfWeek: 3 }), // Wednesday: not a weekend slot
      habit({ id: "h-3", teamId: "team-3", dayOfWeek: 7, venueId: null }),
    ]);
    expect(model.blocks).toHaveLength(1);
    expect(model.venueless.map((h) => h.id)).toEqual(["h-3"]);
  });

  it("lanes overlapping ideal slots of the same column side by side (a template collision must be SEEN)", () => {
    const model = buildTypicalWeekend([habit(), habit({ id: "h-2", teamId: "team-2", kickoffTime: "16:00" })]);
    const lanes = model.blocks.map((b) => b.lane).sort();
    expect(lanes).toEqual([0, 1]);
    expect(model.blocks.every((b) => 2 === b.laneCount)).toBe(true);
  });

  it("is empty only when NO weekend ideal slot exists at all", () => {
    expect(buildTypicalWeekend([]).empty).toBe(true);
    expect(buildTypicalWeekend([habit({ venueId: null })]).empty).toBe(false); // venue-less still worth showing
  });
});

describe("hasAlternatingWeeks — le club alterne (P4-271)", () => {
  it("aucun tag A/B (tout « toutes ») → pas d'alternance", () => {
    expect(hasAlternatingWeeks([habit(), habit({ id: "h-2", week: "ALL" })])).toBe(false);
  });
  it("au moins un tag A ou B → alternance", () => {
    expect(hasAlternatingWeeks([habit({ week: "A" })])).toBe(true);
    expect(hasAlternatingWeeks([habit(), habit({ id: "h-2", week: "B" })])).toBe(true);
  });
});

describe("buildTypicalWeekend — filtrage par semaine A/B (P4-271)", () => {
  it("la semaine A garde les créneaux tagués A ou « toutes », jamais ceux tagués B", () => {
    const habits = [
      habit({ id: "a", teamId: "t-a", week: "A", venueId: "v", kickoffTime: "13:00" }),
      habit({ id: "b", teamId: "t-b", week: "B", venueId: "v", kickoffTime: "15:00" }),
      habit({ id: "all", teamId: "t-all", week: "ALL", venueId: "v", kickoffTime: "17:00" }),
    ];
    const weekA = buildTypicalWeekend(habits, "A");
    const shownA = weekA.blocks.map((blk) => blk.key).sort();
    expect(shownA).toEqual(["a", "all"]);

    const weekB = buildTypicalWeekend(habits, "B");
    const shownB = weekB.blocks.map((blk) => blk.key).sort();
    expect(shownB).toEqual(["all", "b"]);
  });

  it("sans semaine (vue unique) tous les créneaux sont rendus", () => {
    const habits = [
      habit({ id: "a", teamId: "t-a", week: "A", venueId: "v", kickoffTime: "13:00" }),
      habit({ id: "b", teamId: "t-b", week: "B", venueId: "v", kickoffTime: "15:00" }),
    ];
    expect(buildTypicalWeekend(habits).blocks).toHaveLength(2);
  });
});
