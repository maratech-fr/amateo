import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { WeekendGridLegend } from "./WeekendGridLegend";

describe("WeekendGridLegend (lot 1, 2026-09-17)", () => {
  it("rend NUL quand la semaine n'a ni à-confirmer ni habitude", () => {
    const { container } = render(<WeekendGridLegend toConfirmCount={0} showHabits={false} />);
    expect(container.firstChild).toBeNull();
  });

  // Retours Nico (BCCL, 2026-10-27), M3 — la légende dit « proposé par l'import » (plus « à
  // confirmer », qui laissait croire à une action due). La tournure exacte reste à NEO ; la
  // promesse pinée : « proposé par l'import » apparaît, « à confirmer » a disparu.
  it("montre l'entrée « proposé par l'import » seulement quand la semaine en contient", () => {
    render(<WeekendGridLegend toConfirmCount={3} showHabits={false} />);
    expect(screen.getByText(/proposé par l'import/)).toBeInTheDocument();
    expect(screen.queryByText(/à confirmer/)).toBeNull();
    expect(screen.queryByText(/Habitude/)).toBeNull();
  });

  it("montre l'entrée pour une seule proposition (compte 1 visible)", () => {
    render(<WeekendGridLegend toConfirmCount={1} showHabits={false} />);
    const entry = screen.getByText(/proposé par l'import/);
    expect(entry).toBeInTheDocument();
    expect(entry).toHaveTextContent("1");
  });

  it("montre l'entrée « Habitude » seulement quand « Semaine type » est active ET qu'il y a des fantômes", () => {
    render(<WeekendGridLegend toConfirmCount={0} showHabits={true} />);
    expect(screen.getByText("Habitude — fenêtre protégée")).toBeInTheDocument();
    expect(screen.queryByText(/à confirmer/)).toBeNull();
  });

  it("montre les deux entrées quand les deux sont présentes", () => {
    render(<WeekendGridLegend toConfirmCount={2} showHabits={true} />);
    expect(screen.getByText(/proposé par l'import/)).toBeInTheDocument();
    expect(screen.getByText("Habitude — fenêtre protégée")).toBeInTheDocument();
  });

  it("correctif 10 — montre l'entrée « trajet » seulement quand un extérieur porte un trajet", () => {
    const { rerender } = render(<WeekendGridLegend toConfirmCount={0} showHabits={false} showTravel={true} />);
    expect(screen.getByText(/Trajet aller-retour/)).toBeInTheDocument();
    rerender(<WeekendGridLegend toConfirmCount={0} showHabits={false} showTravel={false} />);
    expect(screen.queryByText(/Trajet aller-retour/)).toBeNull();
  });
});
