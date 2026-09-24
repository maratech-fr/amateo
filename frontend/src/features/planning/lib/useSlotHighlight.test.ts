import { act, renderHook } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import type { Slot } from "../api";
import { useSlotHighlight } from "./useSlotHighlight";

/** Un `Slot` minimal — le surlignage ne lit que `id` et `teamId`. */
function slot(id: string, teamId: string): Slot {
  return { id, scheduleId: "s", teamId, venueId: "v", coachId: null, dayOfWeek: 1, startTime: "18:00:00", durationMinutes: 90, lockLevel: "NONE", lockOrigin: null };
}

describe("useSlotHighlight", () => {
  afterEach(() => {
    document.body.innerHTML = "";
    vi.restoreAllMocks();
  });

  const slots = [slot("slot-1", "team-1"), slot("slot-2", "team-2")];

  it("dérive les identifiants d'un refus depuis les violations via `slots`", () => {
    const { result } = renderHook(() => useSlotHighlight(slots));

    act(() => result.current.highlightViolations([{ rule: "coach_double_booking", message: "x", conflictingTeamId: "team-2" }]));

    // L'équipe en conflit (team-2) siège en slot-2 : c'est LUI qui est surligné.
    expect([...result.current.highlightSlotIds]).toEqual(["slot-2"]);
  });

  it("un ensemble vide ne déclenche AUCUN défilement", () => {
    const raf = vi.spyOn(window, "requestAnimationFrame");
    const { result } = renderHook(() => useSlotHighlight(slots));

    act(() => result.current.highlightSlots(new Set()));

    expect(result.current.highlightSlotIds.size).toBe(0);
    expect(raf).not.toHaveBeenCalled();
  });

  it("défile vers le PREMIER identifiant surligné", () => {
    const raf = vi.spyOn(window, "requestAnimationFrame").mockImplementation((cb: FrameRequestCallback) => (cb(0), 0));
    const el = document.createElement("div");
    el.setAttribute("data-slot-id", "slot-1");
    document.body.appendChild(el);
    const scroll = vi.fn();
    el.scrollIntoView = scroll;

    const { result } = renderHook(() => useSlotHighlight(slots));
    act(() => result.current.highlightSlots(new Set(["slot-1"])));

    expect(raf).toHaveBeenCalled();
    expect(scroll).toHaveBeenCalled();
    expect([...result.current.highlightSlotIds]).toEqual(["slot-1"]);
  });

  it("efface le surlignage", () => {
    const { result } = renderHook(() => useSlotHighlight(slots));
    act(() => result.current.highlightSlots(new Set(["slot-1"])));
    expect(result.current.highlightSlotIds.size).toBe(1);

    act(() => result.current.clearHighlight());

    expect(result.current.highlightSlotIds.size).toBe(0);
  });

  it("`highlightSlots` et `clearHighlight` gardent leur identité à travers les re-rendus (anti-boucle d'effet)", () => {
    // 🔴 Le piège : `DiagnosticsPanel` place `onHighlight` (= `highlightSlots`) dans le tableau
    // de dépendances d'un effet. Une identité qui change à chaque rendu = boucle d'effet infinie.
    const { result, rerender } = renderHook(({ s }) => useSlotHighlight(s), { initialProps: { s: slots } });
    const firstHighlight = result.current.highlightSlots;
    const firstClear = result.current.clearHighlight;

    // Re-rendu avec un NOUVEAU tableau `slots` (identité différente) : les deux intentions stables
    // ne doivent PAS changer.
    rerender({ s: [slot("slot-1", "team-1"), slot("slot-2", "team-2")] });

    expect(result.current.highlightSlots).toBe(firstHighlight);
    expect(result.current.clearHighlight).toBe(firstClear);
  });
});
