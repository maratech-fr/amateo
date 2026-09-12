import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { pickListboxOption } from "@/test/pickListboxOption";
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
  suggestionSource: null,
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

    await pickListboxOption(user, "Équipe pour Pré régionale masculine", "SM2"); // team-sm2
    await user.click(screen.getByRole("button", { name: "Confirmer 1 appariement" }));

    expect(confirmFfbbPairings).toHaveBeenCalledWith([{ ffbbCompetitionId: "comp-1", teamId: "team-sm2" }]);
  });

  it("pre-fills from the suggestion — next phases are 1 click", async () => {
    getFfbbEngagements.mockResolvedValue({ engagements: [engagement({ suggestedTeamId: "team-sm1" })] });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    // La suggestion (team-sm1 = SM1) se lit sur le trigger sans ouvrir la liste.
    expect(await screen.findByRole("button", { name: /Équipe pour Pré régionale masculine/ })).toHaveAccessibleName(/SM1/);
    expect(screen.getByRole("button", { name: "Confirmer 1 appariement" })).toBeEnabled();
  });

  it("an emptied row is NOT sent — the absence of a link is the state", async () => {
    const user = userEvent.setup();
    getFfbbEngagements.mockResolvedValue({
      engagements: [engagement({ suggestedTeamId: "team-sm1" }), engagement({ ffbbCompetitionId: "comp-2", competitionName: "Coupe", suggestedTeamId: "team-sm2" })],
    });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await screen.findByText("Coupe");
    await pickListboxOption(user, "Équipe pour Coupe", "Non rattachée"); // le placeholder efface la suggestion
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

    // Palier « xl » (P4-200 C2 — même patron que `ImportFbiDialog`) : la modale a la place de
    // montrer la queue de chaîne ET la sous-ligne désambiguïsante sans enroulement agressif.
    expect(screen.getByRole("dialog")).toHaveClass("lg:max-w-5xl");

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

    const select = await screen.findByRole("button", { name: /Équipe pour Pré régionale masculine/ });
    expect(select).toHaveClass("w-52");
    // La valeur pré-remplie (SM2) se lit sur le trigger sans ouvrir la liste.
    expect(select).toHaveAccessibleName(/SM2/);
  });
});

// ── P4-200 C2 — chip d'origine FBI, compteur, competitionId de la suggestion conservée ────────
describe("P4-200 C2 — refonte de la modale Engagements", () => {
  it("chip « suggéré depuis l'import FBI » quand la suggestion vient de l'import et n'est pas retouchée", async () => {
    getFfbbEngagements.mockResolvedValue({ engagements: [engagement({ suggestionSource: "fbi", suggestedTeamId: "team-sm1" })] });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await screen.findByText("Pré régionale masculine");
    expect(screen.getByText("suggéré depuis l'import FBI")).toBeInTheDocument();
  });

  it("aucune chip pour un appariement reconduit (source pairing) — pas une hypothèse", async () => {
    getFfbbEngagements.mockResolvedValue({ engagements: [engagement({ suggestionSource: "pairing", suggestedTeamId: "team-sm1" })] });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await screen.findByText("Pré régionale masculine");
    expect(screen.queryByText("suggéré depuis l'import FBI")).not.toBeInTheDocument();
  });

  it("la chip disparaît dès qu'un choix explicite est fait sur la ligne", async () => {
    const user = userEvent.setup();
    getFfbbEngagements.mockResolvedValue({ engagements: [engagement({ suggestionSource: "fbi", suggestedTeamId: "team-sm1" })] });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await screen.findByText("suggéré depuis l'import FBI");
    await pickListboxOption(user, "Équipe pour Pré régionale masculine", "SM2");
    expect(screen.queryByText("suggéré depuis l'import FBI")).not.toBeInTheDocument();
  });

  it("compteur « N rattachée(s) sur M » — passe de 1/2 à 2/2 après un choix", async () => {
    const user = userEvent.setup();
    getFfbbEngagements.mockResolvedValue({
      engagements: [
        engagement({ suggestionSource: "fbi", suggestedTeamId: "team-sm1" }),
        engagement({ ffbbCompetitionId: "comp-2", competitionName: "Coupe", suggestionSource: null, suggestedTeamId: null }),
      ],
    });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await screen.findByText("Coupe");
    expect(screen.getByText("1 rattachée sur 2")).toBeInTheDocument();
    await pickListboxOption(user, "Équipe pour Coupe", "SM2");
    expect(screen.getByText("2 rattachées sur 2")).toBeInTheDocument();
  });

  it("confirm envoie competitionId quand la suggestion est conservée telle quelle", async () => {
    const user = userEvent.setup();
    getFfbbEngagements.mockResolvedValue({
      engagements: [engagement({ suggestionSource: "pairing", suggestedTeamId: "team-sm1", suggestedCompetitionId: "comp-x" })],
    });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await screen.findByRole("button", { name: "Confirmer 1 appariement" });
    await user.click(screen.getByRole("button", { name: "Confirmer 1 appariement" }));

    expect(confirmFfbbPairings).toHaveBeenCalledWith([{ ffbbCompetitionId: "comp-1", teamId: "team-sm1", competitionId: "comp-x" }]);
  });

  it("confirm N'ENVOIE PAS de competitionId quand l'équipe est changée", async () => {
    const user = userEvent.setup();
    getFfbbEngagements.mockResolvedValue({
      engagements: [engagement({ suggestionSource: "pairing", suggestedTeamId: "team-sm1", suggestedCompetitionId: "comp-x" })],
    });
    renderWithProviders(<FfbbEngagementsDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await screen.findByText("Pré régionale masculine");
    await pickListboxOption(user, "Équipe pour Pré régionale masculine", "SM2");
    await user.click(screen.getByRole("button", { name: "Confirmer 1 appariement" }));

    expect(confirmFfbbPairings).toHaveBeenCalledWith([{ ffbbCompetitionId: "comp-1", teamId: "team-sm2" }]);
  });
});
