import { screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { TeamLink } from "@/features/matches/api";

import type { PriorityTier, SharedTrainingBlock, Team } from "../api";

const blocksState: { data: SharedTrainingBlock[] } = { data: [] };
const teamLinksState: { data: TeamLink[] } = { data: [] };

vi.mock("../queries", () => ({
  useTeamPeriodOverrides: () => ({ data: [] }),
  // P2-51 — le BLOC est la SEULE notion de mutualisation montée dans la modale.
  useSharedTrainingBlocks: () => ({ data: blocksState.data }),
  useCreateSharedTrainingBlock: () => ({ mutateAsync: vi.fn(() => Promise.resolve({})), isPending: false }),
  useUpdateSharedTrainingBlock: () => ({ mutateAsync: vi.fn(() => Promise.resolve({})), isPending: false }),
  useDeleteSharedTrainingBlock: () => ({ mutate: vi.fn() }),
}));
vi.mock("@/features/matches/queries", () => ({
  useTeamLinks: () => ({ data: teamLinksState.data, isError: false }),
  useCreateTeamLink: () => ({ mutate: vi.fn(), isPending: false }),
  useUpdateTeamLink: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteTeamLink: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { TeamLinksModal } from "./TeamLinksModal";

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
const TEAMS: Team[] = [team({ id: "t1", name: "SM1" }), team({ id: "t2", name: "SM2" })];
const TIERS: PriorityTier[] = [{ id: 1, label: "S", name: "Fanion", color: null }];

const renderModal = (readOnlyLinks = false, schedulePlanId: string | null = null) =>
  renderWithProviders(
    <TeamLinksModal team={{ id: "t1", name: "SM1" }} teams={TEAMS} tiers={TIERS} schedulePlanId={schedulePlanId} readOnlyLinks={readOnlyLinks} onClose={vi.fn()} />,
  );

beforeEach(() => {
  blocksState.data = [];
  teamLinksState.data = [];
});

describe("TeamLinksModal — deux ONGLETS (Passerelles · Mutualisation)", () => {
  // #10 (améliorations légères, retour Rillieux 2026-10-21) — mutualisation et passerelle vivaient
  // dans le MÊME bloc, trop chargé : la modale les sépare désormais en DEUX onglets (primitive
  // `Tabs`), « Passerelles » actif par défaut (l'ordre historique haut → bas de la modale).
  it("titre « Liens de {équipe} » et une barre d'onglets Passerelles · Mutualisation", () => {
    renderModal();
    expect(screen.getByRole("dialog", { name: "Liens de SM1" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Passerelles" })).toBeInTheDocument();
    expect(screen.getByRole("tab", { name: "Mutualisation" })).toBeInTheDocument();
    // Passerelles est l'onglet actif par défaut.
    expect(screen.getByRole("tab", { name: "Passerelles" })).toHaveAttribute("aria-selected", "true");
    expect(screen.getByRole("tab", { name: "Mutualisation" })).toHaveAttribute("aria-selected", "false");
  });

  it("par défaut, SEUL le panneau Passerelles est visible ; la mutualisation est dans l'onglet voisin", () => {
    renderModal();
    // Panneau actif = Passerelles : son contenu est accessible…
    expect(screen.getByRole("heading", { level: 3, name: "Passerelles entre équipes" })).toBeInTheDocument();
    // …et celui de la mutualisation (panneau inactif, `hidden`) ne l'est pas tant qu'on n'a pas
    // activé son onglet (`getByRole` ignore un sous-arbre `hidden`).
    expect(screen.queryByRole("button", { name: "Créer le groupe" })).toBeNull();
  });

  it("activer l'onglet Mutualisation montre la mutualisation et masque les passerelles", async () => {
    const user = userEvent.setup();
    renderModal();

    await user.click(screen.getByRole("tab", { name: "Mutualisation" }));
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeInTheDocument();
    // Les passerelles sont alors dans le panneau inactif.
    expect(screen.queryByRole("button", { name: "Ajouter la passerelle" })).toBeNull();
    // D13 (correction fondateur) — UNE seule mutualisation (rendue par `SharedTrainingBlockPanel`) :
    // pas de titre « Bloc d'équipes ».
    expect(screen.queryByRole("heading", { name: "Bloc d'équipes" })).toBeNull();
  });

  it("jamais un dialog dans le dialog : « Gérer les passerelles » est absent", () => {
    renderModal();
    expect(screen.queryByRole("button", { name: "Gérer les passerelles" })).toBeNull();
  });

  it("readOnlyLinks=false : passerelles éditables (onglet actif), mutualisation éditable (onglet voisin)", async () => {
    const user = userEvent.setup();
    renderModal(false);
    expect(screen.getByRole("button", { name: "Ajouter la passerelle" })).toBeInTheDocument();
    await user.click(screen.getByRole("tab", { name: "Mutualisation" }));
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeInTheDocument();
  });

  it("readOnlyLinks=true : passerelles en lecture seule (raison dite, pas d'ajout), mutualisation TOUJOURS éditable", async () => {
    const user = userEvent.setup();
    renderModal(true, "plan-9");
    // Onglet Passerelles (actif) : raison dite, aucun ajout.
    expect(screen.getByText(/se déclarent au niveau de la saison/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Ajouter la passerelle" })).toBeNull();
    // La mutualisation reste éditable (ancrée au plan) même quand les passerelles ne le sont pas.
    await user.click(screen.getByRole("tab", { name: "Mutualisation" }));
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeInTheDocument();
  });
});
