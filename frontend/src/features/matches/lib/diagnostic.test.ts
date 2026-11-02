import { describe, expect, it } from "vitest";

import type { Conflict } from "../api";
import { groupBySeverity, toneOf } from "./diagnostic";

const conflict = (type: Conflict["type"], severity: number): Conflict => ({ type, severity, resolution: null });

describe("groupBySeverity (P1-4 PR E2)", () => {
  it("sorts groups worst-first and keeps the server's severity as-is", () => {
    const groups = groupBySeverity([
      conflict("AWAY_NO_FOOTPRINT", 7),
      conflict("MATCH_MATCH", 3),
      conflict("VENUE_OVERLAP", 1),
      conflict("MATCH_MATCH", 3),
    ]);
    expect(groups.map((g) => g.severity)).toEqual([1, 3, 7]);
    expect(groups[1]?.conflicts).toHaveLength(2);
  });

  it("grades the tone: 1-2 destructive, 3-5 warning, 7 muted", () => {
    const groups = groupBySeverity([
      conflict("VENUE_OVERLAP", 1),
      conflict("LEAGUE_WINDOW_VIOLATION", 2),
      conflict("VENUE_UNAVAILABLE", 4),
      conflict("AWAY_NO_FOOTPRINT", 7),
    ]);
    expect(groups.map((g) => g.tone)).toEqual(["destructive", "destructive", "warning", "muted"]);
  });

  it.each(["MATCH_MATCH", "CLUB_RULE_VIOLATION", "TEAM_VENUE_FORBIDDEN"] as const)("la gravité 3 est un vrai rouge, quelle que soit sa source : %s", (type) => {
    expect(groupBySeverity([conflict(type, 3)])[0]?.tone).toBe("destructive");
  });

  it.each(["MATCH_MATCH", "MATCH_TRAINING", "MATCH_COACH_UNAVAILABLE"] as const)("un adjoint libre face à un match reste orange, sur les trois familles : %s", (type) => {
    expect(groupBySeverity([conflict(type, 4)])[0]?.tone).toBe("warning");
  });

  it("la gravité 3 se lit en trois groupes, dans cet ordre : Personne, Gymnase, Règle", () => {
    const groups = groupBySeverity([conflict("CLUB_RULE_VIOLATION", 3), conflict("TEAM_VENUE_FORBIDDEN", 3), conflict("MATCH_MATCH", 3)]);
    expect(groups.map((g) => g.title)).toEqual(["Personne", "Gymnase", "Règle"]);
    expect(groups.map((g) => g.severity)).toEqual([3, 3, 3]);
  });

  it.each(["MATCH_MATCH", "MATCH_TRAINING", "MATCH_COACH_UNAVAILABLE"] as const)("%s tombe sous « Personne » : doublons et coach indisponible sont regroupés", (type) => {
    expect(groupBySeverity([conflict(type, 3)])[0]?.title).toBe("Personne");
  });

  it("un type inconnu de gravité 3 tombe sous « Autres », après les trois groupes, sans se perdre", () => {
    const unknown = conflict("VENUE_OVERLAP", 3);
    const groups = groupBySeverity([unknown, conflict("CLUB_RULE_VIOLATION", 3), conflict("MATCH_MATCH", 3)]);
    expect(groups.map((g) => g.title)).toEqual(["Personne", "Règle", "Autres"]);
    expect(groups[2]?.conflicts).toEqual([unknown]);
  });

  it("chaque groupe porte une clé distincte, y compris deux « Autres » de gravités différentes", () => {
    const groups = groupBySeverity([conflict("MATCH_MATCH", 3), conflict("TEAM_VENUE_FORBIDDEN", 3), conflict("CLUB_RULE_VIOLATION", 3), conflict("VENUE_OVERLAP", 3), conflict("VENUE_OVERLAP", 9), conflict("VENUE_OVERLAP", 1)]);
    const keys = groups.map((g) => g.key);
    expect(keys.every((k) => undefined !== k)).toBe(true);
    expect(new Set(keys).size).toBe(groups.length);
  });

  it("le pivot garde le même ordre : à plat, Personne passe avant Gymnase et Règle", () => {
    const person = conflict("MATCH_MATCH", 3);
    const venue = conflict("TEAM_VENUE_FORBIDDEN", 3);
    const rule = conflict("CLUB_RULE_VIOLATION", 3);
    expect(groupBySeverity([rule, venue, person]).flatMap((g) => g.conflicts)).toEqual([person, venue, rule]);
  });

  it("folds the structural groups 6 and 7 (N teams = N lines max, not N alerts)", () => {
    const groups = groupBySeverity([conflict("AWAY_NO_FOOTPRINT", 7), conflict("COMPETITION_INCOMPLETE", 6), conflict("VENUE_OVERLAP", 1)]);
    expect(groups.find((g) => 7 === g.severity)?.folded).toBe(true);
    expect(groups.find((g) => 6 === g.severity)?.folded).toBe(true);
    expect(groups.find((g) => 6 === g.severity)?.title).toBe("Calendriers incomplets");
    expect(groups.find((g) => 1 === g.severity)?.folded).toBe(false);
  });

  it("a conflict without severity falls in the watch group (5), never crashes", () => {
    const legacy = { type: "MATCH_MATCH" } as Conflict;
    expect(groupBySeverity([legacy])[0]?.severity).toBe(5);
  });
});

describe("toneOf (exporté pour l'onglet Conflits)", () => {
  it("grade la tonalité : 1-3 destructive, 4-5 warning, 6-7 muted", () => {
    expect(toneOf(1)).toBe("destructive");
    expect(toneOf(2)).toBe("destructive");
    expect(toneOf(3)).toBe("destructive");
    expect(toneOf(4)).toBe("warning");
    expect(toneOf(5)).toBe("warning");
    expect(toneOf(6)).toBe("muted");
    expect(toneOf(7)).toBe("muted");
  });
});
