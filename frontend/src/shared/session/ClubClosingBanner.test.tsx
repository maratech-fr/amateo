import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MeResponse } from "./api";

/**
 * Promesse (PR 2, décision fondateur 3) : quand le club n'a plus de gestionnaire, CHAQUE écran dit
 * la date d'effacement (`me.club.closingOn`, JJ/MM/AAAA) ; rien quand `closingOn` est null ou que la
 * session n'est pas chargée.
 *
 * PR 3 l'enrichit du geste de reprise : un Membre demande à devenir gestionnaire DEPUIS le bandeau
 * (la demande part à l'adresse officielle). La promesse PR 2 « sans aucun bouton » est donc
 * REMPLACÉE (plus retirée sans filet) par « aucune demande → bouton ». Trois états, ceux que le
 * serveur sert (`/api/me.managerRequest`, jamais redérivés) : aucune demande → bouton ; transmise →
 * la date, sans bouton ; expirée / refusée → la phrase de la carte « Mon rôle » (renvoi possible
 * seulement si expirée).
 *
 * Hooks RÉELS (`./queries`) sur un vrai QueryClient : seule la couche `./api` est mockée — le test
 * garde le câblage du geste (appel, toast de succès, relecture de `/api/me`), pas seulement le rendu.
 */
const h = vi.hoisted(() => ({ getMe: vi.fn(), requestManagerRole: vi.fn(), cancelManagerRequest: vi.fn() }));
vi.mock("./api", async (importOriginal) => ({ ...(await importOriginal<object>()), ...h }));
vi.mock("@/shared/stores/authStore", () => ({
  useAuthStore: (sel: (s: { isAuthenticated: boolean }) => unknown) => sel({ isAuthenticated: true }),
}));
const toast = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn(), info: vi.fn() }));
vi.mock("@/shared/stores/toastStore", () => ({ toast }));

import { ClubClosingBanner } from "./ClubClosingBanner";
import { SESSION_TEXTS } from "./texts";

const SINCE = "2026-11-03T12:00:00+00:00";

function meWith(managerRequest: MeResponse["managerRequest"], closingOn: string | null = "2026-12-03"): Partial<MeResponse> {
  return {
    role: "member",
    membershipStatus: "active",
    managerRequest,
    club: { id: "club-1", name: "BC Test", closingOn } as MeResponse["club"],
  };
}

function renderBanner(me: Partial<MeResponse> | Promise<never>) {
  if (me instanceof Promise) {
    h.getMe.mockReturnValue(me);
  } else {
    h.getMe.mockResolvedValue(me);
  }
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <ClubClosingBanner />
    </QueryClientProvider>,
  );
}

describe("ClubClosingBanner", () => {
  beforeEach(() => vi.clearAllMocks());

  it("ne rend rien quand le club a un gestionnaire (closingOn null)", async () => {
    renderBanner(meWith(null, null));
    await vi.waitFor(() => expect(h.getMe).toHaveBeenCalled());
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("ne rend rien tant que la session n'est pas chargée", () => {
    renderBanner(new Promise<never>(() => undefined));
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("aucune demande : la date d'effacement et le bouton ; le clic envoie la demande, le dit et relit la session", async () => {
    h.requestManagerRole.mockResolvedValue({ status: "club_pending", since: SINCE, clubEmailKnown: true });
    renderBanner(meWith(null));

    expect(await screen.findByText(SESSION_TEXTS.clubClosing("03/12/2026"))).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: SESSION_TEXTS.requestManagerRole }));

    expect(h.requestManagerRole).toHaveBeenCalledTimes(1);
    await vi.waitFor(() => expect(toast.success).toHaveBeenCalledWith(SESSION_TEXTS.managerRequestSent));
    await vi.waitFor(() => expect(h.getMe).toHaveBeenCalledTimes(2));
  });

  it("transmise à l'adresse officielle : la date d'envoi, plus de bouton", async () => {
    renderBanner(meWith({ status: "club_pending", since: SINCE, escalated: false, clubEmailKnown: true }));

    expect(await screen.findByText(SESSION_TEXTS.repriseRequestSent("03/11/2026"))).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: SESSION_TEXTS.requestManagerRole })).not.toBeInTheDocument();
  });

  it("expirée : la phrase de la carte et le bouton pour renvoyer ; refusée : les deux dates, sans bouton", async () => {
    const { unmount } = renderBanner(meWith({ status: "expired", since: SINCE, escalated: false }));
    expect(await screen.findByText(SESSION_TEXTS.managerRequestExpired("03/11/2026"))).toBeInTheDocument();
    expect(screen.getByRole("button", { name: SESSION_TEXTS.requestManagerRole })).toBeInTheDocument();
    unmount();

    renderBanner(meWith({ status: "refused", since: SINCE, escalated: false, until: "2026-12-03" }));
    expect(await screen.findByText(SESSION_TEXTS.managerRequestRefused("03/11/2026", "03/12/2026"))).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: SESSION_TEXTS.requestManagerRole })).not.toBeInTheDocument();
  });

  it("un échec serveur (429 du seau club, 409…) est restitué en toast, jamais avalé", async () => {
    h.requestManagerRole.mockRejectedValue(new Error("429"));
    renderBanner(meWith(null));
    await userEvent.click(await screen.findByRole("button", { name: SESSION_TEXTS.requestManagerRole }));
    await vi.waitFor(() => expect(toast.error).toHaveBeenCalled());
  });
});
