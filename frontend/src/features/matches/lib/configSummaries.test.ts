import { describe, expect, it } from "vitest";

import type { Competition, MatchSlotRotation, SportCategoryDuration, Venue, VenueLabelInventoryRow, VenueMatchWindow } from "../api";
import { accessSummary, deadlinesSummary, durationsSummary, labelsSummary, rotationsSummary } from "./configSummaries";

const venue = (id: string): Venue => ({ id, name: `Gymnase ${id}`, color: null, externalLabels: [] });
const matchWindow = (venueId: string): VenueMatchWindow => ({ id: `${venueId}-w`, venueId, dayOfWeek: 6, startTime: "14:00", endTime: "22:00" });

const invRow = (over: Partial<VenueLabelInventoryRow> = {}): VenueLabelInventoryRow => ({
  labelKey: "gymnase mateo",
  displayLabel: "GYMNASE MATEO",
  venueId: null,
  suggestedVenueId: null,
  homeCount: 0,
  placedCount: 0,
  unplacedCount: 0,
  ...over,
});

const rotation = (id: string): MatchSlotRotation => ({ id, venueId: "v1", dayOfWeek: 6, kickoffTime: "20:30", teamIds: ["t1", "t2"] });

const competition = (over: Partial<Competition> = {}): Competition => ({ id: "c1", teamId: "t1", name: "PNM", competitionType: "championnat", ...over });

const category = (over: Partial<SportCategoryDuration> = {}): SportCategoryDuration => ({
  id: "cat",
  sportId: "sport-1",
  name: "U13",
  matchMinutes: null,
  warmupMinutes: null,
  defaultMatchMinutes: 90,
  defaultWarmupMinutes: 30,
  ...over,
});


describe("rotationsSummary", () => {
  it("undefined (chargement/échec) ⇒ null — jamais un « 0 » fabriqué", () => {
    expect(rotationsSummary(undefined)).toBeNull();
  });

  it("aucune ⇒ « aucune rotation »", () => {
    expect(rotationsSummary([])).toBe("aucune rotation");
  });

  it("une seule ⇒ singulier", () => {
    expect(rotationsSummary([rotation("r1")])).toBe("1 rotation");
  });

  it("plusieurs ⇒ pluriel", () => {
    expect(rotationsSummary([rotation("r1"), rotation("r2"), rotation("r3")])).toBe("3 rotations");
  });
});

describe("deadlinesSummary", () => {
  it("undefined ⇒ null", () => {
    expect(deadlinesSummary(undefined)).toBeNull();
  });

  it("compte les échéances EFFECTIVES (club OU proposée), pas les seuls overrides club", () => {
    // c1 : valeur club ; c2 : défaut communauté proposé ; c3 : aucune échéance résolue.
    const competitions = [
      competition({ id: "c1", entryDeadline: "2026-10-01", effectiveEntryDeadline: "2026-10-01", deadlineSource: "club" }),
      competition({ id: "c2", entryDeadline: null, effectiveEntryDeadline: "2026-10-15", deadlineSource: "community" }),
      competition({ id: "c3", entryDeadline: null, effectiveEntryDeadline: null, deadlineSource: null }),
    ];
    expect(deadlinesSummary(competitions)).toBe("2 renseignées sur 3 compétitions");
  });

  it("une seule compétition renseignée ⇒ tout au singulier", () => {
    expect(deadlinesSummary([competition({ effectiveEntryDeadline: "2026-10-01" })])).toBe("1 renseignée sur 1 compétition");
  });

  it("aucune compétition ⇒ « 0 sur 0 »", () => {
    expect(deadlinesSummary([])).toBe("0 renseignée sur 0 compétition");
  });
});

describe("durationsSummary", () => {
  it("undefined ⇒ null", () => {
    expect(durationsSummary(undefined)).toBeNull();
  });

  it("aucune valeur propre ⇒ « défauts par catégorie » (jamais 75/90/105 en dur)", () => {
    expect(durationsSummary([category(), category({ id: "cat2" })])).toBe("défauts par catégorie");
  });

  it("une valeur match OU échauffement non nulle ⇒ compte comme personnalisée", () => {
    expect(durationsSummary([category({ matchMinutes: 100 }), category({ id: "cat2" })])).toBe("1 personnalisée");
    expect(durationsSummary([category({ warmupMinutes: 20 }), category({ id: "cat2", matchMinutes: 100 })])).toBe("2 personnalisées");
  });
});

describe("labelsSummary (E2, P4-205)", () => {
  it("undefined (chargement/échec) ⇒ null — en-tête muet, jamais un « 0 non apparié » fabriqué", () => {
    expect(labelsSummary(undefined)).toBeNull();
  });

  it("inventaire vide ⇒ « aucun libellé importé »", () => {
    expect(labelsSummary([])).toBe("aucun libellé importé");
  });

  it("compte les libellés ET les non appariés (venueId null) : 3 libellés, 2 sans gymnase", () => {
    expect(
      labelsSummary([
        invRow({ labelKey: "a", venueId: "v1" }),
        invRow({ labelKey: "b", venueId: null }),
        invRow({ labelKey: "c", venueId: null }),
      ]),
    ).toBe("3 libellés · 2 non appariés");
  });

  it("un seul libellé, un seul non apparié ⇒ singulier des deux côtés", () => {
    expect(labelsSummary([invRow({ labelKey: "a", venueId: null })])).toBe("1 libellé · 1 non apparié");
  });

  it("tous appariés ⇒ « N libellés · 0 non apparié »", () => {
    expect(labelsSummary([invRow({ labelKey: "a", venueId: "v1" }), invRow({ labelKey: "b", venueId: "v2" })])).toBe("2 libellés · 0 non apparié");
  });
});

describe("accessSummary (PR 2a — la section « Accès match »)", () => {
  it("undefined (chargement/échec de l'une des lectures) ⇒ null", () => {
    expect(accessSummary(undefined, [venue("v1")])).toBeNull();
    expect(accessSummary([matchWindow("v1")], undefined)).toBeNull();
  });

  it("aucun gymnase avec accès ⇒ phrase dédiée", () => {
    expect(accessSummary([], [venue("v1"), venue("v2")])).toBe("aucun gymnase avec accès match");
  });

  it("compte les gymnases qui ONT au moins une fenêtre (pluriel)", () => {
    // v1 et v2 ont un accès (plusieurs fenêtres pour v1 ne comptent qu'une fois), v3 non.
    const windows = [matchWindow("v1"), matchWindow("v1"), matchWindow("v2")];
    expect(accessSummary(windows, [venue("v1"), venue("v2"), venue("v3")])).toBe("2 gymnases");
  });

  it("un seul gymnase avec accès ⇒ singulier", () => {
    expect(accessSummary([matchWindow("v1")], [venue("v1"), venue("v2")])).toBe("1 gymnase");
  });
});
