import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import { DropActionMenu } from "./DropActionMenu";

const baseProps = {
  open: true as const,
  occupantName: "U18M1",
  disabled: {},
  onTakePlace: () => {},
  onSwap: () => {},
  onMutualize: () => {},
  onClose: () => {},
};

describe("DropActionMenu", () => {
  it("propose les TROIS choix et nomme l'occupant", () => {
    render(<DropActionMenu {...baseProps} />);
    expect(screen.getByRole("button", { name: "Prendre sa place" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Échanger" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Mutualiser" })).toBeInTheDocument();
    expect(screen.getByText(/U18M1/)).toBeInTheDocument();
  });

  it("appelle le bon handler quand un choix ACTIF est cliqué", () => {
    const onTakePlace = vi.fn();
    const onSwap = vi.fn();
    const onMutualize = vi.fn();
    render(<DropActionMenu {...baseProps} onTakePlace={onTakePlace} onSwap={onSwap} onMutualize={onMutualize} />);

    fireEvent.click(screen.getByRole("button", { name: "Prendre sa place" }));
    fireEvent.click(screen.getByRole("button", { name: "Échanger" }));
    fireEvent.click(screen.getByRole("button", { name: "Mutualiser" }));

    expect(onTakePlace).toHaveBeenCalledTimes(1);
    expect(onSwap).toHaveBeenCalledTimes(1);
    expect(onMutualize).toHaveBeenCalledTimes(1);
  });

  it("DÉSACTIVE un choix impossible AVEC son motif (A11Y-30 : aria-disabled + motif visible) et NEUTRALISE son clic", () => {
    const onTakePlace = vi.fn();
    render(
      <DropActionMenu
        {...baseProps}
        onTakePlace={onTakePlace}
        disabled={{ takePlace: "Ce créneau est verrouillé — déverrouillez-le d'abord." }}
      />,
    );

    const takePlace = screen.getByRole("button", { name: "Prendre sa place" });
    // A11Y-30 : désactivation DÉCOUVRABLE (aria-disabled, pas un `disabled` natif qui sort du tab order).
    expect(takePlace).toHaveAttribute("aria-disabled", "true");
    // Le motif est présent (visible sous le bouton ET annoncé via aria-describedby de `disabledReason`).
    expect(screen.getAllByText("Ce créneau est verrouillé — déverrouillez-le d'abord.").length).toBeGreaterThanOrEqual(1);
    // Le clic est neutralisé.
    fireEvent.click(takePlace);
    expect(onTakePlace).not.toHaveBeenCalled();
  });

  it("ne rend rien quand il est fermé", () => {
    const { container } = render(<DropActionMenu {...baseProps} open={false} />);
    expect(container).toBeEmptyDOMElement();
  });
});
