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
    expect(screen.getByText(/expire le 2026-09-12/)).toBeInTheDocument();
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
   * Chantier Membre PR 3 — la page dit ce qu'on approuve : une REPRISE (un Membre d'un club sans
   * gestionnaire) n'est pas une création ; l'adresse du demandeur est montrée pour qu'on le
   * reconnaisse ; l'issue affichée est celle que le serveur rend (`approved` / `superseded` /
   * `refused`), jamais « Espace créé » pour une demande dépassée. Page non migrée vers
   * `auth/texts.ts` : libellés du plan recopiés (tolérés), le rôle discrimine les gestes.
   */
  describe("reprise d'un espace sans gestionnaire (PR 3)", () => {
    const reprise = { ...info, kind: "reprise", requesterEmail: "sonia@club.fr" };

    it("nomme la reprise, le demandeur ET son adresse", async () => {
      h.get.mockResolvedValueOnce(reprise);
      renderAt();

      expect(await screen.findByText("Demande de reprise d'espace")).toBeInTheDocument();
      expect(screen.getByText(/sonia@club\.fr/)).toBeInTheDocument();
      expect(screen.getByText(/demande à reprendre la gestion de l'espace de/)).toBeInTheDocument();
      expect(screen.getByText(/elle deviendra gestionnaire et la suppression de l'espace sera annulée/)).toBeInTheDocument();
      expect(screen.queryByText("Demande de création d'espace")).not.toBeInTheDocument();
      expect(screen.getByRole("button", { name: "Approuver" })).toBeInTheDocument();
    });

    it("approuvée → « Reprise approuvée »", async () => {
      h.get.mockResolvedValueOnce(reprise);
      h.decide.mockResolvedValueOnce({ status: "approved" });
      renderAt();
      await userEvent.click(await screen.findByRole("button", { name: "Approuver" }));
      expect(await screen.findByText("Reprise approuvée")).toBeInTheDocument();
      expect(screen.queryByText("Espace créé")).not.toBeInTheDocument();
    });

    it("dépassée (un gestionnaire a déjà repris) → « Demande dépassée », jamais « refusée » ni « créé »", async () => {
      h.get.mockResolvedValueOnce(reprise);
      h.decide.mockResolvedValueOnce({ status: "superseded" });
      renderAt();
      await userEvent.click(await screen.findByRole("button", { name: "Approuver" }));
      expect(await screen.findByText("Demande dépassée")).toBeInTheDocument();
      expect(screen.queryByText("Demande refusée")).not.toBeInTheDocument();
      expect(screen.queryByText("Espace créé")).not.toBeInTheDocument();
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
