import { describe, expect, it } from "vitest";

import type { Coach, Slot, Team, Venue } from "../api";
import type { TierLike } from "@/shared/lib/teamTiers";

import { buildClubView } from "./clubView";
import type { Lookups } from "./grid";

function slot(over: Partial<Slot>): Slot {
  return {
    id: "id",
    scheduleId: "s",
    teamId: "t1",
    venueId: "v1",
    coachId: null,
    dayOfWeek: 1,
    startTime: "18:00:00",
    durationMinutes: 90,
    lockLevel: "NONE",
    lockOrigin: null,
    sharedTrainingBlockId: null,
    ...over,
  };
}

const team = (id: string, name: string, tier: number, order = 0): Team => ({
  id,
  name,
  sportCategoryId: "cat1",
  priorityTierId: tier,
  tierOrder: order,
  sessionsPerWeek: 2,
});

const lookups: Lookups = {
  teams: new Map<string, Team>([
    ["t1", team("t1", "SM1", 1)],
    ["t2", team("t2", "U13 F1", 2)],
    ["t3", team("t3", "U11 M2", 2, 1)],
  ]),
  venues: new Map<string, Venue>([
    ["v1", { id: "v1", name: "Matéo", color: "#ff0000" }],
    ["v2", { id: "v2", name: "Debarros", color: null }],
  ]),
  coaches: new Map<string, Coach>([["c1", { id: "c1", firstName: "Jean", lastName: "Paul", gender: "UNSPECIFIED" }]]),
  teamCoach: new Map<string, string>([["t1", "c1"]]),
  teamPlayerCoaches: new Map<string, string[]>(),
};

const tiers: TierLike[] = [
  { id: 1, label: "S", name: "Fanion" },
  { id: 2, label: "A", name: "Importante" },
];

const rowOf = (model: ReturnType<typeof buildClubView>, teamId: string) => model.groups.flatMap((g) => g.rows).find((r) => r.teamId === teamId);

describe("buildClubView — la vue par club (P3-20)", () => {
  it("projette une ligne par ÉQUIPE et une colonne par jour RÉELLEMENT utilisé", () => {
    const model = buildClubView([slot({ id: "a", teamId: "t1", dayOfWeek: 1 }), slot({ id: "b", teamId: "t2", dayOfWeek: 4 })], lookups, new Set(), tiers);

    expect(model.dayColumns.map((d) => d.day)).toEqual([1, 4]);
    expect(model.dayColumns.map((d) => d.label)).toEqual(["Lundi", "Jeudi"]);
    // Chaque ligne porte AUTANT de cellules que de colonnes : la cellule sous « Jeudi »
    // lit toujours le jeudi de CETTE équipe, jamais un voisin positionnel.
    for (const row of model.groups.flatMap((g) => g.rows)) {
      expect(row.cells.map((c) => c.day)).toEqual([1, 4]);
    }
  });

  it("garde la ligne d'une équipe SANS séance — c'est le trou que le gestionnaire doit voir", () => {
    const model = buildClubView([slot({ id: "a", teamId: "t1" })], lookups, new Set(), tiers);

    const orphan = rowOf(model, "t3");
    expect(orphan).toBeDefined();
    expect(orphan?.sessionCount).toBe(0);
    expect(orphan?.cells.every((c) => 0 === c.entries.length)).toBe(true);
  });

  it("empile les DEUX séances d'une équipe le même jour, triées par heure", () => {
    const model = buildClubView(
      [
        slot({ id: "late", teamId: "t1", dayOfWeek: 2, startTime: "20:30:00", venueId: "v2" }),
        slot({ id: "early", teamId: "t1", dayOfWeek: 2, startTime: "18:00:00", venueId: "v1" }),
      ],
      lookups,
      new Set(),
      tiers,
    );

    const cell = rowOf(model, "t1")?.cells.find((c) => 2 === c.day);
    expect(cell?.entries.map((e) => e.slotId)).toEqual(["early", "late"]);
    expect(cell?.entries.map((e) => e.venueLabel)).toEqual(["Matéo", "Debarros"]);
    expect(cell?.entries[0]?.startLabel).toBe("18:00");
    expect(cell?.entries[0]?.venueColor).toBe("#ff0000");
    expect(rowOf(model, "t1")?.sessionCount).toBe(2);
  });

  it("groupe les lignes par RANG et les ordonne rang → ordre manuel → nom", () => {
    const model = buildClubView([slot({ id: "a", teamId: "t1" }), slot({ id: "b", teamId: "t2" }), slot({ id: "c", teamId: "t3" })], lookups, new Set(), tiers);

    expect(model.groups.map((g) => g.label)).toEqual(["S · Fanion", "A · Importante"]);
    expect(model.groups[1]?.rows.map((r) => r.teamId)).toEqual(["t2", "t3"]);
  });

  it("sans rangs chargés, rend un groupe plat plutôt que de perdre des équipes", () => {
    const model = buildClubView([slot({ id: "a", teamId: "t1" })], lookups, new Set(), []);

    expect(model.groups).toHaveLength(1);
    expect(model.groups[0]?.label).toBeNull();
    expect(model.groups[0]?.rows.map((r) => r.teamId).sort()).toEqual(["t1", "t2", "t3"]);
  });

  it("le filtre d'équipes rétrécit les LIGNES, et les colonnes suivent ce qui reste", () => {
    const model = buildClubView([slot({ id: "a", teamId: "t1", dayOfWeek: 1 }), slot({ id: "b", teamId: "t2", dayOfWeek: 4 })], lookups, new Set(["t2"]), tiers);

    expect(model.groups.flatMap((g) => g.rows).map((r) => r.teamId)).toEqual(["t2"]);
    expect(model.dayColumns.map((d) => d.day)).toEqual([4]);
  });

  it("n'invente jamais d'équipe : un placement dont l'équipe est inconnue garde sa ligne", () => {
    const model = buildClubView([slot({ id: "a", teamId: "ghost" })], lookups, new Set(), tiers);

    const ghost = rowOf(model, "ghost");
    expect(ghost).toBeDefined();
    expect(ghost?.teamLabel).toBe("Équipe ?");
    expect(ghost?.sessionCount).toBe(1);
  });

  it("ignore les FENÊTRES VIDES : elles n'appartiennent à aucune équipe", () => {
    const model = buildClubView([slot({ id: "a", teamId: "t1" }), slot({ id: "empty:v1:1:1080", teamId: "" })], lookups, new Set(), tiers);

    expect(model.groups.flatMap((g) => g.rows).some((r) => "" === r.teamId)).toBe(false);
    expect(rowOf(model, "t1")?.sessionCount).toBe(1);
  });

  it("porte le verrou et son ORIGINE sur l'entrée, pour la lentille", () => {
    const model = buildClubView([slot({ id: "a", teamId: "t1", lockLevel: "HARD", lockOrigin: "RESERVATION" })], lookups, new Set(), tiers);

    const entry = rowOf(model, "t1")?.cells[0]?.entries[0];
    expect(entry?.locked).toBe(true);
    expect(entry?.lockOrigin).toBe("RESERVATION");
  });
});
