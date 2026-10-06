import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * UXC-28 (audit 2026-10-03) — le contrôle SEGMENTÉ (une rangée de boutons nus dans UN conteneur
 * bordé) a UNE maison : `shared/components/ui/segmented-control.tsx`. Il était recopié six fois
 * (vues planning, axe équipe/coach/gymnase, « Période », pivot « Regrouper par », filtre
 * adversaires, « Types ») avec des hauteurs et des contrats a11y divergents.
 *
 * Ce garde STATIQUE (modèle `dayPickerGuard`/`pillPrimitiveGuard`) rougit dès qu'un fichier de
 * `src/features/**` ou `src/app/**` porte la SIGNATURE du conteneur segmenté fait main — `bg-card`
 * + `p-0.5` sur la MÊME ligne (la boîte bordée à faible padding qui enveloppe les segments). La
 * largeur est documentée : `bg-card p-0.5` est la marque de ce conteneur et n'apparaissait QUE là
 * (les autres boîtes bordées utilisent `bg-background p-2`, etc.). Une variante légitime (un
 * conteneur `bg-card p-0.5` qui ne serait PAS un contrôle segmenté) entre dans EXEMPTIONS, nommée.
 *
 * PORTÉE délibérée : `shared/components/ui/*` (le foyer de la primitive) est hors scan ;
 * `src/features/admin/**` (palette console, décision UXC-12) est exempté ; les `*.test.ts(x)` aussi.
 *
 * Regex LITTÉRALES uniquement (pas de `new RegExp(<variable>)`, gate Semgrep).
 */
const ROOTS = [join(import.meta.dirname, "..", "features"), join(import.meta.dirname, "..", "app")];

interface Exemption {
  path: string;
  reason: string;
}

// Aucune exemption : les six copies ont été absorbées par SegmentedControl, CampaignDialog (puces
// de filtre rounded-full) est passé à FilterChip.
const EXEMPTIONS: Exemption[] = [];

// Signature du conteneur segmenté fait main : `bg-card` ET l'utilitaire de padding `p-0.5` sur la
// même ligne. Les `\b` encadrent `p-0.5` pour ne PAS confondre avec `gap-0.5`/`py-0.5` (`p-0.5` y
// est un sous-mot, pas l'utilitaire) — faux positif attrapé sur `WeekendGrid` (`gap-0.5 … bg-card`).
const SEGMENTED_CONTAINER = /\bbg-card\b[^"'`]*(?<![\w-])p-0\.5\b|(?<![\w-])p-0\.5\b[^"'`]*\bbg-card\b/;

function hasHandRolledSegmented(source: string): boolean {
  return source.split("\n").some((line) => SEGMENTED_CONTAINER.test(line));
}

function isExempt(rel: string): boolean {
  return rel.includes("/admin/") || EXEMPTIONS.some((e) => rel.includes(e.path));
}

function sourceFiles(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...sourceFiles(full));
    } else if (/\.tsx?$/.test(entry.name) && !/\.test\.tsx?$/.test(entry.name)) {
      out.push(full);
    }
  }
  return out;
}

describe("Contrôle segmenté fait main → SegmentedControl (UXC-28)", () => {
  it("aucun conteneur segmenté recodé dans features/ ou app/, hors exemption nominative", () => {
    const offenders: string[] = [];
    for (const root of ROOTS) {
      for (const file of sourceFiles(root)) {
        const rel = file.slice(file.indexOf("/src/") + 5);
        if (isExempt(rel)) continue;
        if (hasHandRolledSegmented(readFileSync(file, "utf8"))) {
          offenders.push(rel);
        }
      }
    }
    expect(
      offenders,
      "Conteneur segmenté fait main : utiliser la primitive `SegmentedControl` (`shared/components/ui/segmented-control.tsx`). Un foyer unique, jamais une copie (UXC-28, audit 2026-10-03).",
    ).toEqual([]);
  });

  it("le garde mord : le conteneur bordé segmenté est repéré, une autre boîte bordée ne l'est pas", () => {
    const segmented = '<div className="flex items-center gap-1 rounded-md border border-border bg-card p-0.5">';
    const otherBox = '<div className="flex flex-col gap-1 rounded-md border border-border bg-background p-2">';
    expect(hasHandRolledSegmented(segmented)).toBe(true);
    expect(hasHandRolledSegmented(otherBox)).toBe(false);
  });
});
