import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { PRODUCT_NAME } from "@/shared/lib/product";

import { BrandSplash } from "./brand-splash";

/** Mocke `prefers-reduced-motion` (jsdom n'a pas de matchMedia). */
function mockReducedMotion(reduce: boolean) {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: reduce && query.includes("prefers-reduced-motion"),
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn(),
    onchange: null,
  })) as unknown as typeof window.matchMedia;
}

const noop = () => {};
// rAF neutralisé : le rendu initial suffit à ces assertions (pas de temps qui coule).
const inertProps = {
  ready: false,
  onIntroComplete: noop,
  onBreathingSettled: noop,
  onOutroComplete: noop,
  onCancelComplete: noop,
  raf: () => 0,
  caf: noop,
  now: () => 0,
};

afterEach(() => {
  vi.restoreAllMocks();
});

describe("BrandSplash — rendu", () => {
  it("annonce « Connexion en cours » dans une région de statut polie", () => {
    mockReducedMotion(false);
    render(<BrandSplash phase="intro" {...inertProps} />);
    const region = screen.getByRole("status");
    expect(region).toHaveAttribute("aria-live", "polite");
    expect(region).toHaveAttribute("aria-busy", "true");
    expect(screen.getByText("Connexion en cours…")).toBeInTheDocument();
  });

  it("l'annonce du lecteur d'écran est paramétrable (défaut inchangé)", () => {
    mockReducedMotion(false);
    render(<BrandSplash phase="intro" announcement="Préparation de votre formulaire…" {...inertProps} />);
    expect(screen.getByText("Préparation de votre formulaire…")).toBeInTheDocument();
    expect(screen.queryByText("Connexion en cours…")).not.toBeInTheDocument();
  });

  it("dessine le mot produit, ses 2 dernières lettres en teal", () => {
    mockReducedMotion(false);
    render(<BrandSplash phase="breathing" {...inertProps} />);
    const word = screen.getByTestId("splash-word");
    expect(word.textContent).toBe(PRODUCT_NAME.toLowerCase());
    const spans = Array.from(word.querySelectorAll("span"));
    // Les deux dernières portent une couleur inline (le teal), les autres héritent (encre).
    expect(spans.at(-1)?.style.color).toBe("rgb(70, 175, 172)");
    expect(spans.at(-2)?.style.color).toBe("rgb(70, 175, 172)");
    expect(spans[0]?.style.color).toBe("");
  });

  it("en annulation, l'overlay ne bloque plus les clics et n'est plus busy", () => {
    mockReducedMotion(false);
    render(<BrandSplash phase="cancelling" {...inertProps} />);
    const region = screen.getByRole("status");
    expect(region).toHaveAttribute("aria-busy", "false");
    expect(region).toHaveStyle({ pointerEvents: "none" });
  });
});
