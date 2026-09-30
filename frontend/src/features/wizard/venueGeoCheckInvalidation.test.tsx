import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { describe, expect, it, vi } from "vitest";

import * as wizardApi from "./api";
import { useCreateVenue, useDeleteVenue, useUpdateVenue } from "./queries";

vi.mock("./api", () => ({
  createVenue: vi.fn(() => Promise.resolve({ id: "v1" })),
  updateVenue: vi.fn(() => Promise.resolve({ id: "v1" })),
  deleteVenue: vi.fn(() => Promise.resolve()),
}));

/**
 * Le contrôle de cohérence de position (verdict serveur, clé `["wizard", "venue_geo_check"]`,
 * `staleTime` de session) DOIT être invalidé au succès de toute écriture de gymnase : sans quoi
 * l'alerte « Position à vérifier » resterait affichée après que le gestionnaire a corrigé les
 * coordonnées. Ce garde tient l'invalidation sur create / update / delete.
 */
describe("une écriture de gymnase invalide le contrôle de cohérence de position", () => {
  const harness = () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    const spy = vi.spyOn(queryClient, "invalidateQueries");
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
    return { spy, wrapper };
  };

  const invalidated = (spy: ReturnType<typeof vi.spyOn>, ...expected: string[]): boolean =>
    spy.mock.calls.some(([arg]: [unknown]) => {
      const key = (arg as { queryKey?: unknown[] } | undefined)?.queryKey;
      return Array.isArray(key) && key.length === expected.length && expected.every((k, i) => key[i] === k);
    });

  it("mettre à jour un gymnase invalide le contrôle de position (l'alerte disparaît une fois corrigé)", async () => {
    const { spy, wrapper } = harness();
    const { result } = renderHook(() => useUpdateVenue(), { wrapper });
    result.current.mutate({ id: "v1", body: { name: "ADN" } });
    await waitFor(() => expect(vi.mocked(wizardApi.updateVenue)).toHaveBeenCalled());
    await waitFor(() => expect(invalidated(spy, "wizard", "venue_geo_check")).toBe(true));
  });

  it("créer un gymnase invalide le contrôle de position", async () => {
    const { spy, wrapper } = harness();
    const { result } = renderHook(() => useCreateVenue(), { wrapper });
    result.current.mutate({ name: "JDR" });
    await waitFor(() => expect(vi.mocked(wizardApi.createVenue)).toHaveBeenCalled());
    await waitFor(() => expect(invalidated(spy, "wizard", "venue_geo_check")).toBe(true));
  });

  it("supprimer un gymnase invalide le contrôle de position", async () => {
    const { spy, wrapper } = harness();
    const { result } = renderHook(() => useDeleteVenue(), { wrapper });
    result.current.mutate("v1");
    await waitFor(() => expect(vi.mocked(wizardApi.deleteVenue)).toHaveBeenCalled());
    await waitFor(() => expect(invalidated(spy, "wizard", "venue_geo_check")).toBe(true));
  });
});
