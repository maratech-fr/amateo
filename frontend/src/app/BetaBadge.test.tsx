import { fireEvent, render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { MeResponse } from "@/shared/session/api";
import { PRODUCT_NAME } from "@/shared/lib/product";

// On mocke le SOCLE (session) : la règle testée — « la pastille reflète l'OFFRE bêta du
// club » — vit dans le composant, lu du serveur (`entitlements.planCode`), jamais recalculé.
let meData: Partial<MeResponse> | undefined;
vi.mock("@/shared/session/queries", () => ({ useMe: () => ({ data: meData }) }));

import { BetaBadge } from "./BetaBadge";

const clubWith = (planCode: string): Partial<MeResponse> => ({
  club: { entitlements: { planCode } } as unknown as MeResponse["club"],
});

beforeEach(() => {
  meData = undefined;
});

describe("BetaBadge", () => {
  it("ne rend RIEN hors offre bêta (planCode ≠ beta)", () => {
    meData = clubWith("decouverte");
    const { container } = render(<BetaBadge onReport={vi.fn()} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("ne rend RIEN sans club ni entitlements", () => {
    meData = {};
    const { container } = render(<BetaBadge onReport={vi.fn()} />);
    expect(container).toBeEmptyDOMElement();
  });

  it("affiche la pastille « BÊTA » quand l'offre est bêta", () => {
    meData = clubWith("beta");
    render(<BetaBadge onReport={vi.fn()} />);
    expect(screen.getByRole("button", { name: "BÊTA" })).toBeInTheDocument();
  });

  it("popover fermé au repos : aria-expanded=false, aucun dialog", () => {
    meData = clubWith("beta");
    render(<BetaBadge onReport={vi.fn()} />);
    const pill = screen.getByRole("button", { name: "BÊTA" });
    expect(pill).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("ouvre le popover au clic : aria-expanded=true, titre + message reliés (aria-controls)", () => {
    meData = clubWith("beta");
    render(<BetaBadge onReport={vi.fn()} />);
    const pill = screen.getByRole("button", { name: "BÊTA" });
    fireEvent.click(pill);
    expect(pill).toHaveAttribute("aria-expanded", "true");
    const dialog = screen.getByRole("dialog");
    expect(dialog).toHaveAccessibleName(`${PRODUCT_NAME} est en bêta`);
    expect(dialog).toHaveTextContent("Vos retours comptent");
    expect(pill).toHaveAttribute("aria-controls", dialog.id);
  });

  it("Échap ferme le popover et rend le focus à la pastille", () => {
    meData = clubWith("beta");
    render(<BetaBadge onReport={vi.fn()} />);
    const pill = screen.getByRole("button", { name: "BÊTA" });
    fireEvent.click(pill);
    expect(screen.getByRole("dialog")).toBeInTheDocument();
    fireEvent.keyDown(document, { key: "Escape" });
    expect(screen.queryByRole("dialog")).toBeNull();
    expect(pill).toHaveFocus();
  });

  it("un clic à l'extérieur ferme le popover", () => {
    meData = clubWith("beta");
    render(
      <div>
        <BetaBadge onReport={vi.fn()} />
        <button type="button">dehors</button>
      </div>,
    );
    fireEvent.click(screen.getByRole("button", { name: "BÊTA" }));
    expect(screen.getByRole("dialog")).toBeInTheDocument();
    fireEvent.mouseDown(screen.getByRole("button", { name: "dehors" }));
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("« Signaler un problème » appelle onReport et ferme le popover", () => {
    meData = clubWith("beta");
    const onReport = vi.fn();
    render(<BetaBadge onReport={onReport} />);
    fireEvent.click(screen.getByRole("button", { name: "BÊTA" }));
    fireEvent.click(screen.getByRole("button", { name: "Signaler un problème" }));
    expect(onReport).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole("dialog")).toBeNull();
  });
});
