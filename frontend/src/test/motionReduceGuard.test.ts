import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * A11Y-31 — toute animation doit s'effacer sous `prefers-reduced-motion` (WCAG 2.3.3 ·
 * `reduced-motion`). Un spinner qui tourne, une carte qui pulse, une barre qui défile sont du
 * confort, jamais de l'information : un utilisateur qui a demandé « moins de mouvement » (vestibulaire,
 * migraineux, TDAH) ne doit plus les voir bouger. Ce garde STATIQUE lit les sources de `src/` et rougit
 * sur les deux foyers où une animation peut rester non bornée :
 *   1. `.tsx` — une classe utilitaire `animate-<x>` (hors `animate-none`) sur une ligne qui ne porte
 *      PAS la variante `motion-reduce:` qui l'annule (patron maison `motion-reduce:animate-none`).
 *   2. `.css` — un fichier qui déclare `animation:` doit contenir une requête `prefers-reduced-motion`
 *      (le volet qui remet l'animation à `none`/`0s` sous la préférence).
 *
 * PORTÉE = sources de `src/` ; les `*.test.tsx` sont EXCLUS (ils peuvent asserter une classe
 * littérale). Ce fichier est un `.test.ts` — hors du glob `.tsx`, donc hors auto-scan.
 *
 * Patron : `textOpacityGuard.test.ts` (A11Y-22) — grep statique, exemptions NOMINATIVES plafonnées.
 * jsdom n'a aucun moteur de mise en page ni de `matchMedia` : la conformité `prefers-reduced-motion`
 * ne se MESURE qu'en Playwright ; ce garde est la barrière STATIQUE qui rougit dans Vitest avant le
 * scan e2e, pour qu'une régression de mouvement ne reste pas verte jusque-là (même doctrine que A11Y-22).
 */
const SRC_ROOT = join(import.meta.dirname, "..");

// Une classe d'animation VIVE : `animate-<x>` où `<x>` n'est pas `none`. Couvre la forme
// arbitraire `animate-[loading-bar_1s_…]`. Le `\b` en tête évite de ferrer `motion-reduce:animate-none`
// (son `animate-none` est exclu par `(?!none\b)`), ce qui laisse une ligne qui porte DÉJÀ l'annulation
// n'être détectée que par sa classe vive — et donc passer dès que `motion-reduce:` est présent.
const LIVE_ANIMATE = /\banimate-(?!none\b)[a-z0-9[\]_.-]+/;
const MOTION_REDUCE = "motion-reduce:";

interface Exemption {
  file: string;
  reason: string;
}

// ≤ 5 entrées. Une ligne `.tsx` qui anime SANS `motion-reduce:` mais dont l'annulation est portée
// AUTREMENT (JS `matchMedia`, bloc CSS frère nommé) s'inscrit ici, fichier + raison. Vide à ce jour :
// les deux sites JS vivants (`brand-splash`, qui gate son animation par `matchMedia`) n'utilisent aucune
// classe `animate-*`, donc n'apparaissent pas au scan .tsx.
const TSX_EXEMPTIONS: Exemption[] = [];

function sourceFiles(dir: string, ext: RegExp): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...sourceFiles(full, ext));
    } else if (ext.test(entry.name) && !/\.test\.tsx$/.test(entry.name)) {
      out.push(full);
    }
  }
  return out;
}

describe("A11Y-31 — toute animation s'efface sous prefers-reduced-motion", () => {
  it("aucune classe animate-<x> (.tsx) sans la variante motion-reduce: qui l'annule", () => {
    const offenders: string[] = [];
    for (const file of sourceFiles(SRC_ROOT, /\.tsx$/)) {
      const rel = file.slice(SRC_ROOT.length + 1);
      if (TSX_EXEMPTIONS.some((e) => rel.endsWith(e.file))) continue;
      const lines = readFileSync(file, "utf8").split("\n");
      lines.forEach((line, index) => {
        if (!LIVE_ANIMATE.test(line)) return;
        if (line.includes(MOTION_REDUCE)) return;
        offenders.push(`src/${rel}:${index + 1} → ${line.trim().slice(0, 100)}`);
      });
    }
    expect(
      offenders,
      "Classe d'animation sans garde `prefers-reduced-motion` : ajouter `motion-reduce:animate-none` sur la même ligne (patron `Spinner`/`RootShell`/`OfflineBanner`). Un site dont l'animation est bornée AUTREMENT (JS `matchMedia`, bloc CSS frère) entre dans TSX_EXEMPTIONS (≤ 5).",
    ).toEqual([]);
  });

  it("tout fichier .css qui déclare animation: porte une requête prefers-reduced-motion", () => {
    const offenders: string[] = [];
    for (const file of sourceFiles(SRC_ROOT, /\.css$/)) {
      const rel = file.slice(SRC_ROOT.length + 1);
      const content = readFileSync(file, "utf8");
      if (!/\banimation:/.test(content)) continue;
      if (/prefers-reduced-motion/.test(content)) continue;
      offenders.push(`src/${rel}`);
    }
    expect(
      offenders,
      "Feuille CSS qui anime sans volet `prefers-reduced-motion` : ajouter une `@media (prefers-reduced-motion: reduce)` qui remet l'animation à `none`/`0s` (patron `GenerationWaiting.css`/`ActionVeil.css`/`system-scene.css`).",
    ).toEqual([]);
  });
});
