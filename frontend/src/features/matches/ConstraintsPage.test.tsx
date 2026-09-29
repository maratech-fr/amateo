import { fireEvent, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { ClubLeagueWindow, MatchConstraint, MatchConstraintCoherence } from "./api";
import { ConstraintsPage } from "./ConstraintsPage";

const createWindow = vi.fn();
const updateWindow = vi.fn();
const deleteWindow = vi.fn();
const windowsState: { data: ClubLeagueWindow[] | undefined; isError: boolean } = { data: [], isError: false };
const createRule = vi.fn();
const updateRule = vi.fn();
const deleteRule = vi.fn();
const rulesState: { data: MatchConstraint[] | undefined; isError: boolean } = { data: [], isError: false };
const coherenceState: { data: MatchConstraintCoherence } = { data: { byRule: [], byHabit: [] } };

// On pilote les hooks (miroir de la copie stockée), jamais le réseau. Le badge et
// l'alerte de cohérence viennent du SERVEUR : l'écran les affiche, on ne les recalcule pas ici.
vi.mock("./queries", () => ({
  useClubLeagueWindows: () => ({ ...windowsState, refetch: vi.fn() }),
  useCreateClubLeagueWindow: () => ({ mutate: createWindow, isPending: false }),
  useUpdateClubLeagueWindow: () => ({ mutate: updateWindow, isPending: false }),
  useDeleteClubLeagueWindow: () => ({ mutate: deleteWindow, isPending: false }),
  // Bloc « Plages suggérées » : neutre ici (instance null → rien rendu). Testé
  // séparément dans LeagueSuggestions.test.tsx.
  useLeagueWindowSuggestions: () => ({ data: { instance: null, items: [] } }),
  useApplyLeagueWindowSuggestions: () => ({ mutate: vi.fn(), isPending: false }),
  // Section Club (P4-272 ③).
  useMatchConstraints: () => ({ ...rulesState, refetch: vi.fn() }),
  useMatchConstraintCoherence: () => ({ data: coherenceState.data }),
  useCreateMatchConstraint: () => ({ mutate: createRule, isPending: false }),
  useUpdateMatchConstraint: () => ({ mutate: updateRule, isPending: false }),
  useDeleteMatchConstraint: () => ({ mutate: deleteRule, isPending: false }),
}));

const window = (over: Partial<ClubLeagueWindow> = {}): ClubLeagueWindow => ({
  id: "w1",
  version: 1,
  league: "AURA",
  category: "Seniors",
  level: "REGIONAL",
  gender: null,
  dayOfWeek: 6,
  kickoffMin: "14:00",
  kickoffMax: "16:00",
  badge: null,
  ...over,
});

function openLigue(): void {
  renderWithProviders(<ConstraintsPage />, { route: "/matchs/contraintes?section=ligue" });
}

const rule = (over: Partial<MatchConstraint> = {}): MatchConstraint => ({
  id: "r1",
  version: 1,
  scope: "CLUB",
  scopeTargetId: null,
  ruleType: "HARD",
  daysOfWeek: [6],
  kickoffMin: null,
  kickoffMax: "21:00",
  venueId: null,
  ...over,
});

function openClub(): void {
  renderWithProviders(<ConstraintsPage />, { route: "/matchs/contraintes?section=club" });
}

beforeEach(() => {
  createWindow.mockClear();
  updateWindow.mockClear();
  deleteWindow.mockClear();
  windowsState.data = [];
  windowsState.isError = false;
  createRule.mockClear();
  updateRule.mockClear();
  deleteRule.mockClear();
  rulesState.data = [];
  rulesState.isError = false;
  coherenceState.data = { byRule: [], byHabit: [] };
});

describe("ConstraintsPage — section Ligue (P4-272 ①)", () => {
  it("affiche le bandeau « aucune règle fédérale » quand la copie est vide", () => {
    windowsState.data = [];
    openLigue();
    expect(screen.getByText(/Aucune fenêtre ligue — le placement n'applique plus de règle fédérale\./)).toBeInTheDocument();
  });

  it("affiche le badge « modifié »/« ajouté » calculé par le serveur (jamais redérivé)", () => {
    windowsState.data = [window({ id: "w1", category: "Seniors", badge: "modified" }), window({ id: "w2", category: "Poussins", badge: "added" })];
    openLigue();
    expect(screen.getByText("Modifié")).toBeInTheDocument();
    expect(screen.getByText("Ajouté")).toBeInTheDocument();
  });

  it("ajoute une fenêtre via l'API (genre « Tous » → null)", async () => {
    const user = userEvent.setup();
    windowsState.data = [];
    openLigue();

    // La ligne d'ajout (bordée en pointillés) porte le bouton « Ajouter ».
    const addRow = screen.getByRole("button", { name: "Ajouter" }).closest("div") as HTMLElement;
    fireEvent.change(within(addRow).getByLabelText("Catégorie"), { target: { value: "U13" } });
    await user.selectOptions(within(addRow).getByLabelText("Niveau"), "DEPARTEMENTAL");
    await user.selectOptions(within(addRow).getByLabelText("Jour"), "7");
    fireEvent.change(within(addRow).getByLabelText("De"), { target: { value: "10:00" } });
    fireEvent.change(within(addRow).getByLabelText("À"), { target: { value: "11:30" } });

    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    expect(createWindow).toHaveBeenCalledWith(
      { category: "U13", level: "DEPARTEMENTAL", gender: null, dayOfWeek: 7, kickoffMin: "10:00", kickoffMax: "11:30" },
      expect.anything(),
    );
  });

  it("enregistre une correction de fenêtre via l'API (PUT id + input)", async () => {
    const user = userEvent.setup();
    windowsState.data = [window({ id: "w1", kickoffMax: "16:00" })];
    openLigue();

    // Le bouton reste inerte tant que rien n'a changé.
    const save = screen.getByRole("button", { name: "Enregistrer" });
    expect(save).toBeDisabled();
    // La ligne d'édition rend AVANT la ligne d'ajout → le premier champ « À » est le sien.
    fireEvent.change(screen.getAllByLabelText("À")[0], { target: { value: "17:30" } });
    expect(screen.getByRole("button", { name: "Enregistrer" })).toBeEnabled();
    await user.click(screen.getByRole("button", { name: "Enregistrer" }));

    expect(updateWindow).toHaveBeenCalledWith({
      id: "w1",
      input: { category: "Seniors", level: "REGIONAL", gender: null, dayOfWeek: 6, kickoffMin: "14:00", kickoffMax: "17:30" },
    });
  });
});

describe("ConstraintsPage — section Club (P4-272 ③)", () => {
  it("indique l'absence de règle quand la liste est vide", () => {
    rulesState.data = [];
    openClub();
    expect(screen.getByText(/Aucune règle de club/)).toBeInTheDocument();
  });

  it("ajoute une règle « pas après 21h » le samedi via l'API (bornes nullables)", async () => {
    const user = userEvent.setup();
    rulesState.data = [];
    openClub();

    // La ligne d'ajout par défaut a samedi déjà coché ; on renseigne « Pas après ».
    fireEvent.change(screen.getByLabelText("Pas après (heure de fin)"), { target: { value: "21:00" } });
    await user.click(screen.getByRole("button", { name: "Ajouter" }));

    expect(createRule).toHaveBeenCalledWith(
      { ruleType: "HARD", daysOfWeek: [6], kickoffMin: null, kickoffMax: "21:00" },
      expect.anything(),
    );
  });

  it("affiche l'alerte de cohérence (calculée serveur) sous la règle qui heurte un créneau idéal", () => {
    rulesState.data = [rule({ id: "r1", kickoffMax: "21:00" })];
    coherenceState.data = {
      byRule: [{ ruleId: "r1", habits: [{ teamId: "t1", teamName: "U13M", week: "A", dayOfWeek: 6, kickoff: "21:30" }] }],
      byHabit: [],
    };
    openClub();

    expect(screen.getByText("Cette règle heurte le créneau idéal des U13M (semaine A) : samedi 21h30.")).toBeInTheDocument();
  });

  it("omet « (semaine …) » quand le créneau idéal vaut pour toutes les semaines", () => {
    rulesState.data = [rule({ id: "r1", kickoffMax: "21:00" })];
    coherenceState.data = {
      byRule: [{ ruleId: "r1", habits: [{ teamId: "t2", teamName: "SM1", week: "ALL", dayOfWeek: 7, kickoff: "21:45" }] }],
      byHabit: [],
    };
    openClub();

    expect(screen.getByText("Cette règle heurte le créneau idéal des SM1 : dimanche 21h45.")).toBeInTheDocument();
  });
});
