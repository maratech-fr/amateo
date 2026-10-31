import { readFileSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Finitions d'interface (essai d'onboarding, 30/10, point 6) : tout bouton actif montre le curseur « main ».
 * Tailwind 4 a retiré ce curseur de son reset, d'où une règle de base unique dans `index.css`.
 *
 * Elle vit DANS `@layer base` : hors couche, elle l'emporterait sur les utilitaires de la couche
 * `utilities` et un `cursor-not-allowed` n'agirait plus. Lecture statique : jsdom ne calcule pas le
 * curseur, le rendu réel se juge sur capture.
 */
const CSS = readFileSync(join(import.meta.dirname, "..", "index.css"), "utf8");

/** Le contenu de chaque bloc `@layer base { … }`, accolades équilibrées. */
function baseLayerBlocks(css: string): string[] {
  const blocks: string[] = [];
  let from = css.indexOf("@layer base");
  while (-1 !== from) {
    const open = css.indexOf("{", from);
    let depth = 1;
    let i = open + 1;
    while (depth > 0 && i < css.length) {
      depth += "{" === css[i] ? 1 : "}" === css[i] ? -1 : 0;
      i += 1;
    }
    blocks.push(css.slice(open + 1, i - 1));
    from = css.indexOf("@layer base", i);
  }
  return blocks;
}

describe("Curseur des boutons (règle de base d'index.css)", () => {
  it("une règle de @layer base donne cursor: pointer aux boutons non désactivés", () => {
    const rule = baseLayerBlocks(CSS)
      .flatMap((block) => [...block.matchAll(/([^{}]+)\{([^{}]*)\}/g)])
      .find((match) => /\bbutton\b/.test(match[1] ?? "") && /cursor:\s*pointer/.test(match[2] ?? ""));

    expect(rule, "aucune règle button { cursor: pointer } dans @layer base").toBeDefined();
    expect(rule?.[1] ?? "", "un bouton désactivé ne doit pas montrer la main").toContain(":not(:disabled)");
  });
});
