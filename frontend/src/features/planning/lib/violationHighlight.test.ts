import { describe, expect, it } from "vitest";

import type { MoveViolation, Slot } from "../api";
import { violationHighlightSlotIds } from "./violationHighlight";

// F2b — après un déplacement REFUSÉ, le moteur nomme l'équipe DÉJÀ en place (`conflictingTeamId`)
// qui cause le conflit. Ce helper est de la PRÉSENTATION pure : il retrouve, dans le cache
// affiché, les créneaux de cette équipe pour les surligner. Il ne re-dérive aucune règle — le
// backend a dit « non » et fourni l'id.

function slot(id: string, teamId: string): Slot {
  return {
    id,
    scheduleId: "sched",
    teamId,
    venueId: "v1",
    coachId: null,
    dayOfWeek: 2,
    startTime: "18:00",
    durationMinutes: 90,
    lockLevel: "NONE",
    lockOrigin: null,
    sharedTrainingBlockId: null,
  };
}

function violation(over: Partial<MoveViolation>): MoveViolation {
  return { rule: "coach_double_booking", message: "conflit", ...over };
}

describe("violationHighlightSlotIds", () => {
  it("surligne le créneau de l'équipe conflictingTeamId déjà en place", () => {
    const slots = [slot("slotA", "teamA"), slot("slotB", "teamB")];
    const ids = violationHighlightSlotIds([violation({ conflictingTeamId: "teamB" })], slots);

    expect([...ids]).toEqual(["slotB"]);
  });

  it("surligne TOUS les créneaux de l'équipe fautive (elle peut avoir plusieurs séances)", () => {
    const slots = [slot("slotA1", "teamA"), slot("slotA2", "teamA"), slot("slotB", "teamB")];
    const ids = violationHighlightSlotIds([violation({ conflictingTeamId: "teamA" })], slots);

    expect(new Set(ids)).toEqual(new Set(["slotA1", "slotA2"]));
  });

  it("agrège plusieurs violations sans doublon", () => {
    const slots = [slot("slotA", "teamA"), slot("slotB", "teamB")];
    const ids = violationHighlightSlotIds(
      [violation({ conflictingTeamId: "teamA" }), violation({ conflictingTeamId: "teamB" }), violation({ conflictingTeamId: "teamA" })],
      slots,
    );

    expect(new Set(ids)).toEqual(new Set(["slotA", "slotB"]));
  });

  it("un id absent du cache n'ajoute AUCUN surlignage fantôme", () => {
    const slots = [slot("slotA", "teamA")];
    const ids = violationHighlightSlotIds([violation({ conflictingTeamId: "teamInconnue" })], slots);

    expect(ids.size).toBe(0);
  });

  it("une violation SANS conflictingTeamId n'ajoute rien (null-safe)", () => {
    const slots = [slot("slotA", "teamA")];
    const ids = violationHighlightSlotIds([violation({ conflictingTeamId: null }), violation({})], slots);

    expect(ids.size).toBe(0);
  });
});
