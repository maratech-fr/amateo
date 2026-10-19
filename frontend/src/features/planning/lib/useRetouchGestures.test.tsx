import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, renderHook, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { toast } from "@/shared/stores/toastStore";

import * as planningApi from "../api";
import { MoveRejectedError } from "../api";
import type { EvictedSlot, LockLevel, Slot } from "../api";
import { useRetouchGestures } from "./useRetouchGestures";

// P4-306 — on MUTE la PROD (le vrai hook, ses vrais hooks de mutation), on ne mocke QUE les deux
// fonctions RÉSEAU que ces tests exercent (`moveSlot`/`placeSlot`), en gardant les VRAIES classes
// d'erreur : `MoveRejectedError` est consommée en `instanceof` par le `onError` de NIVEAU HOOK de
// `queries.ts` (patron `queries.test.tsx`). Les autres fonctions `../api` restent réelles — aucun
// test ne les appelle (le menu de dépôt ne touche pas le réseau).
vi.mock("../api", async (importOriginal) => {
  const actual = await importOriginal<typeof import("../api")>();
  return { ...actual, moveSlot: vi.fn(), placeSlot: vi.fn() };
});

/**
 * Lot 2 — le MENU À 3 CHOIX du dépôt. On n'exerce QUE la logique du hook (aucun réseau : ouvrir le
 * menu et calculer ses motifs de désactivation d'IDENTITÉ ne touche pas l'API). On prouve que déposer
 * sur une case occupée ouvre le menu, que l'occupant est nommé (équipe ou groupe), et que chaque choix
 * impossible porte le BON motif — l'affordance que le composant rend en `aria-disabled`.
 */

const V1 = "venue-1";
const V2 = "venue-2";

function slot(over: Partial<Slot> & { id: string; teamId: string }): Slot {
  return {
    scheduleId: "sched-1",
    venueId: V1,
    coachId: null,
    dayOfWeek: 2,
    startTime: "18:00",
    durationMinutes: 90,
    lockLevel: "NONE" as LockLevel,
    lockOrigin: null,
    sharedTrainingBlockId: null,
    ...over,
  };
}

function renderGestures(slots: Slot[], selectedSlotId: string) {
  const wrapper = ({ children }: { children: ReactNode }) => <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>{children}</QueryClientProvider>;
  return renderHook(
    () => useRetouchGestures(slots, "sched-1", selectedSlotId, "gymnase", false, false, true, new Map<string, string>(), [], [], (id) => `T-${id}`, () => {}, () => {}, () => {}),
    { wrapper },
  );
}

describe("useRetouchGestures — menu de dépôt (lot 2)", () => {
  it("déposer une équipe sur une case occupée par une AUTRE équipe ouvre le menu, TOUS les choix actifs", () => {
    const source = slot({ id: "src", teamId: "a" });
    const occupant = slot({ id: "occ", teamId: "b", venueId: V2, dayOfWeek: 4, startTime: "20:00" });
    const { result } = renderGestures([source, occupant], "src");

    act(() => result.current.armMove("src"));
    act(() => result.current.onPickTarget("occ"));

    expect(result.current.dropMenu).not.toBeNull();
    expect(result.current.dropMenu?.occupantName).toBe("T-b");
    expect(result.current.dropMenu?.teamCount).toBe(2);
    expect(result.current.dropMenu?.disabled).toEqual({ takePlace: undefined, swap: undefined, mutualize: undefined });

    // « Mutualiser » ouvre la modale rejoindre/fusion (pas de réseau).
    act(() => result.current.chooseMutualize());
    expect(result.current.dropMenu).toBeNull();
    expect(result.current.mutualizeAtCaseDialog?.teamCount).toBe(2);
  });

  it("un occupant ÉQUIPE verrouillé à la main désactive « Prendre sa place » ET « Échanger » (verrou souverain)", () => {
    const source = slot({ id: "src", teamId: "a" });
    const occupant = slot({ id: "occ", teamId: "b", venueId: V2, dayOfWeek: 4, startTime: "20:00", lockLevel: "HARD" as LockLevel });
    const { result } = renderGestures([source, occupant], "src");

    act(() => result.current.armMove("src"));
    act(() => result.current.onPickTarget("occ"));

    const reason = "Ce créneau est verrouillé — déverrouillez-le d'abord.";
    expect(result.current.dropMenu?.disabled.takePlace).toBe(reason);
    expect(result.current.dropMenu?.disabled.swap).toBe(reason);
    // Mutualiser reste possible (le verrou gestionnaire n'empêche pas de proposer — le serveur refusera).
    expect(result.current.dropMenu?.disabled.mutualize).toBeUndefined();
  });

  it("une équipe DÉJÀ membre du groupe cible désactive « Mutualiser » (ensembles non disjoints)", () => {
    // La case cible est un GROUPE {a, b} (deux séances HARD liées au même bloc) ; la source porte « a ».
    const occA = slot({ id: "occa", teamId: "a", venueId: V2, dayOfWeek: 4, startTime: "20:00", lockLevel: "HARD" as LockLevel, sharedTrainingBlockId: "blk-O" });
    const occB = slot({ id: "occb", teamId: "b", venueId: V2, dayOfWeek: 4, startTime: "20:00", lockLevel: "HARD" as LockLevel, sharedTrainingBlockId: "blk-O" });
    const source = slot({ id: "src", teamId: "a" });
    const { result } = renderGestures([source, occA, occB], "src");

    act(() => result.current.armMove("src"));
    act(() => result.current.onPickTarget("occa"));

    expect(result.current.dropMenu?.occupantName).toBe("le groupe (2 équipes)");
    expect(result.current.dropMenu?.disabled.mutualize).toBe("Une équipe est déjà présente des deux côtés.");
    // Un occupant GROUPE (verrou d'ancrage) reste évinçable/échangeable (Q2) — pas désactivé sur le verrou.
    expect(result.current.dropMenu?.disabled.takePlace).toBeUndefined();
    expect(result.current.dropMenu?.disabled.swap).toBeUndefined();
  });

  it("déposer un GROUPE sur un GROUPE désactive « Prendre sa place » (moveGroup n'évince pas un groupe)", () => {
    const srcMember = slot({ id: "s1", teamId: "a", sharedTrainingBlockId: "blk-S", lockLevel: "HARD" as LockLevel });
    const occMember = slot({ id: "o1", teamId: "b", venueId: V2, dayOfWeek: 4, startTime: "20:00", sharedTrainingBlockId: "blk-O", lockLevel: "HARD" as LockLevel });
    const { result } = renderGestures([srcMember, occMember], "s1");

    act(() => result.current.armMoveGroup("s1", "blk-S", { venueId: V1, dayOfWeek: 2, startTime: "18:00" }));
    act(() => result.current.onPickTarget("o1"));

    expect(result.current.dropMenu?.disabled.takePlace).toBe("Pour deux groupes, utilisez Échanger ou Mutualiser.");
    expect(result.current.dropMenu?.disabled.swap).toBeUndefined();
    expect(result.current.dropMenu?.disabled.mutualize).toBeUndefined();
  });
});

/**
 * P4-306 — le toast d'erreur d'un geste d'annulation / de raccourci vit UNE SEULE FOIS. Les mutations
 * `move`/`place` toastent déjà un vrai TRANSPORT au NIVEAU HOOK (`queries.ts::onSlotEditError`, qui
 * TAIT le métier). Les 3 sites `mutate()` de ce hook (annulation simple, replacement imbriqué de
 * l'undo, raccourci « Remettre ») toastent AUSSI sur transport → DOUBLE toast. Promesse protégée :
 *   • TRANSPORT → EXACTEMENT 1 toast (aujourd'hui 2) + les états éphémères (undo / evictionNotice)
 *     sont remis à null INCONDITIONNELLEMENT ;
 *   • MÉTIER → le toast CONTEXTUEL (noms d'équipes, message de la violation) SURVIT, seul (1 toast).
 * On MUTE la PROD : l'API est mockée EN REJET (jamais le hook), et on compte `toast.error`. Le toast
 * du HOOK est posé de façon ASYNCHRONE (`errorMessage(...).then`) — on vide la file de tâches avant
 * de compter, pour que les DEUX toasts (site + hook) soient comptés en RED sans course.
 */
describe("useRetouchGestures — transport double toast (P4-306)", () => {
  const V = { venue1: V1, venue2: V2 };
  const EVICTED: EvictedSlot = { slotId: "occ", teamId: "b", dayOfWeek: 4, startTime: "20:00", venueId: V.venue2, durationMinutes: 90 };

  function renderFull(slots: Slot[], gridSlots: Slot[], selectedSlotId: string | null) {
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>{children}</QueryClientProvider>
    );
    return renderHook(
      () => useRetouchGestures(slots, "sched-1", selectedSlotId, "gymnase", false, false, true, new Map<string, string>(), gridSlots, [], (id) => `T-${id}`, () => {}, () => {}, () => {}),
      { wrapper },
    );
  }

  // Le toast du hook est posé APRÈS la micro-tâche `errorMessage(...).then` ; un macro-tour de boucle
  // garantit qu'il a bien été compté (sinon le RED verrait parfois 1, course avec l'async du hook).
  const flushToasts = async () => {
    await act(async () => {
      await new Promise((r) => setTimeout(r, 0));
    });
  };

  beforeEach(() => {
    vi.mocked(planningApi.moveSlot).mockReset();
    vi.mocked(planningApi.placeSlot).mockReset();
  });

  it("annulation (runUndo simple) : une panne TRANSPORT ne toaste qu'UNE fois et remet `undo` à null", async () => {
    const source = slot({ id: "src", teamId: "a" });
    const emptyWin = slot({ id: "empty:w1", teamId: "", venueId: V.venue2, dayOfWeek: 4, startTime: "20:00" });
    // Déplacement réel ACCEPTÉ → arme l'undo simple (seule porte : aucun `setUndo` exposé).
    vi.mocked(planningApi.moveSlot).mockResolvedValue({ valid: true } as never);
    const { result } = renderFull([source], [emptyWin], "src");

    act(() => result.current.armMove("src"));
    act(() => result.current.onPickTarget("empty:w1"));
    await waitFor(() => expect(result.current.undo?.kind).toBe("move"));

    // L'annulation (move inverse) tombe en TRANSPORT (Error réseau nue → `errorMessage` → transport).
    vi.mocked(planningApi.moveSlot).mockRejectedValueOnce(new Error("network down"));
    const errorSpy = vi.spyOn(toast, "error");
    errorSpy.mockClear();
    act(() => result.current.runUndo());
    await waitFor(() => expect(result.current.moveMutation.isError).toBe(true));
    await flushToasts();

    // Aujourd'hui 2 (site « Annulation impossible » + hook transport) ; promesse = 1.
    expect(errorSpy).toHaveBeenCalledTimes(1);
    // Reset inconditionnel même sur transport (le site ne le faisait pas).
    expect(result.current.undo).toBeNull();
  });

  it("annulation avec éviction (placement imbriqué de l'undo) : un échec TRANSPORT du replacement ne toaste qu'UNE fois et purge undo + evictionNotice", async () => {
    const source = slot({ id: "src", teamId: "a" });
    const occupant = slot({ id: "occ", teamId: "b", venueId: V.venue2, dayOfWeek: 4, startTime: "20:00" });
    // Un déplacement avec éviction ACCEPTÉ arme l'undo `move-evict` (via la modale acceptée + confirm).
    vi.mocked(planningApi.moveSlot).mockResolvedValue({ valid: true, evicted: EVICTED } as never);
    const { result } = renderFull([source, occupant], [], "src");
    act(() => result.current.setEvictDialog({ phase: "accepted", sourceSlotId: "src", targetSlot: occupant, compromises: [] }));
    act(() => result.current.confirmEvict());
    await waitFor(() => expect(result.current.undo?.kind).toBe("move-evict"));
    expect(result.current.evictionNotice).not.toBeNull();

    // runUndo : le move inverse RÉUSSIT, puis le replacement de l'évincée tombe en TRANSPORT.
    vi.mocked(planningApi.moveSlot).mockResolvedValue({ valid: true } as never);
    vi.mocked(planningApi.placeSlot).mockRejectedValueOnce(new Error("network down"));
    const errorSpy = vi.spyOn(toast, "error");
    errorSpy.mockClear();
    act(() => result.current.runUndo());
    await waitFor(() => expect(result.current.placeMutation.isError).toBe(true));
    await flushToasts();

    // Aujourd'hui 2 (site contextuel + hook transport) ; promesse = 1.
    expect(errorSpy).toHaveBeenCalledTimes(1);
    expect(result.current.undo).toBeNull();
    expect(result.current.evictionNotice).toBeNull();
  });

  it("annulation avec éviction : un refus MÉTIER du replacement laisse SURVIVRE le toast contextuel (1 toast, nommé)", async () => {
    const source = slot({ id: "src", teamId: "a" });
    const occupant = slot({ id: "occ", teamId: "b", venueId: V.venue2, dayOfWeek: 4, startTime: "20:00" });
    vi.mocked(planningApi.moveSlot).mockResolvedValue({ valid: true, evicted: EVICTED } as never);
    const { result } = renderFull([source, occupant], [], "src");
    act(() => result.current.setEvictDialog({ phase: "accepted", sourceSlotId: "src", targetSlot: occupant, compromises: [] }));
    act(() => result.current.confirmEvict());
    await waitFor(() => expect(result.current.undo?.kind).toBe("move-evict"));

    // Le replacement est REFUSÉ (métier) : le hook TAIT, seul le toast CONTEXTUEL du site survit.
    vi.mocked(planningApi.moveSlot).mockResolvedValue({ valid: true } as never);
    vi.mocked(planningApi.placeSlot).mockRejectedValueOnce(new MoveRejectedError([{ rule: "capacity", message: "refusé" }]));
    const errorSpy = vi.spyOn(toast, "error");
    errorSpy.mockClear();
    act(() => result.current.runUndo());
    await waitFor(() => expect(result.current.placeMutation.isError).toBe(true));
    await flushToasts();

    expect(errorSpy).toHaveBeenCalledTimes(1);
    expect(errorSpy).toHaveBeenCalledWith("T-a est revenue, T-b reste à replacer.");
  });

  it("raccourci « Remettre » (placeEvictedShortcut) : une panne TRANSPORT ne toaste qu'UNE fois et purge evictionNotice", async () => {
    const source = slot({ id: "src", teamId: "a" });
    const { result } = renderFull([source], [], "src");
    act(() => result.current.setEvictionNotice({ evicted: EVICTED, freed: { dayOfWeek: 4, startTime: "20:00", venueId: V.venue2 } }));

    vi.mocked(planningApi.placeSlot).mockRejectedValueOnce(new Error("network down"));
    const errorSpy = vi.spyOn(toast, "error");
    errorSpy.mockClear();
    act(() => result.current.placeEvictedShortcut());
    await waitFor(() => expect(result.current.placeMutation.isError).toBe(true));
    await flushToasts();

    // Aujourd'hui 2 (site « Replacement impossible » + hook transport) ; promesse = 1.
    expect(errorSpy).toHaveBeenCalledTimes(1);
    // Reset inconditionnel même sur transport (le site ne le faisait pas).
    expect(result.current.evictionNotice).toBeNull();
    expect(result.current.undo).toBeNull();
  });

  it("raccourci « Remettre » : un refus MÉTIER laisse SURVIVRE le message de la violation (1 toast, nommé)", async () => {
    const source = slot({ id: "src", teamId: "a" });
    const { result } = renderFull([source], [], "src");
    act(() => result.current.setEvictionNotice({ evicted: EVICTED, freed: { dayOfWeek: 4, startTime: "20:00", venueId: V.venue2 } }));

    vi.mocked(planningApi.placeSlot).mockRejectedValueOnce(new MoveRejectedError([{ rule: "capacity", message: "Case déjà occupée." }]));
    const errorSpy = vi.spyOn(toast, "error");
    errorSpy.mockClear();
    act(() => result.current.placeEvictedShortcut());
    await waitFor(() => expect(result.current.placeMutation.isError).toBe(true));
    await flushToasts();

    expect(errorSpy).toHaveBeenCalledTimes(1);
    expect(errorSpy).toHaveBeenCalledWith("Case déjà occupée.");
  });
});
