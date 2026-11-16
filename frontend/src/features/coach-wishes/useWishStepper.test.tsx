import { act, renderHook } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { useWishStepper } from "./useWishStepper";

/**
 * Pur état du parcours en étapes (lot E, P2-24). Aucune donnée de section ici —
 * seulement l'ordre des écrans, les visites et la navigation. Testable sans DOM.
 */
describe("useWishStepper", () => {
  it("ordonne intro → une étape par équipe → récap (la validation) pour N équipes", () => {
    const { result } = renderHook(() => useWishStepper(["t1", "t2"]));
    expect(result.current.steps.map((s) => s.kind)).toEqual(["intro", "team", "team", "recap"]);
    expect(result.current.steps[1]).toMatchObject({ kind: "team", teamId: "t1", teamIndex: 0 });
    expect(result.current.steps[2]).toMatchObject({ kind: "team", teamId: "t2", teamIndex: 1 });
    expect(result.current.current.kind).toBe("intro");
  });

  it("1 équipe = 1 seule étape équipe", () => {
    const { result } = renderHook(() => useWishStepper(["solo"]));
    expect(result.current.steps.map((s) => s.kind)).toEqual(["intro", "team", "recap"]);
  });

  it("« Suivant » avance intro → équipes → récap et n'outrepasse pas le récap", () => {
    const { result } = renderHook(() => useWishStepper(["t1", "t2"]));
    act(() => result.current.next()); // team t1
    expect(result.current.current).toMatchObject({ kind: "team", teamId: "t1" });
    act(() => result.current.next()); // team t2
    expect(result.current.current).toMatchObject({ kind: "team", teamId: "t2" });
    act(() => result.current.next()); // recap
    expect(result.current.current.kind).toBe("recap");
    expect(result.current.isRecap).toBe(true);
    act(() => result.current.next()); // reste au récap
    expect(result.current.current.kind).toBe("recap");
  });

  it("« Précédent » recule d'une étape et n'outrepasse pas l'intro", () => {
    const { result } = renderHook(() => useWishStepper(["t1"]));
    act(() => result.current.next()); // team t1
    act(() => result.current.prev()); // intro
    expect(result.current.current.kind).toBe("intro");
    expect(result.current.isFirst).toBe(true);
    act(() => result.current.prev()); // reste à l'intro
    expect(result.current.current.kind).toBe("intro");
  });

  it("depuis le récap, « Modifier » saute à l'étape d'une équipe puis revient au récap (pas de re-traversée)", () => {
    const { result } = renderHook(() => useWishStepper(["t1", "t2"]));
    act(() => result.current.next()); // t1
    act(() => result.current.next()); // t2
    act(() => result.current.next()); // recap
    act(() => result.current.editTeam("t1"));
    expect(result.current.current).toMatchObject({ kind: "team", teamId: "t1" });
    expect(result.current.returningToRecap).toBe(true);
    // « Suivant » ne repart PAS vers t2 : il revient droit au récap.
    act(() => result.current.next());
    expect(result.current.current.kind).toBe("recap");
    expect(result.current.returningToRecap).toBe(false);
  });

  it("restaure l'étape courante et marque visitées les étapes 0..index (brouillon)", () => {
    const { result } = renderHook(() => useWishStepper(["t1", "t2"], 2)); // t2
    expect(result.current.current).toMatchObject({ kind: "team", teamId: "t2" });
    expect(result.current.canGoTo(0)).toBe(true);
    expect(result.current.canGoTo(1)).toBe(true);
    expect(result.current.canGoTo(2)).toBe(true);
    expect(result.current.canGoTo(3)).toBe(false); // récap pas encore atteint
  });

  it("les étapes visitées sont cliquables, les non-visitées non (un saut vers une non-visitée est inerte)", () => {
    const { result } = renderHook(() => useWishStepper(["t1", "t2"]));
    // Au départ, seule l'intro (index 0) est visitée.
    expect(result.current.canGoTo(0)).toBe(true);
    expect(result.current.canGoTo(1)).toBe(false);
    expect(result.current.canGoTo(3)).toBe(false); // récap

    // Sauter vers une étape non visitée ne bouge rien (falsification).
    act(() => result.current.goTo(3));
    expect(result.current.current.kind).toBe("intro");

    // On visite t1 puis t2, puis on revient à l'intro par un saut arrière autorisé.
    act(() => result.current.next()); // t1
    act(() => result.current.next()); // t2
    expect(result.current.canGoTo(1)).toBe(true);
    act(() => result.current.goTo(1)); // retour t1 (visitée)
    expect(result.current.current).toMatchObject({ kind: "team", teamId: "t1" });
    // Le récap reste non visité → toujours interdit.
    expect(result.current.canGoTo(3)).toBe(false);
    act(() => result.current.goTo(3));
    expect(result.current.current).toMatchObject({ kind: "team", teamId: "t1" });
  });
});
