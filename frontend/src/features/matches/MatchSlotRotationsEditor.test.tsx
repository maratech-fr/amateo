import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { MatchSlotRotation, PriorityTier, Team, Venue } from "./api";
import { MatchSlotRotationsEditor } from "./MatchSlotRotationsEditor";

const createMutate = vi.fn();
const updateMutate = vi.fn();
const deleteMutate = vi.fn();
const rotationsState: { data: MatchSlotRotation[]; isError: boolean; isLoading: boolean } = { data: [], isError: false, isLoading: false };

// On pilote les hooks, jamais le réseau (patron TeamLinksSection.test).
vi.mock("./queries", () => ({
  useMatchSlotRotations: () => ({ data: rotationsState.data, isError: rotationsState.isError, isLoading: rotationsState.isLoading }),
  useCreateMatchSlotRotation: () => ({ mutate: createMutate, isPending: false }),
  useUpdateMatchSlotRotation: () => ({ mutate: updateMutate, isPending: false }),
  useDeleteMatchSlotRotation: () => ({ mutate: deleteMutate, isPending: false }),
}));

const team = (id: string, name: string): Team => ({ id, name, sportCategoryId: "cat", level: null, gender: null, priorityTierId: 1, tierOrder: 0 });
const TEAMS: Team[] = [team("t1", "SM1"), team("t2", "SM2"), team("t3", "SM3")];
const TIERS: PriorityTier[] = [{ id: 1, label: "S", name: "Fanion", color: null }];
const VENUES: Venue[] = [{ id: "v1", name: "Coubertin", color: null }];

const rotation = (over: Partial<MatchSlotRotation> = {}): MatchSlotRotation => ({ id: "rot-1", venueId: "v1", dayOfWeek: 6, kickoffTime: "20:30", teamIds: ["t1", "t2"], ...over });

beforeEach(() => {
  createMutate.mockClear();
  updateMutate.mockClear();
  deleteMutate.mockClear();
  rotationsState.data = [];
  rotationsState.isError = false;
  rotationsState.isLoading = false;
});

async function addTeamToDraft(user: ReturnType<typeof userEvent.setup>, value: string): Promise<void> {
  await user.selectOptions(screen.getByLabelText("Ajouter une équipe au nouveau créneau"), value);
  await user.click(screen.getByRole("button", { name: "Ajouter l'équipe au nouveau créneau" }));
}

describe("MatchSlotRotationsEditor — création d'un créneau partagé", () => {
  it("état vide quand aucune rotation", () => {
    renderWithProviders(<MatchSlotRotationsEditor teams={TEAMS} tiers={TIERS} venues={VENUES} />);
    expect(screen.getByText(/Aucun créneau partagé déclaré/)).toBeInTheDocument();
  });

  it("porte la phrase « l'ordre ne pilote aucun calendrier »", () => {
    renderWithProviders(<MatchSlotRotationsEditor teams={TEAMS} tiers={TIERS} venues={VENUES} />);
    expect(screen.getByText(/ne commande aucun calendrier/)).toBeInTheDocument();
  });

  it("« Créer » reste inerte tant qu'il n'y a pas DEUX équipes", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchSlotRotationsEditor teams={TEAMS} tiers={TIERS} venues={VENUES} />);
    await user.selectOptions(screen.getByLabelText("Gymnase du créneau partagé"), "v1");
    expect(screen.getByRole("button", { name: "Créer le créneau" })).toBeDisabled();
    await addTeamToDraft(user, "t1");
    expect(screen.getByRole("button", { name: "Créer le créneau" })).toBeDisabled(); // une seule équipe
    await addTeamToDraft(user, "t2");
    expect(screen.getByRole("button", { name: "Créer le créneau" })).toBeEnabled();
  });

  it("crée avec DEUX équipes, l'ordre saisi = l'ordre envoyé", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchSlotRotationsEditor teams={TEAMS} tiers={TIERS} venues={VENUES} />);
    await user.selectOptions(screen.getByLabelText("Gymnase du créneau partagé"), "v1");
    await addTeamToDraft(user, "t1");
    await addTeamToDraft(user, "t2");
    await user.click(screen.getByRole("button", { name: "Créer le créneau" }));

    expect(createMutate).toHaveBeenCalledTimes(1);
    expect(createMutate.mock.calls[0][0]).toEqual({ venueId: "v1", dayOfWeek: 6, kickoffTime: "20:30", teamIds: ["t1", "t2"] });
  });

  it("crée avec TROIS équipes (le N-aire marche au-delà de 2)", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchSlotRotationsEditor teams={TEAMS} tiers={TIERS} venues={VENUES} />);
    await user.selectOptions(screen.getByLabelText("Gymnase du créneau partagé"), "v1");
    await addTeamToDraft(user, "t1");
    await addTeamToDraft(user, "t2");
    await addTeamToDraft(user, "t3");
    await user.click(screen.getByRole("button", { name: "Créer le créneau" }));
    expect(createMutate.mock.calls[0][0].teamIds).toEqual(["t1", "t2", "t3"]);
  });

  it("réordonne le brouillon (monter) : l'ordre envoyé suit les flèches", async () => {
    const user = userEvent.setup();
    renderWithProviders(<MatchSlotRotationsEditor teams={TEAMS} tiers={TIERS} venues={VENUES} />);
    await user.selectOptions(screen.getByLabelText("Gymnase du créneau partagé"), "v1");
    await addTeamToDraft(user, "t1");
    await addTeamToDraft(user, "t2");
    await addTeamToDraft(user, "t3");
    // Monter SM3 (position C) d'un cran → SM1, SM3, SM2.
    await user.click(screen.getByRole("button", { name: "Monter SM3" }));
    await user.click(screen.getByRole("button", { name: "Créer le créneau" }));
    expect(createMutate.mock.calls[0][0].teamIds).toEqual(["t1", "t3", "t2"]);
  });

  it("une erreur de création s'affiche LISIBLEMENT sous le formulaire (role=alert)", async () => {
    createMutate.mockImplementation((_input, opts?: { onError?: (e: unknown) => void }) => {
      opts?.onError?.(new Error("boom"));
    });
    const user = userEvent.setup();
    renderWithProviders(<MatchSlotRotationsEditor teams={TEAMS} tiers={TIERS} venues={VENUES} />);
    await user.selectOptions(screen.getByLabelText("Gymnase du créneau partagé"), "v1");
    await addTeamToDraft(user, "t1");
    await addTeamToDraft(user, "t2");
    await user.click(screen.getByRole("button", { name: "Créer le créneau" }));

    expect(await screen.findByRole("alert")).toBeInTheDocument();
  });
});

describe("MatchSlotRotationsEditor — édition d'un créneau existant", () => {
  it("liste le créneau lisible et ses membres ordonnés A/B/C", () => {
    rotationsState.data = [rotation({ teamIds: ["t1", "t2", "t3"] })];
    renderWithProviders(<MatchSlotRotationsEditor teams={TEAMS} tiers={TIERS} venues={VENUES} />);
    // Scopé à la RANGÉE du créneau (le nom d'équipe reparaît dans le sélecteur du formulaire du bas).
    const row = screen.getByText("Samedi 20:30 · Coubertin").closest("li") as HTMLElement;
    expect(row).not.toBeNull();
    expect(within(row).getByText("SM1")).toBeInTheDocument();
    expect(within(row).getByText("SM2")).toBeInTheDocument();
    expect(within(row).getByText("SM3")).toBeInTheDocument();
    // Badges de position A/B/C (aria-hidden, lus au texte).
    expect(within(row).getByText("A")).toBeInTheDocument();
    expect(within(row).getByText("C")).toBeInTheDocument();
  });

  it("réordonne un membre existant (descendre) → PUT avec le nouvel ordre", async () => {
    rotationsState.data = [rotation({ teamIds: ["t1", "t2", "t3"] })];
    const user = userEvent.setup();
    renderWithProviders(<MatchSlotRotationsEditor teams={TEAMS} tiers={TIERS} venues={VENUES} />);
    await user.click(screen.getByRole("button", { name: "Descendre SM1" }));
    expect(updateMutate).toHaveBeenCalledWith({ id: "rot-1", input: { venueId: "v1", dayOfWeek: 6, kickoffTime: "20:30", teamIds: ["t2", "t1", "t3"] } });
  });

  it("retire un membre → PUT sans lui", async () => {
    rotationsState.data = [rotation({ teamIds: ["t1", "t2", "t3"] })];
    const user = userEvent.setup();
    renderWithProviders(<MatchSlotRotationsEditor teams={TEAMS} tiers={TIERS} venues={VENUES} />);
    await user.click(screen.getByRole("button", { name: "Retirer SM3 du créneau" }));
    expect(updateMutate).toHaveBeenCalledWith({ id: "rot-1", input: { venueId: "v1", dayOfWeek: 6, kickoffTime: "20:30", teamIds: ["t1", "t2"] } });
  });

  it("à deux membres, le retrait est inerte (le créneau doit garder ≥ 2 équipes)", () => {
    rotationsState.data = [rotation({ teamIds: ["t1", "t2"] })];
    renderWithProviders(<MatchSlotRotationsEditor teams={TEAMS} tiers={TIERS} venues={VENUES} />);
    expect(screen.getByRole("button", { name: "Retirer SM1 du créneau" })).toBeDisabled();
  });

  it("supprime un créneau → DELETE de son id", async () => {
    rotationsState.data = [rotation()];
    const user = userEvent.setup();
    renderWithProviders(<MatchSlotRotationsEditor teams={TEAMS} tiers={TIERS} venues={VENUES} />);
    await user.click(screen.getByRole("button", { name: "Supprimer le créneau Samedi 20:30 · Coubertin" }));
    expect(deleteMutate).toHaveBeenCalledWith("rot-1");
  });

  it("ajoute une équipe à un créneau existant → PUT avec l'équipe en fin de liste", async () => {
    rotationsState.data = [rotation({ teamIds: ["t1", "t2"] })];
    const user = userEvent.setup();
    renderWithProviders(<MatchSlotRotationsEditor teams={TEAMS} tiers={TIERS} venues={VENUES} />);
    const addSelect = screen.getByLabelText("Ajouter une équipe au créneau Samedi 20:30 · Coubertin");
    // Seule SM3 est proposable (t1/t2 déjà membres).
    expect(within(addSelect).getByRole("option", { name: "SM3" })).toBeInTheDocument();
    await user.selectOptions(addSelect, "t3");
    await user.click(screen.getByRole("button", { name: "Ajouter l'équipe au créneau Samedi 20:30 · Coubertin" }));
    expect(updateMutate).toHaveBeenCalledWith({ id: "rot-1", input: { venueId: "v1", dayOfWeek: 6, kickoffTime: "20:30", teamIds: ["t1", "t2", "t3"] } });
  });
});
