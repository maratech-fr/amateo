import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { PriorityTier, Reservation, SharedTrainingBlock, Team, TeamSoloBudget, Venue, VenueTrainingSlot } from "../api";

// Les mutations sont mockées : le test porte sur le GESTE (quel rail est appelé, dans quel ordre),
// pas sur react-query.
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

const block = (id: string, teamIds: string[], commonSessions = 1): SharedTrainingBlock =>
  ({ id, version: 1, createdAt: "2026-08-31T00:00:00+00:00", updatedAt: "2026-08-31T00:00:00+00:00", schedulePlanId: null, teamIds, commonSessions });

const resa = (teamId: string, venueId: string, dayOfWeek: number, startTime: string): Reservation =>
  ({ id: `${teamId}-${venueId}-${dayOfWeek}-${startTime}`, schedulePlanId: null, teamId, venueId, dayOfWeek, startTime, durationMinutes: 90 });

/** Budget solo servi par le backend (P2-60) — le sélecteur AFFICHE ce budget, il ne le recalcule pas. */
const soloBudget = (teamId: string, o: Partial<TeamSoloBudget> = {}): TeamSoloBudget =>
  ({ teamId, schedulePlanId: null, effectiveSessions: 2, blockSessions: 0, residual: 2, individualUsed: 0, inBlock: false, ...o });
// Défaut : chaque équipe garde 2 créneaux libres ; a/b sont membres du bloc « g » (inBlock).
const DEFAULT_BUDGETS: TeamSoloBudget[] = [soloBudget("a", { inBlock: true }), soloBudget("b", { inBlock: true }), soloBudget("c"), soloBudget("d")];

/** Une HTTPError ky porteuse d'un corps `{ error }` (ce que `apiErrorMessage` lit dans `error.data`). */
const httpError = (message: string): HTTPError => Object.assign(Object.create(HTTPError.prototype) as HTTPError, { data: { error: message } });

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
      sharedTrainingBlocks={[block("g", ["a", "b"])]}
      teamSoloBudgets={DEFAULT_BUDGETS}
      budgetsPending={false}
      budgetsFailed={false}
      onRetryBudgets={vi.fn()}
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

describe("SlotReservationModal — mutualisation par bloc (P2-51)", () => {
  it("offre le bloc sous « Entraînements mutualisés » sur un créneau LIBRE", () => {
    renderModal();
    const option = within(selector()).getByRole("option", { name: "SM1 + SM2 — 1 séance commune" });
    expect(option).toBeInTheDocument();
    expect(option.closest("optgroup")?.label).toBe("Entraînements mutualisés");
  });

  it("n'offre PAS le bloc sur un créneau occupé, et dit POURQUOI (raison visible)", () => {
    renderModal({ reservations: [resa("c", "v1", 1, "18:00")] });
    expect(within(selector()).queryByRole("option", { name: "SM1 + SM2 — 1 séance commune" })).toBeNull();
    expect(screen.getByText(/ne se pose que sur un créneau libre/i)).toBeInTheDocument();
  });

  it("un bloc posé dans le brouillon ferme le sélecteur sur une raison NOMMÉE", async () => {
    renderModal();
    await userEvent.selectOptions(selector(), "block:g");
    expect(screen.getByText(/occupe seul ce créneau/i)).toBeInTheDocument();
    expect(screen.queryByRole("combobox", { name: "Ajouter une équipe" })).toBeNull();
    // Le lot en brouillon est nommé et « à valider ».
    expect(screen.getByText(/SM1 \+ SM2/)).toBeInTheDocument();
    expect(screen.getByText("à valider")).toBeInTheDocument();
  });

  it("« retirer SM4 + poser le bloc » passe en UNE validation, retraits AVANT ajouts, un seul appel au rail bloc", async () => {
    const onClose = vi.fn();
    renderModal({ reservations: [resa("d", "v1", 1, "18:00")], onClose });

    await userEvent.click(screen.getByRole("button", { name: "Retirer SM4" }));
    await userEvent.selectOptions(selector(), "block:g");
    await userEvent.click(screen.getByRole("button", { name: "Valider" }));

    expect(callOrder).toEqual(["del", "group"]); // le retrait libère la case AVANT que le bloc s'y pose
    expect(delMut).toHaveBeenCalledWith("d-v1-1-18:00");
    expect(groupMut).toHaveBeenCalledTimes(1);
    expect(groupMut).toHaveBeenCalledWith({ sharedTrainingBlockId: "g", venueId: "v1", dayOfWeek: 1, startTime: "18:00", durationMinutes: 90, schedulePlanId: null });
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
    renderModal({ teams: [], sharedTrainingBlocks: [] });
    expect(
      screen.getByText("Aucune équipe disponible (toutes ont atteint leur nombre de créneaux, sont déjà sur ce créneau, ou s'entraînent uniquement en groupe)."),
    ).toBeInTheDocument();
  });

  it("un bloc ayant atteint ses séances communes n'est PAS offert, avec sa raison", () => {
    // Une case complète {a,b} ailleurs dans la portée → séances communes (1) atteintes.
    renderModal({ reservations: [resa("a", "v1", 3, "20:00"), resa("b", "v1", 3, "20:00")] });
    expect(within(selector()).queryByRole("option", { name: "SM1 + SM2 — 1 séance commune" })).toBeNull();
    expect(screen.getByText(/séance commune est déjà posée/i)).toBeInTheDocument();
    expect(screen.getByText(/indisponible/i)).toBeInTheDocument();
  });
});

describe("SlotReservationModal — budget solo servi par le backend (P2-60)", () => {
  it("retire l'option individuelle d'un membre de bloc à résidu nul, mais garde son bloc", () => {
    // SM1 : toutes ses séances viennent du bloc (résidu 0) → plus d'option individuelle,
    // mais le bloc SM1 + SM2 reste proposé.
    renderModal({
      teamSoloBudgets: [
        soloBudget("a", { residual: 0, blockSessions: 2, inBlock: true }),
        soloBudget("b", { residual: 0, blockSessions: 2, inBlock: true }),
        soloBudget("c"),
        soloBudget("d"),
      ],
    });
    expect(within(selector()).queryByRole("option", { name: /^SM1 —/ })).toBeNull();
    expect(within(selector()).getByRole("option", { name: "SM1 + SM2 — 1 séance commune" })).toBeInTheDocument();
  });

  it("étiquette chaque option de son résidu restant : pluriel, singulier, et « hors groupe » pour un membre de bloc", () => {
    renderModal({
      sharedTrainingBlocks: [],
      teamSoloBudgets: [
        soloBudget("a", { residual: 3, individualUsed: 1, effectiveSessions: 4, blockSessions: 1, inBlock: true }), // reste 2 hors groupe
        soloBudget("b", { residual: 1, individualUsed: 0, effectiveSessions: 2, blockSessions: 1, inBlock: true }), // reste 1 hors groupe
        soloBudget("c", { residual: 1, individualUsed: 0, effectiveSessions: 1, blockSessions: 0, inBlock: false }), // reste 1 créneau
        soloBudget("d", { residual: 2, individualUsed: 0, effectiveSessions: 2, blockSessions: 0, inBlock: false }), // reste 2 créneaux
      ],
    });
    expect(within(selector()).getByRole("option", { name: "SM1 — reste 2 créneaux hors groupe" })).toBeInTheDocument();
    expect(within(selector()).getByRole("option", { name: "SM2 — reste 1 créneau hors groupe" })).toBeInTheDocument();
    expect(within(selector()).getByRole("option", { name: "SM3 — reste 1 créneau" })).toBeInTheDocument();
    expect(within(selector()).getByRole("option", { name: "SM4 — reste 2 créneaux" })).toBeInTheDocument();
  });

  it("une fois choisie, une équipe dont le dernier créneau est consommé quitte le sélecteur", async () => {
    // Créneau divisible (2 places) ; SM3 n'a qu'un créneau libre, les autres en ont encore (le sélecteur reste).
    renderModal({ sharedTrainingBlocks: [], teamSoloBudgets: [soloBudget("a"), soloBudget("b"), soloBudget("c", { residual: 1, effectiveSessions: 1 }), soloBudget("d")] });
    expect(within(selector()).getByRole("option", { name: "SM3 — reste 1 créneau" })).toBeInTheDocument();
    await userEvent.selectOptions(selector(), "c");
    // SM3 passe « à valider » et ne réapparaît plus dans le sélecteur (créneau libre restant, mais résidu épuisé + déjà sur la case).
    expect(screen.getByText("à valider")).toBeInTheDocument();
    expect(within(selector()).queryByRole("option", { name: /^SM3/ })).toBeNull();
  });

  it("quand le dernier résidu est consommé, l'invite vide remplace le contrôle et s'annonce (role=status)", () => {
    renderModal({
      sharedTrainingBlocks: [],
      teamSoloBudgets: [soloBudget("a", { residual: 0 }), soloBudget("b", { residual: 0 }), soloBudget("c", { residual: 0 }), soloBudget("d", { residual: 0 })],
    });
    const hint = screen.getByText(/Aucune équipe disponible/);
    expect(hint).toHaveAttribute("role", "status");
    expect(hint.textContent).toMatch(/s'entraînent uniquement en groupe/);
  });

  it("budget en échec : la saisie est fermée (fail-closed) avec un bouton Réessayer", async () => {
    const onRetryBudgets = vi.fn();
    renderModal({ teamSoloBudgets: null, budgetsFailed: true, onRetryBudgets });
    expect(screen.queryByRole("combobox", { name: "Ajouter une équipe" })).toBeNull();
    const retry = screen.getByRole("button", { name: /réessayer/i });
    await userEvent.click(retry);
    expect(onRetryBudgets).toHaveBeenCalled();
  });

  it("un 422 serveur sur un ajout unitaire affiche le message du serveur dans l'alerte, la ligne « à valider » restant", async () => {
    createMut.mockReset().mockRejectedValue(httpError("SM1 a déjà toutes ses réservations individuelles pour ce plan."));
    renderModal({ sharedTrainingBlocks: [] });

    await userEvent.selectOptions(selector(), "a"); // choisir SM1 (résidu 2 par défaut)
    expect(screen.getByText("à valider")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: "Valider" }));

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("SM1 a déjà toutes ses réservations individuelles pour ce plan.");
    // La ligne « à valider » de l'équipe refusée reste, avec son Undo (rejeu possible).
    expect(screen.getByText("à valider")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Annuler l'ajout de SM1" })).toBeInTheDocument();
  });
});

