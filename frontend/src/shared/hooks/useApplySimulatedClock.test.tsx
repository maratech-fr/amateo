import { render, renderHook, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { setTodayOverride, toISODate, todayISO, useTodayISO } from "@/shared/lib/clock";

import { useApplySimulatedClock } from "./useApplySimulatedClock";

// On pilote la date serveur du club via /api/me (seule source du cale d'horloge).
let simulatedToday: string | null = null;
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: { club: { simulatedToday } } }) }));

afterEach(() => {
  setTodayOverride(null);
  simulatedToday = null;
});

function mount() {
  const client = new QueryClient();
  const invalidate = vi.spyOn(client, "invalidateQueries").mockResolvedValue(undefined);
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  const view = renderHook(() => useApplySimulatedClock(), { wrapper });

  return { invalidate, view };
}

describe("useApplySimulatedClock — cale todayISO sur le serveur et invalide react-query quand la date effective bouge", () => {
  it("à la POSE d'une horloge simulée : cale todayISO sur la date serveur ET invalide le cache", () => {
    simulatedToday = "2026-12-15";
    const { invalidate } = mount();

    expect(todayISO()).toBe("2026-12-15");
    expect(invalidate).toHaveBeenCalledTimes(1);
  });

  it("au RETRAIT de l'horloge (retour à l'heure réelle) : relâche todayISO ET invalide le cache", () => {
    simulatedToday = "2026-12-15";
    const { invalidate, view } = mount();
    expect(todayISO()).toBe("2026-12-15");
    invalidate.mockClear();

    simulatedToday = null;
    view.rerender();

    expect(todayISO()).toBe(toISODate(new Date()));
    expect(invalidate).toHaveBeenCalledTimes(1);
  });

  it("un vrai club (simulatedToday déjà null) n'invalide RIEN : la date effective ne bouge pas", () => {
    simulatedToday = null;
    const { invalidate } = mount();

    expect(todayISO()).toBe(toISODate(new Date()));
    expect(invalidate).not.toHaveBeenCalled();
  });

  // Bug capture prod : l'override est posé dans un effet APRÈS le premier rendu ; un consommateur
  // qui lit la date EN RENDU doit se recaler au premier rendu stable, sans aucun changement de
  // données react-query (/api/me identique). `useTodayISO` (store externe) rend ça réactif.
  it("un consommateur abonné via useTodayISO se recale sur la date serveur au premier rendu stable", async () => {
    simulatedToday = "2099-12-15";

    function Probe() {
      useApplySimulatedClock();

      return <span data-testid="today">{useTodayISO()}</span>;
    }

    const client = new QueryClient();
    const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
    render(<Probe />, { wrapper });

    await waitFor(() => expect(screen.getByTestId("today")).toHaveTextContent("2099-12-15"));
  });
});
