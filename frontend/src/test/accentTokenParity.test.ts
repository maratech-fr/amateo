import { readFileSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

import { accentForMode, accentHoverForMode } from "@/shared/lib/color";
import { PRODUCT_ACCENT } from "@/shared/lib/product";

/**
 * DA « base chaude + accent produit » — GARDE D'ÉGALITÉ statique ⇄ dérivation.
 *
 * L'accent PRODUIT par défaut vit à DEUX endroits, par choix (réactivité sans aller-retour) :
 *   1. en STATIQUE dans `index.css` (les jetons `--accent` / `--accent-hover` des blocs `:root`
 *      clair et `.dark`), ce que l'écran affiche AVANT que le hook ne tourne / sur les surfaces
 *      qu'il ne touche pas ;
 *   2. DÉRIVÉ à l'exécution par `useApplyClubTheme` (`accentForMode`/`accentHoverForMode` sur
 *      `PRODUCT_ACCENT`) pour un club sans couleur.
 *
 * Deux valeurs pour une seule couleur = dérive garantie. Ce test LIT `index.css`, extrait
 * `--accent`/`--accent-hover` des deux blocs et exige qu'ils SOIENT la sortie EXACTE de la
 * dérivation sur `PRODUCT_ACCENT`, avec les surfaces de `color.ts` (patron déjà en place pour le
 * défaut historique, cf. le commentaire « CALCULÉ par accentHoverForMode » d'`index.css`).
 * Décaler une valeur statique rougit ici — c'est ce qui empêche la duplication assumée de dériver.
 */
const CSS = readFileSync(join(import.meta.dirname, "..", "index.css"), "utf8");

/** Le corps du PREMIER bloc `:root { … }` (les jetons de design ; le 2ᵉ `:root` = console admin). */
function firstRootBody(css: string): string {
  const m = /:root\s*\{([^}]*)\}/.exec(css);
  if (null === m) {
    throw new Error("index.css : bloc :root introuvable");
  }
  return m[1];
}

/** Le corps du bloc `.dark { … }`. */
function darkBody(css: string): string {
  const m = /\.dark\s*\{([^}]*)\}/.exec(css);
  if (null === m) {
    throw new Error("index.css : bloc .dark introuvable");
  }
  return m[1];
}

/** La valeur d'un jeton dans un corps de bloc, en minuscules (`--accent:` ≠ `--accent-hover:`). */
function tokenValue(body: string, token: string): string {
  const m = new RegExp(`${token}:\\s*([^;]+);`).exec(body);
  if (null === m) {
    throw new Error(`index.css : jeton ${token} introuvable dans le bloc`);
  }
  return m[1].trim().toLowerCase();
}

describe("index.css ⇄ dérivation : l'accent PRODUIT statique EST la sortie de accentForMode", () => {
  const blocks = { light: firstRootBody(CSS), dark: darkBody(CSS) } as const;

  for (const mode of ["light", "dark"] as const) {
    it(`--accent (${mode}) = accentForMode(PRODUCT_ACCENT, "${mode}")`, () => {
      expect(tokenValue(blocks[mode], "--accent")).toBe(accentForMode(PRODUCT_ACCENT, mode).toLowerCase());
    });

    it(`--accent-hover (${mode}) = accentHoverForMode(accentForMode(PRODUCT_ACCENT), "${mode}")`, () => {
      const hover = accentHoverForMode(accentForMode(PRODUCT_ACCENT, mode), mode);
      expect(tokenValue(blocks[mode], "--accent-hover")).toBe(hover.toLowerCase());
    });
  }
});
