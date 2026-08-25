import { describe, expect, it } from "vitest";

import type { MatchSlotRotation, TeamMatchHabit } from "../api";
import { MATCH_MINUTES, WARMUP_MINUTES } from "./weekendGrid";
import { buildTypicalWeekend, weekCountOf } from "./typicalWeekend";

const rotation = (over: Partial<MatchSlotRotation> = {}): MatchSlotRotation => ({
  id: "rot-1",
  venueId: "venue-9",
  dayOfWeek: 6,
  kickoffTime: "20:30",
  teamIds: ["team-a", "team-b"],
  ...over,
});

const habit = (over: Partial<TeamMatchHabit> = {}): TeamMatchHabit => ({
  id: "h-1",
  teamId: "team-1",
  dayOfWeek: 6,
  kickoffTime: "15:30",
  venueId: "venue-1",
  ...over,
});

describe("buildTypicalWeekend (P1-4 PR E2)", () => {
  // D-02 — ce cas épinglait 15:00→17:45, soit 2h45, sous un nom qui annonce « 2h15 » : il
  // verrouillait la divergence au lieu de la révéler. Le serveur fait foi (`MatchFootprint.php`,
  // 30 min avant le coup d'envoi + 105 après = 2h15) et la grille DATÉE le respectait déjà ;
  // seul le « week-end type » dessinait 2h45. Les bornes ci-dessous sont désormais dérivées des
  // mêmes constantes que le code, pour que la valeur ne puisse plus être recopiée de travers.
  it("lays a venue-anchored habit as a 2h15 footprint in its day×venue column", () => {
    const model = buildTypicalWeekend([habit()]);
    const kickoffMin = 15 * 60 + 30;
    expect(model.empty).toBe(false);
    expect(model.columns).toEqual([{ key: "6:venue-1", dayOfWeek: 6, venueId: "venue-1" }]);
    expect(model.blocks[0]).toMatchObject({ startMin: kickoffMin - WARMUP_MINUTES, endMin: kickoffMin + MATCH_MINUTES, kickoff: "15:30" });
    // L'empreinte totale annoncée par le nom du test : 2h15.
    expect(WARMUP_MINUTES + MATCH_MINUTES).toBe(135);
  });

  it("keeps only weekend habits and lists venue-less ones apart", () => {
    const model = buildTypicalWeekend([
      habit(),
      habit({ id: "h-2", teamId: "team-2", dayOfWeek: 3 }), // Wednesday: not a weekend habit
      habit({ id: "h-3", teamId: "team-3", dayOfWeek: 7, venueId: null }),
    ]);
    expect(model.blocks).toHaveLength(1);
    expect(model.venueless.map((h) => h.id)).toEqual(["h-3"]);
  });

  it("lanes overlapping habits of the same column side by side (a template collision must be SEEN)", () => {
    const model = buildTypicalWeekend([habit(), habit({ id: "h-2", teamId: "team-2", kickoffTime: "16:00" })]);
    const lanes = model.blocks.map((b) => b.lane).sort();
    expect(lanes).toEqual([0, 1]);
    expect(model.blocks.every((b) => 2 === b.laneCount)).toBe(true);
  });

  it("is empty only when NO weekend habit exists at all", () => {
    expect(buildTypicalWeekend([]).empty).toBe(true);
    expect(buildTypicalWeekend([habit({ venueId: null })]).empty).toBe(false); // venue-less still worth showing
  });
});

describe("weekCountOf — le nombre de semaines de l'alternance", () => {
  it("aucune rotation → 1 (pas de segmenté)", () => {
    expect(weekCountOf([])).toBe(1);
  });
  it("une rotation N=2 → 2 (Semaine A / B)", () => {
    expect(weekCountOf([rotation()])).toBe(2);
  });
  it("N=3 → 3 semaines", () => {
    expect(weekCountOf([rotation({ teamIds: ["a", "b", "c"] })])).toBe(3);
  });
  it("le max des tailles across rotations", () => {
    expect(weekCountOf([rotation({ id: "r1", teamIds: ["a", "b"] }), rotation({ id: "r2", teamIds: ["c", "d", "e"] })])).toBe(3);
  });
});

describe("buildTypicalWeekend — rotation A/B (RMM-5 PR-4)", () => {
  it("sans rotation → modèle IDENTIQUE à l'appel historique (anti-régression)", () => {
    const habits = [habit(), habit({ id: "h-2", teamId: "team-2", dayOfWeek: 7, venueId: null })];
    // L'appel à 1 argument (l'historique) et l'appel explicite [], 0 rendent le MÊME modèle.
    expect(buildTypicalWeekend(habits, [], 0)).toEqual(buildTypicalWeekend(habits));
    // Et rien ne fuit du champ neuf : aucune rotation hors week-end.
    expect(buildTypicalWeekend(habits).offWeekendRotations).toEqual([]);
  });

  it("semaine A montre le membre 0, semaine B le membre 1, sur le créneau", () => {
    const rot = rotation({ teamIds: ["team-a", "team-b"], venueId: "venue-9", dayOfWeek: 6, kickoffTime: "20:30" });
    const weekA = buildTypicalWeekend([], [rot], 0);
    const weekB = buildTypicalWeekend([], [rot], 1);
    expect(weekA.empty).toBe(false);
    const blockA = weekA.blocks.find((b) => b.key === "rot:rot-1");
    const blockB = weekB.blocks.find((b) => b.key === "rot:rot-1");
    expect(blockA?.teamId).toBe("team-a");
    expect(blockB?.teamId).toBe("team-b");
    // Même créneau (colonne day×venue) sur les deux semaines.
    expect(blockA?.columnKey).toBe("6:venue-9");
    expect(blockB?.columnKey).toBe("6:venue-9");
    expect(blockA?.kickoff).toBe("20:30");
  });

  it("semaine C (N=3) montre le membre 2, puis reboucle en semaine A→membre 0", () => {
    const rot = rotation({ teamIds: ["a", "b", "c"] });
    expect(buildTypicalWeekend([], [rot], 2).blocks.find((b) => b.key === "rot:rot-1")?.teamId).toBe("c");
    // week index 3 mod 3 = 0 → membre 0.
    expect(buildTypicalWeekend([], [rot], 3).blocks.find((b) => b.key === "rot:rot-1")?.teamId).toBe("a");
  });

  it("les habitudes simples sont IDENTIQUES sur toutes les semaines (elles ne tournent pas)", () => {
    const rot = rotation({ teamIds: ["team-a", "team-b"], venueId: "venue-9" });
    const simple = habit({ id: "h-simple", teamId: "team-x", venueId: "venue-1", kickoffTime: "15:30" });
    const weekA = buildTypicalWeekend([simple], [rot], 0);
    const weekB = buildTypicalWeekend([simple], [rot], 1);
    const habitBlockA = weekA.blocks.find((b) => b.key === "h-simple");
    const habitBlockB = weekB.blocks.find((b) => b.key === "h-simple");
    expect(habitBlockA?.teamId).toBe("team-x");
    expect(habitBlockB?.teamId).toBe("team-x");
    expect(habitBlockA?.kickoff).toBe(habitBlockB?.kickoff);
  });

  it("un créneau hors week-end (vendredi) est LISTÉ À PART, jamais un bloc de grille", () => {
    const off = rotation({ id: "rot-fri", dayOfWeek: 5, teamIds: ["team-a", "team-b"] });
    const model = buildTypicalWeekend([], [off], 0);
    expect(model.blocks).toHaveLength(0);
    expect(model.offWeekendRotations).toHaveLength(1);
    expect(model.offWeekendRotations[0]).toMatchObject({ rotationId: "rot-fri", dayOfWeek: 5, teamId: "team-a" });
    // …et la semaine B y montre le membre 1.
    expect(buildTypicalWeekend([], [off], 1).offWeekendRotations[0].teamId).toBe("team-b");
    expect(model.empty).toBe(false); // une rotation à montrer ≠ vide
  });
});
