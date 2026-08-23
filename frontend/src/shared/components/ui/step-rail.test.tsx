import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { StepRail, type StepRailStep } from "./step-rail";

// Ce que les suites du wizard ne couvrent PAS : le composant NU, ses états passés
// CALCULÉS. Chaque assertion est falsifiable en retirant sa branche du composant.
const steps: StepRailStep[] = [
  { id: "a", label: "Équipes" },
  { id: "b", label: "Gymnases", done: true },
  { id: "c", label: "Coachs", locked: true },
  { id: "d", label: "Génération", done: true, locked: true },
];

describe("StepRail", () => {
  it("done ET locked simultanés → pastille ✓, cadenas ET bouton disabled", () => {
    render(<StepRail steps={steps} currentId="a" onSelect={vi.fn()} />);
    // Le nom accessible enrichi (done) identifie l'entrée d/d.
    const both = screen.getByRole("button", { name: "Génération — étape terminée" });
    expect(both).toBeDisabled();
    // ✓ présent (le numéro « 4 » est remplacé par la coche) ET cadenas rendu.
    expect(both.textContent).not.toContain("4");
    expect(both.querySelectorAll("svg")).toHaveLength(2); // Check + Lock
  });

  it("aria-current=\"step\" ne marque QUE la courante", () => {
    render(<StepRail steps={steps} currentId="b" onSelect={vi.fn()} />);
    const current = screen.getByRole("button", { name: "Gymnases — étape terminée" });
    expect(current).toHaveAttribute("aria-current", "step");
    // Les autres ne le portent pas.
    expect(screen.getByRole("button", { name: /Équipes/ })).not.toHaveAttribute("aria-current");
    expect(screen.getByRole("button", { name: /Coachs/ })).not.toHaveAttribute("aria-current");
  });

  it("nom accessible : contient le label visible, « — étape terminée » ajouté quand done", () => {
    render(<StepRail steps={steps} currentId="a" onSelect={vi.fn()} />);
    // Non-done : le nom EST le label (via le contenu texte, pas d'aria-label).
    const plain = screen.getByRole("button", { name: /Équipes/ });
    expect(plain).not.toHaveAttribute("aria-label");
    // Done : le nom accessible CONTIENT le label visible + le suffixe (WCAG 2.5.3).
    const done = screen.getByRole("button", { name: "Gymnases — étape terminée" });
    expect(done).toHaveAttribute("aria-label", "Gymnases — étape terminée");
  });

  it("locked → disabled et onSelect JAMAIS appelé au clic", async () => {
    const onSelect = vi.fn();
    const user = userEvent.setup();
    render(<StepRail steps={steps} currentId="a" onSelect={onSelect} />);
    const locked = screen.getByRole("button", { name: /Coachs/ });
    expect(locked).toBeDisabled();
    await user.click(locked);
    expect(onSelect).not.toHaveBeenCalled();
  });

  it("clic sur étape libre → onSelect(id) appelé UNE fois avec l'id", async () => {
    const onSelect = vi.fn();
    const user = userEvent.setup();
    render(<StepRail steps={steps} currentId="a" onSelect={onSelect} />);
    await user.click(screen.getByRole("button", { name: /Équipes/ }));
    expect(onSelect).toHaveBeenCalledTimes(1);
    expect(onSelect).toHaveBeenCalledWith("a");
  });

  it("numérotation i+1 quand non-done, dérivée de la position", () => {
    render(<StepRail steps={steps} currentId="a" onSelect={vi.fn()} />);
    // a (position 0) non-done → « 1 » ; c (position 2) non-done → « 3 ».
    expect(screen.getByRole("button", { name: /Équipes/ }).textContent).toContain("1");
    expect(screen.getByRole("button", { name: /Coachs/ }).textContent).toContain("3");
  });
});
