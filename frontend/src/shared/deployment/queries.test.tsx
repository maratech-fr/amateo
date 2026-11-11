import { QueryClientProvider } from "@tanstack/react-query";
import { cleanup, renderHook, waitFor } from "@testing-library/react";
import { HTTPError } from "ky";
import type { ReactNode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { queryClient } from "@/shared/lib/queryClient";
import { useAuthStore } from "@/shared/stores/authStore";
import { toast } from "@/shared/stores/toastStore";

import { getDeploymentNotice } from "./api";
import { useDeploymentNotice } from "./queries";

vi.mock("./api", () => ({ getDeploymentNotice: vi.fn() }));

const mockNotice = vi.mocked(getDeploymentNotice);

/**
 * D5 (console PR 1) — l'annonce de mise en production est une lecture de FOND, sondée toutes les
 * 5 min sur chaque écran : son échec ne fait JAMAIS surgir de toast (le bandeau se tait, cf. le
 * docblock du hook). Rendu sous le VRAI `queryClient` de l'app : c'est son filet global
 * (`queryCache.onError`) qui toastait, un QueryClient nu de test serait vert par construction.
 */
describe("useDeploymentNotice — un échec de lecture reste silencieux", () => {
  beforeEach(() => {
    useAuthStore.setState({ isAuthenticated: true });
  });

  afterEach(() => {
    cleanup();
    queryClient.clear();
    useAuthStore.setState({ isAuthenticated: false });
    vi.restoreAllMocks();
  });

  it("une lecture rejetée (500) ne toaste pas", async () => {
    const toastError = vi.spyOn(toast, "error");
    mockNotice.mockRejectedValue(new HTTPError(new Response("{}", { status: 500 }), new Request("http://t/api/deployment-notice"), {} as never));
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const { result } = renderHook(() => useDeploymentNotice(), { wrapper });

    await waitFor(() => expect(result.current.isError).toBe(true));
    await new Promise((resolve) => setTimeout(resolve, 0)); // le filet résout errorMessage() avant de toaster
    expect(toastError).not.toHaveBeenCalled();
  });
});
