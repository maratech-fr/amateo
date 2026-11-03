import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { MemoryRouter, Route, Routes } from "react-router";
import { describe, expect, it, vi } from "vitest";

const h = { get: vi.fn(), decide: vi.fn() };

// Couche API voisine mockée (le mock ESM n'intercepte pas l'intra-module) : la
// page reste montée sur un vrai QueryClient — le câblage query/mutation est réel.
vi.mock("./api", () => ({
  getClubApproval: (token: string) => h.get(token),
  decideClubApproval: (token: string, decision: string) => h.decide(token, decision),
}));

import { ClubApprovalPage } from "./ClubApprovalPage";
import { AUTH_TEXTS } from "./texts";

function renderAt(token = "a".repeat(64)) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[`/club-approval/${token}`]}>
        <Routes>
          <Route path="/club-approval/:token" element={<ClubApprovalPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

const info = { clubName: "BC Testville", ara: "ARA0000001", requesterName: "Sonia Saison", expiresAt: "2026-09-12" };

const httpError = (status: number): HTTPError =>
  new HTTPError(new Response("{}", { status }), new Request("http://t/api/club-approvals/x"), {} as never);

describe("ClubApprovalPage (P3-4 PR C — page publique)", () => {
  it("nomme le demandeur et le club, et porte les deux gestes", async () => {
    h.get.mockResolvedValueOnce(info);
    renderAt();

    expect(await screen.findByText("Sonia Saison")).toBeInTheDocument();
    expect(screen.getByText("BC Testville")).toBeInTheDocument();
    // Relevé SWITCH (PR 3) : la date d'expiration s'écrit JJ/MM/AAAA, jamais en ISO (`Y-m-d` servi par l'API).
    expect(screen.getByText(AUTH_TEXTS.clubApprovalExpiresOn("12/09/2026"))).toBeInTheDocument();
    expect(screen.queryByText(/2026-09-12/)).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Approuver" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Refuser" })).toBeInTheDocument();
  });

  it("approuve : POST decision=approve puis écran de confirmation", async () => {
    h.get.mockResolvedValueOnce(info);
    h.decide.mockResolvedValueOnce({ status: "approved" });
    renderAt();

    await userEvent.click(await screen.findByRole("button", { name: "Approuver" }));

    expect(h.decide).toHaveBeenCalledWith("a".repeat(64), "approve");
    expect(await screen.findByText("Espace créé")).toBeInTheDocument();
  });

  it("refuse : POST decision=refuse puis écran de clôture", async () => {
    h.get.mockResolvedValueOnce(info);
    h.decide.mockResolvedValueOnce({ status: "refused" });
    renderAt();

    await userEvent.click(await screen.findByRole("button", { name: "Refuser" }));

    expect(h.decide).toHaveBeenCalledWith("a".repeat(64), "refuse");
    expect(await screen.findByText("Demande refusée")).toBeInTheDocument();
  });

  /**
   * Chantier Membre PR 3 (plan v5) — la page dit ce qu'on approuve, selon le `kind` servi par le
   * serveur : `reprise` (club sans gestionnaire) ou `promotion` (gestionnaires sans réponse
   * 14 jours) ne sont pas une création ; l'adresse du demandeur est montrée pour qu'on le
   * reconnaisse ; l'issue affichée est celle que le serveur rend (`approved` / `superseded` /
   * `refused`) — jamais « Demande refusée » pour une demande dépassée (bug PR 1, création comprise).
   * Page migrée : le test vérifie les CLÉS d'`AUTH_TEXTS`, jamais la phrase recopiée.
   */
  describe("reprise et promotion (PR 3)", () => {
    const reprise = { ...info, kind: "reprise", requesterEmail: "sonia@club.fr" };
    const promotion = { ...info, kind: "promotion", requesterEmail: "sonia@club.fr" };

    it("reprise : titre de reprise, le demandeur ET son adresse, l'aide qui annonce l'annulation de la suppression", async () => {
      h.get.mockResolvedValueOnce(reprise);
      renderAt();

      expect(await screen.findByText(AUTH_TEXTS.clubApprovalRepriseTitle)).toBeInTheDocument();
      expect(screen.getByText(/sonia@club\.fr/)).toBeInTheDocument();
      expect(screen.getByText(AUTH_TEXTS.clubApprovalRepriseHelp)).toBeInTheDocument();
      expect(screen.queryByText(AUTH_TEXTS.clubApprovalCreateTitle)).not.toBeInTheDocument();
      expect(screen.getByRole("button", { name: AUTH_TEXTS.clubApprovalApprove })).toBeInTheDocument();
    });

    it("promotion : titre de promotion, l'adresse, l'aide — sans promettre que « personne n'est retiré »", async () => {
      h.get.mockResolvedValueOnce(promotion);
      const { container } = renderAt();

      expect(await screen.findByText(AUTH_TEXTS.clubApprovalPromotionTitle)).toBeInTheDocument();
      expect(screen.getByText(/sonia@club\.fr/)).toBeInTheDocument();
      expect(screen.getByText(AUTH_TEXTS.clubApprovalPromotionHelp)).toBeInTheDocument();
      expect(container.textContent).not.toContain("personne n'est retiré");
    });

    it("reprise approuvée → « Reprise approuvée » ; promotion approuvée → « Gestionnaire nommé »", async () => {
      h.get.mockResolvedValueOnce(reprise);
      h.decide.mockResolvedValueOnce({ status: "approved" });
      const { unmount } = renderAt();
      await userEvent.click(await screen.findByRole("button", { name: AUTH_TEXTS.clubApprovalApprove }));
      expect(await screen.findByText(AUTH_TEXTS.clubApprovalRepriseApprovedTitle)).toBeInTheDocument();
      expect(screen.queryByText(AUTH_TEXTS.clubApprovalCreatedTitle)).not.toBeInTheDocument();
      unmount();

      h.get.mockResolvedValueOnce(promotion);
      h.decide.mockResolvedValueOnce({ status: "approved" });
      renderAt("c".repeat(64));
      await userEvent.click(await screen.findByRole("button", { name: AUTH_TEXTS.clubApprovalApprove }));
      expect(await screen.findByText(AUTH_TEXTS.clubApprovalPromotionApprovedTitle)).toBeInTheDocument();
    });

    it("dépassée (reprise) → « Demande dépassée », jamais « refusée » ni « créé »", async () => {
      h.get.mockResolvedValueOnce(reprise);
      h.decide.mockResolvedValueOnce({ status: "superseded" });
      renderAt();
      await userEvent.click(await screen.findByRole("button", { name: AUTH_TEXTS.clubApprovalApprove }));
      expect(await screen.findByText(AUTH_TEXTS.clubApprovalSupersededTitle)).toBeInTheDocument();
      expect(screen.getByText(AUTH_TEXTS.clubApprovalSupersededTakenOver)).toBeInTheDocument();
      expect(screen.queryByText(AUTH_TEXTS.clubApprovalRefusedTitle)).not.toBeInTheDocument();
      expect(screen.queryByText(AUTH_TEXTS.clubApprovalCreatedTitle)).not.toBeInTheDocument();
    });

    it("dépassée (création : le club existe déjà) → « Demande dépassée » + transmise aux gestionnaires, jamais « refusée »", async () => {
      h.get.mockResolvedValueOnce({ ...info, kind: "create" });
      h.decide.mockResolvedValueOnce({ status: "superseded" });
      renderAt();
      await userEvent.click(await screen.findByRole("button", { name: AUTH_TEXTS.clubApprovalApprove }));
      expect(await screen.findByText(AUTH_TEXTS.clubApprovalSupersededTitle)).toBeInTheDocument();
      expect(screen.getByText(AUTH_TEXTS.clubApprovalSupersededCreate)).toBeInTheDocument();
      expect(screen.queryByText(AUTH_TEXTS.clubApprovalRefusedTitle)).not.toBeInTheDocument();
    });
  });

  it("410 → « expirée », 404 → « lien invalide » (les deux états d'erreur se distinguent)", async () => {
    h.get.mockRejectedValueOnce(httpError(410));
    renderAt();
    expect(await screen.findByText(/Cette demande a expiré/)).toBeInTheDocument();

    h.get.mockRejectedValueOnce(httpError(404));
    renderAt("b".repeat(64));
    expect(await screen.findByText(/lien est invalide/)).toBeInTheDocument();
  });
});
