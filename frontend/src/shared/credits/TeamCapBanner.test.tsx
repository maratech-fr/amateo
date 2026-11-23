import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { ClubEntitlements, MeResponse } from "@/shared/session/api";

/**
 * Promesse (lot 8, PR 3) : au-dessus de son palier, chaque écran prévient le club — le bandeau
 * affiche le message que le serveur sert (`me.club.entitlements.teamCapMessage`, jamais recalculé
 * à partir de `teamsUsed`/`maxTeams`) et mène aux offres (`/club`) ; sans message, aucun bandeau.
 *
 * Hooks RÉELS (`useMe`) sur un vrai QueryClient : seule la couche `@/shared/session/api` est mockée
 * (patron `ClubClosingBanner.test.tsx`).
 */
const h = vi.hoisted(() => ({ getMe: vi.fn() }));
vi.mock("@/shared/session/api", async (importOriginal) => ({ ...(await importOriginal<object>()), ...h }));
vi.mock("@/shared/stores/authStore", () => ({
  useAuthStore: (sel: (s: { isAuthenticated: boolean }) => unknown) => sel({ isAuthenticated: true }),
}));

import { TeamCapBanner } from "./TeamCapBanner";

/** Une donnée SERVIE (pas un libellé de l'écran) : le texte exact du contrat serveur. */
const SERVED_MESSAGE =
  "Votre club compte 23 équipes, votre palier en permet 20. Les générations, le placement des matchs et les exports sont suspendus : supprimez des équipes qui n'ont pas de match, ou changez de palier.";

function meWith(teamCapMessage: string | null): Partial<MeResponse> {
  const entitlements = {
    planCode: "complete",
    planName: "Offre complète",
    tierCode: "palier-1",
    maxTeams: 20,
    teamsUsed: null === teamCapMessage ? 20 : 23,
    creditsMax: null,
    creditsUsed: 0,
    canGenerate: true,
    canPlaceMatches: true,
    canExportPdf: true,
    seasonTransition: true,
    teamCapMessage,
  } as ClubEntitlements;
  return { role: "member", membershipStatus: "active", club: { id: "club-1", name: "BC Test", entitlements } as MeResponse["club"] };
}

function renderBanner(me: Partial<MeResponse>) {
  h.getMe.mockResolvedValue(me);
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <TeamCapBanner />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("TeamCapBanner", () => {
  beforeEach(() => vi.clearAllMocks());

  it("affiche le message du dépassement servi par le serveur, avec un lien vers les offres", async () => {
    renderBanner(meWith(SERVED_MESSAGE));

    expect(await screen.findByText(SERVED_MESSAGE)).toBeInTheDocument();
    expect(screen.getByRole("link")).toHaveAttribute("href", "/club");
  });

  it("n'affiche rien quand le serveur ne sert aucun message", async () => {
    const { container } = renderBanner(meWith(null));

    await waitFor(() => expect(h.getMe).toHaveBeenCalled());
    // Le témoin du cas précédent prouve que la session chargée PEINT le bandeau : ici, rien.
    await waitFor(() => expect(container).toBeEmptyDOMElement());
    expect(screen.queryByRole("link")).toBeNull();
  });
});
