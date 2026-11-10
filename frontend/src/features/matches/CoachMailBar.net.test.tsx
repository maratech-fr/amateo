import { QueryClientProvider } from "@tanstack/react-query";
import { render, renderHook, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import type { ReactNode } from "react";
import { createMemoryRouter, RouterProvider } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import * as wizardApi from "@/features/wizard/api";
import { queryClient } from "@/shared/lib/queryClient";
import { useToastStore } from "@/shared/stores/toastStore";

import type { Coach, ConflictMailPreview, ConflictMailPreviewRecipient } from "./api";
import * as matchesApi from "./api";
import { CoachMailBar } from "./CoachMailBar";
import { useConflictMailPreview } from "./queries";
import { MATCHES_CONFLICTS_MAIL_TEXTS as MAIL } from "./texts/conflictsMail";

/**
 * Les deux FILETS GLOBAUX de l'aperçu avant envoi (plan D9, D10 ; corrections m-d, m-e) : une
 * adresse refusée par le serveur ne toaste qu'UNE fois (le filet `MutationCache.onError`, jamais
 * doublé par un `onError` de `mutate`), et un aperçu en échec ne toaste JAMAIS (la modale le dit
 * elle-même par `LoadErrorHint` ; `meta: { ownsError: true }` fait taire `QueryCache.onError`).
 *
 * ⚠ VRAI `queryClient` de prod (patron `features/planning/queries.net.test.tsx`) — jamais
 * `renderWithProviders` (`test/utils.tsx` fabrique un client SANS filet : ces tests y seraient verts
 * à vide). Le rendu de la modale elle-même : `CoachMailBar.test.tsx`.
 */

vi.mock("./api", () => ({
  getConflicts: vi.fn(() => Promise.resolve({ clubId: "c", seasonId: "s", seasonPlanChosen: true, conflicts: [], held: [] })),
  getConflictMailPreview: vi.fn(),
  mailConflictsToCoaches: vi.fn(),
}));

vi.mock("@/features/wizard/api", async (importOriginal) => ({ ...(await importOriginal<typeof import("@/features/wizard/api")>()), updateCoach: vi.fn() }));

const NADIA: ConflictMailPreviewRecipient = {
  coachId: "coach-nadia",
  firstName: "Nadia",
  lastName: "Kader",
  email: "nadia@",
  hasValidAddress: false,
  kind: "conflicts",
  receiptKnown: true,
  conflictCount: 2,
  newCount: 2,
  changedCount: 0,
  alreadySentCount: 0,
  lastSentAt: null,
  sendsByDefault: false,
  sendsOnResendAll: false,
};
const PREVIEW: ConflictMailPreview = { seasonId: "s", personConflictCount: 2, defaultMailCount: 0, resendAllMailCount: 0, blockedByDefault: "no_address", blockedOnResendAll: "no_address", recipients: [NADIA] };

function httpError(status: number, data: unknown): HTTPError {
  const response = new Response(JSON.stringify(data), { status, headers: { "content-type": "application/json" } });
  const error = new HTTPError(response, new Request("http://localhost/api/coaches/coach-nadia", { method: "PUT" }), {} as never);
  (error as unknown as { data?: unknown }).data = data;
  return error;
}

function renderBarWithTheRealClient() {
  const router = createMemoryRouter(
    [{ path: "*", element: <CoachMailBar mailing={{ personConflictCount: 2, pendingRecipientCount: 0, recipientCount: 0, last: null }} canManage isDemo={false} seasonReadonly={false} seasonName="2026-2027" coaches={new Map<string, Coach>()} /> }],
    { initialEntries: ["/matchs/conflits"] },
  );
  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}
const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;

const errorToasts = () => useToastStore.getState().toasts.filter((t) => "error" === t.variant);

beforeEach(() => {
  vi.clearAllMocks();
  useToastStore.setState({ toasts: [] });
  queryClient.clear();
});

describe("CoachMailBar — filets globaux (vrai queryClient)", () => {
  it("une adresse refusée (422) : UN seul toast d'erreur, la ligne reste « Adresse invalide »", async () => {
    vi.mocked(matchesApi.getConflictMailPreview).mockResolvedValue(PREVIEW);
    const refusal = "Cette valeur n'est pas une adresse email valide.";
    vi.mocked(wizardApi.updateCoach).mockRejectedValue(httpError(422, { detail: refusal, violations: [{ propertyPath: "email", message: refusal }] }));
    const user = userEvent.setup();
    renderBarWithTheRealClient();

    await user.click(await screen.findByRole("button", { name: MAIL.sendButton }));
    const dialog = await screen.findByRole("dialog", { name: MAIL.confirmTitle });
    const field = await within(dialog).findByRole("textbox", { name: MAIL.emailAria("Nadia Kader") });
    await user.clear(field);
    await user.type(field, "nadia@club");
    await user.tab();

    await waitFor(() => expect(errorToasts().length).toBeGreaterThan(0));
    // Laisse aux deux filets possibles (hook ET mutate) le temps de parler : un doublon apparaîtrait ici.
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(errorToasts(), "le filet global toaste, un `onError` de `mutate` ne doit pas doubler").toHaveLength(1);
    expect(within(dialog).getByText(MAIL.invalidAddressPill)).toBeInTheDocument();
    expect(within(dialog).getByText(MAIL.willNotSendNoAddress, { exact: false })).toBeInTheDocument();
  });

  it("un aperçu en échec au premier chargement : `LoadErrorHint` dans la modale, ZÉRO toast", async () => {
    vi.mocked(matchesApi.getConflictMailPreview).mockRejectedValue(new Error("network down"));
    const user = userEvent.setup();
    renderBarWithTheRealClient();

    await user.click(await screen.findByRole("button", { name: MAIL.sendButton }));
    const dialog = await screen.findByRole("dialog", { name: MAIL.confirmTitle });
    // `retry: 1` du vrai client : l'échec se déclare après une relance (~1 s).
    const alert = await within(dialog).findByRole("alert", {}, { timeout: 4000 });
    expect(alert).toHaveTextContent(MAIL.previewLoadFailed);
    expect(useToastStore.getState().toasts, "la modale dit l'échec : le toast générique le doublerait").toHaveLength(0);
  });

  /**
   * Le même filet, prouvé sur le hook seul : sans `meta: { ownsError: true }` (le squelette
   * d'avant NEO), `QueryCache.onError` toaste — ce test est le témoin de cette falsification.
   */
  it("`useConflictMailPreview` en échec ne toaste jamais (`meta.ownsError`)", async () => {
    vi.mocked(matchesApi.getConflictMailPreview).mockRejectedValue(new Error("network down"));
    const { result } = renderHook(() => useConflictMailPreview(true), { wrapper });

    await waitFor(() => expect(result.current.isError).toBe(true), { timeout: 4000 });
    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(useToastStore.getState().toasts).toHaveLength(0);
  });
});
