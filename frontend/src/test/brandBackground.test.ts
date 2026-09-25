import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Fond d'écran commun app + vitrine (P5-16) — GARDE de jetons & de purge.
 *
 * Un SEUL fond, identique app et vitrine : les SVG multi-sport du logo posés en `background-image`
 * sur `body`, sur notre papier (`--background`). Ce garde tient DEUX faits qu'aucun autre test ne
 * voit (les motifs sont des SVG statiques dans `public/`, hors du graphe TS, et aucun scan de
 * contraste ne regarde un `background-image` décoratif) :
 *
 *   (a) `index.css` : la règle `body` référence `/brand/fond-light.svg` ET un bloc `.dark body`
 *       référence `/brand/fond-dark.svg` — parité clair/sombre, sinon un thème perd son fond ;
 *   (b) les deux copies servies (`public/brand/fond-*.svg`) existent et sont PURGÉES : ni manifeste
 *       `c2pa`, ni `<metadata>`, ni le `<rect width="1440" height="900">` de sol (le sol est notre
 *       papier, pas le gris de la livraison).
 *
 * Aucune assertion sur la VALEUR d'opacité (fourchette fondateur 0,15–0,18, ajustable). Lecture
 * LIGNE À LIGNE (comme `accentTokenParity.test.ts`) — pas de `new RegExp` construite (Semgrep
 * `detect-non-literal-regexp`).
 */
const SRC = join(import.meta.dirname, "..");
const CSS = readFileSync(join(SRC, "index.css"), "utf8");
const PUBLIC_BRAND = join(SRC, "..", "public", "brand");

/**
 * Corps de la première règle dont l'ouverture (ligne `trim`ée) commence par `${selector} ` ou
 * `${selector}{` et porte le `{` : les lignes suivantes jusqu'à la ligne `}`. Le sélecteur `body`
 * ne capte pas `.dark body` (dont la ligne commence par `.dark`), et inversement.
 */
function ruleBody(css: string, selector: string): string {
  const lines = css.split("\n");
  for (let i = 0; i < lines.length; i++) {
    const t = lines[i].trim();
    if ((t.startsWith(`${selector} `) || t.startsWith(`${selector}{`)) && t.includes("{")) {
      const collected = [t.slice(t.indexOf("{") + 1)];
      for (let j = i + 1; j < lines.length; j++) {
        if ("}" === lines[j].trim()) {
          return collected.join("\n");
        }
        collected.push(lines[j]);
      }
    }
  }
  throw new Error(`index.css : règle ${selector} introuvable`);
}

describe("fond d'écran commun (P5-16) : jetons index.css", () => {
  it("la règle body référence /brand/fond-light.svg", () => {
    expect(ruleBody(CSS, "body")).toContain("/brand/fond-light.svg");
  });

  it(".dark body référence /brand/fond-dark.svg (parité clair/sombre)", () => {
    expect(ruleBody(CSS, ".dark body")).toContain("/brand/fond-dark.svg");
  });
});

describe("fond d'écran commun (P5-16) : copies SVG purgées", () => {
  const GROUND_RECT = '<rect width="1440" height="900"';

  for (const name of ["fond-light.svg", "fond-dark.svg"] as const) {
    describe(name, () => {
      const path = join(PUBLIC_BRAND, name);

      it("existe dans public/brand", () => {
        expect(existsSync(path)).toBe(true);
      });

      it("est purgé (ni c2pa, ni <metadata, ni rect de sol)", () => {
        const svg = readFileSync(path, "utf8");
        expect(svg).not.toContain("c2pa");
        expect(svg).not.toContain("<metadata");
        expect(svg).not.toContain(GROUND_RECT);
      });
    });
  }
});
