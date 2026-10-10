import { describe, expect, it } from "vitest";

import { railState } from "./railState";

/**
 * D1 (lot 2) — le rail du wizard est TRI-ÉTAT : vert « fait », orange « alertes non bloquantes »,
 * rouge « blocage ». Blocage ET alerte ⇒ ROUGE (le blocage prime). La règle vit en fonction pure
 * pour être falsifiée sans monter `WizardLayout` (qui n'a pas de test aujourd'hui).
 */
describe("railState — l'état tri-état d'une étape du rail", () => {
  it("rouge dès qu'il y a un blocage, même avec des alertes", () => {
    expect(railState({ errors: ["x"], warnings: [] }, false)).toBe("error");
    expect(railState({ errors: ["x"], warnings: ["w"] }, true)).toBe("error");
  });

  it("orange quand il n'y a que des alertes non bloquantes", () => {
    expect(railState({ errors: [], warnings: ["w"] }, false)).toBe("warning");
  });

  it("orange aussi sur une notice typée de ton avertissement (créneau partagé partiel)", () => {
    expect(railState({ errors: [], warnings: [], notices: [{ tone: "warning" }] }, true)).toBe("warning");
  });

  it("une notice d'INFORMATION grise ne vire PAS le rail à l'orange", () => {
    expect(railState({ errors: [], warnings: [], notices: [{ tone: "muted" }] }, true)).toBe("done");
    expect(railState({ errors: [], warnings: [], notices: [{ tone: "muted" }] }, false)).toBeUndefined();
  });

  it("vert quand l'étape est faite et sans alerte", () => {
    expect(railState({ errors: [], warnings: [] }, true)).toBe("done");
  });

  it("neutre (undefined) quand rien n'est fait et qu'il n'y a ni blocage ni alerte", () => {
    expect(railState({ errors: [], warnings: [] }, false)).toBeUndefined();
  });
});
