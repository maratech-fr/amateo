import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { Bus } from "lucide-react";
import { describe, expect, it, vi } from "vitest";

import { FilterChip } from "./filter-chip";

describe("FilterChip (puce de filtre partagée)", () => {
  it("rend un bouton pressé étiqueté par son libellé", () => {
    render(
      <FilterChip pressed onPress={() => {}}>
        Personne en double
      </FilterChip>,
    );
    const chip = screen.getByRole("button", { name: /Personne en double/ });
    expect(chip).toHaveAttribute("aria-pressed", "true");
  });

  it("non pressé : aria-pressed=false", () => {
    render(
      <FilterChip pressed={false} onPress={() => {}}>
        Personne en double
      </FilterChip>,
    );
    expect(screen.getByRole("button", { name: /Personne en double/ })).toHaveAttribute("aria-pressed", "false");
  });

  it("affiche le compteur ; un 0 est mis en sourdine (text-muted-foreground)", () => {
    const { rerender } = render(
      <FilterChip pressed count={2} onPress={() => {}}>
        Personne en double
      </FilterChip>,
    );
    expect(within(screen.getByRole("button")).getByText("2")).not.toHaveClass("text-muted-foreground");
    rerender(
      <FilterChip pressed count={0} onPress={() => {}}>
        Personne en double
      </FilterChip>,
    );
    expect(within(screen.getByRole("button")).getByText("0")).toHaveClass("text-muted-foreground");
  });

  it("sans `count` : aucun compteur n'est rendu", () => {
    render(
      <FilterChip pressed onPress={() => {}}>
        Amical
      </FilterChip>,
    );
    // Le libellé seul, pas de nombre en fin de puce.
    expect(screen.getByRole("button", { name: "Amical" }).textContent).toBe("Amical");
  });

  it("rend l'icône fournie en tête", () => {
    render(
      <FilterChip pressed count={3} icon={<Bus data-testid="chip-icon" aria-hidden="true" />} onPress={() => {}}>
        Extérieurs
      </FilterChip>,
    );
    expect(within(screen.getByRole("button")).getByTestId("chip-icon")).toBeInTheDocument();
  });

  it("appelle onPress au clic", async () => {
    const onPress = vi.fn();
    const user = userEvent.setup();
    render(
      <FilterChip pressed={false} onPress={onPress}>
        Personne en double
      </FilterChip>,
    );
    await user.click(screen.getByRole("button", { name: /Personne en double/ }));
    expect(onPress).toHaveBeenCalledTimes(1);
  });
});
