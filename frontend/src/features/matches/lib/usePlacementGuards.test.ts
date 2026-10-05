import { renderHook } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { VenueClosure, VenueMatchWindow, VenueUnavailability } from "../api";
import { usePlacementGuards } from "./usePlacementGuards";

// Une lecture-guard minimale : `data` présente ⇒ « ready », `undefined` + `isError` ⇒
// « failed », `undefined` sans erreur ⇒ « loading » (doctrine readState).
function read<T>(data: T[] | undefined, isError = false) {
  return { data, isError, refetch: vi.fn() };
}

const win: VenueMatchWindow = { id: "w1", venueId: "v1", dayOfWeek: 6, startTime: "10:00", endTime: "12:00" };
const unavail: VenueUnavailability = { id: "u1", venueId: "v1", startDate: "2026-10-03", endDate: "2026-10-03", label: null };
const closure: VenueClosure = { id: "c1", venueId: "v1", title: "Travaux", startDate: "2026-10-03", endDate: "2026-10-16" };

describe("usePlacementGuards", () => {
  it("est prêt quand les quatre lectures ont une donnée, et fait transiter les tableaux", () => {
    const { result } = renderHook(() => usePlacementGuards(read([win]), read([unavail]), read([closure]), read([])));
    expect(result.current.state).toBe("ready");
    expect(result.current.matchWindows).toEqual([win]);
    expect(result.current.unavailabilities).toEqual([unavail]);
    expect(result.current.closures).toEqual([closure]);
  });

  it("les données absentes retombent sur des tableaux vides", () => {
    const { result } = renderHook(() => usePlacementGuards(read(undefined), read(undefined), read(undefined), read(undefined)));
    expect(result.current.matchWindows).toEqual([]);
    expect(result.current.unavailabilities).toEqual([]);
    expect(result.current.closures).toEqual([]);
  });

  it("charge tant qu'une lecture n'a ni donnée ni erreur", () => {
    // Trois prêtes, une encore en vol → l'ensemble n'est pas encore prêt.
    const { result } = renderHook(() => usePlacementGuards(read([win]), read(undefined), read([closure]), read([])));
    expect(result.current.state).toBe("loading");
  });

  it("échoue dès qu'une lecture échoue sans cache", () => {
    const { result } = renderHook(() => usePlacementGuards(read(undefined, true), read([unavail]), read([closure]), read([])));
    expect(result.current.state).toBe("failed");
  });

  it("l'échec PRIME le chargement (une échouée + une en vol ⇒ failed)", () => {
    const { result } = renderHook(() => usePlacementGuards(read(undefined, true), read(undefined), read([closure]), read([])));
    expect(result.current.state).toBe("failed");
  });

  it("le chargement PRIME le prêt (une en vol + trois prêtes ⇒ loading)", () => {
    const { result } = renderHook(() => usePlacementGuards(read([win]), read([unavail]), read([closure]), read(undefined)));
    expect(result.current.state).toBe("loading");
  });

  it("l'échec de la seule lecture des fermetures suspend le geste (P4-300)", () => {
    const { result } = renderHook(() => usePlacementGuards(read([win]), read([unavail]), read(undefined, true), read([])));
    expect(result.current.state).toBe("failed");
  });

  it("l'échec de la seule enveloppe ligue suffit à suspendre le geste", () => {
    const { result } = renderHook(() => usePlacementGuards(read([win]), read([unavail]), read([closure]), read(undefined, true)));
    expect(result.current.state).toBe("failed");
  });

  it("retry refetch les QUATRE lectures", () => {
    const mw = read([win]);
    const un = read([unavail]);
    const cl = read([closure]);
    const lw = read([]);
    const { result } = renderHook(() => usePlacementGuards(mw, un, cl, lw));
    result.current.retry();
    expect(mw.refetch).toHaveBeenCalledTimes(1);
    expect(un.refetch).toHaveBeenCalledTimes(1);
    expect(cl.refetch).toHaveBeenCalledTimes(1);
    expect(lw.refetch).toHaveBeenCalledTimes(1);
  });
});
