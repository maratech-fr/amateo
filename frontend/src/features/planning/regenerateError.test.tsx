import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import { HTTPError } from "ky";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { toast } from "@/shared/stores/toastStore";

import * as planningApi from "./api";
import { useRegenerate, useRegenerateOverlay } from "./queries";

vi.mock("@/shared/stores/toastStore", () => ({ toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));
vi.mock("./api", () => ({
  regenerate: vi.fn(),
  createOverlayVersion: vi.fn(),
  generateSchedule: vi.fn(),
  deleteSchedule: vi.fn().mockResolvedValue(undefined),
}));

/**
 * Revue #8 round 4 — le garde d'épinglage orphelin refuse la génération en 422 avec un
 * message qui NOMME le gymnase et le jour. C'est tout son intérêt : « on ne fait pas de
 * chose magique, on voit et on informe le gestionnaire. » Les deux régénérations
 * l'écrasaient sous un « la régénération a échoué » anonyme — un garde écrit pour parler,
 * rendu muet juste avant d'atteindre l'écran.
 */
describe("régénération : le motif du serveur atteint le gestionnaire", () => {
  const GUARD_MESSAGE = "Les créneaux du mardi à Gym Barros ne sont plus disponibles en l’état : une séance y est épinglée sans créneau correspondant.";

  const wrapper = ({ children }: { children: ReactNode }) => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
  };

  // ky expose le corps d'erreur déjà analysé sur `error.data` (relire la réponse
  // lèverait « body stream already read ») — c'est là que errorMessage va le chercher.
  const guardRejection = () => {
    const body = { error: GUARD_MESSAGE };
    const response = new Response(JSON.stringify(body), { status: 422, headers: { "content-type": "application/json" } });
    const error = new HTTPError(response, new Request("http://localhost/api/schedules/sched-1/generate"), {} as never);
    (error as unknown as { data?: unknown }).data = body;
    return Promise.reject(error);
  };

  beforeEach(() => {
    vi.mocked(toast.error).mockClear();
  });

  it("sur la régénération d’une période", async () => {
    vi.mocked(planningApi.createOverlayVersion).mockResolvedValue({ id: "sched-1" } as never);
    vi.mocked(planningApi.generateSchedule).mockImplementation(guardRejection);

    const { result } = renderHook(() => useRegenerateOverlay(), { wrapper });
    result.current.mutate("plan-1");

    await waitFor(() => expect(vi.mocked(toast.error)).toHaveBeenCalledWith(GUARD_MESSAGE));
  });

  it("sur la régénération du planning principal", async () => {
    vi.mocked(planningApi.regenerate).mockImplementation(guardRejection);

    const { result } = renderHook(() => useRegenerate(), { wrapper });
    result.current.mutate("sched-1");

    await waitFor(() => expect(vi.mocked(toast.error)).toHaveBeenCalledWith(GUARD_MESSAGE));
  });
});
