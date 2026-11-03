import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import { HTTPError } from "ky";
import type { ReactNode } from "react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { api } from "@/shared/api/client";
import { toast } from "@/shared/stores/toastStore";

import type { CalendarEntry } from "./api";
import { asWindowAlreadyPlanned, createSchedulePlan, deleteCalendarEntry, updateCalendarEntry, WindowAlreadyPlannedError } from "./api";
import { useCreatePeriodPlan, useCreateVenueClosure, useDeleteEntry, useRedateEntry } from "./queries";
import { COCKPIT_TEXTS } from "./texts";

// On teste la PROD : le vrai `createSchedulePlan` / les vrais hooks contre le client ky mocké.
vi.mock("@/shared/api/client", () => ({ api: { post: vi.fn(), put: vi.fn(), get: vi.fn(), delete: vi.fn() } }));
vi.mock("@/shared/stores/toastStore", () => ({ toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

const mockPost = vi.mocked(api.post);
const mockPut = vi.mocked(api.put);

function httpError(status: number, data: unknown): HTTPError {
  const response = new Response(JSON.stringify(data), { status });
  const request = new Request("http://localhost/api/schedule_plans");
  const error = new HTTPError(response, request, {} as never);
  // ky 2.x expose le corps d'erreur parsé sur error.data (re-lire response throw).
  (error as unknown as { data: unknown }).data = data;
  return error;
}

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}

afterEach(() => {
  mockPost.mockReset();
  mockPut.mockReset();
  vi.mocked(toast.error).mockClear();
});

const closureEntry = (over: Partial<CalendarEntry> = {}): CalendarEntry => ({
  id: "inc",
  kind: "period",
  title: "Gymnase Matéo indisponible",
  startDate: "2026-05-12",
  endDate: "2026-06-16",
  isDisruptive: false,
  periodType: "closure",
  schoolHolidayId: null,
  parentEntryId: null,
  status: "active",
  createdBy: null,
  redatable: true, redateNeedsPreview: false,
  ...over,
});

describe("asWindowAlreadyPlanned — lecture du 409 structuré (P2-38)", () => {
  it("traduit un 409 window_already_planned en erreur typée PORTANT le message serveur + l'entryId", () => {
    const err = httpError(409, { code: "window_already_planned", error: "Ces dates sont déjà planifiées par « X ».", entryId: "e-conflit" });
    const parsed = asWindowAlreadyPlanned(err);
    expect(parsed).toBeInstanceOf(WindowAlreadyPlannedError);
    expect(parsed?.message).toContain("déjà planifiées");
    expect(parsed?.conflictingEntryId).toBe("e-conflit");
  });

  it("laisse passer les autres échecs (autre 409, 500) → null", () => {
    expect(asWindowAlreadyPlanned(httpError(409, { code: "overlays_exist", count: 2 }))).toBeNull();
    expect(asWindowAlreadyPlanned(httpError(500, {}))).toBeNull();
    expect(asWindowAlreadyPlanned(new Error("network"))).toBeNull();
  });
});

describe("createSchedulePlan — le geste « Adapter » sous la garde (P2-38)", () => {
  it("lève WindowAlreadyPlannedError sur un 409 window_already_planned", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(409, { code: "window_already_planned", error: "déjà planifié", entryId: "e-conflit" })) } as never);
    await expect(createSchedulePlan("entry-x")).rejects.toBeInstanceOf(WindowAlreadyPlannedError);
  });

  it("relaie tout autre échec inchangé (pas d'avalage)", async () => {
    const boom = httpError(500, {});
    mockPost.mockReturnValue({ json: () => Promise.reject(boom) } as never);
    await expect(createSchedulePlan("entry-x")).rejects.toBe(boom);
  });
});

describe("useCreatePeriodPlan — le hook POSSÈDE son feedback (P2-38)", () => {
  it("TAIT le refus de chevauchement (le dialogue l'affiche) — aucun toast générique", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(409, { code: "window_already_planned", error: "déjà planifié", entryId: "e-conflit" })) } as never);
    const { result } = renderHook(() => useCreatePeriodPlan(), { wrapper });

    result.current.mutate("entry-x");

    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(result.current.error).toBeInstanceOf(WindowAlreadyPlannedError);
    expect(toast.error).not.toHaveBeenCalled();
  });

  it("REMPLACE le filet global sur une vraie erreur transport (toast du motif)", async () => {
    mockPost.mockReturnValue({ json: () => Promise.reject(httpError(500, {})) } as never);
    const { result } = renderHook(() => useCreatePeriodPlan(), { wrapper });

    result.current.mutate("entry-x");

    await waitFor(() => expect(toast.error).toHaveBeenCalledTimes(1));
  });
});

describe("updateCalendarEntry — re-datage d'une fermeture (PUT, D3 v1 PR-2)", () => {
  it("envoie un corps MINIMAL mais complet (kind/title servis inchangés + nouvelles dates)", async () => {
    mockPut.mockReturnValue({ json: () => Promise.resolve(closureEntry({ endDate: "2026-06-20" })) } as never);

    await updateCalendarEntry(closureEntry(), { startDate: "2026-05-12", endDate: "2026-06-20" });

    expect(mockPut).toHaveBeenCalledWith("calendar_entries/inc", {
      json: { kind: "period", periodType: "closure", title: "Gymnase Matéo indisponible", startDate: "2026-05-12", endDate: "2026-06-20" },
    });
  });

  it("traduit un 409 window_already_planned en erreur typée (comme « Adapter »)", async () => {
    mockPut.mockReturnValue({ json: () => Promise.reject(httpError(409, { code: "window_already_planned", error: "déjà planifié", entryId: "e-conflit" })) } as never);
    await expect(updateCalendarEntry(closureEntry(), { startDate: "2026-05-12", endDate: "2026-06-20" })).rejects.toBeInstanceOf(WindowAlreadyPlannedError);
  });

  it("relaie tout autre échec inchangé (422 hors saison, 500) — le serveur reste le juge", async () => {
    const boom = httpError(422, { detail: "La fenêtre sort de la saison." });
    mockPut.mockReturnValue({ json: () => Promise.reject(boom) } as never);
    await expect(updateCalendarEntry(closureEntry(), { startDate: "2026-05-12", endDate: "2027-09-01" })).rejects.toBe(boom);
  });
});

/**
 * Fermetures PR 1, correction E2a — « on ne retire rien sans l'avoir annoncé » : un re-datage qui
 * retirerait des verrous ou des réservations est refusé en 409 `closure_pins_preview_required` avec
 * l'annonce détaillée (`pinRemoval`, gardé côté serveur par
 * `ClosureRemovesPinsAtomicallyTest::testRedatingAClosureThatWouldRemovePinsNeedsTheAnnouncementFirst`).
 * Le client le traduit en erreur TYPÉE portant l'annonce, le hook la TAIT (l'écran l'affiche avec
 * son bouton de confirmation), et la confirmation renvoie le PUT avec `confirmPinRemoval: true`.
 */
describe("re-datage qui retire des épingles — annonce puis confirmation (E2a)", () => {
  const pinRemoval = {
    venueName: "Mistral",
    plans: [{ entryId: "mars", title: "Vacances de mars", locks: 2, reservations: [{ teamId: "t-sm1", teamName: "SM1", dayOfWeek: 2 }] }],
  };
  const refusal = { code: "closure_pins_preview_required", error: "Ce re-datage retire des séances verrouillées ou des réservations : confirmez après avoir lu l’annonce.", toRemove: { locks: 2, reservations: 1 }, pinRemoval };

  it("traduit le 409 closure_pins_preview_required en erreur typée PORTANT l'annonce servie", async () => {
    mockPut.mockReturnValue({ json: () => Promise.reject(httpError(409, refusal)) } as never);
    const { ClosurePinsPreviewRequiredError } = await import("./api");

    const failure = updateCalendarEntry(closureEntry(), { startDate: "2026-05-12", endDate: "2026-06-20" });

    await expect(failure).rejects.toBeInstanceOf(ClosurePinsPreviewRequiredError);
    await expect(failure).rejects.toMatchObject({ pinRemoval });
  });

  it("la confirmation renvoie le PUT avec confirmPinRemoval: true (et seulement elle)", async () => {
    mockPut.mockReturnValue({ json: () => Promise.resolve(closureEntry({ endDate: "2026-06-20" })) } as never);

    await updateCalendarEntry(closureEntry(), { startDate: "2026-05-12", endDate: "2026-06-20", confirmPinRemoval: true } as never);

    expect(mockPut).toHaveBeenCalledWith("calendar_entries/inc", {
      json: expect.objectContaining({ startDate: "2026-05-12", endDate: "2026-06-20", confirmPinRemoval: true }) as unknown,
    });
  });

  it("le hook TAIT ce refus (l'écran l'affiche avec son bouton) et relaie confirmPinRemoval", async () => {
    mockPut.mockReturnValueOnce({ json: () => Promise.reject(httpError(409, refusal)) } as never);
    const { ClosurePinsPreviewRequiredError } = await import("./api");
    const { result } = renderHook(() => useRedateEntry(), { wrapper });

    result.current.mutate({ entry: closureEntry(), startDate: "2026-05-12", endDate: "2026-06-20" });
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(result.current.error).toBeInstanceOf(ClosurePinsPreviewRequiredError);
    expect(toast.error).not.toHaveBeenCalled();

    mockPut.mockReturnValueOnce({ json: () => Promise.resolve(closureEntry({ endDate: "2026-06-20" })) } as never);
    result.current.mutate({ entry: closureEntry(), startDate: "2026-05-12", endDate: "2026-06-20", confirmPinRemoval: true } as never);
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(mockPut).toHaveBeenLastCalledWith("calendar_entries/inc", { json: expect.objectContaining({ confirmPinRemoval: true }) as unknown });
  });
});

describe("useRedateEntry — le hook POSSÈDE son feedback (D3 v1 PR-2)", () => {
  it("TAIT le refus de chevauchement (le mode re-datage l'affiche) — aucun toast générique", async () => {
    mockPut.mockReturnValue({ json: () => Promise.reject(httpError(409, { code: "window_already_planned", error: "déjà planifié", entryId: "e-conflit" })) } as never);
    const { result } = renderHook(() => useRedateEntry(), { wrapper });

    result.current.mutate({ entry: closureEntry(), startDate: "2026-05-12", endDate: "2026-06-20" });

    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(result.current.error).toBeInstanceOf(WindowAlreadyPlannedError);
    expect(toast.error).not.toHaveBeenCalled();
  });

  it("REMPLACE le filet global sur une vraie erreur (422 hors saison → message serveur affiché)", async () => {
    mockPut.mockReturnValue({ json: () => Promise.reject(httpError(422, { detail: "La fenêtre sort de la saison." })) } as never);
    const { result } = renderHook(() => useRedateEntry(), { wrapper });

    result.current.mutate({ entry: closureEntry(), startDate: "2026-05-12", endDate: "2027-09-01" });

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith("La fenêtre sort de la saison."));
  });
});

/**
 * Défaut relevé par SWITCH (captures `fermetures/03-form-09-generation-en-cours-message-faux.png`) :
 * `DELETE /api/calendar_entries/{id}` répond 204 SANS corps, et ky 2 fait `JSON.parse('')` sur
 * `.json()` (`node_modules/ky/distribution/core/Ky.js:228`) → il LÈVE. Une suppression RÉUSSIE passe
 * donc pour un échec : le rollback d'une fermeture refusée (409 génération en cours) annonce
 * « l'annulation a échoué », et `useDeleteEntry` toaste une erreur sur un succès.
 *
 * Le double de ky reproduit EXACTEMENT ce comportement : une `Response` 204 vide, dont `.json()`
 * relit le texte et le parse (donc lève) — jamais un `.json()` truqué.
 */
function kyResponse(status: number, body: string | null) {
  const response = new Response(body, { status });
  return Object.assign(Promise.resolve(response), {
    json: async () => JSON.parse(await response.clone().text()) as unknown,
  });
}

describe("une suppression réussie (204 sans corps) n'est jamais un échec", () => {
  const mockDelete = vi.mocked(api.delete);

  afterEach(() => {
    mockDelete.mockReset();
  });

  it("deleteCalendarEntry se résout sur un 204 sans corps", async () => {
    mockDelete.mockReturnValue(kyResponse(204, null) as never);

    await deleteCalendarEntry("e1"); // lève aujourd'hui (SyntaxError du JSON.parse('')) → test rouge
    expect(mockDelete).toHaveBeenCalledWith("calendar_entries/e1");
  });

  it("useDeleteEntry : un 204 est un succès, sans toast d'erreur", async () => {
    mockDelete.mockReturnValue(kyResponse(204, null) as never);
    const { result } = renderHook(() => useDeleteEntry(), { wrapper });

    result.current.mutate("e1");

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(toast.error).not.toHaveBeenCalled();
  });

  it("fermeture refusée pendant une génération : le rollback (204) réussit et le toast dit la génération en cours, pas un faux échec d'annulation", async () => {
    mockPost.mockImplementation(((path: string) =>
      "calendar_entries" === path
        ? { json: () => Promise.resolve(closureEntry({ id: "e-new", redatable: false })) }
        : { json: () => Promise.reject(httpError(409, { code: "generation_in_progress", error: COCKPIT_TEXTS.generationInProgress })) }) as never);
    mockDelete.mockReturnValue(kyResponse(204, null) as never);
    const { result } = renderHook(() => useCreateVenueClosure(), { wrapper });

    result.current.mutate({ title: "Mistral — fermé", startDate: "2999-01-05", endDate: "2999-01-09", venueId: "v-mistral" });

    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(mockDelete).toHaveBeenCalledWith("calendar_entries/e-new");
    await waitFor(() => expect(toast.error).toHaveBeenCalledWith(COCKPIT_TEXTS.generationInProgress));
    expect(vi.mocked(toast.error).mock.calls.flat().join(" ")).not.toMatch(/annulation a échoué/);
  });
});
