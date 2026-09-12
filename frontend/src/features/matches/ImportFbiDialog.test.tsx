import { screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { toast } from "@/shared/stores/toastStore";
import { listboxTrigger, pickListboxOption } from "@/test/pickListboxOption";
import { renderWithProviders } from "@/test/utils";

import type { ImportAnalysisDivision, ImportFbiAnalysis, ImportFbiResult, PriorityTier, Team } from "./api";
import { ImportFbiDialog } from "./ImportFbiDialog";

const { analyzeFbiFixtures, importFbiFixtures, placeMatches } = vi.hoisted(() => ({
  analyzeFbiFixtures: vi.fn(() =>
    Promise.resolve({
      divisions: [
        { name: "DF2", fbiTeamLabel: null, rowCount: 22, teamId: null, competitionId: null, suggestedTeamId: null, suggestedCompetitionId: null, pouleError: null, pouleUnknownOpponents: [] },
        { name: "PNM", fbiTeamLabel: null, rowCount: 10, teamId: "team-1", competitionId: "comp-1", suggestedTeamId: null, suggestedCompetitionId: null, pouleError: null, pouleUnknownOpponents: [] },
      ],
      totalRows: 34,
      exempted: 2,
      errors: [],
      deviations: [],
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
      unresolvedDeviations: [],
      depositedAt: "2026-08-24T10:00:00+00:00",
    } satisfies ImportFbiResult as ImportFbiResult),
  ),
  placeMatches: vi.fn(() => Promise.resolve({ placed: 22, skipped: 0, unplaced: [], diagnostics: [] })),
}));

vi.mock("./api", () => ({ analyzeFbiFixtures, importFbiFixtures, placeMatches }));

// useCredits lit useMe : `club` mutable pour piloter le solde (bouton de placement
// de fin d'import — grisé à 0 AVEC le solde, jamais masqué).
const meState = vi.hoisted(() => ({ club: undefined as Record<string, unknown> | undefined }));
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: { club: meState.club } }) }));

const teams: Team[] = [
  { id: "team-1", name: "SM1", sportCategoryId: "cat", level: null, gender: null, priorityTierId: 1, tierOrder: 0 },
  { id: "team-2", name: "SF3", sportCategoryId: "cat2", level: null, gender: null, priorityTierId: 3, tierOrder: 0 },
];
const tiers: PriorityTier[] = [
  { id: 1, label: "S", name: "Fanion", color: null },
  { id: 3, label: "B", name: "Moyenne", color: null },
];

const unmatchedDiv = (name: string, fbiTeamLabel: string | null = null): ImportAnalysisDivision => ({
  name,
  fbiTeamLabel,
  rowCount: 3,
  teamId: null,
  competitionId: null,
  suggestedTeamId: null,
  suggestedCompetitionId: null,
  pouleError: null,
  pouleUnknownOpponents: [],
});
const matchedDiv = (name: string, teamId: string): ImportAnalysisDivision => ({ ...unmatchedDiv(name), teamId, competitionId: "comp-x" });
const analysisOf = (divisions: ImportAnalysisDivision[]): ImportFbiAnalysis => ({
  divisions,
  totalRows: divisions.reduce((n, d) => n + d.rowCount, 0),
  exempted: 0,
  errors: [],
  deviations: [],
});

const pickFile = async (user: ReturnType<typeof userEvent.setup>) => {
  const file = new File(["xlsx"], "fbi.xlsx", { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
  await user.upload(screen.getByLabelText("Fichier FBI"), file);
};

beforeEach(() => {
  analyzeFbiFixtures.mockClear();
  importFbiFixtures.mockClear();
  placeMatches.mockClear();
  meState.club = undefined;
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

    // Le fichier est lu en mémoire (async) avant l'analyse → attendre l'appel.
    await waitFor(() => expect(analyzeFbiFixtures).toHaveBeenCalledOnce());
    // The persisted PNM mapping is pre-filled (text, no select)…
    await waitFor(() => expect(screen.getByText("→ SM1")).toBeInTheDocument());
    // …and the unknown DF2 division offers the team picker.
    expect(listboxTrigger(/Équipe pour DF2/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Équipe pour PNM/ })).not.toBeInTheDocument();
  });

  it("imports in ONE pass: file + the new mappings only, then shows the report", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(listboxTrigger(/Équipe pour DF2/)).toBeInTheDocument());
    await pickListboxOption(user, "Équipe pour DF2", "SF3"); // team-2
    await user.click(screen.getByRole("button", { name: "Importer" }));

    expect(importFbiFixtures).toHaveBeenCalledOnce();
    // Only DF2 rides along — PNM is already persisted server-side. No deviation →
    // the import carries an EMPTY decisions list (RMM-4, nothing to reconcile).
    expect(importFbiFixtures).toHaveBeenCalledWith(expect.any(File), [{ division: "DF2", fbiTeamLabel: null, teamId: "team-2", competitionId: null }], []);

    // The dialog stays open and surfaces the diff report + warnings + errors.
    await waitFor(() => expect(screen.getByText(/22 créés · 1 mis à jour · 9 inchangés/)).toBeInTheDocument());
    const warning = screen.getByText(/PNM n°101137 : re-programmé/);
    expect(warning).toBeInTheDocument();
    // P4-130 — le texte d'avertissement porte le jeton RÉEL `text-warning` (amber-ish, gardé
    // AA), plus le no-op `text-warning-foreground` (aucun `--color-warning-foreground` déclaré
    // → héritait de la couleur parente au lieu du ton warning voulu).
    expect(warning.closest("ul")).toHaveClass("text-warning");
    expect(warning.closest("ul")).not.toHaveClass("text-warning-foreground");
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
      deviations: [],
    });
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(listboxTrigger(/Équipe pour DF2/)).toHaveAccessibleName(/SF3/)); // team-2
    expect(screen.getByText("proposé par la FFBB")).toBeInTheDocument();

    // What the select DISPLAYS is what gets imported — untouched suggestion
    // included, and its competitionId rides along (pairing reused server-side).
    await user.click(screen.getByRole("button", { name: "Importer" }));
    expect(importFbiFixtures).toHaveBeenCalledWith(expect.any(File), [{ division: "DF2", fbiTeamLabel: null, teamId: "team-2", competitionId: "comp-9" }], []);
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
      deviations: [],
    });
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(listboxTrigger(/Équipe pour DF2/)).toBeInTheDocument());
    expect(listboxTrigger(/Équipe pour DF2/)).toHaveAccessibleName(/Associer à/); // valeur vide → placeholder
    expect(screen.queryByText("proposé par la FFBB")).not.toBeInTheDocument();

    // DF2 sans équipe → confirmation ; même en confirmant, rien d'invisible n'est envoyé.
    await user.click(screen.getByRole("button", { name: "Importer" }));
    await user.click(await screen.findByRole("button", { name: "Importer quand même" }));
    expect(importFbiFixtures).toHaveBeenCalledWith(expect.any(File), [], []);
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
      deviations: [],
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
      deviations: [],
    });
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);

    // B3 — le nom de division + son fbiTeamLabel (qui n'existe QUE quand il est indispensable).
    // `selector` : écarte le libellé sr-only de la listbox (aria-label repris en texte a11y).
    const label = await screen.findByText(/Division 2 Masculine Séniors/, { selector: "span[title]" });
    expect(label).not.toHaveClass("truncate");
    expect(label.getAttribute("title")).toContain("Équipe 2");

    // B4 — la valeur pré-remplie du select se lit sans l'ouvrir (élargie + title de secours).
    const select = screen.getByRole("button", { name: /Équipe pour Division 2 Masculine Séniors/ });
    expect(select).toHaveClass("w-52");
    expect(select).toHaveAccessibleName(/SF3/); // la valeur pré-remplie se lit sans ouvrir la liste
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
      unresolvedDeviations: [],
      depositedAt: "2026-08-24T10:00:00+00:00",
    });
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(listboxTrigger(/Équipe pour DF2/)).toBeInTheDocument());
    await user.click(screen.getByRole("button", { name: "Importer" }));
    // DF2 reste sans équipe → confirmation avant l'import.
    await user.click(await screen.findByRole("button", { name: "Importer quand même" }));

    await waitFor(() => expect(screen.getByText(/PNM : 9\/22 journées — fichier partiel ou phase pas encore sortie/)).toBeInTheDocument());
  });

  // ── RMM-1 PR2 — le placement proposé en fin d'import (décision fondateur) ─────────
  it("propose de placer les matchs importés AU rapport réussi — jamais avant, jamais sans clic", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(listboxTrigger(/Équipe pour DF2/)).toBeInTheDocument());
    await pickListboxOption(user, "Équipe pour DF2", "SF3"); // team-2

    // Avant le rapport : aucun bouton de placement (l'offre naît du rapport réussi).
    expect(screen.queryByRole("button", { name: /Placer les matchs importés/ })).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Importer" }));
    await waitFor(() => expect(screen.getByText(/22 créés · 1 mis à jour · 9 inchangés/)).toBeInTheDocument());

    const place = screen.getByRole("button", { name: /Placer les matchs importés/ });
    // FALSIFICATION — le rail n'est JAMAIS lancé tant que le bouton n'est pas cliqué.
    expect(placeMatches).not.toHaveBeenCalled();

    await user.click(place);
    expect(placeMatches).toHaveBeenCalledOnce();
  });

  it("à 0 crédit, le bouton de placement est GRISÉ avec le solde visible (jamais masqué)", async () => {
    meState.club = { entitlements: { planCode: "decouverte", planName: "Découverte", maxTeams: null, teamsUsed: 4, creditsMax: 10, creditsUsed: 10, canGenerate: false, canPlaceMatches: false, canExportPdf: false, seasonTransition: false } };
    const user = userEvent.setup();
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(listboxTrigger(/Équipe pour DF2/)).toBeInTheDocument());
    await pickListboxOption(user, "Équipe pour DF2", "SF3"); // team-2
    await user.click(screen.getByRole("button", { name: "Importer" }));
    await waitFor(() => expect(screen.getByText(/22 créés/)).toBeInTheDocument());

    const place = await screen.findByRole("button", { name: /Placer les matchs importés \(0 crédit\)/ });
    expect(place).toBeDisabled();
    await user.click(place);
    expect(placeMatches).not.toHaveBeenCalled();
  });

  // ── PR-3b (D2) — l'import CONSIGNE les écarts, plus de détour « Examiner » ────────
  it("écarts détectés → import direct (jamais « Examiner »), le rapport les compte et ouvre la file", async () => {
    const user = userEvent.setup();
    importFbiFixtures.mockResolvedValueOnce({
      message: "Import terminé.",
      created: 0,
      updated: 1,
      unchanged: 9,
      exempted: 0,
      errors: [],
      warnings: [],
      unmappedDivisions: [],
      completeness: [],
      unresolvedDeviations: [
        { fixtureId: "fx-1", externalRef: "101137", division: "PNM", teamId: "team-1", status: "PLACED", persisting: false, fields: { date: { app: "2026-11-28", file: "2026-12-05" } } },
      ],
      depositedAt: "2026-08-24T10:00:00+00:00",
    });
    const onClose = vi.fn();
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={onClose} />);

    await pickFile(user);
    await waitFor(() => expect(listboxTrigger(/Équipe pour DF2/)).toBeInTheDocument());
    // Plus jamais de bouton « Examiner » : l'action primaire reste « Importer ».
    expect(screen.queryByRole("button", { name: /Examiner/i })).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Importer" }));
    await user.click(await screen.findByRole("button", { name: "Importer quand même" }));

    // Le rapport COMPTE les écarts consignés dans la file et offre de l'ouvrir.
    await waitFor(() => expect(screen.getByText(/1 écart consigné dans Importer/i)).toBeInTheDocument());
    await user.click(screen.getByRole("button", { name: "Ouvrir la file" }));
    expect(onClose).toHaveBeenCalled();
  });

  it("aucun écart → rapport sans « Ouvrir la file »", async () => {
    const user = userEvent.setup();
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(listboxTrigger(/Équipe pour DF2/)).toBeInTheDocument());
    expect(screen.queryByRole("button", { name: /Examiner/i })).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Importer" }));
    await user.click(await screen.findByRole("button", { name: "Importer quand même" }));
    await waitFor(() => expect(screen.getByText(/22 créés/)).toBeInTheDocument());
    // unresolvedDeviations vide (mock par défaut) → aucune file à ouvrir.
    expect(screen.queryByRole("button", { name: "Ouvrir la file" })).not.toBeInTheDocument();
  });

  // ── Onglets par famille (mesure terrain : 50 divisions, écran illisible) ─────────

  it("range les divisions en onglets par famille, avec un compteur (appariées/total)", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures.mockResolvedValueOnce(
      analysisOf([
        matchedDiv("DF2", "team-1"), // départemental, apparié (persisté)
        unmatchedDiv("DMU13"), // départemental, non apparié
        unmatchedDiv("PNM"), // régional, non apparié
      ]),
    );
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    expect(await screen.findByRole("tab", { name: "Départemental (1/2)" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Régional (0/1)" })).toBeInTheDocument();
  });

  it("une famille sans division est absente du tablist", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures.mockResolvedValueOnce(analysisOf([unmatchedDiv("DF2")]));
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    expect(await screen.findByRole("tab", { name: /Départemental/ })).toBeInTheDocument();
    expect(screen.queryByRole("tab", { name: /Régional/ })).not.toBeInTheDocument();
    expect(screen.queryByRole("tab", { name: /Amicaux/ })).not.toBeInTheDocument();
  });

  it("bascule d'onglet : seules les divisions de la famille active sont accessibles", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures.mockResolvedValueOnce(analysisOf([unmatchedDiv("DF2"), unmatchedDiv("RM2")]));
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    // Départemental actif par défaut (1re famille présente dans FAMILY_ORDER).
    await waitFor(() => expect(listboxTrigger(/Équipe pour DF2/)).toBeInTheDocument());
    expect(screen.queryByRole("button", { name: /Équipe pour RM2/ })).not.toBeInTheDocument(); // panneau régional caché

    await user.click(screen.getByRole("tab", { name: /Régional/ }));
    expect(listboxTrigger(/Équipe pour RM2/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Équipe pour DF2/ })).not.toBeInTheDocument();
  });

  it("associer une équipe fait monter le compteur de l'onglet (0/1 → 1/1)", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures.mockResolvedValueOnce(analysisOf([unmatchedDiv("DF2")]));
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    expect(await screen.findByRole("tab", { name: "Départemental (0/1)" })).toBeInTheDocument();
    await pickListboxOption(user, "Équipe pour DF2", "SF3");
    expect(screen.getByRole("tab", { name: "Départemental (1/1)" })).toBeInTheDocument();
  });

  // ── Bouton Importer actif + confirmation des divisions sans équipe ───────────────

  it("import avec divisions non appariées → confirmation qui les NOMME (texte exact)", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures.mockResolvedValueOnce(analysisOf([unmatchedDiv("RMU13 Brassage"), unmatchedDiv("CMRLU13M"), unmatchedDiv("AMICAL SF")]));
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(listboxTrigger(/Équipe pour RMU13 Brassage/)).toBeInTheDocument());
    await user.click(screen.getByRole("button", { name: "Importer" }));

    expect(await screen.findByText("Des divisions restent sans équipe")).toBeInTheDocument();
    expect(
      screen.getByText("Les rencontres de RMU13 Brassage, CMRLU13M et AMICAL SF ne seront pas importées — elles resteront à associer au prochain dépôt. Importer quand même ?"),
    ).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Importer quand même" }));
    // Rien d'apparié → aucune mapping n'accompagne l'import.
    expect(importFbiFixtures).toHaveBeenCalledWith(expect.any(File), [], []);
  });

  it("la confirmation nomme les divisions au format « name (fbiTeamLabel) »", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures.mockResolvedValueOnce(analysisOf([unmatchedDiv("DF2", "Équipe 2")]));
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(listboxTrigger(/Équipe pour DF2/)).toBeInTheDocument());
    await user.click(screen.getByRole("button", { name: "Importer" }));
    expect(await screen.findByText(/Les rencontres de DF2 \(Équipe 2\) ne seront pas importées/)).toBeInTheDocument();
  });

  it("« Annuler » sur la confirmation : aucun import, la modale hôte reste ouverte", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures.mockResolvedValueOnce(analysisOf([unmatchedDiv("DF2")]));
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(listboxTrigger(/Équipe pour DF2/)).toBeInTheDocument());
    await user.click(screen.getByRole("button", { name: "Importer" }));
    await user.click(await screen.findByRole("button", { name: "Annuler" }));

    expect(importFbiFixtures).not.toHaveBeenCalled();
    expect(screen.queryByText("Des divisions restent sans équipe")).not.toBeInTheDocument();
    // La modale hôte (bouton Importer) est toujours là.
    expect(screen.getByRole("button", { name: "Importer" })).toBeInTheDocument();
  });

  it("tout apparié (persisté + suggestion affichée) → import direct, sans confirmation", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures.mockResolvedValueOnce(
      analysisOf([matchedDiv("PNM", "team-1"), { ...unmatchedDiv("DF2"), suggestedTeamId: "team-2", suggestedCompetitionId: "comp-9" }]),
    );
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(listboxTrigger(/Équipe pour DF2/)).toHaveAccessibleName(/SF3/));
    await user.click(screen.getByRole("button", { name: "Importer" }));

    expect(screen.queryByText("Des divisions restent sans équipe")).not.toBeInTheDocument();
    expect(importFbiFixtures).toHaveBeenCalledWith(expect.any(File), [{ division: "DF2", fbiTeamLabel: null, teamId: "team-2", competitionId: "comp-9" }], []);
  });

  // ── Re-dépôt : l'onglet actif est un state séparé, non réinitialisé ──────────────

  it("re-dépôt : la famille active est conservée si elle existe encore", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures
      .mockResolvedValueOnce(analysisOf([unmatchedDiv("DF2"), unmatchedDiv("CMRLU13M")])) // départemental + coupes CRM
      .mockResolvedValueOnce(analysisOf([unmatchedDiv("DFU9"), unmatchedDiv("CRMLU13M")])); // coquille corrigée, même famille
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await user.click(await screen.findByRole("tab", { name: /Coupes CRM/ }));
    expect(listboxTrigger(/Équipe pour CMRLU13M/)).toBeInTheDocument();

    await pickFile(user);
    // Coupes CRM toujours présente → on y reste ; le départemental (DFU9) est caché.
    await waitFor(() => expect(listboxTrigger(/Équipe pour CRMLU13M/)).toBeInTheDocument());
    expect(screen.queryByRole("button", { name: /Équipe pour DFU9/ })).not.toBeInTheDocument();
  });

  it("re-dépôt : repli sur la première famille non vide si l'active a disparu", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures
      .mockResolvedValueOnce(analysisOf([unmatchedDiv("DF2"), unmatchedDiv("CMRLU13M")]))
      .mockResolvedValueOnce(analysisOf([unmatchedDiv("DF2")])); // championnat pur, plus de coupes CRM
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await user.click(await screen.findByRole("tab", { name: /Coupes CRM/ }));
    expect(listboxTrigger(/Équipe pour CMRLU13M/)).toBeInTheDocument();

    await pickFile(user);
    // Coupes CRM a disparu → repli sur Départemental (1re non vide de FAMILY_ORDER).
    await waitFor(() => expect(listboxTrigger(/Équipe pour DF2/)).toBeInTheDocument());
    expect(screen.queryByRole("tab", { name: /Coupes CRM/ })).not.toBeInTheDocument();
  });

  // ── Défilement : la Modale (xl) est l'unique zone défilante ────────────────────

  it("la liste des divisions ne défile plus (Modal seule) ; les diagnostics gardent leurs bornes", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures.mockResolvedValueOnce({
      divisions: [{ ...matchedDiv("DF2", "team-1"), pouleUnknownOpponents: ["US INTRUS"] }],
      totalRows: 3,
      exempted: 0,
      errors: ["Ligne 4 : format inattendu."],
      deviations: [],
    });
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    const divisionRow = await screen.findByText(/DF2/, { selector: "span[title]" });
    const list = divisionRow.closest("ul");
    expect(list).not.toHaveClass("max-h-64");
    expect(list).not.toHaveClass("overflow-y-auto");
    // Les listes de diagnostics gardent leur hauteur bornée + défilement propre.
    expect(screen.getByText(/hors poule — US INTRUS/).closest("ul")).toHaveClass("overflow-y-auto");
    expect(screen.getByText(/Ligne 4 : format inattendu/).closest("ul")).toHaveClass("overflow-y-auto");
  });

  // ── Fix « Problème de connexion » : snapshot mémoire lu une fois à la sélection ──

  it("lit le fichier en mémoire UNE fois : le même snapshot part à l'analyse ET à l'import", async () => {
    const user = userEvent.setup();
    analyzeFbiFixtures.mockResolvedValueOnce(analysisOf([matchedDiv("PNM", "team-1")])); // tout apparié → import direct
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await waitFor(() => expect(analyzeFbiFixtures).toHaveBeenCalledOnce());
    await user.click(await screen.findByRole("button", { name: "Importer" }));
    await waitFor(() => expect(importFbiFixtures).toHaveBeenCalledOnce());

    const analyzed = (analyzeFbiFixtures.mock.calls as unknown[][])[0]?.[0];
    const imported = (importFbiFixtures.mock.calls as unknown[][])[0]?.[0];
    expect(analyzed).toBeInstanceOf(File);
    expect(imported).toBe(analyzed); // MÊME objet mémoire, pas le File de l'<input> relu sur disque
  });

  it("échec de lecture du fichier → toast nommé, aucune requête envoyée", async () => {
    const user = userEvent.setup();
    const errorSpy = vi.spyOn(toast, "error");
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    // Fichier verrouillé/modifié entre-temps : la lecture mémoire échoue.
    const locked = new File(["xlsx"], "fbi.xlsx", { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
    vi.spyOn(locked, "arrayBuffer").mockRejectedValueOnce(new Error("locked"));
    await user.upload(screen.getByLabelText("Fichier FBI"), locked);

    await waitFor(() => expect(errorSpy).toHaveBeenCalledWith("Le fichier n'a pas pu être lu — est-il ouvert dans un autre logiciel ?"));
    expect(analyzeFbiFixtures).not.toHaveBeenCalled();

    errorSpy.mockRestore();
  });

  it("range les divisions d'un onglet par ordre alphabétique naturel (DFU9 avant DFU11), pas dans l'ordre du fichier", async () => {
    const user = userEvent.setup();
    const division = (name: string) => ({ name, fbiTeamLabel: null, rowCount: 3, teamId: null, competitionId: null, suggestedTeamId: null, suggestedCompetitionId: null, pouleError: null, pouleUnknownOpponents: [] });
    analyzeFbiFixtures.mockResolvedValueOnce({
      divisions: [division("PRM"), division("DFU11"), division("DM2"), division("DFU9"), division("DF2")],
      totalRows: 15,
      exempted: 0,
      errors: [],
      deviations: [],
    });
    renderWithProviders(<ImportFbiDialog teams={teams} tiers={tiers} onClose={vi.fn()} />);

    await pickFile(user);
    await screen.findByText("PRM");
    const names = ["PRM", "DFU11", "DM2", "DFU9", "DF2"];
    const rendered = screen
      .getAllByRole("listitem")
      .map((li) => names.find((n) => li.textContent?.startsWith(n) ?? false))
      .filter((n): n is string => undefined !== n);
    expect(rendered).toEqual(["DF2", "DFU9", "DFU11", "DM2", "PRM"]);
  });
});
