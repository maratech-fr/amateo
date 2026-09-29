import { fireEvent, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { pickListboxOption } from "@/test/pickListboxOption";
import { renderWithProviders } from "@/test/utils";

import type { MatchConstraintCoherence, Team, TeamMatchHabit, Venue } from "./api";
import { IdealSlotsEditor } from "./IdealSlotsEditor";

const createHabit = vi.fn();
const updateHabit = vi.fn();
const deleteHabit = vi.fn();
const habitsState: { data: TeamMatchHabit[] } = { data: [] };
const coherenceState: { data: MatchConstraintCoherence } = { data: { byRule: [], byHabit: [] } };

// On pilote les hooks (jamais le réseau) : le composant AFFICHE les créneaux stockés et rejoue
// ses mutations. On MUTE la prod, jamais le mock (§ règles frontend).
vi.mock("./queries", () => ({
  useTeamMatchHabits: () => ({ data: habitsState.data, isError: false }),
  useCreateTeamMatchHabit: () => ({ mutate: createHabit, isPending: false }),
  useUpdateTeamMatchHabit: () => ({ mutate: updateHabit, isPending: false }),
  useDeleteTeamMatchHabit: () => ({ mutate: deleteHabit, isPending: false }),
  useMatchConstraintCoherence: () => ({ data: coherenceState.data }),
}));

const team = (id: string, name: string): Team => ({ id, name, sportCategoryId: "cat", level: null, gender: null, priorityTierId: 1, tierOrder: 0 });
const TEAMS: Team[] = [team("t1", "SM1"), team("t2", "SM2")];
const VENUES: Venue[] = [{ id: "v1", name: "Alpha", color: null, externalLabels: [] }];

const habit = (over: Partial<TeamMatchHabit> = {}): TeamMatchHabit => ({ id: "h1", teamId: "t1", dayOfWeek: 6, kickoffTime: "15:30", venueId: "v1", week: "A", ...over });

beforeEach(() => {
  createHabit.mockClear();
  updateHabit.mockClear();
  deleteHabit.mockClear();
  habitsState.data = [];
  coherenceState.data = { byRule: [], byHabit: [] };
});

describe("IdealSlotsEditor (P4-271)", () => {
  it("rend une ligne par équipe", () => {
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} />);
    expect(screen.getByRole("heading", { name: "Créneaux idéaux", level: 3 })).toBeInTheDocument();
    expect(screen.getByText("SM1")).toBeInTheDocument();
    expect(screen.getByText("SM2")).toBeInTheDocument();
  });

  it("crée un créneau idéal : heure posée, gymnase omis ⇒ venueId null explicite", async () => {
    const user = userEvent.setup();
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} />);

    fireEvent.change(screen.getByLabelText("Heure du créneau idéal de SM1"), { target: { value: "12:15" } });
    await user.selectOptions(screen.getByLabelText("Semaine du créneau idéal de SM1"), "A");
    await user.click(screen.getByRole("button", { name: "Enregistrer le créneau idéal de SM1" }));

    expect(createHabit).toHaveBeenCalledWith({ teamId: "t1", dayOfWeek: 6, kickoffTime: "12:15", week: "A", venueId: null });
  });

  it("met à jour un créneau existant (changer la semaine) via PUT", async () => {
    const user = userEvent.setup();
    habitsState.data = [habit({ week: "A" })];
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} />);

    await user.selectOptions(screen.getByLabelText("Semaine du créneau idéal de SM1"), "B");
    await user.click(screen.getByRole("button", { name: "Enregistrer le créneau idéal de SM1" }));

    expect(updateHabit).toHaveBeenCalledWith({ id: "h1", input: { teamId: "t1", dayOfWeek: 6, kickoffTime: "15:30", week: "B", venueId: "v1" } });
  });

  it("RETIRE le gymnase d'un créneau : choisir « — » ⇒ PUT venueId null", async () => {
    const user = userEvent.setup();
    habitsState.data = [habit({ venueId: "v1" })];
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} />);

    await pickListboxOption(user, "Gymnase du créneau idéal de SM1", "—");
    await user.click(screen.getByRole("button", { name: "Enregistrer le créneau idéal de SM1" }));

    expect(updateHabit).toHaveBeenCalledWith({ id: "h1", input: { teamId: "t1", dayOfWeek: 6, kickoffTime: "15:30", week: "A", venueId: null } });
  });

  it("supprime un créneau idéal existant", async () => {
    const user = userEvent.setup();
    habitsState.data = [habit()];
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} />);

    await user.click(screen.getByRole("button", { name: "Supprimer le créneau idéal de SM1" }));
    expect(deleteHabit).toHaveBeenCalledWith("h1");
  });

  it("le bouton Enregistrer reste désactivé tant que rien n'a changé (créneau existant) ou sans heure (création)", () => {
    habitsState.data = [habit()];
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} />);
    // SM1 a un créneau non modifié → Enregistrer désactivé ; SM2 n'a pas d'heure → désactivé aussi.
    expect(screen.getByRole("button", { name: "Enregistrer le créneau idéal de SM1" })).toBeDisabled();
    const sm2Save = screen.getByRole("button", { name: "Enregistrer le créneau idéal de SM2" });
    expect(sm2Save).toBeDisabled();
    // SM2 n'a pas de bouton Supprimer (aucun créneau).
    expect(screen.queryByRole("button", { name: "Supprimer le créneau idéal de SM2" })).toBeNull();
    // garde-fou : within limite l'assertion à une ligne, jamais un match global fortuit.
    expect(within(document.body).getAllByRole("listitem").length).toBeGreaterThanOrEqual(2);
  });

  it("affiche l'alerte de cohérence (calculée serveur) sous le créneau qui heurte une règle du club (P4-272 ③)", () => {
    habitsState.data = [habit()]; // h1 → SM1
    coherenceState.data = {
      byRule: [],
      byHabit: [{ habitId: "h1", rules: [{ ruleId: "r1", ruleType: "HARD", daysOfWeek: [6], kickoffMin: null, kickoffMax: "21:00" }] }],
    };
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} />);

    expect(screen.getByText("Heurte la règle du club « pas après 21h ».")).toBeInTheDocument();
    // Aucune alerte sur SM2 (pas de créneau, pas d'entrée byHabit).
    expect(screen.getAllByText(/Heurte la règle du club/)).toHaveLength(1);
  });
});
