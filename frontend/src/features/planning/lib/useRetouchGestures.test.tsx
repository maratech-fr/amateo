import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, renderHook } from "@testing-library/react";
import type { ReactNode } from "react";
import { describe, expect, it } from "vitest";

import type { LockLevel, Slot } from "../api";
import { useRetouchGestures } from "./useRetouchGestures";

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
