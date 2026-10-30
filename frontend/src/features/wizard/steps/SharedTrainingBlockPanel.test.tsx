import { screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { HTTPError } from "ky";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { PriorityTier, SharedBlockSession, SharedTrainingBlock, Team, TeamPeriodOverride } from "../api";
import { sharedGroupLabel } from "../lib/sharedTraining";

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

const block = (id: string, teamIds: string[], commonSessions = 1, schedulePlanId: string | null = null, sessions: SharedBlockSession[] = []): SharedTrainingBlock => ({
  id,
  version: 1,
  createdAt: "2026-08-31T00:00:00+00:00",
  updatedAt: "2026-08-31T00:00:00+00:00",
  schedulePlanId,
  teamIds,
  commonSessions,
  label: null,
  sessions,
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
    overridesState.data = [{ id: "o", schedulePlanId: "plan-1", teamId: "t1", isActive: true, sessionsPerWeek: 1, source: null }];
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

  it("supprime un bloc après confirmation ; sans séance placée, la confirmation reste par équipes", async () => {
    const user = userEvent.setup();
    blocksState.data = [block("b1", ["t1", "t2"], 1)];
    renderPanel();

    await user.click(screen.getByRole("button", { name: /Supprimer le groupe/ }));
    // Bloc sans séance servie : on garde le texte par équipes, jamais de liste vide.
    const dialog = screen.getByRole("dialog");
    expect(within(dialog).getByText(/les séances communes de U9F1, U9F2/)).toBeInTheDocument();
    expect(within(dialog).queryAllByRole("listitem")).toHaveLength(0);

    await user.click(screen.getByRole("button", { name: "Supprimer" }));
    expect(stbDelete).toHaveBeenCalledWith("b1");
  });

  it("NOMME les séances emportées dans la confirmation (jour en français, heures HH:MM, gymnase)", async () => {
    const user = userEvent.setup();
    const session = (teamId: string, teamName: string): SharedBlockSession => ({
      teamId,
      teamName,
      dayOfWeek: 2,
      startTime: "2026-01-06T18:00:00+00:00",
      endTime: "2026-01-06T19:30:00+00:00",
      venueName: "Gymnase Jean-Macé",
    });
    blocksState.data = [block("b1", ["t1", "t2"], 1, "plan-1", [session("t1", "U9F1"), session("t2", "U9F2")])];
    renderPanel("plan-1");

    await user.click(screen.getByRole("button", { name: /Supprimer le groupe/ }));

    const dialog = screen.getByRole("dialog");
    expect(within(dialog).getByText(/retirera ces séances du planning/)).toBeInTheDocument();
    const lines = within(dialog)
      .getAllByRole("listitem")
      .map((li) => li.textContent);
    expect(lines).toContain("U9F1 — mardi 18:00-19:30, Gymnase Jean-Macé");
    expect(lines).toContain("U9F2 — mardi 18:00-19:30, Gymnase Jean-Macé");
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

/**
 * ─────────── PR A lot 2 — E12 : « Nom du groupe » facultatif d'une séance commune ───────────
 * Un groupe mutualisé porte un `label` en base (servi en lecture) mais AUCUN écran ne le saisit :
 * le panneau ne propose qu'équipes + séances communes, et la liste (:272) affiche
 * `sharedGroupLabel(...)` (par les équipes), jamais le nom. TANK écrit ROUGE (option A), NEO implémente.
 *  (a) champ « Nom du groupe » facultatif à la création/modification → sa valeur part dans le payload API ;
 *  (b) la liste affiche le NOM s'il existe, sinon l'affichage par les équipes ;
 *  (c) vider le nom → retour à l'affichage par les équipes (branche fallback, témoin VERT).
 * Cohérence E11 : la case de réservation lit ce MÊME `label` (pas `slot.groupLabel`).
 * ⚠ FINDING : `SharedTrainingBlockPanel.tsx` n'est PAS dans `MIGRATED_FILES` — ajouter un libellé de
 * champ impose à NEO de MIGRER l'écran (texts.ts + inscription à `MIGRATED_FILES`). Élargissement de
 * périmètre à acter ; non fait ici. Le test vise un nom accessible (écran non migré → littéral toléré).
 */
describe("SharedTrainingBlockPanel — E12 : nom du groupe (label)", () => {
  it("E12 (a) — le « Nom du groupe » saisi part dans le payload de création (label)", async () => {
    const user = userEvent.setup();
    renderPanel();
    await user.click(screen.getByRole("checkbox", { name: "U9F1" }));
    await user.click(screen.getByRole("checkbox", { name: "U9F2" }));

    // Champ « Nom du groupe » facultatif — ABSENT aujourd'hui (le panneau ne propose qu'équipes + séances).
    const nameField = screen.getByRole("textbox", { name: (n: string) => n.toLowerCase().includes("nom du groupe") });
    await user.type(nameField, "SM Shooting");
    await user.click(screen.getByRole("button", { name: "Créer le groupe" }));

    expect(stbCreate).toHaveBeenCalledWith(expect.objectContaining({ label: "SM Shooting" }));
  });

  it("E12 (b/c) — la liste affiche le NOM quand il existe, sinon les équipes (fallback)", () => {
    blocksState.data = [
      { ...block("b1", ["t1", "t2"], 1), label: "SM Shooting" }, // avec nom
      block("b2", ["t1", "t3"], 1), // sans nom (label null) → fallback par les équipes
    ];
    renderPanel();

    // Avec nom → le NOM s'affiche. ROUGE aujourd'hui : la liste rend toujours `sharedGroupLabel` (équipes).
    expect(screen.getByText("SM Shooting")).toBeInTheDocument();
    // Sans nom → l'affichage par les équipes DEMEURE (témoin VERT du fallback, promesse (c)).
    const nameOf = (id: string): string => TEAMS.find((t) => t.id === id)?.name ?? id;
    expect(screen.getByText(sharedGroupLabel(["t1", "t3"], 1, nameOf))).toBeInTheDocument();
  });
});
