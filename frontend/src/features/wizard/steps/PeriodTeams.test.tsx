import { act, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Team, TeamPeriodOverride } from "../api";
import { TEAMS_TEXTS } from "../texts/teams";

/**
 * PROMESSE (PR 1a, plafond de séances par jour) : à l'étape Équipes d'une PÉRIODE, décocher puis
 * recocher une équipe n'efface pas son plafond de période, et le repère de la ligne montre la valeur
 * que la période applique (3), pas celle de la saison (2). Avant ce lot, la ligne reconstruisait le
 * corps de la surcharge à la main, sans le plafond, et la supprimait dès « active + séances de saison »
 * (`PeriodTeams.tsx`, `upsertAsync`) : recocher perdait le plafond en silence.
 * Jumeau logique : `lib/periodOverride.test.ts` (tous les cas du corps ; ici, le seul geste à l'écran).
 */

const h = vi.hoisted(() => ({
  overrides: [] as unknown[],
  rerender: { fn: () => {} },
  update: vi.fn(),
  create: vi.fn(),
  del: vi.fn(),
}));

const u15 = { id: "t1", name: "U15", sportCategoryId: "cat", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 2, isActive: true, maxSessionsPerDay: 2 } as Team;

const capOverride = (isActive: boolean): TeamPeriodOverride =>
  ({ id: "o1", schedulePlanId: "plan-1", teamId: "t1", isActive, sessionsPerWeek: null, maxSessionsPerDay: 3, source: null }) as TeamPeriodOverride;

vi.mock("@/shared/session/queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/shared/session/queries")>()),
  useCanManage: () => true,
}));
vi.mock("@/features/cockpit/queries", () => ({
  usePeriodAnchor: () => ({ state: "period", planId: "plan-1" }),
  useCalendarEntry: () => ({ data: { id: "entry-1", periodType: "holiday" } }),
  useSchedulePlanForEntry: () => ({ data: { id: "plan-1", teamSelectionInitialized: true }, isLoading: false }),
}));
vi.mock("@/features/matches/queries", () => ({ useTeamLinks: () => ({ data: [] }) }));
vi.mock("../queries", async () => {
  const { useReducer } = await vi.importActual<typeof import("react")>("react");
  return {
    useWizardTeams: () => ({ data: [u15] }),
    usePriorityTiers: () => ({ data: [{ id: 1, label: "S", name: "Fanion", color: null }] }),
    // Comme le vrai hook : la liste se relit après une écriture (le double se re-rend sur demande).
    useTeamPeriodOverrides: () => {
      const [, rerender] = useReducer((n: number) => n + 1, 0);
      h.rerender.fn = rerender;
      return { data: h.overrides, isLoading: false, isError: false, isPending: false, refetch: vi.fn() };
    },
    useCreateTeamPeriodOverride: () => ({ mutate: h.create, mutateAsync: h.create, isPending: false }),
    useUpdateTeamPeriodOverride: () => ({ mutate: h.update, mutateAsync: h.update, isPending: false }),
    useDeleteTeamPeriodOverride: () => ({ mutate: h.del, mutateAsync: h.del, isPending: false }),
    useSharedTrainingBlocks: () => ({ data: [] }),
    useWizardConstraints: () => ({ data: [] }),
    useDeleteConstraint: () => ({ mutate: vi.fn(), isPending: false }),
  };
});

import { PeriodTeams } from "./PeriodTeams";

/** Le repère « N/jour » de la ligne, trouvé par son nom accessible (clé centralisée). */
function markerShown(n: number): boolean {
  const name = TEAMS_TEXTS.dailyCapMarkerAria(n);
  return screen.queryAllByLabelText(name).length + screen.queryAllByText(name).length > 0;
}

/** Le corps envoyé par le DERNIER appel d'écriture de la surcharge (update ou create). */
function lastBody(): Record<string, unknown> | undefined {
  const calls = [...h.update.mock.calls, ...h.create.mock.calls];
  const arg = calls.at(-1)?.[0] as { body?: Record<string, unknown> } & Record<string, unknown> | undefined;
  return arg?.body ?? arg;
}

beforeEach(() => {
  h.overrides = [capOverride(true)];
  for (const fn of [h.update, h.create, h.del]) {
    fn.mockReset();
    fn.mockImplementation(() => Promise.resolve({}));
  }
});

describe("PeriodTeams — le plafond de période survit à décocher / recocher", () => {
  it("décocher puis recocher U15 garde son plafond de période (3), et le repère montre 3", async () => {
    const user = userEvent.setup();
    renderWithProviders(<PeriodTeams calendarEntryId="entry-1" />);

    await user.click(screen.getByRole("checkbox", { name: "U15 active cette période" }));
    expect(lastBody()).toEqual(expect.objectContaining({ isActive: false, maxSessionsPerDay: 3 }));

    // Le serveur a enregistré la pause : la liste relue porte l'équipe en pause, plafond intact.
    h.overrides = [capOverride(false)];
    act(() => h.rerender.fn());

    await user.click(screen.getByRole("checkbox", { name: "U15 active cette période" }));
    expect(h.del).not.toHaveBeenCalled();
    expect(lastBody()).toEqual(expect.objectContaining({ isActive: true, maxSessionsPerDay: 3 }));

    // Le repère de la ligne montre la valeur que la période applique, pas celle de la saison.
    h.overrides = [capOverride(true)];
    act(() => h.rerender.fn());
    expect(markerShown(3)).toBe(true);
    expect(markerShown(2)).toBe(false);
  });
});
