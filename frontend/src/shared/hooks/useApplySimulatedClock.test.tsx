import { act, cleanup, render, renderHook, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { setTodayOverride, toISODate, todayISO, useTodayISO } from "@/shared/lib/clock";

import { useApplySimulatedClock } from "./useApplySimulatedClock";

// On pilote la date serveur du club via /api/me (seule source du cale d'horloge).
let simulatedToday: string | null = null;
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: { club: { simulatedToday } } }) }));

afterEach(() => {
  // Démonter AVANT de relâcher l'override : sinon le notify de setTodayOverride re-rend un
  // composant encore monté HORS act (avertissement « not wrapped in act », cliquet FRT-34).
  cleanup();
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

  // Bug capture prod : poser l'override APRÈS le premier rendu (ce que fait useApplySimulatedClock
  // dans son effet — couvert par les tests ci-dessus) ne recalait pas les consommateurs qui lisent
  // la date EN RENDU. `useTodayISO` (store externe) les abonne : on prouve ici qu'un changement
  // d'override les RE-REND. Falsifiable : sans le notify de setTodayOverride, la 2ᵉ assertion
  // reste sur la date réelle. (On pilote l'override DANS act() — le re-rendu du store externe doit
  // y être capturé, sinon un avertissement « not wrapped in act » fuirait, cliquet FRT-34.)
  it("un consommateur abonné via useTodayISO se re-rend quand l'override serveur change", () => {
    function Probe() {
      return <span data-testid="today">{useTodayISO()}</span>;
    }

    render(<Probe />);
    expect(screen.getByTestId("today")).toHaveTextContent(toISODate(new Date()));

    act(() => setTodayOverride("2099-12-15"));
    expect(screen.getByTestId("today")).toHaveTextContent("2099-12-15");
  });
});
