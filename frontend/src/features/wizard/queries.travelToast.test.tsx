import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { toast } from "@/shared/stores/toastStore";

import * as wizardApi from "./api";
import { useCreateVenueTravelTime, useUpdateTravelRuleSetting, useUpdateVenueTravelTime } from "./queries";

/**
 * FRT-27 (P2-56) — la saisie de la matrice de trajet NE doit JAMAIS échouer en silence.
 * Chaque mutation d'écriture (create/update d'une minute, changement d'intensité) doit
 * remonter l'échec par un toast, comme le module matchs (matches/queries.ts). Avant ce
 * garde-fou, ces trois hooks n'avaient QUE `onSuccess` : un 422/409/réseau laissait la
 * valeur affichée diverger du serveur jusqu'au refetch, sans un mot.
 *
 * On monte le VRAI hook sur un QueryClient de test avec le module API voisin qui REJETTE
 * (on mute la PROD, pas un double du hook — cf. .claude/rules/frontend.md).
 */
vi.mock("./api", () => ({
  createVenueTravelTime: vi.fn(() => Promise.reject(new Error("boom"))),
  updateVenueTravelTime: vi.fn(() => Promise.reject(new Error("boom"))),
  updateTravelRuleSetting: vi.fn(() => Promise.reject(new Error("boom"))),
}));

const wrapper = ({ children }: { children: ReactNode }) => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
};

afterEach(() => {
  vi.restoreAllMocks();
});

describe("les mutations de la matrice de trajet signalent leurs échecs (FRT-27)", () => {
  it("useCreateVenueTravelTime : un rejet part en toast d'erreur", async () => {
    const errorSpy = vi.spyOn(toast, "error").mockImplementation(() => 0);
    const { result } = renderHook(() => useCreateVenueTravelTime(), { wrapper });

    result.current.mutate({ venueAId: "v1", venueBId: "v2", drivingMinutes: 12 });

    await waitFor(() => expect(vi.mocked(wizardApi.createVenueTravelTime)).toHaveBeenCalled());
    await waitFor(() => expect(errorSpy).toHaveBeenCalledWith(expect.stringMatching(/\S/)));
  });

  it("useUpdateVenueTravelTime : un rejet part en toast d'erreur", async () => {
    const errorSpy = vi.spyOn(toast, "error").mockImplementation(() => 0);
    const { result } = renderHook(() => useUpdateVenueTravelTime(), { wrapper });

    result.current.mutate({ id: "r1", body: { venueAId: "v1", venueBId: "v2", drivingMinutes: 12 } });

    await waitFor(() => expect(vi.mocked(wizardApi.updateVenueTravelTime)).toHaveBeenCalled());
    await waitFor(() => expect(errorSpy).toHaveBeenCalledWith(expect.stringMatching(/\S/)));
  });

  it("useUpdateTravelRuleSetting : un rejet part en toast d'erreur", async () => {
    const errorSpy = vi.spyOn(toast, "error").mockImplementation(() => 0);
    const { result } = renderHook(() => useUpdateTravelRuleSetting(), { wrapper });

    result.current.mutate("MANDATORY");

    await waitFor(() => expect(vi.mocked(wizardApi.updateTravelRuleSetting)).toHaveBeenCalled());
    await waitFor(() => expect(errorSpy).toHaveBeenCalledWith(expect.stringMatching(/\S/)));
  });
});
