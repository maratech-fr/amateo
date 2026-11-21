import { QueryClientProvider } from "@tanstack/react-query";
import { cleanup, renderHook, waitFor } from "@testing-library/react";
import { HTTPError } from "ky";
import type { ReactNode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { queryClient } from "@/shared/lib/queryClient";
import { useAuthStore } from "@/shared/stores/authStore";
import { toast } from "@/shared/stores/toastStore";

import { postModuleVisit } from "./api";
import { useModuleVisit } from "./queries";

vi.mock("./api", () => ({ postModuleVisit: vi.fn() }));

const mockVisit = vi.mocked(postModuleVisit);

/**
 * La visite du module des matchs est une télémétrie sans effet visible (les chips « Nouveau » et
 * le bandeau résumé s'en passent) : son refus par le serveur — 403 ou 409 du contrôle central, lot 2
 * PR 5 — ne fait JAMAIS surgir de toast. Rendu sous le VRAI `queryClient` de l'app : c'est son filet
 * global (`queryCache.onError`) qui toasterait, un QueryClient nu de test serait vert par
 * construction (patron `shared/deployment/queries.test.tsx`).
 */
describe("useModuleVisit — un refus de la visite reste silencieux", () => {
  beforeEach(() => {
    useAuthStore.setState({ isAuthenticated: true });
  });

  afterEach(() => {
    cleanup();
    queryClient.clear();
    useAuthStore.setState({ isAuthenticated: false });
    vi.restoreAllMocks();
  });

  it.each([403, 409])("une visite refusée (%i) ne toaste pas", async (status) => {
    const toastError = vi.spyOn(toast, "error");
    mockVisit.mockRejectedValue(new HTTPError(new Response("{}", { status }), new Request("http://t/api/matches/module-visit"), {} as never));
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const { result } = renderHook(() => useModuleVisit(), { wrapper });

    await waitFor(() => expect(result.current.isError).toBe(true));
    await new Promise((resolve) => setTimeout(resolve, 0)); // le filet résout errorMessage() avant de toaster
    expect(toastError).not.toHaveBeenCalled();
  });
});
