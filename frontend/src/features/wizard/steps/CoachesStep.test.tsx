import { fireEvent, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Coach } from "../api";
import { useWizardStore } from "../store";

const updateMut = vi.fn();
const createMut = vi.fn();

const coachesState: { data: Coach[] } = { data: [] };

vi.mock("../queries", () => ({
  useWizardCoaches: () => ({ data: coachesState.data }),
  useWizardTeams: () => ({ data: [] }),
  usePriorityTiers: () => ({ data: [] }),
  useWizardTeamCoaches: () => ({ data: [] }),
  useWizardCoachPlayers: () => ({ data: [] }),
  useCreateCoach: () => ({ mutate: createMut, isPending: false }),
  useUpdateCoach: () => ({ mutate: updateMut, isPending: false }),
  useDeleteCoach: () => ({ mutate: vi.fn() }),
  useCreateTeamCoach: () => ({ mutate: vi.fn() }),
  useDeleteTeamCoach: () => ({ mutate: vi.fn() }),
  useCreateCoachPlayer: () => ({ mutate: vi.fn() }),
  useDeleteCoachPlayer: () => ({ mutate: vi.fn() }),
  useDeletionImpact: () => ({ data: null, isPending: false, isError: false }),
}));

import { CoachesStep } from "./CoachesStep";

const coach = (over: Partial<Coach> & Pick<Coach, "id" | "firstName">): Coach => ({
  lastName: "Martin",
  email: null,
  isEmployee: false,
  isActive: true,
  maxDaysOverride: null,
  isVehicled: false,
  ...over,
});

beforeEach(() => {
  updateMut.mockClear();
  createMut.mockClear();
  coachesState.data = [];
  useWizardStore.setState({ mode: "season" });
});

describe("CoachesStep — statut véhiculé", () => {
  it("un coach non véhiculé : la case « Véhiculé » est décochée par défaut", () => {
    coachesState.data = [coach({ id: "c1", firstName: "Léa", isVehicled: false })];
    renderWithProviders(<CoachesStep />);
    fireEvent.click(screen.getByRole("button", { name: "Éditer le coach" }));

    const vehicled = screen.getByRole("checkbox", { name: "Véhiculé" });
    expect(vehicled).not.toBeChecked();
  });

  it("cocher « Véhiculé » PATCHe le coach avec isVehicled=true (mute la prod, pas le mock)", () => {
    coachesState.data = [coach({ id: "c1", firstName: "Léa", isVehicled: false })];
    renderWithProviders(<CoachesStep />);
    fireEvent.click(screen.getByRole("button", { name: "Éditer le coach" }));

    fireEvent.click(screen.getByRole("checkbox", { name: "Véhiculé" }));

    expect(updateMut).toHaveBeenCalledTimes(1);
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "c1", body: expect.objectContaining({ isVehicled: true }) }));
  });

  it("un coach déjà véhiculé : la case est cochée, la décocher envoie isVehicled=false", () => {
    coachesState.data = [coach({ id: "c1", firstName: "Léa", isVehicled: true })];
    renderWithProviders(<CoachesStep />);
    fireEvent.click(screen.getByRole("button", { name: "Éditer le coach" }));

    const vehicled = screen.getByRole("checkbox", { name: "Véhiculé" });
    expect(vehicled).toBeChecked();

    fireEvent.click(vehicled);
    expect(updateMut).toHaveBeenCalledWith(expect.objectContaining({ id: "c1", body: expect.objectContaining({ isVehicled: false }) }));
  });
});
