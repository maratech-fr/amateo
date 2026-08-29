import { describe, expect, it } from "vitest";

import type { Coach, Slot, Team, Venue } from "../api";
import { availableResourceGroups, availableResources, buildGrid, computeTimeBounds, concernedSlots, formatMinutes, type Lookups, NO_COACH, parseTimeToMinutes, resourceKeysForSlot, toHourMinute } from "./grid";

function slot(over: Partial<Slot>): Slot {
  return {
    id: "id",
    scheduleId: "s",
    teamId: "t1",
    venueId: "v1",
    coachId: "c1",
    dayOfWeek: 1,
    startTime: "18:00:00",
    durationMinutes: 90,
    lockLevel: "NONE",
    lockOrigin: null,
    ...over,
  };
}

const lookups: Lookups = {
  teams: new Map<string, Team>([
    ["t1", { id: "t1", name: "U11", sportCategoryId: "cat1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }],
    ["t2", { id: "t2", name: "U13", sportCategoryId: "cat1", priorityTierId: 2, tierOrder: 0, sessionsPerWeek: 2 }],
  ]),
  venues: new Map<string, Venue>([
    ["v1", { id: "v1", name: "Alpha", color: "#ff0000" }],
    ["v2", { id: "v2", name: "Beta", color: null }],
  ]),
  coaches: new Map<string, Coach>([
    ["c1", { id: "c1", firstName: "Jean", lastName: "Paul" }],
    ["c9", { id: "c9", firstName: "Team", lastName: "Coach" }],
  ]),
  teamCoach: new Map<string, string>(),
  teamPlayerCoaches: new Map<string, string[]>(),
};

describe("time helpers", () => {
  it("parses and formats", () => {
    expect(parseTimeToMinutes("18:00:00")).toBe(1080);
    expect(parseTimeToMinutes("18:30")).toBe(1110);
    // The API serializes TimeImmutable as an ISO datetime — the time must still parse.
    expect(parseTimeToMinutes("1970-01-01T18:30:00+00:00")).toBe(1110);
    expect(toHourMinute("1970-01-01T18:05:00+00:00")).toBe("18:05");
    expect(formatMinutes(1080)).toBe("18:00");
    expect(formatMinutes(1110)).toBe("18:30");
  });

  it("computes bounds floored/ceiled to the hour, with fallback", () => {
    expect(computeTimeBounds([])).toEqual({ startMin: 17 * 60, endMin: 21 * 60 });
    const bounds = computeTimeBounds([slot({ startTime: "18:15:00", durationMinutes: 90 })]);
    expect(bounds).toEqual({ startMin: 1080, endMin: 1200 });
  });
});

describe("resourceKeysForSlot", () => {
  const s = slot({ venueId: "v1", coachId: "c1", teamId: "t1" });
  it("maps per view (single key)", () => {
    expect(resourceKeysForSlot(s, "gymnase", lookups)).toEqual(["v1"]);
    expect(resourceKeysForSlot(s, "coach", lookups)).toEqual(["c1"]);
    expect(resourceKeysForSlot(s, "equipe", lookups)).toEqual(["t1"]);
  });
  it("buckets a coachless slot with no team coach", () => {
    expect(resourceKeysForSlot(slot({ coachId: null }), "coach", lookups)).toEqual([NO_COACH]);
  });
  it("falls back to the team's main coach when the slot has none", () => {
    const withTeamCoach = { ...lookups, teamCoach: new Map([["t1", "c9"]]) };
    expect(resourceKeysForSlot(slot({ coachId: null, teamId: "t1" }), "coach", withTeamCoach)).toEqual(["c9"]);
  });
  it("also surfaces the coach under teams where he is a player", () => {
    const withPlayers = { ...lookups, teamCoach: new Map([["t1", "c9"]]), teamPlayerCoaches: new Map([["t1", ["p1"]]]) };
    expect(resourceKeysForSlot(slot({ coachId: null, teamId: "t1" }), "coach", withPlayers).sort()).toEqual(["c9", "p1"]);
  });
});

describe("availableResourceGroups", () => {
  const tiers = [
    { id: 1, label: "S", name: "Fanion" },
    { id: 2, label: "A", name: "Importante" },
  ];
  const slots = [
    slot({ id: "a", teamId: "t2", dayOfWeek: 1 }), // rank A first in slot order…
    slot({ id: "b", teamId: "t1", dayOfWeek: 2 }),
  ];

  it("groups the equipe view by rank with visible tier headers, S before A", () => {
    const groups = availableResourceGroups(slots, "equipe", lookups, tiers);
    expect(groups.map((g) => g.label)).toEqual(["S · Fanion", "A · Importante"]);
    expect(groups[0].resources.map((r) => r.id)).toEqual(["t1"]); // …but S (t1) leads
    expect(groups[1].resources.map((r) => r.id)).toEqual(["t2"]);
  });

  it("keeps other views (and equipe while tiers load) a single flat unlabelled group", () => {
    const gymnase = availableResourceGroups(slots, "gymnase", lookups, tiers);
    expect(gymnase).toHaveLength(1);
    expect(gymnase[0].label).toBeNull();

    const noTiers = availableResourceGroups(slots, "equipe", lookups, []);
    expect(noTiers).toHaveLength(1);
    expect(noTiers[0].resources.map((r) => r.id)).toEqual(["t1", "t2"]); // still rank-sorted
  });

  it("keeps a resource missing from the team lookup visible in a trailing flat group", () => {
    const groups = availableResourceGroups([...slots, slot({ id: "c", teamId: "ghost", dayOfWeek: 3 })], "equipe", lookups, tiers);
    const last = groups[groups.length - 1];
    expect(last.label).toBeNull();
    expect(last.resources.map((r) => r.id)).toEqual(["ghost"]);
  });
});

describe("buildGrid", () => {
  const slots = [
    slot({ id: "a", venueId: "v1", teamId: "t1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90 }),
    slot({ id: "b", venueId: "v2", teamId: "t2", dayOfWeek: 1, startTime: "19:00:00", durationMinutes: 60 }),
  ];

  it("places slots into day/resource columns and 15-min rows (gymnase)", () => {
    const model = buildGrid(slots, "gymnase", lookups);
    expect(model.columns.map((c) => c.label)).toEqual(["Alpha", "Beta"]);
    expect(model.dayGroups).toEqual([{ day: 1, label: "Lun", startColumn: 2, span: 2 }]);
    // 18:00→20:00 (b ends 20:00) at 15-min steps, labels only on the half hour.
    expect(model.rows.map((r) => r.label)).toEqual(["18:00", null, "18:30", null, "19:00", null, "19:30", null]);

    const a = model.cells.find((c) => c.slotId === "a")!;
    expect(a.gridColumn).toBe(2);
    expect(a.gridRowStart).toBe(3);
    expect(a.gridRowSpan).toBe(6); // 90min / 15
    expect(a.venueColor).toBe("#ff0000");

    const b = model.cells.find((c) => c.slotId === "b")!;
    expect(b.gridColumn).toBe(3);
    expect(b.gridRowStart).toBe(7); // 19:00 is 4 rows after 18:00
    expect(b.gridRowSpan).toBe(4); // 60min / 15
  });

  it("distinguishes quarter-hour starts/durations (real placement)", () => {
    const model = buildGrid(
      [
        slot({ id: "u21", startTime: "20:15:00", durationMinutes: 135 }),
        slot({ id: "indiv", startTime: "20:30:00", durationMinutes: 120 }),
      ],
      "gymnase",
      lookups,
    );
    const u21 = model.cells.find((c) => c.slotId === "u21")!;
    const indiv = model.cells.find((c) => c.slotId === "indiv")!;
    // Same end (22:30) but different starts → different heights.
    expect(u21.gridRowSpan).toBe(9);
    expect(indiv.gridRowSpan).toBe(8);
    expect(u21.gridRowStart).toBe(indiv.gridRowStart - 1);
  });

  it("re-groups the same slots when the view changes (equipe)", () => {
    const model = buildGrid(slots, "equipe", lookups);
    expect(model.columns.map((c) => c.label)).toEqual(["U11", "U13"]);
    expect(model.cells).toHaveLength(2);
  });

  it("hides empty columns and applies the resource filter", () => {
    const filtered = buildGrid(slots, "gymnase", lookups, new Set(["v1"]));
    expect(filtered.columns.map((c) => c.label)).toEqual(["Alpha"]);
    expect(filtered.cells).toHaveLength(1);
  });

  it("drops slots on an ABERRANT day, but no longer on Sunday", () => {
    // ⚠ Ce test épinglait « hors Lun-Sam » : il gardait précisément le défaut que la revue
    // de P4-37 a levé (une séance du dimanche escamotée alors qu'elle est placée par le
    // solveur et imprimée par l'export). Re-visé sur ce qui reste vrai : la garde protège
    // d'un jour hors semaine ISO, pas du dimanche.
    const aberrant = buildGrid([slot({ id: "day9", dayOfWeek: 9 })], "gymnase", lookups);
    expect(aberrant.columns).toHaveLength(0);
    expect(aberrant.cells).toHaveLength(0);

    const sunday = buildGrid([slot({ id: "sun", dayOfWeek: 7 })], "gymnase", lookups);
    expect(sunday.cells).toHaveLength(1);
  });

  it("coach filter shows the slot only under the selected coach (no co-player columns)", () => {
    // Slot's team is coached by c9 and has player-coaches p1, p2 → 3 possible columns.
    const withCoaches = {
      ...lookups,
      teamCoach: new Map([["t1", "c9"]]),
      teamPlayerCoaches: new Map([["t1", ["p1", "p2"]]]),
    };
    const s = slot({ id: "sf2", coachId: null, teamId: "t1", dayOfWeek: 1 });
    expect(buildGrid([s], "coach", withCoaches).columns).toHaveLength(3);
    // Focused on c9: only c9's column, one cell.
    const focused = buildGrid([s], "coach", withCoaches, new Set(["c9"]));
    expect(focused.columns.map((c) => c.resourceId)).toEqual(["c9"]);
    expect(focused.cells).toHaveLength(1);
  });

  it("flags locked slots", () => {
    const model = buildGrid([slot({ id: "l", lockLevel: "HARD" })], "gymnase", lookups);
    expect(model.cells[0].locked).toBe(true);
  });

  it("lays time-overlapping slots of a column into side-by-side lanes", () => {
    const model = buildGrid(
      [
        slot({ id: "o1", venueId: "v1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 60 }),
        slot({ id: "o2", venueId: "v1", dayOfWeek: 1, startTime: "18:30:00", durationMinutes: 60 }),
      ],
      "gymnase",
      lookups,
    );
    const o1 = model.cells.find((c) => c.slotId === "o1")!;
    const o2 = model.cells.find((c) => c.slotId === "o2")!;
    expect(o1.gridColumn).toBe(o2.gridColumn);
    expect(o1.laneCount).toBe(2);
    expect(o2.laneCount).toBe(2);
    expect(new Set([o1.lane, o2.lane])).toEqual(new Set([0, 1]));
  });

  it("keeps non-overlapping slots in a single lane", () => {
    const model = buildGrid(
      [
        slot({ id: "n1", venueId: "v1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 60 }),
        slot({ id: "n2", venueId: "v1", dayOfWeek: 1, startTime: "19:00:00", durationMinutes: 60 }),
      ],
      "gymnase",
      lookups,
    );
    expect(model.cells.every((c) => c.laneCount === 1 && c.lane === 0)).toBe(true);
  });
});

describe("buildGrid — libellé de groupe / fusion (P2-17 D4)", () => {
  const u15: Team = { id: "t3", name: "U15", sportCategoryId: "cat1", priorityTierId: 3, tierOrder: 0, sessionsPerWeek: 2 };
  const withLabels = (entries: [string, string][], teams?: Map<string, Team>): Lookups => ({
    ...lookups,
    teams: teams ?? lookups.teams,
    groupLabels: new Map(entries),
  });
  const shared = [
    slot({ id: "s1", venueId: "v1", teamId: "t1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90 }),
    slot({ id: "s2", venueId: "v1", teamId: "t2", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90 }),
  ];

  it("fusionne les équipes d'un créneau mutualisé libellé en UNE carte titrée (vue gymnase)", () => {
    const model = buildGrid(shared, "gymnase", withLabels([["v1|1|1080", "CEC3"]]));
    const groups = model.cells.filter((c) => null !== c.groupLabel);
    expect(groups).toHaveLength(1);
    expect(groups[0].groupLabel).toBe("CEC3");
    expect(groups[0].laneCount).toBe(1); // pleine largeur, pas en couloirs
    expect(groups[0].members.map((m) => m.teamLabel).sort()).toEqual(["U11", "U13"]);
    // Aucune cellule ordinaire résiduelle pour ces séances.
    expect(model.cells.filter((c) => null === c.groupLabel)).toHaveLength(0);
  });

  it("garde chaque équipe cliquable — les slotId des membres sont conservés", () => {
    const model = buildGrid(shared, "gymnase", withLabels([["v1|1|1080", "CEC3"]]));
    const group = model.cells.find((c) => null !== c.groupLabel)!;
    expect(group.members.map((m) => m.slotId).sort()).toEqual(["s1", "s2"]);
  });

  it("fusionne trois équipes d'un terrain divisé en trois", () => {
    const three = [
      slot({ id: "a", venueId: "v1", teamId: "t1", dayOfWeek: 1, startTime: "18:00:00" }),
      slot({ id: "b", venueId: "v1", teamId: "t2", dayOfWeek: 1, startTime: "18:00:00" }),
      slot({ id: "c", venueId: "v1", teamId: "t3", dayOfWeek: 1, startTime: "18:00:00" }),
    ];
    const teams = new Map([...lookups.teams, ["t3", u15] as const]);
    const model = buildGrid(three, "gymnase", withLabels([["v1|1|1080", "CEC3"]], teams));
    const groups = model.cells.filter((c) => null !== c.groupLabel);
    expect(groups).toHaveLength(1);
    expect(groups[0].members).toHaveLength(3);
  });

  it("sans libellé, les équipes du même créneau restent des cartes séparées (couloirs)", () => {
    const model = buildGrid(shared, "gymnase", lookups); // pas de groupLabels
    expect(model.cells.every((c) => null === c.groupLabel && 0 === c.members.length)).toBe(true);
    expect(model.cells).toHaveLength(2);
    expect(model.cells.every((c) => c.laneCount === 2)).toBe(true);
  });

  it("une seule équipe sous un libellé ne fusionne pas (« plusieurs » requiert ≥ 2)", () => {
    const solo = [slot({ id: "only", venueId: "v1", teamId: "t1", dayOfWeek: 1, startTime: "18:00:00" })];
    const model = buildGrid(solo, "gymnase", withLabels([["v1|1|1080", "CEC3"]]));
    expect(model.cells).toHaveLength(1);
    expect(model.cells[0].groupLabel).toBeNull();
  });

  it("ne fusionne jamais hors vue gymnase (équipe)", () => {
    const model = buildGrid(shared, "equipe", withLabels([["v1|1|1080", "CEC3"]]));
    expect(model.cells.every((c) => null === c.groupLabel)).toBe(true);
    expect(model.cells).toHaveLength(2);
  });

  it("des créneaux libellés différemment ne se fusionnent pas ensemble", () => {
    // Deux fenêtres distinctes (heures différentes), chacune son libellé : aucune fusion
    // croisée — la clé de fusion porte gymnase|jour|début|libellé.
    const twoWindows = [
      slot({ id: "e", venueId: "v1", teamId: "t1", dayOfWeek: 1, startTime: "18:00:00" }),
      slot({ id: "l", venueId: "v1", teamId: "t2", dayOfWeek: 1, startTime: "20:00:00" }),
    ];
    const model = buildGrid(twoWindows, "gymnase", withLabels([["v1|1|1080", "CEC3"], ["v1|1|1200", "CEC4"]]));
    expect(model.cells.every((c) => null === c.groupLabel)).toBe(true);
    expect(model.cells).toHaveLength(2);
  });
});

describe("vue « Par jour » (P2-33)", () => {
  // La 4ᵉ vue : l'axe FILTRABLE est le jour (clé = numéro ISO, libellé = nom du jour),
  // mais les COLONNES de la grille restent les gymnases — filtrer sur lundi ramène les
  // ~5 gymnases de ce jour au lieu des ~40 colonnes jour×gymnase.
  it("expose le JOUR comme ressource (clé = ISO, libellé = nom du jour)", () => {
    expect(resourceKeysForSlot(slot({ dayOfWeek: 1 }), "jour", lookups)).toEqual(["1"]);
    expect(resourceKeysForSlot(slot({ dayOfWeek: 7 }), "jour", lookups)).toEqual(["7"]);
  });

  it("liste les jours dans l'ordre lundi→dimanche, pas alphabétique", () => {
    // Dimanche AVANT lundi trierait « Dim » en tête en alphabétique — l'ordre doit rester ISO.
    const slots = [slot({ id: "sun", venueId: "v1", dayOfWeek: 7 }), slot({ id: "mon", venueId: "v2", dayOfWeek: 1 })];
    // Libellés EN TOUTES LETTRES dans le filtre (retour fondateur) — l'abrégé reste
    // aux en-têtes de la grille, où la place manque.
    expect(availableResources(slots, "jour", lookups)).toEqual([
      { id: "1", label: "lundi" },
      { id: "7", label: "dimanche" },
    ]);
  });

  it("garde les GYMNASES en colonnes (le layout n'est pas réécrit sur le jour)", () => {
    const slots = [
      slot({ id: "a", venueId: "v1", dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90 }),
      slot({ id: "b", venueId: "v2", dayOfWeek: 2, startTime: "18:00:00", durationMinutes: 90 }),
    ];
    const model = buildGrid(slots, "jour", lookups);
    // Colonnes = gymnases (pas des jours) ; les jours restent les super-colonnes.
    expect(model.columns.map((c) => c.resourceId)).toEqual(["v1", "v2"]);
    expect(model.columns.map((c) => c.label)).toEqual(["Alpha", "Beta"]);
    expect(model.dayGroups.map((g) => g.label)).toEqual(["Lun", "Mar"]);
    // La couleur de gymnase transite comme en vue gymnase (colonnes = gymnases).
    expect(model.columns.find((c) => c.resourceId === "v1")?.color).toBe("#ff0000");
  });

  it("un filtre sur un jour ne garde QUE ce jour — tous ses gymnases", () => {
    const slots = [
      slot({ id: "a", venueId: "v1", dayOfWeek: 1, startTime: "18:00:00" }),
      slot({ id: "b", venueId: "v2", dayOfWeek: 1, startTime: "18:00:00" }),
      slot({ id: "c", venueId: "v1", dayOfWeek: 2, startTime: "18:00:00" }),
    ];
    const model = buildGrid(slots, "jour", lookups, new Set(["1"]));
    // Lundi seul : ses deux gymnases restent, mardi disparaît.
    expect(model.dayGroups.map((g) => g.label)).toEqual(["Lun"]);
    expect(model.columns.map((c) => c.resourceId).sort()).toEqual(["v1", "v2"]);
    expect(model.cells.map((c) => c.slotId).sort()).toEqual(["a", "b"]);
  });
});

describe("availableResources", () => {
  it("lists distinct resources for the view, sorted", () => {
    const slots = [slot({ venueId: "v2" }), slot({ venueId: "v1" }), slot({ venueId: "v1" })];
    expect(availableResources(slots, "gymnase", lookups)).toEqual([
      { id: "v1", label: "Alpha" },
      { id: "v2", label: "Beta" },
    ]);
  });

  it("orders TEAMS by rank (tier), not alphabetically", () => {
    const ranked: Lookups = {
      ...lookups,
      teams: new Map<string, Team>([
        ["t1", { id: "t1", name: "Alpha", sportCategoryId: "c", priorityTierId: 5, tierOrder: 0, sessionsPerWeek: 2 }], // D
        ["t2", { id: "t2", name: "Zoulou", sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 2 }], // S (fanion)
      ]),
    };
    const slots = [slot({ teamId: "t1" }), slot({ teamId: "t2" })];
    // Zoulou is the fanion (S) → first, even though "Alpha" sorts first alphabetically.
    expect(availableResources(slots, "equipe", ranked).map((r) => r.id)).toEqual(["t2", "t1"]);
  });

  it("keeps COACHES alphabetical", () => {
    const slots = [slot({ coachId: "c9" }), slot({ coachId: "c1" })];
    expect(availableResources(slots, "coach", lookups).map((r) => r.label)).toEqual(["Jean Paul", "Team Coach"]);
  });
});

describe("concernedSlots", () => {
  it("lists the slots a venue conflict points at, with day + time + team", () => {
    const slots = [
      slot({ id: "x", venueId: "v1", teamId: "t1", dayOfWeek: 1, startTime: "18:00:00" }),
      slot({ id: "y", venueId: "v1", teamId: "t2", dayOfWeek: 1, startTime: "18:00:00" }),
      slot({ id: "z", venueId: "v2", teamId: "t1", dayOfWeek: 2, startTime: "19:00:00" }),
    ];
    const result = concernedSlots({ teamId: null, venueId: "v1", coachId: null }, slots, lookups);
    expect(result.map((r) => r.slotId)).toEqual(["x", "y"]);
    expect(result[0]).toMatchObject({ dayLabel: "Lun", timeLabel: "18:00", teamLabel: "U11", venueLabel: "Alpha" });
  });

  it("P4-95 — un conflit de COACH (jour+heure, SANS gymnase) resserre sur les 2 créneaux du choc", () => {
    // `diag-conflict-coach-*` ne porte PAS de venueId : tout son sens est que le coach est
    // attendu dans DEUX gymnases au même moment. L'ancienne clé exigeait le gymnase, donc
    // ce diagnostic retombait sur « tous les créneaux du coach » — y compris un autre jour.
    const slots = [
      slot({ id: "clash-a", coachId: "c1", venueId: "v1", teamId: "t1", dayOfWeek: 3, startTime: "18:00:00" }),
      slot({ id: "clash-b", coachId: "c1", venueId: "v2", teamId: "t2", dayOfWeek: 3, startTime: "18:00:00" }),
      slot({ id: "ailleurs", coachId: "c1", venueId: "v1", teamId: "t1", dayOfWeek: 5, startTime: "18:00:00" }),
    ];
    const result = concernedSlots({ teamId: null, venueId: null, coachId: "c1", dayOfWeek: 3, startTime: "18:00" }, slots, lookups);
    expect(result.map((r) => r.slotId)).toEqual(["clash-a", "clash-b"]);
    expect(result.map((r) => r.slotId)).not.toContain("ailleurs");
  });

  it("un conflict PORTANT (venue, jour, heure) resserre sur CE créneau — pas tout le gymnase", () => {
    const slots = [
      slot({ id: "a", venueId: "v1", teamId: "t1", dayOfWeek: 6, startTime: "10:00:00" }),
      slot({ id: "b", venueId: "v1", teamId: "t2", dayOfWeek: 6, startTime: "10:00:00" }),
      // Même gymnase, autre heure/jour : NE doit PAS être retenu.
      slot({ id: "c", venueId: "v1", teamId: "t1", dayOfWeek: 6, startTime: "18:00:00" }),
      slot({ id: "d", venueId: "v1", teamId: "t1", dayOfWeek: 3, startTime: "10:00:00" }),
    ];
    // L'engine émet « HH:MM » ; les slots sont en « HH:MM:SS » → comparaison à la minute.
    const result = concernedSlots({ teamId: null, venueId: "v1", coachId: null, dayOfWeek: 6, startTime: "10:00" }, slots, lookups);
    expect(result.map((r) => r.slotId)).toEqual(["a", "b"]);
  });

  it("sans jour/heure, garde le comportement large (venue/team/coach)", () => {
    const slots = [
      slot({ id: "a", venueId: "v1", dayOfWeek: 6, startTime: "10:00:00" }),
      slot({ id: "c", venueId: "v1", dayOfWeek: 6, startTime: "18:00:00" }),
    ];
    // dayOfWeek/startTime null → on retombe sur la correspondance gymnase (les DEUX créneaux).
    const result = concernedSlots({ teamId: null, venueId: "v1", coachId: null, dayOfWeek: null, startTime: null }, slots, lookups);
    expect(result.map((r) => r.slotId).sort()).toEqual(["a", "c"]);
  });
});

describe("le dimanche dans la boucle de travail (revue P4-37)", () => {
  it("rend une séance du dimanche au lieu de l'escamoter", () => {
    // `buildGrid` filtrait `dayOfWeek <= 6` : une séance du dimanche — que le backend
    // accepte, que le solveur place et que l'export serveur imprime — disparaissait de
    // l'écran où le planning se travaille. Le gestionnaire lisait un planning à six
    // colonnes qui se donnait pour complet.
    const grid = buildGrid([slot({ id: "dim", dayOfWeek: 7, startTime: "10:00:00" })], "equipe", lookups);

    expect(grid.dayGroups.map((g) => g.label)).toEqual(["Dim"]);
    expect(grid.cells.map((c) => c.slotId)).toEqual(["dim"]);
  });

  it("n'ajoute AUCUNE colonne quand personne ne s'entraîne le dimanche", () => {
    // Le 7ᵉ jour ne coûte rien aux ≈95 % de clubs qui ne l'utilisent pas : un jour sans
    // séance reste masqué. C'est ce qui rend la règle applicable sans arbitrage.
    const grid = buildGrid([slot({ dayOfWeek: 2 })], "equipe", lookups);

    expect(grid.dayGroups.map((g) => g.label)).toEqual(["Mar"]);
  });
});

describe("lockOrigin transite dans la grille (lentille verrous, PR 3)", () => {
  // La lentille colore un créneau par l'ORIGINE de son verrou (MANUAL/RESERVATION/UNKNOWN) :
  // ce champ, déjà porté par le Slot, doit atteindre la cellule ET chaque membre d'une carte
  // fusionnée — sinon WeekGrid ne pourrait pas les distinguer.
  it("porte lockOrigin sur une cellule ordinaire", () => {
    const grid = buildGrid([slot({ id: "a", lockLevel: "HARD", lockOrigin: "MANUAL" })], "gymnase", lookups);
    expect(grid.cells.find((c) => c.slotId === "a")?.lockOrigin).toBe("MANUAL");
  });

  it("laisse lockOrigin à null sur un créneau non verrouillé", () => {
    const grid = buildGrid([slot({ id: "a", lockLevel: "NONE", lockOrigin: null })], "gymnase", lookups);
    expect(grid.cells.find((c) => c.slotId === "a")?.lockOrigin).toBeNull();
  });

  it("porte lockOrigin sur chaque membre d'une carte fusionnée", () => {
    const mergeLookups: Lookups = { ...lookups, groupLabels: new Map<string, string>([["v1|1|1080", "CEC3"]]) };
    const grid = buildGrid(
      [
        slot({ id: "s1", teamId: "t1", lockLevel: "HARD", lockOrigin: "RESERVATION" }),
        slot({ id: "s2", teamId: "t2", lockLevel: "NONE", lockOrigin: null }),
      ],
      "gymnase",
      mergeLookups,
    );
    const card = grid.cells.find((c) => null !== c.groupLabel);
    expect(card?.members.map((m) => m.lockOrigin)).toEqual(["RESERVATION", null]);
    // Le lockOrigin de tête de carte = celui de la première séance du groupe.
    expect(card?.lockOrigin).toBe("RESERVATION");
  });
});
