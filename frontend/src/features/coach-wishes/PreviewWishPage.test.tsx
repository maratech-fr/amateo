import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Route, Routes } from "react-router";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { PublicWishContext } from "./publicApi";

const h = { preview: vi.fn() };
vi.mock("./campaignApi", async (importOriginal) => ({
  ...(await importOriginal<typeof import("./campaignApi")>()),
  getCampaignPreview: (campaignId: string, coachId: string) => h.preview(campaignId, coachId),
}));

import { PreviewWishPage } from "./PreviewWishPage";

const context = (over: Partial<PublicWishContext> = {}): PublicWishContext => ({
  coachFirstName: "Maxime",
  periodTitle: "Vacances de février",
  periodStart: "2026-02-16",
  periodEnd: "2026-03-01",
  deadline: "2027-06-30",
  weeks: ["2026-02-16"],
  teams: [{ id: "t1", name: "SM1" }],
  partnerTeams: [
    { id: "t1", name: "SM1" },
    { id: "t2", name: "SF1" },
  ],
  teamLinks: [],
  wishes: [],
  mutualizations: [],
  respondedAt: null,
  ...over,
});

function renderPreview() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={["/doleances/apercu/camp1?coach=c1"]}>
        <Routes>
          <Route path="/doleances/apercu/:campaignId" element={<PreviewWishPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("PreviewWishPage — aperçu gestionnaire (lecture seule)", () => {
  beforeEach(() => {
    h.preview.mockReset();
    sessionStorage.clear();
  });

  it("appelle le preview avec campaignId + coach et annonce « Aperçu — ce que voit {Prénom} »", async () => {
    h.preview.mockResolvedValue(context());
    renderPreview();

    await screen.findByText(/club prépare le planning/);
    expect(h.preview).toHaveBeenCalledWith("camp1", "c1");
    expect(screen.getByText(/Aperçu — ce que voit Maxime/)).toBeInTheDocument();
  });

  it("l'envoi est désactivé (lecture seule) et aucun brouillon n'est posé", async () => {
    h.preview.mockResolvedValue(context());
    renderPreview();

    await userEvent.click(await screen.findByRole("button", { name: /Commencer/ }));
    await userEvent.click(screen.getByRole("button", { name: "Suivant" })); // récap
    const validate = screen.getByRole("button", { name: /Confirmer sans modification|Valider et envoyer/ });
    expect(validate).toHaveAttribute("aria-disabled", "true");
    // Aucun brouillon posé par l'aperçu.
    expect(sessionStorage.length).toBe(0);
  });

  it("affiche un état indisponible si le coach n'est pas dans la collecte (404)", async () => {
    h.preview.mockRejectedValue(new Error("not found"));
    renderPreview();
    expect(await screen.findByText(/Aperçu indisponible/)).toBeInTheDocument();
  });
});
