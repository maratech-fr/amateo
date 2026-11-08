import { QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import { HTTPError } from "ky";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { ReactNode } from "react";

import { queryClient } from "@/shared/lib/queryClient";
import { useToastStore } from "@/shared/stores/toastStore";

import * as api from "./api";
import type { SocleDeviation } from "./api";
import { useLockSlot, useMoveSlot, usePlaceSlot, useSocleDeviation } from "./queries";

const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

const EMPTY: SocleDeviation = { socleScheduleId: "socle", window: { startDate: "2026-03-09", endDate: "2026-03-15" }, moved: [], unplaced: [], changedSlotIds: [], teams: [], ranks: [] };

afterEach(() => {
  vi.restoreAllMocks();
  queryClient.clear();
});

describe("useSocleDeviation — gating", () => {
  it("scheduleId NUL → la route n'est JAMAIS appelée (vacance, /planning autonome)", async () => {
    const spy = vi.spyOn(api, "getSocleDeviation").mockResolvedValue(EMPTY);
    renderHook(() => useSocleDeviation(null), { wrapper });

    // Laisse react-query s'installer : une query désarmée ne doit rien fetcher.
    await new Promise((r) => setTimeout(r, 10));
    expect(spy).not.toHaveBeenCalled();
  });

  it("scheduleId présent → la route est appelée une fois", async () => {
    const spy = vi.spyOn(api, "getSocleDeviation").mockResolvedValue(EMPTY);
    const { result } = renderHook(() => useSocleDeviation("sc-1"), { wrapper });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(spy).toHaveBeenCalledWith("sc-1");
  });
});

/**
 * D8 — le bilan DIT lui-même son échec (dans le panneau : message serveur du 409, ou « Le bilan n'a
 * pas pu être lu. » + Réessayer) : le filet global `QueryCache.onError` ne doit pas y ajouter un
 * toast générique en double (`meta.ownsError`, patron `useClosureImpactPreview`). Exercé avec le
 * VRAI client de l'app — c'est lui qui porte le filet.
 */
describe("useSocleDeviation — l'échec de lecture n'est dit qu'une fois (dans le panneau)", () => {
  it.each([409, 500])("un %d ne pose AUCUN toast générique", async (status) => {
    const error = new HTTPError(new Response(JSON.stringify({ error: "Message serveur." }), { status }), new Request("http://localhost/api/schedules/sc-1/socle-deviation"), {} as never);
    vi.spyOn(api, "getSocleDeviation").mockRejectedValue(error);
    useToastStore.setState({ toasts: [] });
    const { result } = renderHook(() => useSocleDeviation("sc-1"), { wrapper });

    await waitFor(() => expect(result.current.isError).toBe(true), { timeout: 5_000 });
    // Laisse au filet global le temps de résoudre son message (il passe par une promesse).
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(useToastStore.getState().toasts).toEqual([]);
  });
});

describe("useSocleDeviation — réinvalidée après un placement changé", () => {
  it.each([
    ["move", (h: ReturnType<typeof useMounted>) => h.move.mutate({ id: "s1", patch: { dayOfWeek: 2, startTime: "19:00", venueId: "v1" } })],
    ["lock", (h: ReturnType<typeof useMounted>) => h.lock.mutate({ id: "s1", lockLevel: "HARD" })],
    ["place", (h: ReturnType<typeof useMounted>) => h.place.mutate({ scheduleId: "sc-1", body: { teamId: "t", dayOfWeek: 1, startTime: "18:00", venueId: "v" } })],
  ])("un %s accepté relit le diff socle↔période", async (_name, fire) => {
    const devSpy = vi.spyOn(api, "getSocleDeviation").mockResolvedValue(EMPTY);
    vi.spyOn(api, "moveSlot").mockResolvedValue({ valid: true, violations: [] } as never);
    vi.spyOn(api, "lockSlot").mockResolvedValue(undefined as never);
    vi.spyOn(api, "placeSlot").mockResolvedValue({ valid: true, violations: [] } as never);

    const { result } = renderHook(() => useMounted(), { wrapper });
    await waitFor(() => expect(result.current.dev.isSuccess).toBe(true));
    const before = devSpy.mock.calls.length;

    fire(result.current);
    await waitFor(() => expect(devSpy.mock.calls.length).toBeGreaterThan(before));
  });
});

function useMounted() {
  return { dev: useSocleDeviation("sc-1"), move: useMoveSlot(), lock: useLockSlot(), place: usePlaceSlot() };
}
