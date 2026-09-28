import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { describe, expect, it, vi } from "vitest";

import * as wizardApi from "./api";
import { useCreateCoachPlayer, useCreateTeamCoach, useDeleteCoach, useDeleteCoachPlayer, useDeleteTeamCoach } from "./queries";

vi.mock("./api", () => ({
  createTeamCoach: vi.fn(() => Promise.resolve({ id: "tc1" })),
  deleteTeamCoach: vi.fn(() => Promise.resolve()),
  createCoachPlayer: vi.fn(() => Promise.resolve({ id: "cp1" })),
  deleteCoachPlayer: vi.fn(() => Promise.resolve()),
  deleteCoach: vi.fn(() => Promise.resolve()),
  listCoaches: vi.fn(() => Promise.resolve([])),
  listTeamCoaches: vi.fn(() => Promise.resolve([])),
  listCoachPlayers: vi.fn(() => Promise.resolve([])),
}));

/**
 * P4-268 — le LIEN équipe→coach (et coach→joueur) vit sous DEUX clés de cache : le wizard écrit sous
 * `["wizard", …]`, mais Planning/Matchs lisent la clé NUE (`["team_coaches"]` /
 * `["coach_player_memberships"]`, `staleTime` 5 min) pour bâtir `lookups.teamCoach` /
 * `teamPlayerCoaches` (le coach affiché sur une séance générée sans coach — `planning/lib/grid.ts`).
 * N'invalider que la clé wizard laissait un coach déclaré tardivement via « Modifier les données du
 * club » manquer au planning pendant cinq minutes. Ce garde tient l'invalidation croisée.
 */
describe("les liens coach du wizard invalident AUSSI le cache lu par le planning", () => {
  const harness = () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    const spy = vi.spyOn(queryClient, "invalidateQueries");
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
    return { spy, wrapper };
  };

  const invalidated = (spy: ReturnType<typeof vi.spyOn>, ...expected: string[]): boolean =>
    spy.mock.calls.some(([arg]: [unknown]) => {
      const key = (arg as { queryKey?: unknown[] } | undefined)?.queryKey;
      return Array.isArray(key) && key.length === expected.length && expected.every((k, i) => key[i] === k);
    });

  it("lier un coach à une équipe invalide la clé wizard ET la clé nue du planning", async () => {
    const { spy, wrapper } = harness();
    const { result } = renderHook(() => useCreateTeamCoach(), { wrapper });
    result.current.mutate({ teamId: "t1", coachId: "c1", role: "MAIN" });
    await waitFor(() => expect(vi.mocked(wizardApi.createTeamCoach)).toHaveBeenCalled());
    await waitFor(() => expect(invalidated(spy, "wizard", "team_coaches")).toBe(true));
    expect(invalidated(spy, "team_coaches")).toBe(true);
  });

  it("délier un coach d'une équipe invalide la clé nue du planning", async () => {
    const { spy, wrapper } = harness();
    const { result } = renderHook(() => useDeleteTeamCoach(), { wrapper });
    result.current.mutate("tc1");
    await waitFor(() => expect(vi.mocked(wizardApi.deleteTeamCoach)).toHaveBeenCalled());
    await waitFor(() => expect(invalidated(spy, "team_coaches")).toBe(true));
  });

  it("supprimer un coach invalide les DEUX liens côté planning (team_coaches + coach_player_memberships)", async () => {
    const { spy, wrapper } = harness();
    const { result } = renderHook(() => useDeleteCoach(), { wrapper });
    result.current.mutate("c1");
    await waitFor(() => expect(vi.mocked(wizardApi.deleteCoach)).toHaveBeenCalled());
    await waitFor(() => expect(invalidated(spy, "team_coaches")).toBe(true));
    expect(invalidated(spy, "coach_player_memberships")).toBe(true);
  });

  it("déclarer un joueur-coach invalide la clé nue coach_player_memberships du planning", async () => {
    const { spy, wrapper } = harness();
    const { result } = renderHook(() => useCreateCoachPlayer(), { wrapper });
    result.current.mutate({ teamId: "t1", coachId: "c1", isActive: true });
    await waitFor(() => expect(vi.mocked(wizardApi.createCoachPlayer)).toHaveBeenCalled());
    await waitFor(() => expect(invalidated(spy, "coach_player_memberships")).toBe(true));
  });

  it("retirer un joueur-coach invalide la clé nue coach_player_memberships du planning", async () => {
    const { spy, wrapper } = harness();
    const { result } = renderHook(() => useDeleteCoachPlayer(), { wrapper });
    result.current.mutate("cp1");
    await waitFor(() => expect(vi.mocked(wizardApi.deleteCoachPlayer)).toHaveBeenCalled());
    await waitFor(() => expect(invalidated(spy, "coach_player_memberships")).toBe(true));
  });
});
