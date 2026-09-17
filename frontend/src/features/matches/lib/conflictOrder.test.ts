import { describe, expect, it } from "vitest";

import type { Conflict } from "../api";
import { sortConflictsByDate } from "./conflictOrder";

function side(matchDate: string, kickoffTime: string | null = "16:00") {
  return { fixtureId: "f", teamId: "t", homeAway: "HOME" as const, matchDate, kickoffTime, windowStart: "", windowEnd: "" };
}

const c = (over: Partial<Conflict>): Conflict => ({ type: "MATCH_MATCH", severity: 3, resolution: null, ...over });

describe("sortConflictsByDate (2026-09-17)", () => {
  it("trie par DATE croissante (start ou, à défaut, matchDate du premier côté)", () => {
    const late = c({ fingerprint: "late", start: "2026-10-10T20:00:00", left: side("2026-10-10") });
    const early = c({ fingerprint: "early", left: side("2026-10-03") }); // pas de start → matchDate
    const mid = c({ fingerprint: "mid", start: "2026-10-05T09:00:00" });
    expect(sortConflictsByDate([late, early, mid]).map((x) => x.fingerprint)).toEqual(["early", "mid", "late"]);
  });

  it("rejette les conflits SANS date en fin de liste", () => {
    const dated = c({ fingerprint: "dated", left: side("2026-10-03") });
    const undated = c({ type: "COMPETITION_INCOMPLETE", severity: 6, fingerprint: "u" }); // ni start ni côté
    expect(sortConflictsByDate([undated, dated]).map((x) => x.fingerprint)).toEqual(["dated", "u"]);
  });

  it("à date égale, départage par HEURE croissante, et ne mute pas l'entrée", () => {
    const evening = c({ fingerprint: "evening", left: side("2026-10-03", "20:00") });
    const morning = c({ fingerprint: "morning", left: side("2026-10-03", "10:00") });
    const input = [evening, morning];
    expect(sortConflictsByDate(input).map((x) => x.fingerprint)).toEqual(["morning", "evening"]);
    // fonction PURE : l'entrée reste dans son ordre d'origine.
    expect(input.map((x) => x.fingerprint)).toEqual(["evening", "morning"]);
  });

  it("à date ET heure égales, départage STABLE par empreinte (ordre déterministe)", () => {
    const z = c({ fingerprint: "z", start: "2026-10-03T16:00:00", left: side("2026-10-03") });
    const a = c({ fingerprint: "a", start: "2026-10-03T16:00:00", left: side("2026-10-03") });
    expect(sortConflictsByDate([z, a]).map((x) => x.fingerprint)).toEqual(["a", "z"]);
  });
});
