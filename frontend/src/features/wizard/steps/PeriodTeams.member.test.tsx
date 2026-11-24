import { act, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Team } from "../api";

/**
 * PROMESSE (chantier Membre, PR 2) : ouvrir l'étape Équipes d'une PÉRIODE ne fait écrire un Membre
 * NULLE PART. Depuis le lot 4 (PR 3a), la pré-sélection d'un plan de vacances est posée par le serveur à sa
 * naissance et l'écran n'écrit plus rien à l'ouverture, pour personne (`PeriodTeams.test.tsx`, « aucune écriture
 * à l'ouverture ») ; le Membre reste le cas gardé ici, parce qu'il ne doit jamais écrire.
 * Témoin gestionnaire : même décor, le geste de décocher une équipe écrit par le hook observé — sans quoi
 * « aucune écriture » ne prouverait rien.
 */

const h = vi.hoisted(() => ({ canManage: true, create: vi.fn(() => Promise.resolve({})) }));

const team = (id: string, name: string, priorityTierId: number): Team =>
  ({ id, name, sportCategoryId: "cat", priorityTierId, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true }) as Team;

vi.mock("@/shared/session/queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/shared/session/queries")>()),
  useCanManage: () => h.canManage,
}));
vi.mock("@/features/cockpit/queries", () => ({
  usePeriodAnchor: () => ({ state: "period", planId: "plan-1" }),
  useCalendarEntry: () => ({ data: { id: "entry-1", periodType: "holiday" } }),
  useSchedulePlanForEntry: () => ({ data: { id: "plan-1" }, isLoading: false }),
}));
vi.mock("@/features/matches/queries", () => ({ useTeamLinks: () => ({ data: [] }) }));
vi.mock("../queries", () => ({
  // Deux rangs : S (gardé) et C (désactivé par l'amorçage d'une reprise).
  useWizardTeams: () => ({ data: [team("t-s", "SM1", 1), team("t-c", "U9", 4)] }),
  // Une reprise garde les DEUX premiers rangs (S, A) : avec S/A/B/C, l'équipe C est désactivée.
  usePriorityTiers: () => ({ data: [{ id: 1, label: "S", name: "Fanion", color: null }, { id: 2, label: "A", name: "Réserve", color: null }, { id: 3, label: "B", name: "Moyenne", color: null }, { id: 4, label: "C", name: "Loisir", color: null }] }),
  useTeamPeriodOverrides: () => ({ data: [], isLoading: false, isError: false, refetch: vi.fn() }),
  useCreateTeamPeriodOverride: () => ({ mutateAsync: h.create, mutate: vi.fn(), isPending: false }),
  useUpdateTeamPeriodOverride: () => ({ mutate: vi.fn(), mutateAsync: vi.fn(), isPending: false }),
  useDeleteTeamPeriodOverride: () => ({ mutate: vi.fn(), mutateAsync: vi.fn(), isPending: false }),
  useSharedTrainingBlocks: () => ({ data: [] }),
  useWizardConstraints: () => ({ data: [] }),
  useDeleteConstraint: () => ({ mutate: vi.fn(), isPending: false }),
}));

import { PeriodTeams } from "./PeriodTeams";

beforeEach(() => {
  h.canManage = true;
  h.create.mockClear();
});

describe("PeriodTeams — l'amorçage de la sélection n'est jamais déclenché par un Membre", () => {
  it("témoin gestionnaire : décocher une équipe des vacances écrit son réglage", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PeriodTeams calendarEntryId="entry-1" />);
    await act(async () => {
      await Promise.resolve();
    });
    expect(h.create).not.toHaveBeenCalled();

    await user.click(screen.getByRole("checkbox", { name: /^U9 / }));

    expect(h.create).toHaveBeenCalledWith(expect.objectContaining({ schedulePlanId: "plan-1", teamId: "t-c", isActive: false }));
  });

  it("Membre : la sélection se lit, aucune écriture au montage", async () => {
    h.canManage = false;
    renderWithProviders(<PeriodTeams calendarEntryId="entry-1" />);
    await act(async () => {
      await Promise.resolve();
    });
    expect(screen.getAllByText("SM1").length).toBeGreaterThan(0);
    expect(h.create).not.toHaveBeenCalled();
  });
});
