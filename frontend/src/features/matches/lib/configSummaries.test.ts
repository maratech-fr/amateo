import { describe, expect, it } from "vitest";

import type { Competition, MatchSlotRotation, OpponentTravel, SportCategoryDuration, Venue } from "../api";
import { deadlinesSummary, durationsSummary, labelsSummary, opponentsSummary, rotationsSummary } from "./configSummaries";

const venue = (over: Partial<Venue> = {}): Venue => ({ id: "v1", name: "Gymnase Alpha", color: null, externalLabels: [], ...over });

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

const travel = (over: Partial<OpponentTravel> = {}): OpponentTravel => ({
  opponentOrganismeCode: "ORG",
  opponentLabel: "Adversaire",
  located: true,
  precision: null,
  locationName: null,
  travelMinutes: null,
  approximated: false,
  source: null,
  overrideVenueLabel: null,
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

describe("opponentsSummary", () => {
  it("undefined ⇒ null", () => {
    expect(opponentsSummary(undefined)).toBeNull();
  });

  it("aucun adversaire ⇒ « aucun adversaire »", () => {
    expect(opponentsSummary([])).toBe("aucun adversaire");
  });

  it("tous localisés (N=0, M>0) ⇒ « tous localisés »", () => {
    expect(opponentsSummary([travel(), travel({ opponentLabel: "B" })])).toBe("tous localisés");
  });

  it("des non localisés ⇒ « N à localiser sur M »", () => {
    expect(opponentsSummary([travel({ located: false }), travel({ opponentLabel: "B" }), travel({ opponentLabel: "C", located: false })])).toBe("2 à localiser sur 3");
  });
});

describe("labelsSummary (P4-196)", () => {
  it("undefined (chargement/échec) ⇒ null — en-tête muet, jamais un « 0 » fabriqué", () => {
    expect(labelsSummary(undefined)).toBeNull();
  });

  it("aucun gymnase à alias ⇒ « aucun libellé rattaché »", () => {
    expect(labelsSummary([venue(), venue({ id: "v2" })])).toBe("aucun libellé rattaché");
  });

  it("compte les GYMNASES à alias, jamais les alias : 1 gymnase / 3 alias ⇒ « 1 gymnase nommé par la FFBB »", () => {
    expect(labelsSummary([venue({ externalLabels: ["gymnase mateo", "salle mateo", "mateo"] }), venue({ id: "v2" })])).toBe("1 gymnase nommé par la FFBB");
  });

  it("plusieurs gymnases à alias ⇒ pluriel", () => {
    expect(labelsSummary([venue({ externalLabels: ["gymnase a"] }), venue({ id: "v2", externalLabels: ["gymnase b", "salle b"] }), venue({ id: "v3" })])).toBe("2 gymnases nommés par la FFBB");
  });
});
