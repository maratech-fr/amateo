import { renderHook } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { VenueMatchWindow, VenueUnavailability } from "../api";
import { usePlacementGuards } from "./usePlacementGuards";

// Une lecture-guard minimale : `data` présente ⇒ « ready », `undefined` + `isError` ⇒
// « failed », `undefined` sans erreur ⇒ « loading » (doctrine readState).
function read<T>(data: T[] | undefined, isError = false) {
  return { data, isError, refetch: vi.fn() };
}

const win: VenueMatchWindow = { id: "w1", venueId: "v1", dayOfWeek: 6, startTime: "10:00", endTime: "12:00" };
const unavail: VenueUnavailability = { id: "u1", venueId: "v1", startDate: "2026-10-03", endDate: "2026-10-03", label: null };

describe("usePlacementGuards", () => {
  it("est prêt quand les trois lectures ont une donnée, et fait transiter les tableaux", () => {
    const { result } = renderHook(() => usePlacementGuards(read([win]), read([unavail]), read([])));
    expect(result.current.state).toBe("ready");
    expect(result.current.matchWindows).toEqual([win]);
    expect(result.current.unavailabilities).toEqual([unavail]);
  });

  it("les données absentes retombent sur des tableaux vides", () => {
    const { result } = renderHook(() => usePlacementGuards(read(undefined), read(undefined), read(undefined)));
    expect(result.current.matchWindows).toEqual([]);
    expect(result.current.unavailabilities).toEqual([]);
  });

  it("charge tant qu'une lecture n'a ni donnée ni erreur", () => {
    // Deux prêtes, une encore en vol → l'ensemble n'est pas encore prêt.
    const { result } = renderHook(() => usePlacementGuards(read([win]), read(undefined), read([])));
    expect(result.current.state).toBe("loading");
  });

  it("échoue dès qu'une lecture échoue sans cache", () => {
    const { result } = renderHook(() => usePlacementGuards(read(undefined, true), read([unavail]), read([])));
    expect(result.current.state).toBe("failed");
  });

  it("l'échec PRIME le chargement (une échouée + une en vol ⇒ failed)", () => {
    const { result } = renderHook(() => usePlacementGuards(read(undefined, true), read(undefined), read([])));
    expect(result.current.state).toBe("failed");
  });

  it("le chargement PRIME le prêt (une en vol + deux prêtes ⇒ loading)", () => {
    const { result } = renderHook(() => usePlacementGuards(read([win]), read([unavail]), read(undefined)));
    expect(result.current.state).toBe("loading");
  });

  it("l'échec de la seule enveloppe ligue suffit à suspendre le geste", () => {
    const { result } = renderHook(() => usePlacementGuards(read([win]), read([unavail]), read(undefined, true)));
    expect(result.current.state).toBe("failed");
  });

  it("retry refetch les TROIS lectures", () => {
    const mw = read([win]);
    const un = read([unavail]);
    const lw = read([]);
    const { result } = renderHook(() => usePlacementGuards(mw, un, lw));
    result.current.retry();
    expect(mw.refetch).toHaveBeenCalledTimes(1);
    expect(un.refetch).toHaveBeenCalledTimes(1);
    expect(lw.refetch).toHaveBeenCalledTimes(1);
  });
});
