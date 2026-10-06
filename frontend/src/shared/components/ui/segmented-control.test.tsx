import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { SegmentedControl } from "./segmented-control";

type Axis = "equipe" | "coach" | "gymnase";
const OPTIONS: { key: Axis; label: string }[] = [
  { key: "equipe", label: "Par équipe" },
  { key: "coach", label: "Par coach" },
  { key: "gymnase", label: "Par gymnase" },
];

describe("SegmentedControl", () => {
  it("mode single : groupe nommé, un seul segment aria-pressed, change de valeur au clic", async () => {
    const user = userEvent.setup();
    const onValueChange = vi.fn();
    render(<SegmentedControl mode="single" label="Filtrer par" options={OPTIONS} value="equipe" onValueChange={onValueChange} />);

    expect(screen.getByRole("group", { name: "Filtrer par" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Par équipe" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByRole("button", { name: "Par coach" })).toHaveAttribute("aria-pressed", "false");

    await user.click(screen.getByRole("button", { name: "Par coach" }));
    expect(onValueChange).toHaveBeenCalledWith("coach");
  });

  it("mode multiple : sous-ensemble pressé, bascule indépendante", async () => {
    const user = userEvent.setup();
    const onToggle = vi.fn();
    render(<SegmentedControl mode="multiple" label="Types" options={OPTIONS} values={["equipe", "gymnase"]} onToggle={onToggle} />);

    expect(screen.getByRole("button", { name: "Par équipe" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByRole("button", { name: "Par coach" })).toHaveAttribute("aria-pressed", "false");
    expect(screen.getByRole("button", { name: "Par gymnase" })).toHaveAttribute("aria-pressed", "true");

    await user.click(screen.getByRole("button", { name: "Par coach" }));
    expect(onToggle).toHaveBeenCalledWith("coach");
  });

  it("compteur : nombre nu aria-hidden, le nom accessible le reprend via ariaLabel", () => {
    render(
      <SegmentedControl
        mode="single"
        label="Filtrer"
        options={[{ key: "a", label: "À apparier", count: 3, ariaLabel: "À apparier — 3 salles" }]}
        value="a"
        onValueChange={vi.fn()}
      />,
    );
    // Le nom accessible vient de l'ariaLabel (pas « À apparier (3) »).
    const btn = screen.getByRole("button", { name: "À apparier — 3 salles" });
    expect(btn).toBeInTheDocument();
    expect(btn.textContent).toContain("(3)");
  });

  it("rend le conteneur bordé segmenté partagé (bg-card p-0.5)", () => {
    render(<SegmentedControl mode="single" label="x" options={OPTIONS} value="equipe" onValueChange={vi.fn()} />);
    expect(screen.getByRole("group", { name: "x" }).className).toContain("bg-card");
  });
});
