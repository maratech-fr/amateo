import { describe, expect, it } from "vitest";

import type { Conflict, ConflictFixtureView, ConflictTrainingView, Team, Venue } from "../api";
import { buildConflictSideLines } from "./conflictSideLines";

const teams = new Map<string, Team>([
  ["team-sf2", { id: "team-sf2", name: "SF2", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
  ["team-sm2", { id: "team-sm2", name: "SM2", sportCategoryId: "c", level: null, gender: null, priorityTierId: 1, tierOrder: 0 }],
]);
const venues = new Map<string, Venue>([["v-mateo", { id: "v-mateo", name: "Gymnase Mateo", color: null, externalLabels: [] }]]);

/** Un côté DOMICILE d'une rencontre (kickoff réel, durée servie). */
function homeSide(over: Partial<ConflictFixtureView> = {}): ConflictFixtureView {
  return {
    fixtureId: "fx-home",
    teamId: "team-sm2",
    homeAway: "HOME",
    matchDate: "2026-11-08",
    kickoffTime: "15:30",
    windowStart: "2026-11-08T15:00:00",
    windowEnd: "2026-11-08T17:25:00",
    matchDurationMinutes: 115,
    opponentLabel: "VAULX EN VELIN BASKET CLUB - 2",
    role: "PLAYER",
    ...over,
  };
}

/** Un côté EXTÉRIEUR (trajet connu par défaut). */
function awaySide(over: Partial<ConflictFixtureView> = {}): ConflictFixtureView {
  return {
    fixtureId: "fx-away",
    teamId: "team-sf2",
    homeAway: "AWAY",
    matchDate: "2026-11-08",
    kickoffTime: "15:00",
    estimatedKickoff: false,
    estimatedKickoffTime: null,
    travelOneWayMinutes: 85,
    windowStart: "2026-11-08T13:30:00",
    windowEnd: "2026-11-08T18:25:00",
    matchDurationMinutes: 105,
    opponentLabel: "ASVEL - 2",
    opponentPlace: "Villeurbanne",
    role: "MAIN",
    ...over,
  };
}

function matchMatch(left: ConflictFixtureView, right: ConflictFixtureView, over: Partial<Conflict> = {}): Conflict {
  return {
    type: "MATCH_MATCH",
    severity: 3,
    resolution: null,
    coachId: "p-1",
    start: "2026-11-08T15:30:00",
    end: "2026-11-08T17:25:00",
    left,
    right,
    ...over,
  };
}

describe("buildConflictSideLines — côté MATCH", () => {
  it("domicile : lieu « domicile », adversaire « vs … », coup d'envoi → fin → durée estimée", () => {
    const model = buildConflictSideLines(matchMatch(awaySide(), homeSide()), teams, venues);
    expect(model).not.toBeNull();
    const home = model!.sides[1];
    expect(home.kind).toBe("home");
    expect(home.teamName).toBe("SM2");
    expect(home.roleWord).toBe("joueur");
    expect(home.place).toBe("domicile");
    expect(home.opponent).toBe("vs VAULX EN VELIN BASKET CLUB - 2");
    expect(home.travelUnknown).toBeUndefined();
    // Créneaux fixes : pas de départ (domicile), coup d'envoi, fin (kickoff + durée), durée estimée.
    expect(home.times).toEqual({
      kickoff: { value: "15:30", estimated: false },
      end: "17:25",
      duration: "1 h 55",
    });
  });

  it("extérieur RÉEL : lieu « extérieur à … », départ / coup d'envoi (non estimé) / retour, sans durée", () => {
    const model = buildConflictSideLines(matchMatch(awaySide(), homeSide()), teams, venues);
    const away = model!.sides[0];
    expect(away.kind).toBe("away");
    expect(away.place).toBe("extérieur à Villeurbanne");
    expect(away.opponent).toBe("vs ASVEL - 2");
    expect(away.travelUnknown).toBeUndefined();
    // Départ / coup d'envoi / retour ; la colonne durée reste vide en extérieur.
    expect(away.times).toEqual({
      departure: "13:30",
      kickoff: { value: "15:00", estimated: false },
      end: "18:25",
    });
  });

  it("extérieur ESTIMÉ : le coup d'envoi porte l'heure estimée et le drapeau estimated", () => {
    const away = awaySide({ kickoffTime: null, estimatedKickoff: true, estimatedKickoffTime: "15:00" });
    const model = buildConflictSideLines(matchMatch(away, homeSide()), teams, venues);
    expect(model!.sides[0].times.kickoff).toEqual({ value: "15:00", estimated: true });
  });

  it("trajet INCONNU (travelOneWayMinutes null) : ni départ ni retour, drapeau travelUnknown", () => {
    const away = awaySide({ travelOneWayMinutes: null });
    const model = buildConflictSideLines(matchMatch(away, homeSide()), teams, venues);
    const side = model!.sides[0];
    expect(side.travelUnknown).toBe(true);
    // Seul le coup d'envoi (colonne 2) ; départ/retour/durée absents → cellules « — » à l'écran.
    expect(side.times).toEqual({ kickoff: { value: "15:00", estimated: false } });
  });

  it("lieu INCONNU (opponentPlace null) : « extérieur (lieu inconnu) »", () => {
    const away = awaySide({ opponentPlace: null });
    const model = buildConflictSideLines(matchMatch(away, homeSide()), teams, venues);
    expect(model!.sides[0].place).toBe("extérieur (lieu inconnu)");
  });
});

describe("buildConflictSideLines — MATCH_TRAINING (entraînement)", () => {
  const training: ConflictTrainingView = {
    slotTemplateId: "t-1",
    scheduleId: "sc",
    teamId: "team-sm2",
    venueId: "v-mateo",
    dayOfWeek: 3,
    startTime: "18:00",
    durationMinutes: 90,
    role: "MAIN",
    windowStart: "2026-11-08T18:00:00",
    windowEnd: "2026-11-08T19:30:00",
  };

  it("nomme le gymnase et rend la fenêtre telle quelle (pas de coup d'envoi)", () => {
    const conflict: Conflict = {
      type: "MATCH_TRAINING",
      severity: 3,
      resolution: null,
      coachId: "p-1",
      start: "2026-11-08T18:00:00",
      end: "2026-11-08T18:25:00",
      fixture: awaySide(),
      training,
    };
    const model = buildConflictSideLines(conflict, teams, venues);
    expect(model).not.toBeNull();
    const side = model!.sides[1];
    expect(side.kind).toBe("training");
    expect(side.roleWord).toBe("coach");
    expect(side.place).toBe("Entraînement · Gymnase Mateo");
    expect(side.opponent).toBeUndefined();
    // Entraînement : son début va en colonne coup d'envoi, sa fin en colonne fin/retour.
    expect(side.times).toEqual({ kickoff: { value: "18:00", estimated: false }, end: "19:30" });
  });

  it("gymnase absent de la map → « Gymnase ? » (jamais un crash)", () => {
    const conflict: Conflict = {
      type: "MATCH_TRAINING",
      severity: 3,
      resolution: null,
      start: "2026-11-08T18:00:00",
      end: "2026-11-08T18:25:00",
      fixture: awaySide(),
      training: { ...training, venueId: "v-absent" },
    };
    const model = buildConflictSideLines(conflict, teams, new Map());
    expect(model!.sides[1].place).toBe("Entraînement · Gymnase ?");
  });
});

describe("buildConflictSideLines — chevauchement", () => {
  it("même jour : minutes = end − start, crossDay false, pas de date répétée", () => {
    const model = buildConflictSideLines(matchMatch(awaySide(), homeSide()), teams, venues);
    expect(model!.overlap).toEqual({ start: "15:30", end: "17:25", minutes: 115, crossDay: false, startDay: undefined, endDay: undefined });
  });

  it("deux jours (match tardif) : crossDay true, dates courtes présentes", () => {
    const conflict = matchMatch(awaySide(), homeSide(), { start: "2026-11-08T23:30:00", end: "2026-11-09T00:20:00" });
    const model = buildConflictSideLines(conflict, teams, venues);
    expect(model!.overlap.crossDay).toBe(true);
    expect(model!.overlap.minutes).toBe(50);
    expect(model!.overlap.startDay).toBeDefined();
    expect(model!.overlap.endDay).toBeDefined();
  });
});

describe("buildConflictSideLines — VENUE_OVERLAP (collision de gymnase, détail par côté)", () => {
  /** Deux rencontres DOMICILE qui se percutent sur le même gymnase. */
  function venueOverlap(over: Partial<Conflict> = {}): Conflict {
    return {
      type: "VENUE_OVERLAP",
      severity: 1,
      resolution: null,
      venueId: "v-mateo",
      start: "2026-11-08T16:00:00",
      end: "2026-11-08T17:25:00",
      left: homeSide({ fixtureId: "fx-a", teamId: "team-sf2", kickoffTime: "15:30", matchDurationMinutes: 120, opponentLabel: "BC Villeurbanne" }),
      right: homeSide({ fixtureId: "fx-b", teamId: "team-sm2", kickoffTime: "16:00", matchDurationMinutes: 120, opponentLabel: "ASVEL U21" }),
      ...over,
    };
  }

  it("une ligne par côté : équipe · vs adversaire · gymnase (sur CHAQUE ligne) · date (sur CHAQUE ligne) · coup d'envoi → fin", () => {
    const model = buildConflictSideLines(venueOverlap(), teams, venues);
    expect(model).not.toBeNull();
    expect(model!.kind).toBe("venue");
    const [a, b] = model!.sides;
    // Le gymnase vit dans `place`, la date sur CHAQUE ligne, le créneau dans `times` (coup d'envoi → fin).
    expect(a).toEqual({
      teamName: "SF2",
      kind: "home",
      place: "Gymnase Mateo",
      opponent: "vs BC Villeurbanne",
      date: "8 nov.",
      times: { kickoff: { value: "15:30", estimated: false }, end: "17:30" },
    });
    expect(b).toEqual({
      teamName: "SM2",
      kind: "home",
      place: "Gymnase Mateo",
      opponent: "vs ASVEL U21",
      date: "8 nov.",
      times: { kickoff: { value: "16:00", estimated: false }, end: "18:00" },
    });
  });

  it("le chevauchement reste servi par le backend (start/end du conflit)", () => {
    const model = buildConflictSideLines(venueOverlap(), teams, venues);
    expect(model!.overlap).toEqual({ start: "16:00", end: "17:25", minutes: 85, crossDay: false, startDay: undefined, endDay: undefined });
  });

  it("gymnase absent de la map → « Gymnase ? » (jamais un crash)", () => {
    const model = buildConflictSideLines(venueOverlap(), teams, new Map());
    expect(model!.kind).toBe("venue");
    expect(model!.sides[0].place).toBe("Gymnase ?");
  });

  it("sans venueId (donnée dégradée) → null : on ne peut pas nommer le gymnase, repli sur la ligne grise", () => {
    const model = buildConflictSideLines(venueOverlap({ venueId: undefined }), teams, venues);
    expect(model).toBeNull();
  });
});
