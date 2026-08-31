import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { PriorityTier, SharedTrainingBlock, Team, TeamPeriodOverride } from "../api";

const stbCreate = vi.fn();
const stbUpdate = vi.fn();
const stbDelete = vi.fn();
const blocksState: { data: SharedTrainingBlock[] } = { data: [] };
const overridesState: { data: TeamPeriodOverride[] } = { data: [] };

// Le mock IGNORE `schedulePlanId` (le provider renvoie socle+périodes) : c'est le PANNEAU qui
// filtre le socle.
vi.mock("../queries", () => ({
  useSharedTrainingBlocks: () => ({ data: blocksState.data }),
  useTeamPeriodOverrides: () => ({ data: overridesState.data }),
  useCreateSharedTrainingBlock: () => ({ mutateAsync: stbCreate, isPending: false }),
  useUpdateSharedTrainingBlock: () => ({ mutateAsync: stbUpdate, isPending: false }),
  useDeleteSharedTrainingBlock: () => ({ mutate: stbDelete }),
}));

import { SharedTrainingBlockPanel } from "./SharedTrainingBlockPanel";

const team = (over: Partial<Team> & Pick<Team, "id" | "name">): Team => ({
  sportCategoryId: "senior",
  priorityTierId: 1,
  tierOrder: 0,
  gender: "M",
  level: null,
  sessionsPerWeek: 3,
  isActive: true,
  ...over,
});

const TEAMS: Team[] = [
  team({ id: "t1", name: "U9F1", priorityTierId: 1, tierOrder: 0, sessionsPerWeek: 3 }),
  team({ id: "t2", name: "U9F2", priorityTierId: 1, tierOrder: 1, sessionsPerWeek: 2 }),
  team({ id: "t3", name: "U9M1", priorityTierId: 2, tierOrder: 0, sessionsPerWeek: 2 }),
];

const TIERS: PriorityTier[] = [
  { id: 1, label: "S", name: "Fanion", color: null },
  { id: 2, label: "A", name: "Importante", color: null },
];

const block = (id: string, teamIds: string[], commonSessions = 1, schedulePlanId: string | null = null): SharedTrainingBlock => ({
  id,
  version: 1,
  createdAt: "2026-08-31T00:00:00+00:00",
  updatedAt: "2026-08-31T00:00:00+00:00",
  schedulePlanId,
  teamIds,
  commonSessions,
});

const renderPanel = (schedulePlanId: string | null = null, initialTeamId?: string) =>
  renderWithProviders(<SharedTrainingBlockPanel teams={TEAMS} tiers={TIERS} schedulePlanId={schedulePlanId} initialTeamId={initialTeamId} />);

beforeEach(() => {
  stbCreate.mockClear();
  stbUpdate.mockClear();
  stbDelete.mockClear();
  blocksState.data = [];
  overridesState.data = [];
});

describe("SharedTrainingBlockPanel — la LISTE DÉROULANTE des séances communes (bornée, affichée)", () => {
  it("désactive la liste déroulante tant qu'il y a moins de deux équipes", () => {
    renderPanel();
    expect(screen.getByRole("combobox", { name: "Séances communes" })).toBeDisabled();
    expect(screen.getByText(/Choisissez au moins 2 équipes/)).toBeInTheDocument();
  });

  it("n'offre que 1..min(séances effectives) une fois deux équipes cochées, et annonce le plafond", async () => {
    const user = userEvent.setup();
    renderPanel();
    // U9F1 = 3 séances, U9F2 = 2 → plafond 2 : options 1 et 2, JAMAIS 3.
    await user.click(screen.getByRole("checkbox", { name: "U9F1" }));
    await user.click(screen.getByRole("checkbox", { name: "U9F2" }));

    const select = screen.getByRole("combobox", { name: "Séances communes" });
    expect(select).toBeEnabled();
    const values = [...select.querySelectorAll("option")].map((o) => (o as HTMLOptionElement).value);
    expect(values).toEqual(["1", "2"]);
    expect(screen.getByText(/Jusqu'à 2 avec les équipes choisies/)).toBeInTheDocument();
  });

  it("suit l'override de période dans le plafond (l'override est prioritaire)", async () => {
    const user = userEvent.setup();
    overridesState.data = [{ id: "o", schedulePlanId: "plan-1", teamId: "t1", isActive: true, sessionsPerWeek: 1 }];
    renderPanel("plan-1");

    await user.click(screen.getByRole("checkbox", { name: "U9F1" }));
    await user.click(screen.getByRole("checkbox", { name: "U9F2" }));
    const values = [...screen.getByRole("combobox", { name: "Séances communes" }).querySelectorAll("option")].map((o) => (o as HTMLOptionElement).value);
    expect(values).toEqual(["1"]);
  });
});

describe("SharedTrainingBlockPanel — multi-appartenance PERMISE (jamais de verrou)", () => {
  it("une équipe déjà dans un autre bloc reste COCHABLE, avec un simple repère informatif", () => {
    blocksState.data = [block("b1", ["t1", "t3"])];
    renderPanel();

    const u9f1 = screen.getByRole("checkbox", { name: "U9F1" });
    // Multi-appartenance permise : jamais désactivée (contraste avec le groupe K).
    expect(u9f1).toBeEnabled();
    const row = u9f1.closest("div") as HTMLElement;
    expect(within(row).getByText(/déjà dans 1 groupe/)).toBeInTheDocument();
  });
});

describe("SharedTrainingBlockPanel — créer / modifier / supprimer", () => {
  it("crée un bloc : POST { schedulePlanId, teamIds, commonSessions } avec TOUTES les équipes cochées", async () => {
    const user = userEvent.setup();
    renderPanel(null);

    await user.click(screen.getByRole("checkbox", { name: "U9F1" }));
    await user.click(screen.getByRole("checkbox", { name: "U9F2" }));
    await user.selectOptions(screen.getByRole("combobox", { name: "Séances communes" }), "2");
    await user.click(screen.getByRole("button", { name: "Créer le groupe" }));

    expect(stbCreate).toHaveBeenCalledOnce();
    const arg = stbCreate.mock.calls[0][0] as { schedulePlanId: string | null; teamIds: string[]; commonSessions: number };
    expect(arg.schedulePlanId).toBeNull();
    expect([...arg.teamIds].sort()).toEqual(["t1", "t2"]);
    expect(arg.commonSessions).toBe(2);
  });

  it("le bouton reste inerte tant qu'il n'y a pas au moins deux équipes", async () => {
    const user = userEvent.setup();
    renderPanel();
    await user.click(screen.getByRole("checkbox", { name: "U9F1" }));
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeDisabled();
  });

  it("modifie un bloc existant : préremplissage puis PUT { teamIds, commonSessions } sur le même id", async () => {
    const user = userEvent.setup();
    blocksState.data = [block("b1", ["t1", "t2"], 2)];
    renderPanel();

    await user.click(screen.getByRole("button", { name: /Modifier le groupe/ }));
    await user.click(screen.getByRole("button", { name: "Enregistrer le groupe" }));

    expect(stbUpdate).toHaveBeenCalledOnce();
    const arg = stbUpdate.mock.calls[0][0] as { id: string; body: { teamIds: string[]; commonSessions: number } };
    expect(arg.id).toBe("b1");
    expect([...arg.body.teamIds].sort()).toEqual(["t1", "t2"]);
    expect(arg.body.commonSessions).toBe(2);
  });

  it("supprime un bloc après confirmation", async () => {
    const user = userEvent.setup();
    blocksState.data = [block("b1", ["t1", "t2"], 1)];
    renderPanel();

    await user.click(screen.getByRole("button", { name: /Supprimer le groupe/ }));
    await user.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(stbDelete).toHaveBeenCalledWith("b1");
  });

  it("affiche le motif d'un 422 serveur (garde Σ) sans vider la sélection", async () => {
    const user = userEvent.setup();
    const message = "Le total des séances communes des blocs d'une équipe (4) dépasse son nombre de séances hebdomadaires (3).";
    const err = new HTTPError(new Response(null, { status: 422 }), new Request("http://localhost/"), { method: "POST" } as never);
    (err as { data?: unknown }).data = { title: "An error occurred", detail: message, violations: [{ propertyPath: "", message }] };
    stbCreate.mockRejectedValueOnce(err);
    renderPanel();

    await user.click(screen.getByRole("checkbox", { name: "U9F1" }));
    await user.click(screen.getByRole("checkbox", { name: "U9F2" }));
    await user.click(screen.getByRole("button", { name: "Créer le groupe" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(message);
    // La sélection est CONSERVÉE pour retenter (patron error-recovery).
    expect(screen.getByRole("checkbox", { name: "U9F1" })).toBeChecked();
  });
});
