import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { WeekendGridLegend } from "./WeekendGridLegend";

describe("WeekendGridLegend (lot 1, 2026-09-17)", () => {
  it("rend NUL quand la semaine n'a ni à-confirmer ni habitude", () => {
    const { container } = render(<WeekendGridLegend toConfirmCount={0} showHabits={false} />);
    expect(container.firstChild).toBeNull();
  });

  it("montre l'entrée « à confirmer » (pluriel) seulement quand la semaine en contient", () => {
    render(<WeekendGridLegend toConfirmCount={3} showHabits={false} />);
    expect(screen.getByText(/3 à confirmer — gymnase et heure repris de l'import, pas encore placés/)).toBeInTheDocument();
    expect(screen.queryByText(/Habitude/)).toBeNull();
  });

  it("accorde au singulier (« placé », pas « placés »)", () => {
    render(<WeekendGridLegend toConfirmCount={1} showHabits={false} />);
    expect(screen.getByText(/1 à confirmer — gymnase et heure repris de l'import, pas encore placé$/)).toBeInTheDocument();
  });

  it("montre l'entrée « Habitude » seulement quand « Semaine type » est active ET qu'il y a des fantômes", () => {
    render(<WeekendGridLegend toConfirmCount={0} showHabits={true} />);
    expect(screen.getByText("Habitude — fenêtre protégée")).toBeInTheDocument();
    expect(screen.queryByText(/à confirmer/)).toBeNull();
  });

  it("montre les deux entrées quand les deux sont présentes", () => {
    render(<WeekendGridLegend toConfirmCount={2} showHabits={true} />);
    expect(screen.getByText(/2 à confirmer/)).toBeInTheDocument();
    expect(screen.getByText("Habitude — fenêtre protégée")).toBeInTheDocument();
  });
});
