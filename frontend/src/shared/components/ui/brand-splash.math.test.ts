import { afterEach, describe, expect, it, vi } from "vitest";

import { PRODUCT_NAME } from "@/shared/lib/product";

import {
  breatheOpacity,
  breathingSettleTime,
  introFrame,
  outroFrame,
  prefersReducedMotion,
  signatureLayout,
  splashWord,
  TIMINGS,
} from "./brand-splash.math";

afterEach(() => {
  vi.restoreAllMocks();
});

describe("splashWord — le mot vient de la VARIABLE produit", () => {
  it("est le nom produit en minuscules, jamais un littéral", () => {
    const letters = splashWord(PRODUCT_NAME);
    expect(letters.map((l) => l.ch).join("")).toBe(PRODUCT_NAME.toLowerCase());
  });

  it("colore en teal les DEUX dernières lettres, le reste en encre", () => {
    const letters = splashWord(PRODUCT_NAME);
    expect(letters.at(-1)?.teal).toBe(true);
    expect(letters.at(-2)?.teal).toBe(true);
    expect(letters.slice(0, -2).every((l) => !l.teal)).toBe(true);
  });
});

describe("signatureLayout — géométrie du glissé (handoff)", () => {
  it("à p=0,w=0 : icône centrée (960), pleine échelle, mot rentré", () => {
    const l = signatureLayout(0, 0);
    expect(l.iconX).toBe(960);
    expect(l.iconScale).toBe(1);
    expect(l.wordDx).toBeLessThan(0); // mot décalé à gauche, caché derrière le trait teal
    expect(l.clipLeft).toBeGreaterThan(0);
  });

  it("à p=1,w=1 : icône en position logo (535), échelle 0.625, mot entièrement révélé", () => {
    const l = signatureLayout(1, 1);
    expect(l.iconX).toBe(535);
    expect(l.iconScale).toBeCloseTo(0.625, 6);
    expect(l.wordDx).toBeCloseTo(0, 6);
    expect(l.clipLeft).toBeCloseTo(0, 6);
  });
});

describe("introFrame — l'apparition finit sur le logo complet", () => {
  it("démarre non terminée, overlay déjà opaque", () => {
    const f = introFrame(0, false);
    expect(f.done).toBe(false);
    expect(f.overlayOpacity).toBe(1);
  });

  it("à introMs : terminée, géométrie = logo complet", () => {
    const f = introFrame(TIMINGS.introMs, false);
    expect(f.done).toBe(true);
    expect(f.layout.iconX).toBe(535);
    expect(f.layout.clipLeft).toBeCloseTo(0, 6);
  });

  it("réduit : logo statique complet, l'overlay fait juste un fondu d'entrée", () => {
    const start = introFrame(0, true);
    expect(start.layout.iconX).toBe(535); // déjà complet, pas de glissé
    expect(start.overlayOpacity).toBe(0);
    expect(introFrame(TIMINGS.reducedFadeMs, true).overlayOpacity).toBe(1);
  });
});

describe("outroFrame — la fin, en miroir de l'apparition puis fondu", () => {
  it("commence sur le logo complet, overlay opaque", () => {
    const f = outroFrame(0, false);
    expect(f.overlayOpacity).toBe(1);
    expect(f.layout.iconX).toBeCloseTo(535, 6);
  });

  it("se termine overlay transparent après retrait + fondu", () => {
    const end = outroFrame(TIMINGS.outroMs + TIMINGS.outroFadeMs, false);
    expect(end.done).toBe(true);
    expect(end.overlayOpacity).toBeCloseTo(0, 6);
  });
});

describe("respiration — opacité 1 ↔ ~0,55 et arrêt sur une borne", () => {
  it("vaut 1 aux bornes du cycle, ~0,55 au creux", () => {
    expect(breatheOpacity(0)).toBeCloseTo(1, 6);
    expect(breatheOpacity(TIMINGS.breathePeriodMs / 2)).toBeCloseTo(0.55, 6);
    expect(breatheOpacity(TIMINGS.breathePeriodMs)).toBeCloseTo(1, 6);
  });

  it("l'arrêt se cale sur la prochaine borne (retour à 1, sans saut)", () => {
    expect(breathingSettleTime(100)).toBe(TIMINGS.breathePeriodMs);
    expect(breathingSettleTime(TIMINGS.breathePeriodMs + 10)).toBe(2 * TIMINGS.breathePeriodMs);
    expect(breatheOpacity(breathingSettleTime(100))).toBeCloseTo(1, 6);
  });
});

describe("prefersReducedMotion", () => {
  it("suit le média", () => {
    const spy = (reduce: boolean) => {
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
    };
    spy(true);
    expect(prefersReducedMotion()).toBe(true);
    spy(false);
    expect(prefersReducedMotion()).toBe(false);
  });
});

describe("signatureLayout — groupDx recentre l'ENCRE du logo (D11)", () => {
  // PROMESSE : le logo de chargement est centré sur son ENCRE, pas sur la boîte nominale. La scène
  // ajoute une translation de groupe `groupDx = p × (centre scène 960 − centre de la boîte d'ENCRE
  // finale)` : encre de l'icône = rayon MAX des arcs (`r + w/2`, `LOGO_ARCS`) × ICON_BOX/1000 ×
  // LOGO.scale autour d'`iconX` ; mot = [wordLeft, wordLeft + wordW]. Mesure réelle (Poppins
  // Signature, 2026-10-26) : boîte d'encre ≈ [410, 1542] → centre ≈ 976 → décalée de ~16 unités à
  // DROITE → groupDx ≈ −16. (L'hypothèse initiale « décalé à gauche » — calcul sur ICON_BOX — était
  // fausse : c'est l'ENCRE qu'il faut centrer.) AUCUN groupDy : le décalage vertical du mot sous
  // l'icône est le dessin du graphiste, conservé.

  it("p=0 : aucune translation de groupe (l'intro reste intacte)", () => {
    expect(signatureLayout(0, 0).groupDx).toBe(0);
  });

  it("à pleine ouverture, largeur de mot MESURÉE (837) : l'encre est recentrée (~−16, ±2)", () => {
    const gd = signatureLayout(1, 1, 837).groupDx;
    expect(Math.abs(gd - -16)).toBeLessThanOrEqual(2);
  });

  it("sans mesure de mot : repli constant documenté, un nombre FINI, jamais NaN (jsdom/SSR)", () => {
    const gd = signatureLayout(1, 1).groupDx;
    expect(Number.isNaN(gd)).toBe(false);
    expect(Number.isFinite(gd)).toBe(true);
  });

  it("aucune translation VERTICALE de groupe (groupDy absent — dessin du graphiste conservé)", () => {
    expect("groupDy" in signatureLayout(1, 1, 837)).toBe(false);
  });
});
