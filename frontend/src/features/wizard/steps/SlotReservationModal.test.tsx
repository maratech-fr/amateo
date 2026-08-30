import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { PriorityTier, Reservation, SharedTrainingGroup, Team, Venue, VenueTrainingSlot } from "../api";

// Les mutations sont mockées : le test porte sur le GESTE (quel rail est appelé, dans quel ordre),
// pas sur react-query. Patron identique à MutualisationPanel.test.
const createMut = vi.fn();
const delMut = vi.fn();
const groupMut = vi.fn();
let callOrder: string[] = [];

vi.mock("../queries", () => ({
  useCreateReservation: () => ({ mutateAsync: createMut, isPending: false }),
  useDeleteReservation: () => ({ mutateAsync: delMut, isPending: false }),
  useCreateGroupReservation: () => ({ mutateAsync: groupMut, isPending: false }),
}));

import { SlotReservationModal } from "./SlotReservationModal";

const team = (id: string, name: string, sessionsPerWeek = 2): Team =>
  ({ id, name, sportCategoryId: "c", priorityTierId: 1, tierOrder: 0, gender: "M", level: null, sessionsPerWeek, isActive: true }) as Team;

const TEAMS: Team[] = [team("a", "SM1"), team("b", "SM2"), team("c", "SM3"), team("d", "SM4")];
const TIERS: PriorityTier[] = [{ id: 1, label: "S", name: "Fanion", color: null }];
const VENUE: Venue = { id: "v1", name: "Gymnase A", color: null, canSplit: true, isActive: true };
const SLOT: VenueTrainingSlot = { id: "slot1", venueId: "v1", dayOfWeek: 1, startTime: "18:00", durationMinutes: 90, capacity: 2 };

const group = (id: string, teamIds: string[], commonSessions = 1): SharedTrainingGroup =>
  ({ id, version: 1, createdAt: "2026-08-23T00:00:00+00:00", updatedAt: "2026-08-23T00:00:00+00:00", schedulePlanId: null, teamIds, commonSessions });

const resa = (teamId: string, venueId: string, dayOfWeek: number, startTime: string): Reservation =>
  ({ id: `${teamId}-${venueId}-${dayOfWeek}-${startTime}`, schedulePlanId: null, teamId, venueId, dayOfWeek, startTime, durationMinutes: 90 });

function renderModal(overrides: Partial<Parameters<typeof SlotReservationModal>[0]> = {}) {
  return renderWithProviders(
    <SlotReservationModal
      slot={SLOT}
      venue={VENUE}
      teams={TEAMS}
      tiers={TIERS}
      reservations={[]}
      teamCoaches={[]}
      coachesPending={false}
      coachesFailed={false}
      onRetryCoaches={vi.fn()}
      venues={[VENUE]}
      venueCanSplit={new Map([["v1", true]])}
      sharedTrainingGroups={[group("g", ["a", "b"])]}
      schedulePlanId={null}
      onClose={vi.fn()}
      {...overrides}
    />,
  );
}

const selector = () => screen.getByRole("combobox", { name: "Ajouter une équipe" });

beforeEach(() => {
  callOrder = [];
  createMut.mockReset().mockImplementation(async () => void callOrder.push("create"));
  delMut.mockReset().mockImplementation(async () => void callOrder.push("del"));
  groupMut.mockReset().mockImplementation(async () => {
    callOrder.push("group");

    return { ids: ["r1", "r2"], count: 2 };
  });
});

describe("SlotReservationModal — mutualisation (P2-46 PR-3)", () => {
  it("offre le groupe sous « Entraînements mutualisés » sur un créneau LIBRE", () => {
    renderModal();
    const option = within(selector()).getByRole("option", { name: "SM1 + SM2 — 1 séance commune" });
    expect(option).toBeInTheDocument();
    expect(option.closest("optgroup")?.label).toBe("Entraînements mutualisés");
  });

  it("n'offre PAS le groupe sur un créneau occupé, et dit POURQUOI (raison visible)", () => {
    renderModal({ reservations: [resa("c", "v1", 1, "18:00")] });
    expect(within(selector()).queryByRole("option", { name: "SM1 + SM2 — 1 séance commune" })).toBeNull();
    expect(screen.getByText(/ne se pose que sur un créneau libre/i)).toBeInTheDocument();
  });

  it("un groupe posé dans le brouillon ferme le sélecteur sur une raison NOMMÉE", async () => {
    renderModal();
    await userEvent.selectOptions(selector(), "group:g");
    expect(screen.getByText(/occupe seul ce créneau/i)).toBeInTheDocument();
    expect(screen.queryByRole("combobox", { name: "Ajouter une équipe" })).toBeNull();
    // Le lot en brouillon est nommé et « à valider ».
    expect(screen.getByText(/SM1 \+ SM2/)).toBeInTheDocument();
    expect(screen.getByText("à valider")).toBeInTheDocument();
  });

  it("« retirer SM4 + poser le groupe » passe en UNE validation, retraits AVANT ajouts, un seul appel au rail groupe", async () => {
    const onClose = vi.fn();
    renderModal({ reservations: [resa("d", "v1", 1, "18:00")], onClose });

    await userEvent.click(screen.getByRole("button", { name: "Retirer SM4" }));
    await userEvent.selectOptions(selector(), "group:g");
    await userEvent.click(screen.getByRole("button", { name: "Valider" }));

    expect(callOrder).toEqual(["del", "group"]); // le retrait libère la case AVANT que le groupe s'y pose
    expect(delMut).toHaveBeenCalledWith("d-v1-1-18:00");
    expect(groupMut).toHaveBeenCalledTimes(1);
    expect(groupMut).toHaveBeenCalledWith({ sharedTrainingGroupId: "g", venueId: "v1", dayOfWeek: 1, startTime: "18:00", durationMinutes: 90, schedulePlanId: null });
    expect(createMut).not.toHaveBeenCalled(); // JAMAIS N POST individuels
    expect(onClose).toHaveBeenCalled();
  });

  it("un lot mutualisé DÉJÀ posé s'affiche en UNE ligne, et son retrait empile N DELETE", async () => {
    renderModal({ reservations: [resa("a", "v1", 1, "18:00"), resa("b", "v1", 1, "18:00")] });

    // Une seule ligne pour le lot (pas deux verrous anonymes).
    await userEvent.click(screen.getByRole("button", { name: "Retirer l'entraînement mutualisé SM1 + SM2" }));
    await userEvent.click(screen.getByRole("button", { name: "Valider" }));

    expect(delMut).toHaveBeenCalledTimes(2);
    expect(delMut).toHaveBeenCalledWith("a-v1-1-18:00");
    expect(delMut).toHaveBeenCalledWith("b-v1-1-18:00");
    expect(groupMut).not.toHaveBeenCalled();
  });

  // P4-150 — sur un créneau libre où aucune équipe ni aucun groupe n'est proposable,
  // la copie d'écran de l'état vide est assertée (elle ne s'affiche QUE dans ce cas).
  it("annonce « Aucune équipe disponible » quand rien n'est proposable sur un créneau libre", () => {
    renderModal({ teams: [], sharedTrainingGroups: [] });
    expect(screen.getByText("Aucune équipe disponible (toutes ont atteint leur nombre de séances ou sont déjà sur ce créneau).")).toBeInTheDocument();
  });

  it("un groupe ayant atteint ses K séances communes n'est PAS offert, avec sa raison", () => {
    // Une case complète {a,b} ailleurs dans la portée → K(1) atteint.
    renderModal({ reservations: [resa("a", "v1", 3, "20:00"), resa("b", "v1", 3, "20:00")] });
    expect(within(selector()).queryByRole("option", { name: "SM1 + SM2 — 1 séance commune" })).toBeNull();
    expect(screen.getByText(/séance commune est déjà posée/i)).toBeInTheDocument();
    expect(screen.getByText(/indisponible/i)).toBeInTheDocument();
  });
});
