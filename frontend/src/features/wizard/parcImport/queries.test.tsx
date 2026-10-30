import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { describe, expect, it, vi } from "vitest";

// L'import HTTP est mocké : on ne teste QUE l'invalidation de cache du `onSuccess` (E8).
vi.mock("@/shared/api/client", () => ({
  api: { post: () => ({ json: () => Promise.resolve({ recap: {} }) }) },
}));

import { useImportParc } from "./queries";

/**
 * E8 (lot 2, retours essai fondateur 30/10) — « un import réussi rafraîchit TOUT ce qu'il a pu créer ».
 * Aujourd'hui `useImportParc.onSuccess` n'invalide que teams/venues/coaches (`queries.ts:189-193`) :
 * les séances communes (blocs), les réservations et les créneaux de gymnases créés par l'import
 * n'apparaissent pas tant qu'un autre geste ne les recharge pas (le fondateur a dû cliquer ailleurs).
 * ROUGE : après un import réussi, `invalidateQueries` n'est PAS appelé pour
 * `["wizard","shared_training_blocks"]`, `["wizard","reservations"]` ni `["wizard","venue_slots"]`.
 */
describe("useImportParc — un import réussi rafraîchit tout ce qu'il a pu créer (E8)", () => {
  it("invalide shared_training_blocks, reservations ET venue_slots au succès", async () => {
    const queryClient = new QueryClient();
    const invalidate = vi.spyOn(queryClient, "invalidateQueries").mockResolvedValue();
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const { result } = renderHook(() => useImportParc("club-1"), { wrapper });

    result.current.mutate({ payload: {}, decisions: [] });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    const keys = invalidate.mock.calls.map((c) => JSON.stringify(c[0]?.queryKey));
    expect(keys.some((k) => k.includes("shared_training_blocks")), "une séance commune importée doit rafraîchir les blocs").toBe(true);
    expect(keys.some((k) => k.includes("reservations")), "les réservations importées doivent se rafraîchir").toBe(true);
    expect(keys.some((k) => k.includes("venue_slots")), "les créneaux de gymnases créés doivent se rafraîchir").toBe(true);
  });
});
