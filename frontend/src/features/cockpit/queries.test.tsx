import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { createConstraint } from "@/features/wizard/api";

import * as cockpitApi from "./api";
import { addDays, segmentsFromOffer, type WeekWindow } from "./lib/date";
import { useCreateCutoff, useCreateWeekChildren, usePlannedWindows, usePublicHolidays } from "./queries";

vi.mock("./api", () => ({
  getCalendarEntries: vi.fn(),
  getCalendarEntry: vi.fn(),
  getSchoolHolidays: vi.fn(),
  getPublicHolidays: vi.fn().mockResolvedValue({ zone: "A", items: [] }),
  getEntryConflicts: vi.fn(),
  getPlannedWindows: vi.fn().mockResolvedValue([]),
  createCalendarEntry: vi.fn().mockResolvedValue({ id: "e1" }),
  deleteCalendarEntry: vi.fn(),
}));
vi.mock("@/features/wizard/api", () => ({
  createConstraint: vi.fn(),
}));

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}

describe("cockpit queries — payload contracts", () => {
  beforeEach(() => {
    vi.mocked(cockpitApi.createCalendarEntry).mockClear();
    vi.mocked(cockpitApi.getPublicHolidays).mockClear();
    vi.mocked(createConstraint).mockClear();
  });

  it("useCreateCutoff posts a bare cutoff period — no dated constraint, ever", async () => {
    const { result } = renderHook(() => useCreateCutoff(), { wrapper });

    result.current.mutate({ title: "Coupure de Noël", startDate: "2026-12-21", endDate: "2026-12-27" });

    await waitFor(() =>
      expect(cockpitApi.createCalendarEntry).toHaveBeenCalledWith({
        kind: "period",
        periodType: "cutoff",
        title: "Coupure de Noël",
        startDate: "2026-12-21",
        endDate: "2026-12-27",
      }),
    );
    // Unlike a venue closure, a cutoff must NOT create a FACILITY constraint.
    expect(createConstraint).not.toHaveBeenCalled();
  });

  it("usePublicHolidays sends the explicit window (no-params call 400s without an active season)", async () => {
    renderHook(() => usePublicHolidays("2026-07-01", "2026-08-09"), { wrapper });

    await waitFor(() => expect(cockpitApi.getPublicHolidays).toHaveBeenCalledWith("2026-07-01", "2026-08-09"));
  });

  // P2-38 (prévention) — usePlannedWindows n'est ARMÉ que sur une ref (picker ouvert) : aucun fetch
  // sur les chemins sans modale. Il transmet la fenêtre + la ref (entryId) et déballe `windows`.
  it("usePlannedWindows : désactivé sans ref, armé sur une ref, déballe windows", async () => {
    vi.mocked(cockpitApi.getPlannedWindows).mockClear();
    vi.mocked(cockpitApi.getPlannedWindows).mockResolvedValue([
      { entryId: "conflit-1", title: "Reprise", startDate: "2026-11-16", endDate: "2026-11-22", label: "semaine du 16 nov.", reason: "Ces dates sont déjà planifiées par « Reprise »." },
    ]);

    // Ref nulle → requête désactivée, aucun appel réseau.
    const { result: off } = renderHook(() => usePlannedWindows(null), { wrapper });
    expect(off.current.fetchStatus).toBe("idle");
    expect(cockpitApi.getPlannedWindows).not.toHaveBeenCalled();

    // Ref entryId → armée : la fenêtre + entryId partent, `windows` est déballé.
    const { result } = renderHook(() => usePlannedWindows({ start: "2026-11-01", end: "2026-11-30", entryId: "e1" }), { wrapper });
    await waitFor(() => expect(result.current.data).toBeDefined());
    expect(cockpitApi.getPlannedWindows).toHaveBeenCalledWith({ start: "2026-11-01", end: "2026-11-30", entryId: "e1" });
    expect(result.current.data).toHaveLength(1);
    expect(result.current.data?.[0].reason).toBe("Ces dates sont déjà planifiées par « Reprise ».");
  });

  // P2-41 — un POST par SEGMENT coché : fenêtre = bornes du segment ; titre taille 1 inchangé
  // (« {mère} — semaine du {lundi} »), titre multi « {mère} — semaines du {X} au {Y} ».
  it("useCreateWeekChildren crée un enfant par segment, avec le bon titre selon sa taille", async () => {
    vi.mocked(cockpitApi.createCalendarEntry).mockResolvedValue({ id: "child" } as never);
    const wk = (monday: string): WeekWindow => ({ startDate: monday, endDate: addDays(monday, 6), monday });
    // Indispo mer 02/09 → dim 27/09 → [entame 31/08 (taille 1)] + [bloc 07/09→27/09 (3 semaines)].
    const segments = segmentsFromOffer([wk("2026-08-31"), wk("2026-09-07"), wk("2026-09-14"), wk("2026-09-21")], "2026-09-02", "2026-09-27");
    const mother = { id: "m1", title: "Barros fermé", periodType: "closure" } as never;

    const { result } = renderHook(() => useCreateWeekChildren(), { wrapper });
    result.current.mutate({ mother, segments });

    await waitFor(() => expect(cockpitApi.createCalendarEntry).toHaveBeenCalledTimes(2));
    expect(cockpitApi.createCalendarEntry).toHaveBeenNthCalledWith(1, {
      kind: "period",
      periodType: "closure",
      title: "Barros fermé — semaine du 31 août 2026",
      startDate: "2026-08-31",
      endDate: "2026-09-06",
      parentEntryId: "m1",
    });
    expect(cockpitApi.createCalendarEntry).toHaveBeenNthCalledWith(2, {
      kind: "period",
      periodType: "closure",
      title: "Barros fermé — semaines du 7 sept. 2026 au 27 sept. 2026",
      startDate: "2026-09-07",
      endDate: "2026-09-27",
      parentEntryId: "m1",
    });
  });
});
