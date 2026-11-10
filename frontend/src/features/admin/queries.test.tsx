import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, renderHook, waitFor } from "@testing-library/react";
import { HTTPError } from "ky";
import type { ReactNode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { toast } from "@/shared/stores/toastStore";

import { activateAdminDemo, cancelAdminDeployment, createAdminReleaseNote } from "./api";
import { useActivateAdminDemo, useCancelAdminDeployment, useCreateAdminReleaseNote } from "./queries";
import { useAdminStore } from "./store";
import { ADMIN_DASHBOARD_TEXTS } from "./texts/dashboard";

vi.mock("./api", async (importOriginal) => {
  const original = await importOriginal<typeof import("./api")>();
  return { ...original, activateAdminDemo: vi.fn(), cancelAdminDeployment: vi.fn(), createAdminReleaseNote: vi.fn() };
});

function conflict(error: string): HTTPError {
  const err = new HTTPError(new Response(JSON.stringify({ error }), { status: 409, headers: { "content-type": "application/json" } }), new Request("http://t/api/admin"), {} as never);
  (err as { data?: unknown }).data = { error };
  return err;
}

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

/**
 * D7 (console PR 1) — le toast d'échec d'une action de console dit l'action ET la raison du
 * serveur (« Impossible d'activer… : Démo déjà active. »), jamais l'action seule : le superadmin
 * sait quoi faire sans ouvrir les journaux. Trois hooks représentatifs (démo, mise en prod, note
 * de version) ; le patron est le même pour les neuf toasts de hook.
 */
describe("toasts d'échec de la console : contexte + raison du serveur", () => {
  beforeEach(() => {
    useAdminStore.setState({ identity: { id: "sa", email: "sa@x" }, csrfToken: "csrf" });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("activer une démo refusée (409) toaste le contexte et la raison", async () => {
    const toastError = vi.spyOn(toast, "error");
    vi.mocked(activateAdminDemo).mockRejectedValue(conflict("Démo déjà active."));
    const { result } = renderHook(() => useActivateAdminDemo(), { wrapper });

    act(() => result.current.mutate("bccl"));

    await waitFor(() => expect(toastError).toHaveBeenCalledWith(ADMIN_DASHBOARD_TEXTS.toastWithReason(ADMIN_DASHBOARD_TEXTS.toastDemoActivateError, "Démo déjà active.")));
  });

  it("annuler une mise en production refusée (409) toaste le contexte et la raison", async () => {
    const toastError = vi.spyOn(toast, "error");
    vi.mocked(cancelAdminDeployment).mockRejectedValue(conflict("Le déploiement est déjà parti."));
    const { result } = renderHook(() => useCancelAdminDeployment(), { wrapper });

    act(() => result.current.mutate("11111111-1111-4111-8111-111111111111"));

    await waitFor(() => expect(toastError).toHaveBeenCalledWith(ADMIN_DASHBOARD_TEXTS.toastWithReason(ADMIN_DASHBOARD_TEXTS.toastDeploymentCancelError, "Le déploiement est déjà parti.")));
  });

  it("enregistrer une note de version refusée (409) toaste le contexte et la raison", async () => {
    const toastError = vi.spyOn(toast, "error");
    vi.mocked(createAdminReleaseNote).mockRejectedValue(conflict("Une note existe déjà pour cette version."));
    const { result } = renderHook(() => useCreateAdminReleaseNote(), { wrapper });

    act(() => result.current.mutate({ version: "v1.4.0", title: "T", body: "B" } as never));

    await waitFor(() => expect(toastError).toHaveBeenCalledWith(ADMIN_DASHBOARD_TEXTS.toastWithReason(ADMIN_DASHBOARD_TEXTS.toastReleaseNoteSaveError, "Une note existe déjà pour cette version.")));
  });
});
