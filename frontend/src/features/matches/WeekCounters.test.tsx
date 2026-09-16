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
      <WeekCounters unplaced={1} conflicts={2} fbiToEnter={3} onScrollToPlace={onScrollToPlace} onOpenFbi={onOpenFbi} {...props} />
    </MemoryRouter>,
  );
  return { onScrollToPlace, onOpenFbi };
}

describe("WeekCounters (PR 3b — la barre « Semaine affichée »)", () => {
  it("expose un groupe nommé de trois compteurs, dans l'ordre à placer · conflits · à saisir dans FBI", () => {
    renderCounters();
    const group = screen.getByRole("group", { name: "Semaine affichée" });
    expect(within(group).getByRole("button", { name: /1 à placer/ })).toBeInTheDocument();
    // « conflits » est un LIEN (il change d'onglet), pas un bouton.
    expect(within(group).getByRole("link", { name: /2 conflits/ })).toBeInTheDocument();
    expect(within(group).getByRole("button", { name: /3 à saisir dans FBI/ })).toBeInTheDocument();
  });

  it("« à placer » appelle onScrollToPlace ; « FBI » ouvre la modale (aria-haspopup)", async () => {
    const user = userEvent.setup();
    const { onScrollToPlace, onOpenFbi } = renderCounters();
    await user.click(screen.getByRole("button", { name: /à placer/ }));
    expect(onScrollToPlace).toHaveBeenCalledOnce();
    const fbi = screen.getByRole("button", { name: /à saisir dans FBI/ });
    expect(fbi).toHaveAttribute("aria-haspopup", "dialog");
    await user.click(fbi);
    expect(onOpenFbi).toHaveBeenCalledOnce();
  });

  it("« conflits » est un lien vers l'onglet Conflits — le SEUL à porter une flèche", () => {
    renderCounters();
    const link = screen.getByRole("link", { name: /conflits/ });
    expect(link).toHaveAttribute("href", "/matchs/conflits");
  });

  it("un compteur à zéro met chiffre ET libellé en sourdine (le bouton reste actif)", () => {
    renderCounters({ unplaced: 0 });
    const place = screen.getByRole("button", { name: /0 à placer/ });
    expect(place).toBeEnabled();
    expect(within(place).getByText("0")).toHaveClass("text-muted-foreground");
  });
});
