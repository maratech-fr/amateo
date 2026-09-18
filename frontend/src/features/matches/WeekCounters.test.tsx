import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router";
import { describe, expect, it, vi } from "vitest";

import { WeekCounters } from "./WeekCounters";

function renderCounters(props: Partial<Parameters<typeof WeekCounters>[0]> = {}) {
  const onScrollToPlace = vi.fn();
  const onOpenFbi = vi.fn();
  render(
    <MemoryRouter>
      <WeekCounters unplaced={1} conflicts={2} fbiTodo={3} onScrollToPlace={onScrollToPlace} onOpenFbi={onOpenFbi} {...props} />
    </MemoryRouter>,
  );
  return { onScrollToPlace, onOpenFbi };
}

describe("WeekCounters (PR 3b — la barre au-dessus de la grille)", () => {
  it("le groupe « Semaine affichée » ne porte QUE à placer · conflits (bornés à la semaine)", () => {
    renderCounters();
    const group = screen.getByRole("group", { name: "Semaine affichée" });
    expect(within(group).getByRole("button", { name: /1 à placer/ })).toBeInTheDocument();
    expect(within(group).getByRole("link", { name: /2 conflits/ })).toBeInTheDocument();
    // « FBI à faire » est GLOBAL — hors du groupe « Semaine affichée ».
    expect(within(group).queryByRole("button", { name: /FBI à faire/ })).not.toBeInTheDocument();
  });

  it("« FBI à faire » est un compteur GLOBAL, hors du groupe, avec aria-haspopup=dialog", () => {
    renderCounters();
    const fbi = screen.getByRole("button", { name: /FBI à faire/ });
    expect(fbi).toHaveAttribute("aria-haspopup", "dialog");
    expect(fbi).toHaveAccessibleName(/toutes semaines/);
  });

  it("le compteur « FBI à faire » est INVARIANT au changement de semaine (prend fbiTodo global)", () => {
    // Même valeur globale quelle que soit la semaine : le compteur ne dépend pas de
    // `unplaced`/`conflicts` (bornés à la semaine) mais du `fbiTodo` servi.
    renderCounters({ unplaced: 9, conflicts: 9, fbiTodo: 7 });
    expect(screen.getByRole("button", { name: /7 FBI à faire/ })).toBeInTheDocument();
  });

  it("« à placer » appelle onScrollToPlace ; « FBI à faire » appelle onOpenFbi", async () => {
    const user = userEvent.setup();
    const { onScrollToPlace, onOpenFbi } = renderCounters();
    await user.click(screen.getByRole("button", { name: /à placer/ }));
    expect(onScrollToPlace).toHaveBeenCalledOnce();
    await user.click(screen.getByRole("button", { name: /FBI à faire/ }));
    expect(onOpenFbi).toHaveBeenCalledOnce();
  });

  it("« conflits » est un lien vers l'onglet Conflits", () => {
    renderCounters();
    expect(screen.getByRole("link", { name: /conflits/ })).toHaveAttribute("href", "/matchs/conflits");
  });

  it("un compteur à zéro met chiffre ET libellé en sourdine (le bouton reste actif)", () => {
    renderCounters({ unplaced: 0 });
    const place = screen.getByRole("button", { name: /0 à placer/ });
    expect(place).toBeEnabled();
    expect(within(place).getByText("0")).toHaveClass("text-muted-foreground");
  });
});
