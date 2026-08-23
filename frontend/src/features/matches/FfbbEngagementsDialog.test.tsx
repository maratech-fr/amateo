import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { FfbbEngagement, PriorityTier, Team } from "./api";
import { FfbbEngagementsDialog } from "./FfbbEngagementsDialog";

const { getFfbbEngagements, confirmFfbbPairings } = vi.hoisted(() => ({
  getFfbbEngagements: vi.fn(),
  confirmFfbbPairings: vi.fn(() => Promise.resolve()),
}));

vi.mock("./api", () => ({ getFfbbEngagements, confirmFfbbPairings }));

const teams: Team[] = [
  { id: "team-sm1", name: "SM1", sportCategoryId: "cat", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
  { id: "team-sm2", name: "SM2", sportCategoryId: "cat", level: null, gender: null, priorityTierId: 3, tierOrder: 0 },
];
const tiers: PriorityTier[] = [
  { id: 1, label: "S", name: "Fanion", color: null },
  { id: 3, label: "B", name: "Moyenne", color: null },
];

const engagement = (over: Partial<FfbbEngagement> = {}): FfbbEngagement => ({
  ffbbCompetitionId: "comp-1",
  ffbbCompetitionCode: "PRM",
  competitionName: "Pré régionale masculine",
  ffbbPouleId: "poule-b2",
  pouleName: "Poule B2",
  category: "Seniors",
  level: "Régional",
  gender: "Masculin",
  pouleSize: 8,
  pouleOpponents: [],
  suggestedTeamId: null,
  suggestedCompetitionId: null,
  ...over,
});

beforeEach(() => {
  getFfbbEngagements.mockReset();
  confirmFfbbPairings.mockClear();
});

describe("FfbbEngagementsDialog (P1-4 PR F)", () => {
  it("lists the engagements, requires a choice, and confirms in block", async () => {
    const user = userEvent.setup();
    getFfbbEngagements.mockResolvedValue({ engagements: [engagement()] });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    expect(await screen.findByText("Pré régionale masculine")).toBeInTheDocument();
    expect(screen.getByText(/Poule B2 · 8 clubs/)).toBeInTheDocument();
    // League-data disclosure is MANDATORY (appariement §3).
    expect(screen.getByText(/Données de la ligue/)).toBeInTheDocument();
    // Nothing chosen yet → nothing to confirm.
    expect(screen.getByRole("button", { name: /Confirmer/ })).toBeDisabled();

    await user.selectOptions(screen.getByLabelText("Équipe pour Pré régionale masculine"), "team-sm2");
    await user.click(screen.getByRole("button", { name: "Confirmer 1 appariement" }));

    expect(confirmFfbbPairings).toHaveBeenCalledWith([{ ffbbCompetitionId: "comp-1", teamId: "team-sm2" }]);
  });

  it("pre-fills from the suggestion — next phases are 1 click", async () => {
    getFfbbEngagements.mockResolvedValue({ engagements: [engagement({ suggestedTeamId: "team-sm1" })] });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    expect(await screen.findByLabelText("Équipe pour Pré régionale masculine")).toHaveValue("team-sm1");
    expect(screen.getByRole("button", { name: "Confirmer 1 appariement" })).toBeEnabled();
  });

  it("an emptied row is NOT sent — the absence of a link is the state", async () => {
    const user = userEvent.setup();
    getFfbbEngagements.mockResolvedValue({
      engagements: [engagement({ suggestedTeamId: "team-sm1" }), engagement({ ffbbCompetitionId: "comp-2", competitionName: "Coupe", suggestedTeamId: "team-sm2" })],
    });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await user.selectOptions(await screen.findByLabelText("Équipe pour Coupe"), "");
    await user.click(screen.getByRole("button", { name: "Confirmer 1 appariement" }));

    expect(confirmFfbbPairings).toHaveBeenCalledWith([{ ffbbCompetitionId: "comp-1", teamId: "team-sm1" }]);
  });

  it("FFBB down → named failure, no crash", async () => {
    getFfbbEngagements.mockRejectedValue(new Error("502"));
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await waitFor(() => expect(screen.getByText(/FFBB indisponible/)).toBeInTheDocument());
  });
});

// ── RMM-0 (§6bis B1/B2/B4) — la décision d'appariement ne se prend plus à l'aveugle ─────────
describe("RMM-0 — lisibilité de l'appariement (B1/B2/B4)", () => {
  it("la modale prend le palier large ; le nom de compétition et sa sous-ligne montrent leur queue", async () => {
    getFfbbEngagements.mockResolvedValue({
      engagements: [engagement({ competitionName: "Championnat Régional Séniors Masculins Division 3" })],
    });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    // Palier « lg » (liste d'engagements) : la modale a la place de montrer la queue de chaîne.
    expect(screen.getByRole("dialog")).toHaveClass("lg:max-w-3xl");

    // B1 — le chiffre discriminant (« Division 3 ») est en QUEUE : le libellé ne doit plus être
    // tronqué sans secours (wrap = toujours visible, + title de secours).
    const name = await screen.findByText("Championnat Régional Séniors Masculins Division 3");
    expect(name).not.toHaveClass("truncate");
    expect(name).toHaveAttribute("title", "Championnat Régional Séniors Masculins Division 3");

    // B2 — la sous-ligne désambiguïsante (poule · taille · catégorie · niveau · genre).
    const sub = screen.getByText(/Poule B2 · 8 clubs/);
    expect(sub).not.toHaveClass("truncate");
    expect(sub.getAttribute("title")).toContain("Masculin");
  });

  it("B4 — le select d'équipe expose sa valeur choisie (élargi + title de secours)", async () => {
    getFfbbEngagements.mockResolvedValue({ engagements: [engagement({ suggestedTeamId: "team-sm2" })] });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    const select = await screen.findByLabelText("Équipe pour Pré régionale masculine");
    expect(select).toHaveClass("w-52");
    // La valeur pré-remplie se lit sans ouvrir le select.
    expect(select).toHaveAttribute("title", "SM2");
  });
});
