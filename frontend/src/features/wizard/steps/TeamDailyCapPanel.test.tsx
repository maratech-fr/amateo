import { fireEvent, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { renderWithProviders } from "@/test/utils";

import type { Team } from "../api";
import { TEAMS_TEXTS } from "../texts/teams";

/**
 * PROMESSE (PR 1a, onglet « Séances par jour » de la modale Liens) : le gestionnaire règle le nombre
 * maximum de séances d'une équipe le même jour, et le réglage part au bon endroit —
 *  - en SAISON (`schedulePlanId` null) : le PUT COMPLET de l'équipe (`useUpdateTeam`, jamais un patch
 *    partiel : l'API remplace l'entité) ;
 *  - en PÉRIODE : la surcharge du plan de période, jamais l'équipe (la saison reste à sa valeur) ;
 *  - sous planning de saison EN VIGUEUR (`readOnly`) : le champ de saison est en lecture seule et dit
 *    pourquoi (le serveur refuse aussi, 422 — `TeamApiTest` / scénario Behat S1 étape 14) ;
 *  - pour un MEMBRE : lecture seule, aucune écriture (le serveur refuse en 403 — `MemberWriteSweepTest`).
 * Le geste (une saisie) est envoyé au changement ou à la sortie du champ : le test fait les deux.
 */

const h = vi.hoisted(() => ({
  canManage: true,
  overrides: [] as unknown[],
  updateTeam: vi.fn(),
  createOverride: vi.fn(),
  updateOverride: vi.fn(),
  deleteOverride: vi.fn(),
}));

vi.mock("@/shared/session/queries", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/shared/session/queries")>()),
  useCanManage: () => h.canManage,
}));
vi.mock("../queries", () => ({
  useUpdateTeam: () => ({ mutate: h.updateTeam, mutateAsync: h.updateTeam, isPending: false }),
  useTeamPeriodOverrides: () => ({ data: h.overrides, isLoading: false, isPending: false, isError: false, refetch: vi.fn() }),
  useCreateTeamPeriodOverride: () => ({ mutate: h.createOverride, mutateAsync: h.createOverride, isPending: false }),
  useUpdateTeamPeriodOverride: () => ({ mutate: h.updateOverride, mutateAsync: h.updateOverride, isPending: false }),
  useDeleteTeamPeriodOverride: () => ({ mutate: h.deleteOverride, mutateAsync: h.deleteOverride, isPending: false }),
}));

import { TeamDailyCapPanel } from "./TeamDailyCapPanel";

const u15 = { id: "t1", name: "U15", sportCategoryId: "cat", priorityTierId: 1, tierOrder: 0, gender: null, level: null, sessionsPerWeek: 3, isActive: true, isCtc: false, ctcPartner: null, maxSessionsPerDay: 1 } as Team;

const field = (): HTMLElement => screen.getByLabelText(TEAMS_TEXTS.dailyCapLabel);

function enter(value: string): void {
  fireEvent.change(field(), { target: { value } });
  fireEvent.blur(field());
}

beforeEach(() => {
  h.canManage = true;
  h.overrides = [];
  for (const fn of [h.updateTeam, h.createOverride, h.updateOverride, h.deleteOverride]) {
    fn.mockReset();
    fn.mockImplementation(() => Promise.resolve({}));
  }
});

describe("TeamDailyCapPanel — le plafond part au bon endroit", () => {
  it("saison : saisir 2 envoie le PUT complet de l'équipe avec 2 séances par jour", () => {
    renderWithProviders(<TeamDailyCapPanel team={u15} schedulePlanId={null} readOnly={false} />);
    enter("2");

    expect(h.updateTeam).toHaveBeenCalledWith(
      expect.objectContaining({ id: "t1", body: expect.objectContaining({ name: "U15", sessionsPerWeek: 3, maxSessionsPerDay: 2 }) }),
    );
    expect(h.createOverride).not.toHaveBeenCalled();
    expect(h.updateOverride).not.toHaveBeenCalled();
  });

  it("période : saisir 3 écrit la surcharge du plan, jamais l'équipe", () => {
    renderWithProviders(<TeamDailyCapPanel team={{ ...u15, maxSessionsPerDay: 2 }} schedulePlanId="plan-1" readOnly={false} />);
    enter("3");

    const writes = [...h.createOverride.mock.calls, ...h.updateOverride.mock.calls].map((c) => {
      const arg = c[0] as { body?: Record<string, unknown> } & Record<string, unknown>;
      return arg.body ?? arg;
    });
    expect(writes).toContainEqual(expect.objectContaining({ schedulePlanId: "plan-1", teamId: "t1", maxSessionsPerDay: 3 }));
    expect(h.updateTeam).not.toHaveBeenCalled();
  });

  it("saison sous planning en vigueur : le champ est en lecture seule et dit pourquoi", () => {
    renderWithProviders(<TeamDailyCapPanel team={u15} schedulePlanId={null} readOnly />);

    expect(field()).toBeDisabled();
    expect(screen.getByText(TEAMS_TEXTS.sessionsLockHint)).toBeInTheDocument();
  });

  it("Membre : le champ est en lecture seule", () => {
    h.canManage = false;
    renderWithProviders(<TeamDailyCapPanel team={u15} schedulePlanId={null} readOnly={false} />);

    expect(field()).toBeDisabled();
  });
});
