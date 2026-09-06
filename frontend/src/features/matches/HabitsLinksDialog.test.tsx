import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { pickListboxOption } from "@/test/pickListboxOption";
import { renderWithProviders } from "@/test/utils";

import type { PriorityTier, Team, TeamLink, Venue } from "./api";
import { HabitsLinksDialog } from "./HabitsLinksDialog";

const createLink = vi.fn();
const updateLink = vi.fn();
const deleteLink = vi.fn();
const linksState: { data: TeamLink[] } = { data: [] };

// Le dialog affiche ce que le backend a stocké et rejoue ses mutations : on pilote les hooks,
// jamais le réseau. Habitudes vides (inferHabits est pur, [] → []).
vi.mock("./queries", () => ({
  useTeamMatchHabits: () => ({ data: [], isError: false }),
  useTeamLinks: () => ({ data: linksState.data, isError: false }),
  useCreateTeamMatchHabit: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteTeamMatchHabit: () => ({ mutate: vi.fn(), isPending: false }),
  useCreateTeamLink: () => ({ mutate: createLink, isPending: false }),
  useUpdateTeamLink: () => ({ mutate: updateLink, isPending: false }),
  useDeleteTeamLink: () => ({ mutate: deleteLink, isPending: false }),
}));

const team = (id: string, name: string): Team => ({ id, name, sportCategoryId: "cat", level: null, gender: null, priorityTierId: 1, tierOrder: 0 });
const TEAMS: Team[] = [team("t1", "SM1"), team("t2", "SM2")];
const TIERS: PriorityTier[] = [{ id: 1, label: "S", name: "Fanion", color: null }];
const VENUES: Venue[] = [];

const teamLink = (over: Partial<TeamLink> = {}): TeamLink => ({ id: "l1", teamAId: "t1", teamBId: "t2", linkType: "NOT_SIMULTANEOUS", trainingIntensity: "PREFERRED", ...over });

beforeEach(() => {
  createLink.mockClear();
  updateLink.mockClear();
  deleteLink.mockClear();
  linksState.data = [];
});

describe("HabitsLinksDialog — l'intensité d'entraînement d'une passerelle (lot PASSERELLES PR-3)", () => {
  it("sépare explicitement le réglage MATCHS du réglage ENTRAÎNEMENT (jamais un mensonge d'écran)", () => {
    renderWithProviders(<HabitsLinksDialog teams={TEAMS} tiers={TIERS} venues={VENUES} fixtures={[]} onClose={vi.fn()} />);
    expect(screen.getByText(/Côté matchs/)).toBeInTheDocument();
    expect(screen.getByText(/Côté entraînement/)).toBeInTheDocument();
    // La copie nomme le risque de l'obligatoire (peut rendre infaisable).
    expect(screen.getByText(/infaisable/)).toBeInTheDocument();
  });

  it("crée une passerelle avec l'intensité d'entraînement choisie (défaut PREFERRED, ici MANDATORY)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<HabitsLinksDialog teams={TEAMS} tiers={TIERS} venues={VENUES} fixtures={[]} onClose={vi.fn()} />);

    await pickListboxOption(user, "Première équipe du lien", "SM1"); // t1
    await pickListboxOption(user, "Seconde équipe du lien", "SM2"); // t2
    await user.selectOptions(screen.getByLabelText("Intensité d'entraînement du lien"), "MANDATORY");
    await user.click(screen.getByRole("button", { name: "Ajouter la passerelle" }));

    expect(createLink).toHaveBeenCalledWith({ teamAId: "t1", teamBId: "t2", linkType: "NOT_SIMULTANEOUS", trainingIntensity: "MANDATORY" });
  });

  it("change l'intensité d'une passerelle existante via un vrai contrôle labellisé (pas une icône)", async () => {
    const user = userEvent.setup();
    linksState.data = [teamLink({ trainingIntensity: "PREFERRED" })];
    renderWithProviders(<HabitsLinksDialog teams={TEAMS} tiers={TIERS} venues={VENUES} fixtures={[]} onClose={vi.fn()} />);

    const control = screen.getByLabelText("Intensité d'entraînement, passerelle SM1 – SM2");
    expect(control).toHaveValue("PREFERRED");
    await user.selectOptions(control, "MANDATORY");

    expect(updateLink).toHaveBeenCalledWith({ link: linksState.data[0], input: { trainingIntensity: "MANDATORY" } });
  });
});
