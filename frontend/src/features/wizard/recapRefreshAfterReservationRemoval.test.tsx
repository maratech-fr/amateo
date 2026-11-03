import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import * as wizardApi from "./api";
import { useConstraintValidation, useDeleteReservation } from "./queries";

vi.mock("./api", () => ({
  deleteReservation: vi.fn(() => Promise.resolve()),
  validateConstraints: vi.fn(),
}));

const blocked = { valid: false, errors: {}, conflicts: [], blockers: ["U15 est réservée deux fois le mardi"], doubledReservationIds: ["r1", "r2"] };
const clean = { valid: true, errors: {}, conflicts: [], blockers: [], doubledReservationIds: [] };

/**
 * Une réservation retirée au Récap rafraîchit le verdict du Récap sans recharger la page : le blocage
 * « une seule séance par jour » disparaît et « Continuer » se réactive (le gate lit ce même verdict,
 * `useStepValidation` → `useConstraintValidation`). On observe l'EFFET — le verdict servi après le
 * retrait est le nouveau — et pas l'appel à `invalidateQueries`.
 * Le retrait d'un groupe passe par le même hook, une fois par réservation (`RecapStep.tsx` `removeLot`).
 */
describe("retirer une réservation au Récap rafraîchit son verdict", () => {
  beforeEach(() => {
    vi.mocked(wizardApi.validateConstraints).mockReset();
    vi.mocked(wizardApi.validateConstraints).mockResolvedValueOnce(blocked).mockResolvedValue(clean);
  });

  const render = () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
    return renderHook(() => ({ verdict: useConstraintValidation(true), remove: useDeleteReservation() }), { wrapper });
  };

  it("une réservation désignée retirée : le blocage disparaît", async () => {
    const { result } = render();
    await waitFor(() => expect(result.current.verdict.data?.valid).toBe(false));

    await act(() => result.current.remove.mutateAsync("r2"));

    await waitFor(() => expect(result.current.verdict.data?.valid).toBe(true));
    expect(result.current.verdict.data?.doubledReservationIds).toEqual([]);
  });

  it("un groupe retiré en lot, réservation par réservation : le blocage disparaît", async () => {
    const { result } = render();
    await waitFor(() => expect(result.current.verdict.data?.valid).toBe(false));

    await act(async () => {
      for (const id of ["a2", "b2"]) {
        await result.current.remove.mutateAsync(id);
      }
    });

    await waitFor(() => expect(result.current.verdict.data?.valid).toBe(true));
  });
});
