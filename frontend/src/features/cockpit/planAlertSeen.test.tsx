import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { api } from "@/shared/api/client";

import { markPlanAlertSeen } from "./api";
import { useMarkPlanAlertSeen } from "./queries";

/**
 * P4-503 — « J'ai vu » sur l'alerte d'un planning de période (plan §4) : la PROD (vrai
 * `markPlanAlertSeen`, vrai hook) contre le client ky mocké (patron `windowConflict.test.tsx`).
 * Le contrat serveur (route, 200/403/404/422) est prouvé par
 * `backend/tests/Integration/Api/SchedulePlanAlertSeenTest.php` ; ici, ce que le front ENVOIE et
 * ce qu'il RAFRAÎCHIT (la carte doit disparaître du radar sans recharger la page).
 */
vi.mock("@/shared/api/client", () => ({ api: { post: vi.fn(), put: vi.fn(), get: vi.fn(), delete: vi.fn() } }));

const mockPost = vi.mocked(api.post);

afterEach(() => {
  mockPost.mockReset();
});

/** Ce que l'API renvoie : l'identifiant du plan et le jour de la vue, en `Y-m-d`. */
const SERVED = { id: "pl-1", alertSeenDate: "2026-12-29" };

function servePost(): void {
  mockPost.mockReturnValue({ json: () => Promise.resolve(SERVED) } as unknown as ReturnType<typeof api.post>);
}

describe("markPlanAlertSeen / useMarkPlanAlertSeen (P4-503)", () => {
  /** « J'ai vu » poste le TYPE d'alerte vu sur la route du plan, et rend la réponse servie. */
  it("poste {kind} sur schedule_plans/{id}/alert-seen", async () => {
    servePost();

    await expect(markPlanAlertSeen("pl-1", "auto_validated")).resolves.toEqual(SERVED);

    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(mockPost.mock.calls[0][0]).toBe("schedule_plans/pl-1/alert-seen");
    expect(mockPost.mock.calls[0][1]).toEqual({ json: { kind: "auto_validated" } });
  });

  /** Après « J'ai vu », les plans du radar (clé `calendar-entries`) sont invalidés : la carte disparaît sans recharger la page. */
  it("invalide les lectures calendar-entries (dont les plans du radar) au succès", async () => {
    servePost();
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    queryClient.setQueryData(["calendar-entries", "plans"], []);
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

    const { result } = renderHook(() => useMarkPlanAlertSeen(), { wrapper });
    result.current.mutate({ planId: "pl-1", kind: "started_without_plan" });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(mockPost.mock.calls[0][0]).toBe("schedule_plans/pl-1/alert-seen");
    expect(mockPost.mock.calls[0][1]).toEqual({ json: { kind: "started_without_plan" } });
    expect(queryClient.getQueryState(["calendar-entries", "plans"])?.isInvalidated).toBe(true);
  });
});
