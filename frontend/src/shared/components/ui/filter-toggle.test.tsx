import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { FilterToggle } from "./filter-toggle";

describe("FilterToggle (case de filtre partagée, P4-207)", () => {
  it("rend une case cochable étiquetée par son libellé", () => {
    render(
      <FilterToggle checked={false} onChange={() => {}}>
        Masquer les traités
      </FilterToggle>,
    );
    const box = screen.getByRole("checkbox", { name: "Masquer les traités" });
    expect(box).toBeInTheDocument();
    expect(box).not.toBeChecked();
  });

  it("reflète l'état contrôlé `checked`", () => {
    render(
      <FilterToggle checked onChange={() => {}}>
        Masquer les traités
      </FilterToggle>,
    );
    expect(screen.getByRole("checkbox", { name: "Masquer les traités" })).toBeChecked();
  });

  it("appelle onChange avec la valeur suivante au clic", async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    render(
      <FilterToggle checked={false} onChange={onChange}>
        Masquer les traités
      </FilterToggle>,
    );
    await user.click(screen.getByRole("checkbox", { name: "Masquer les traités" }));
    expect(onChange).toHaveBeenCalledWith(true);
  });
});
