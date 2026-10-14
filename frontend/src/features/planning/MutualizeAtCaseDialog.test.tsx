import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { MutualizeAtCaseDialog } from "./MutualizeAtCaseDialog";

const baseProps = {
  sourceName: "U18F1",
  occupantName: "U18M1",
  teamCount: 2,
  busy: false,
  error: null,
  onClose: () => {},
  onConfirm: () => {},
};

describe("MutualizeAtCaseDialog", () => {
  it("annonce les deux côtés et le nombre d'équipes regroupées", () => {
    render(<MutualizeAtCaseDialog {...baseProps} />);
    expect(screen.getByText(/U18F1/)).toBeInTheDocument();
    expect(screen.getByText(/U18M1/)).toBeInTheDocument();
    expect(screen.getByText(/2 équipes seront regroupées/)).toBeInTheDocument();
  });

  it("confirme avec le nom du groupe saisi (trimé), ou `undefined` si vide", () => {
    const onConfirm = vi.fn();
    const { rerender } = render(<MutualizeAtCaseDialog {...baseProps} onConfirm={onConfirm} />);

    fireEvent.click(screen.getByRole("button", { name: "Mutualiser" }));
    expect(onConfirm).toHaveBeenLastCalledWith(undefined);

    fireEvent.change(screen.getByLabelText(/Nom du groupe/), { target: { value: "  U18  " } });
    fireEvent.click(screen.getByRole("button", { name: "Mutualiser" }));
    expect(onConfirm).toHaveBeenLastCalledWith("U18");

    rerender(<MutualizeAtCaseDialog {...baseProps} onConfirm={onConfirm} />);
  });

  it("affiche le refus serveur TEL QUEL (jamais re-dérivé)", () => {
    render(<MutualizeAtCaseDialog {...baseProps} error="La case visée est déjà occupée : elle ne peut pas accueillir la séance commune." />);
    expect(screen.getByRole("alert")).toHaveTextContent("La case visée est déjà occupée");
  });

  it("gèle la confirmation pendant l'envoi (busy)", () => {
    const onConfirm = vi.fn();
    render(<MutualizeAtCaseDialog {...baseProps} busy onConfirm={onConfirm} />);
    fireEvent.click(screen.getByRole("button", { name: /Mutualisation/ }));
    expect(onConfirm).not.toHaveBeenCalled();
  });
});
