import { describe, expect, it } from "vitest";

import { accentForMode, accentHoverForMode, luminance, nextVenueColor, readableForeground, VENUE_PALETTE } from "./color";

const contrast = (a: string, b: string): number => {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
};

// Surfaces du thème, en sRGB, converties des oklch/hex de `src/index.css` (bloc `:root` clair,
// bloc `.dark`) — la MÊME conversion Björn Ottosson que le test e2e mesure au canvas (base CHAUDE).
//   clair : --background #faf9f7 ; --card #ffffff
//   sombre : --background oklch(0.19 0.006 75) → #151311 ; --card oklch(0.23 0.006 75) → #1f1d1a
const SURFACES = {
  light: { bg: "#faf9f7", card: "#ffffff" },
  dark: { bg: "#151311", card: "#1f1d1a" },
} as const;

describe("readableForeground", () => {
  // Every club accent — including the mid-tones that fooled the old 0.42
  // threshold — must pair with an AA foreground (A11Y-06). Sweep the value axis.
  it("always yields a WCAG-AA foreground on the accent", () => {
    for (let l = 0; l <= 255; l += 5) {
      for (const hex of [`#${l.toString(16).padStart(2, "0").repeat(3)}`, "#3280dc", "#4d96f0", "#b45309"]) {
        expect(contrast(hex, readableForeground(hex)), `${hex} paired with ${readableForeground(hex)}`).toBeGreaterThanOrEqual(4.5);
      }
    }
  });
});

describe("accentForMode — accent de club dérivé par le CONTRASTE (A11Y-22, décision 5)", () => {
  // Un accent de club sert de TEXTE (`text-accent` sur `--background`/`--card`) ; il doit donc
  // clearer AA (4,5:1) sur LES DEUX surfaces de son mode, jamais rester lisible sur la seule
  // plus foncée. On assombrit en clair, on éclaircit en sombre, jusqu'à passer sur bg ET card.
  for (const c of VENUE_PALETTE) {
    it(`${c} : dérivé clair ≥ 4,5:1 sur background ET card clairs, foreground lisible`, () => {
      const derived = accentForMode(c, "light");
      expect(contrast(derived, SURFACES.light.bg), `${c} → ${derived} sur bg clair`).toBeGreaterThanOrEqual(4.5);
      expect(contrast(derived, SURFACES.light.card), `${c} → ${derived} sur card clair`).toBeGreaterThanOrEqual(4.5);
      expect(contrast(derived, readableForeground(derived)), `foreground de ${derived}`).toBeGreaterThanOrEqual(4.5);
    });

    it(`${c} : dérivé sombre ≥ 4,5:1 sur background ET card sombres, foreground lisible`, () => {
      const derived = accentForMode(c, "dark");
      expect(contrast(derived, SURFACES.dark.bg), `${c} → ${derived} sur bg sombre`).toBeGreaterThanOrEqual(4.5);
      expect(contrast(derived, SURFACES.dark.card), `${c} → ${derived} sur card sombre`).toBeGreaterThanOrEqual(4.5);
      expect(contrast(derived, readableForeground(derived)), `foreground de ${derived}`).toBeGreaterThanOrEqual(4.5);
    });
  }

  it("une couleur DÉJÀ conforme est rendue telle quelle (aucun sur-assombrissement)", () => {
    // #911EB4 (violet) passe déjà AA sur les surfaces claires → inchangé.
    const alreadyOk = "#911EB4";
    expect(contrast(alreadyOk, SURFACES.light.card)).toBeGreaterThanOrEqual(4.5);
    expect(accentForMode(alreadyOk, "light")).toBe(alreadyOk);
  });

  it("une couleur non-hex est rendue telle quelle (garde d'entrée)", () => {
    expect(accentForMode("var(--accent)", "light")).toBe("var(--accent)");
  });
});

describe("accentHoverForMode — teinte de survol d'un bouton accent (jeton --accent-hover)", () => {
  // Le survol d'un bouton accent NE change PAS le texte : c'est le MÊME `readableForeground` que
  // le repos. Il ne doit donc jamais faire chuter le contraste sous AA (l'ancien `opacity-90`
  // compositait l'accent vers la surface — blanc/accent tombait à 4,26 en clair). On s'éloigne
  // de la surface (assombrir en clair, éclaircir en sombre) : le sens qui MONTE le contraste.
  // Chaque accent de club (BCCL + toute la palette de gymnase) est d'abord DÉRIVÉ (accentForMode),
  // puis on garde son survol, dans les deux thèmes.
  for (const c of ["#E53935", ...VENUE_PALETTE]) {
    for (const mode of ["light", "dark"] as const) {
      it(`${c} (${mode}) : le survol garde le texte du repos ≥ 4,5:1 ET diffère visiblement du repos`, () => {
        const rest = accentForMode(c, mode);
        const hover = accentHoverForMode(rest, mode);
        const fg = readableForeground(rest); // le texte du bouton NE bouge PAS entre repos et survol
        expect(hover, `survol de ${rest} identique au repos (pas de retour visuel)`).not.toBe(rest);
        expect(contrast(fg, hover), `${fg} sur survol ${hover} (repos ${rest})`).toBeGreaterThanOrEqual(4.5);
      });
    }
  }

  it("une couleur non-hex est rendue telle quelle (garde d'entrée)", () => {
    expect(accentHoverForMode("var(--accent)", "light")).toBe("var(--accent)");
  });
});

describe("nextVenueColor", () => {
  it("returns the first palette hue when nothing is used", () => {
    expect(nextVenueColor([])).toBe(VENUE_PALETTE[0]);
  });

  it("skips already-used colours (case-insensitive)", () => {
    expect(nextVenueColor([VENUE_PALETTE[0].toLowerCase()])).toBe(VENUE_PALETTE[1]);
    expect(nextVenueColor([VENUE_PALETTE[0], VENUE_PALETTE[1]])).toBe(VENUE_PALETTE[2]);
  });

  it("ignores null entries", () => {
    expect(nextVenueColor([null, null])).toBe(VENUE_PALETTE[0]);
  });

  it("cycles once the palette is exhausted", () => {
    const all = [...VENUE_PALETTE];
    expect(nextVenueColor(all)).toBe(VENUE_PALETTE[0]);
  });

  it("never returns black or white", () => {
    for (const c of VENUE_PALETTE) {
      expect(c.toLowerCase()).not.toBe("#000000");
      expect(c.toLowerCase()).not.toBe("#ffffff");
    }
  });
});
