import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { ImportFbiAnalysis, ImportFbiResult, PriorityTier, Team } from "./api";
import { ImportFbiDialog } from "./ImportFbiDialog";

const { analyzeFbiFixtures, importFbiFixtures } = vi.hoisted(() => ({
  analyzeFbiFixtures: vi.fn(() =>
    Promise.resolve({
      divisions: [
        { name: "DF2", fbiTeamLabel: null, rowCount: 22, teamId: null, competitionId: null, suggestedTeamId: null, suggestedCompetitionId: null, pouleError: null, pouleUnknownOpponents: [] },
        { name: "PNM", fbiTeamLabel: null, rowCount: 10, teamId: "team-1", competitionId: "comp-1", suggestedTeamId: null, suggestedCompetitionId: null, pouleError: null, pouleUnknownOpponents: [] },
      ],
      totalRows: 34,
      exempted: 2,
      errors: [],
    } satisfies ImportFbiAnalysis as ImportFbiAnalysis),
  ),
  importFbiFixtures: vi.fn(() =>
    Promise.resolve({
      message: "Import terminé.",
      created: 22,
      updated: 1,
      unchanged: 9,
      exempted: 2,
      errors: ["Ligne 4 : aucune équipe ne correspond au club « BC Test »."],
      warnings: [{ type: "RESCHEDULED", division: "PNM", externalRef: "101137", message: "PNM n°101137 : re-programmé du 28/11/2026 au 05/12/2026." }],
      unmappedDivisions: [],
      completeness: [],
    } satisfies ImportFbiResult as ImportFbiResult),
  ),
}));

vi.mock("./api", () => ({ analyzeFbiFixtures, importFbiFixtures }));

const teams: Team[] = [
  { id: "team-1", name: "SM1", sportCategoryId: "cat", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
  { id: "team-2", name: "SF3", sportCategoryId: "cat2", level: null, gender: null, priorityTierId: 3, tierOrder: 0 },
];
const tiers: PriorityTier[] = [
  { id: 1, label: "S", name: "Fanion", color: null },
  { id: 3, label: "B", name: "Moyenne", color: null },
];

const pickFile = async (user: ReturnType<typeof userEvent.setup>) => {
  const file = new File(["xlsx"], "fbi.xlsx", { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
  await user.upload(screen.getByLabelText("Fichier FBI"), file);
};

beforeEach(() => {
  analyzeFbiFixtures.mockClear();
  importFbiFixtures.mockClear();
});

describe("ImportFbiDialog", () => {
  it("disables Importer until a file is picked and analyzed", () => {
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);
    expect(screen.getByRole("button", { name: "Importer" })).toBeDisabled();
    expect(analyzeFbiFixtures).not.toHaveBeenCalled();
  });

  it("analyzes on file pick: known mappings shown as text, new ones as a select", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);

    expect(analyzeFbiFixtures).toHaveBeenCalledOnce();
    // The persisted PNM mapping is pre-filled (text, no select)…
    await waitFor(() => expect(screen.getByText("→ SM1")).toBeInTheDocument());
    // …and the unknown DF2 division offers the team picker.
    expect(screen.getByLabelText("Équipe pour DF2")).toBeInTheDocument();
    expect(screen.queryByLabelText("Équipe pour PNM")).not.toBeInTheDocument();
  });

  it("imports in ONE pass: file + the new mappings only, then shows the report", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(screen.getByLabelText("Équipe pour DF2")).toBeInTheDocument());
    await user.selectOptions(screen.getByLabelText("Équipe pour DF2"), "team-2");
    await user.click(screen.getByRole("button", { name: "Importer" }));

    expect(importFbiFixtures).toHaveBeenCalledOnce();
    // Only DF2 rides along — PNM is already persisted server-side.
    expect(importFbiFixtures).toHaveBeenCalledWith(expect.any(File), [{ division: "DF2", fbiTeamLabel: null, teamId: "team-2", competitionId: null }]);

    // The dialog stays open and surfaces the diff report + warnings + errors.
    await waitFor(() => expect(screen.getByText(/22 créés · 1 mis à jour · 9 inchangés/)).toBeInTheDocument());
    expect(screen.getByText(/PNM n°101137 : re-programmé/)).toBeInTheDocument();
    expect(screen.getByText(/Ligne 4 : aucune équipe ne correspond/)).toBeInTheDocument();
  });

  // ── FFBB pairing feedback (P1-4 PR F2) ────────────────────────────────────

  it("pre-fills the FFBB suggestion, sends it as the mapping, and shows the badge", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures.mockResolvedValueOnce({
      divisions: [
        { name: "DF2", fbiTeamLabel: null, rowCount: 22, teamId: null, competitionId: null, suggestedTeamId: "team-2", suggestedCompetitionId: "comp-9", pouleError: null, pouleUnknownOpponents: [] },
      ],
      totalRows: 22,
      exempted: 0,
      errors: [],
    });
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(screen.getByLabelText("Équipe pour DF2")).toHaveValue("team-2"));
    expect(screen.getByText("proposé par la FFBB")).toBeInTheDocument();

    // What the select DISPLAYS is what gets imported — untouched suggestion
    // included, and its competitionId rides along (pairing reused server-side).
    await user.click(screen.getByRole("button", { name: "Importer" }));
    expect(importFbiFixtures).toHaveBeenCalledWith(expect.any(File), [{ division: "DF2", fbiTeamLabel: null, teamId: "team-2", competitionId: "comp-9" }]);
  });

  it("ignores a suggestion whose team is not offerable — nothing invisible is ever sent", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures.mockResolvedValueOnce({
      divisions: [
        // The suggested team was deleted since the pairing: the select cannot
        // display it, so the submit must not send it either.
        { name: "DF2", fbiTeamLabel: null, rowCount: 22, teamId: null, competitionId: null, suggestedTeamId: "team-gone", suggestedCompetitionId: "comp-9", pouleError: null, pouleUnknownOpponents: [] },
      ],
      totalRows: 22,
      exempted: 0,
      errors: [],
    });
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(screen.getByLabelText("Équipe pour DF2")).toBeInTheDocument());
    expect(screen.getByLabelText("Équipe pour DF2")).toHaveValue("");
    expect(screen.queryByText("proposé par la FFBB")).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Importer" }));
    expect(importFbiFixtures).toHaveBeenCalledWith(expect.any(File), []);
  });

  it("shows the blocking poule error and the minor drift on the analyze table", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures.mockResolvedValueOnce({
      divisions: [
        { name: "D2", fbiTeamLabel: null, rowCount: 11, teamId: "team-1", competitionId: "c1", suggestedTeamId: null, suggestedCompetitionId: null, pouleError: "Division « D2 » ignorée : 8 adversaires sur 11 hors de la poule « Poule B2 » — mauvais fichier ?", pouleUnknownOpponents: [] },
        { name: "D3", fbiTeamLabel: null, rowCount: 9, teamId: "team-2", competitionId: "c2", suggestedTeamId: null, suggestedCompetitionId: null, pouleError: null, pouleUnknownOpponents: ["US INTRUS"] },
      ],
      totalRows: 20,
      exempted: 0,
      errors: [],
    });
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(screen.getByText(/Division « D2 » ignorée/)).toBeInTheDocument());
    expect(screen.getByText(/D3 : hors poule — US INTRUS/)).toBeInTheDocument();
  });

  // ── RMM-0 (§6bis B3/B4) — mapper à l'aveugle crée des matchs sur la MAUVAISE équipe ─────────
  it("B3/B4 — le libellé de division et la valeur du select restent lisibles (wrap + title, select élargi)", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures.mockResolvedValueOnce({
      divisions: [
        { name: "Division 2 Masculine Séniors", fbiTeamLabel: "Équipe 2", rowCount: 22, teamId: null, competitionId: null, suggestedTeamId: "team-2", suggestedCompetitionId: "comp-9", pouleError: null, pouleUnknownOpponents: [] },
      ],
      totalRows: 22,
      exempted: 0,
      errors: [],
    });
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);

    // B3 — le nom de division + son fbiTeamLabel (qui n'existe QUE quand il est indispensable).
    const label = await screen.findByText(/Division 2 Masculine Séniors/);
    expect(label).not.toHaveClass("truncate");
    expect(label.getAttribute("title")).toContain("Équipe 2");

    // B4 — la valeur pré-remplie du select se lit sans l'ouvrir (élargie + title de secours).
    const select = screen.getByLabelText(/Équipe pour Division 2 Masculine Séniors/);
    expect(select).toHaveClass("w-52");
    expect(select).toHaveAttribute("title", "SF3");
  });

  it("reports the completeness of paired competitions after the import", async () => {
    const user = userEvent.setup();
    importFbiFixtures.mockResolvedValueOnce({
      message: "Import terminé.",
      created: 9,
      updated: 0,
      unchanged: 0,
      exempted: 0,
      errors: [],
      warnings: [],
      unmappedDivisions: [],
      completeness: [{ competitionId: "c1", name: "PNM", imported: 9, expected: 22 }],
    });
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(screen.getByLabelText("Équipe pour DF2")).toBeInTheDocument());
    await user.click(screen.getByRole("button", { name: "Importer" }));

    await waitFor(() => expect(screen.getByText(/PNM : 9\/22 journées — fichier partiel ou phase pas encore sortie/)).toBeInTheDocument());
  });
});
