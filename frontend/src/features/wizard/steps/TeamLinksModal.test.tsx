import { screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { TeamLink } from "@/features/matches/api";

import type { PriorityTier, SharedTrainingBlock, SharedTrainingGroup, Team } from "../api";

const sharedGroupsState: { data: SharedTrainingGroup[] } = { data: [] };
const blocksState: { data: SharedTrainingBlock[] } = { data: [] };
const teamLinksState: { data: TeamLink[] } = { data: [] };

vi.mock("../queries", () => ({
  useSharedTrainingGroups: () => ({ data: sharedGroupsState.data }),
  useTeamPeriodOverrides: () => ({ data: [] }),
  useCreateSharedTrainingGroup: () => ({ mutateAsync: vi.fn(() => Promise.resolve({})), isPending: false }),
  useUpdateSharedTrainingGroup: () => ({ mutateAsync: vi.fn(() => Promise.resolve({})), isPending: false }),
  useDeleteSharedTrainingGroup: () => ({ mutate: vi.fn() }),
  // P2-51 — le BLOC est une notion sœur montée dans la même modale.
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
vi.mock("@/features/matches/HabitsLinksButton", () => ({
  HabitsLinksButton: () => <button type="button">Gérer les passerelles</button>,
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
  sharedGroupsState.data = [];
  blocksState.data = [];
  teamLinksState.data = [];
});

describe("TeamLinksModal — deux sections dans l'ordre passerelles → mutualisation", () => {
  it("titre « Liens de {équipe} » et les deux sections dans l'ordre", () => {
    renderModal();
    expect(screen.getByRole("dialog", { name: "Liens de SM1" })).toBeInTheDocument();
    const headings = screen.getAllByRole("heading", { level: 3 }).map((h) => h.textContent);
    const passIdx = headings.indexOf("Passerelles entre équipes");
    const mutIdx = headings.indexOf("Mutualisation");
    expect(passIdx).toBeGreaterThanOrEqual(0);
    expect(mutIdx).toBeGreaterThan(passIdx);
    // D13 (correction fondateur) — UNE seule section « Mutualisation » (rendue par
    // `SharedTrainingBlockPanel`) : plus de second panneau, plus de titre « Bloc d'équipes ».
    expect(headings).not.toContain("Bloc d'équipes");
  });

  it("jamais un dialog dans le dialog : « Gérer les passerelles » est absent", () => {
    renderModal();
    expect(screen.queryByRole("button", { name: "Gérer les passerelles" })).toBeNull();
  });

  it("readOnlyLinks=false : passerelles éditables, mutualisation éditable", () => {
    renderModal(false);
    expect(screen.getByRole("button", { name: "Ajouter la passerelle" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeInTheDocument();
  });

  it("readOnlyLinks=true : passerelles en lecture seule (raison dite, pas d'ajout), mutualisation TOUJOURS éditable", () => {
    renderModal(true, "plan-9");
    expect(screen.getByText(/se déclarent au niveau de la saison/)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Ajouter la passerelle" })).toBeNull();
    // La mutualisation reste éditable (ancrée au plan) même quand les passerelles ne le sont pas.
    expect(screen.getByRole("button", { name: "Créer le groupe" })).toBeInTheDocument();
  });
});
