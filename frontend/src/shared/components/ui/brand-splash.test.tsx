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

  // D11 — la scène n'applique AUCUNE translation horizontale de groupe : son transform est le SEUL
  // `scale(fit)` qui centre la scène de 1920 dans le viewport. Le centrage vient de la scène NON
  // RÉTRÉCIE (flex-shrink: 0, testé ci-dessous), pas d'un `translateX(groupDx)` — l'ancien câblage a
  // été retiré, mesure Playwright à l'appui (le `groupDx` décalait le logo de −7 px au lieu de le
  // centrer, cf. `brand-splash.math.test.ts`). jsdom ne mesure aucune mise en page : ici on vérifie
  // le CÂBLAGE (absence de `translateX`), le centrage RÉEL restant jugé par l'e2e
  // `tests/e2e/login-splash.spec.ts` (±4 px). Le conteneur de scène est le parent du mot.
  it("la scène ne porte QUE scale(fit) — aucune translation horizontale de groupe", () => {
    mockReducedMotion(false);
    render(<BrandSplash phase="breathing" {...inertProps} />);
    const scene = screen.getByTestId("splash-word").parentElement;
    expect(scene?.style.transform).toContain("scale(");
    expect(scene?.style.transform).not.toContain("translateX(");
  });

  it("reduced-motion : pas davantage de translation de groupe (scale seul, FULL_LAYOUT)", () => {
    mockReducedMotion(true);
    render(<BrandSplash phase="breathing" {...inertProps} />);
    const scene = screen.getByTestId("splash-word").parentElement;
    expect(scene?.style.transform).toContain("scale(");
    expect(scene?.style.transform).not.toContain("translateX(");
  });

  // D11 — la scène NE RÉTRÉCIT PAS avec l'écran. Elle est un item FLEX (enfant du conteneur
  // `fixed inset-0 flex items-center justify-center`), posée en coordonnées SCÈNE (`width: SCENE_W`
  // = 1920) et dont les enfants (mot, icône) sont positionnés en 1920. Sans `flex-shrink: 0` l'item
  // flex rétrécit à la largeur du viewport (boîte 1280 au lieu de 1920 AVANT `scale`) : ses enfants,
  // toujours posés en 1920, se décalent vers la droite (~150 px à 1280 px — centre de l'encre 793 vs
  // 640, et le décalage grandit quand l'écran rétrécit). C'est la cause principale de D11.
  // jsdom n'a aucun moteur de layout (le non-rétrécissement RÉEL se prouve en Playwright,
  // `tests/e2e/login-splash.spec.ts`) : ici on vérifie le CÂBLAGE — que la scène déclare bien
  // `flex-shrink: 0`, en style inline (`flexShrink: 0`) OU en classe (`shrink-0`).
  it("la scène porte flex-shrink: 0 — sinon ses coordonnées 1920 collapsent à la largeur du viewport", () => {
    mockReducedMotion(false);
    render(<BrandSplash phase="breathing" {...inertProps} />);
    const scene = screen.getByTestId("splash-word").parentElement;
    const inlineShrink = scene?.style.flexShrink === "0";
    const classShrink = scene?.classList.contains("shrink-0") ?? false;
    expect(inlineShrink || classShrink).toBe(true);
  });
});
