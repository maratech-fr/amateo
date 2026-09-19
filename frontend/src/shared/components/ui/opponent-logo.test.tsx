import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { OpponentLogo } from "./opponent-logo";

describe("OpponentLogo", () => {
  it("rend le logo fédéral (img décorative) quand un logo est connu", () => {
    const { container } = render(<OpponentLogo code="ARA0069AAA" hasLogo initials="BR" size="md" />);
    const img = container.querySelector("img");
    expect(img).not.toBeNull();
    expect(img?.getAttribute("src")).toBe("/api/opponents/ARA0069AAA/logo");
    expect(img?.getAttribute("alt")).toBe(""); // décoratif — le nom est écrit à côté
    expect(img?.getAttribute("loading")).toBe("lazy");
  });

  it("md sans logo → pastille d'initiales (aria-hidden)", () => {
    render(<OpponentLogo code="ARA0069AAA" hasLogo={false} initials="BR" size="md" />);
    const badge = screen.getByText("BR");
    expect(badge).toHaveAttribute("aria-hidden", "true");
  });

  it("md sans logo tombe sur les initiales après une ERREUR de chargement", () => {
    const { container } = render(<OpponentLogo code="ARA0069AAA" hasLogo initials="BR" size="md" />);
    const img = container.querySelector("img");
    expect(img).not.toBeNull();
    if (null !== img) fireEvent.error(img);
    expect(screen.getByText("BR")).toBeInTheDocument();
    expect(container.querySelector("img")).toBeNull();
  });

  it("sm sans logo ne rend RIEN (une pastille de 16 px serait illisible)", () => {
    const { container } = render(<OpponentLogo code="ARA0069AAA" hasLogo={false} initials="BR" size="sm" />);
    expect(container.firstChild).toBeNull();
  });

  it("sm sans code ne rend rien non plus (rien à charger)", () => {
    const { container } = render(<OpponentLogo code={null} hasLogo initials="BR" size="sm" />);
    expect(container.firstChild).toBeNull();
  });
});
