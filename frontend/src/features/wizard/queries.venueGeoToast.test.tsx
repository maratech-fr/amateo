import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { toast } from "@/shared/stores/toastStore";

import * as wizardApi from "./api";
import { useLocateVenue } from "./queries";
import { WIZARD_TEXTS } from "./texts";

/**
 * PR F (P4-279) — géolocaliser un gymnase à l'étape « Gymnases » enregistre sa position par un PUT
 * (VenuesStep `VenueGeocodeField onLocate`). PROMESSE : au SUCCÈS de ce PUT, un toast
 * « Position enregistrée », posé AU NIVEAU HOOK de la mutation (FRT-38 / `mutateOnErrorToastGuard`),
 * jamais passé à `mutate(vars, { onSuccess })` — et SANS redériver « recalcul lancé ou pas » (le
 * recalcul des trajets se signale par son flux Mercure, pas par ce toast). Le toast dit exactement
 * la clé centralisée `venuePositionSaved`, rien de plus.
 *
 * On monte le VRAI hook sur un QueryClient de test, le module API voisin RÉSOLVANT (on mute la
 * PROD, pas un double du hook — cf. .claude/rules/frontend.md). Tant que le hook ne pose pas le
 * toast au succès, l'attente reste ROUGE. Patron exact de `queries.travelToast.test.tsx`.
 */
vi.mock("./api", () => ({
  updateVenue: vi.fn(() => Promise.resolve({ id: "v1", name: "Gymnase A", color: null, canSplit: false, isActive: true })),
}));

const wrapper = ({ children }: { children: ReactNode }) => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
};

afterEach(() => {
  vi.restoreAllMocks();
});

describe("useLocateVenue — le PUT géo d'un gymnase se confirme au niveau hook (P4-279)", () => {
  it("au succès du PUT, toast « Position enregistrée » (clé centralisée), sans un mot sur le recalcul", async () => {
    const successSpy = vi.spyOn(toast, "success").mockImplementation(() => 0);
    const { result } = renderHook(() => useLocateVenue(), { wrapper });

    result.current.mutate({ id: "v1", body: { name: "Gymnase A", address: "12 rue du sport", latitude: "45.76", longitude: "4.88" } });

    await waitFor(() => expect(vi.mocked(wizardApi.updateVenue)).toHaveBeenCalledWith("v1", expect.objectContaining({ latitude: "45.76", longitude: "4.88" })));
    await waitFor(() => expect(successSpy).toHaveBeenCalledWith(WIZARD_TEXTS.venuePositionSaved));
    // La promesse : le toast ne redérive PAS « recalcul lancé » — il dit EXACTEMENT la position.
    await waitFor(() => expect(successSpy).toHaveBeenCalledTimes(1));
  });
});
