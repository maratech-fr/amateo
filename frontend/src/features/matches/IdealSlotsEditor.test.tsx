import { fireEvent, screen } from "@testing-library/react";
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
  it("liste vide : aucune ligne d'équipe, un message, et le bouton Ajouter", () => {
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} weekendAlternates={true} />);
    expect(screen.getByRole("heading", { name: "Créneaux idéaux", level: 3 })).toBeInTheDocument();
    // Aucune équipe n'a de créneau → aucune ligne SM1/SM2 au repos.
    expect(screen.queryByText("SM1")).toBeNull();
    expect(screen.queryByText("SM2")).toBeNull();
    expect(screen.getByRole("button", { name: "Ajouter un créneau idéal" })).toBeInTheDocument();
  });

  it("ne liste QUE les équipes ayant un créneau (au repos, en résumé)", () => {
    habitsState.data = [habit()]; // h1 → SM1
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} weekendAlternates={true} />);
    expect(screen.getByText("SM1")).toBeInTheDocument();
    expect(screen.queryByText("SM2")).toBeNull(); // SM2 n'a pas de créneau
    // Résumé compact : semaine + jour/heure + gymnase.
    expect(screen.getByText("Semaine A · Samedi 15:30 · Alpha")).toBeInTheDocument();
  });

  it("Ajouter : choisir une équipe SANS créneau, poser l'heure ⇒ POST (semaine A par défaut, gymnase null)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} weekendAlternates={true} />);

    await user.click(screen.getByRole("button", { name: "Ajouter un créneau idéal" }));
    await pickListboxOption(user, "Équipe du nouveau créneau idéal", "SM1");
    fireEvent.change(screen.getByLabelText("Heure du créneau idéal de SM1"), { target: { value: "12:15" } });
    await user.click(screen.getByRole("button", { name: "Enregistrer le nouveau créneau idéal" }));

    expect(createHabit).toHaveBeenCalledWith({ teamId: "t1", dayOfWeek: 6, kickoffTime: "12:15", week: "A", venueId: null }, expect.anything());
  });

  it("modifie un créneau existant : ✎ déplie, changer la semaine ⇒ PUT", async () => {
    const user = userEvent.setup();
    habitsState.data = [habit({ week: "A" })];
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} weekendAlternates={true} />);

    await user.click(screen.getByRole("button", { name: "Modifier le créneau idéal de SM1" }));
    await user.selectOptions(screen.getByLabelText("Semaine du créneau idéal de SM1"), "B");
    await user.click(screen.getByRole("button", { name: "Enregistrer le créneau idéal de SM1" }));

    expect(updateHabit).toHaveBeenCalledWith({ id: "h1", input: { teamId: "t1", dayOfWeek: 6, kickoffTime: "15:30", week: "B", venueId: "v1" } }, expect.anything());
  });

  it("RETIRE le gymnase d'un créneau : ✎ déplie, choisir « — » ⇒ PUT venueId null", async () => {
    const user = userEvent.setup();
    habitsState.data = [habit({ venueId: "v1" })];
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} weekendAlternates={true} />);

    await user.click(screen.getByRole("button", { name: "Modifier le créneau idéal de SM1" }));
    await pickListboxOption(user, "Gymnase du créneau idéal de SM1", "—");
    await user.click(screen.getByRole("button", { name: "Enregistrer le créneau idéal de SM1" }));

    expect(updateHabit).toHaveBeenCalledWith({ id: "h1", input: { teamId: "t1", dayOfWeek: 6, kickoffTime: "15:30", week: "A", venueId: null } }, expect.anything());
  });

  it("supprime un créneau idéal existant (au repos)", async () => {
    const user = userEvent.setup();
    habitsState.data = [habit()];
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} weekendAlternates={true} />);

    await user.click(screen.getByRole("button", { name: "Supprimer le créneau idéal de SM1" }));
    // N2 : la suppression passe désormais par une confirmation (ConfirmDialog).
    expect(deleteHabit).not.toHaveBeenCalled();
    await user.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(deleteHabit).toHaveBeenCalledWith("h1");
  });

  it("club sans alternance : le champ Semaine est masqué (édition) et absent du résumé", async () => {
    const user = userEvent.setup();
    habitsState.data = [habit({ week: "A" })];
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} weekendAlternates={false} />);

    // Résumé sans « Semaine A ».
    expect(screen.getByText("Samedi 15:30 · Alpha")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Modifier le créneau idéal de SM1" }));
    expect(screen.queryByLabelText("Semaine du créneau idéal de SM1")).toBeNull();
  });

  it("club alternant : le champ Semaine est visible à l'ajout", async () => {
    const user = userEvent.setup();
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} weekendAlternates={true} />);
    await user.click(screen.getByRole("button", { name: "Ajouter un créneau idéal" }));
    await pickListboxOption(user, "Équipe du nouveau créneau idéal", "SM1");
    expect(screen.getByLabelText("Semaine du créneau idéal de SM1")).toBeInTheDocument();
  });

  it("affiche l'alerte de cohérence (calculée serveur) sous le créneau qui heurte une règle du club (P4-272 ③)", () => {
    habitsState.data = [habit()]; // h1 → SM1
    coherenceState.data = {
      byRule: [],
      byHabit: [{ habitId: "h1", rules: [{ ruleId: "r1", ruleType: "HARD", daysOfWeek: [6], kickoffMin: null, kickoffMax: "21:00" }] }],
    };
    renderWithProviders(<IdealSlotsEditor teams={TEAMS} venues={VENUES} weekendAlternates={true} />);

    expect(screen.getByText("Heurte la règle du club « pas après 21:00 ».")).toBeInTheDocument();
    expect(screen.getAllByText(/Heurte la règle du club/)).toHaveLength(1);
  });
});
