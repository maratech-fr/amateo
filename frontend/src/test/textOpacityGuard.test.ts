import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * A11Y-22 — l'opacité sur du TEXTE n'est jamais une dé-emphase légitime (WCAG 1.4.3 n'exempte
 * que l'inactif, le décoratif et le logo) ; la hiérarchie se porte par la taille, la graisse, la
 * bordure, le fond, la saturation. Ce garde STATIQUE lit les sources `.tsx` et rougit sur :
 *   - une classe `text-<jeton>/<NN>` (opacité posée sur un jeton de TEXTE), et
 *   - une classe `opacity-30|40|50|60` (estompage d'un élément).
 *
 * Deux issues, dans l'ordre de la règle de fond :
 *   1. INACTIF (auto-détecté) — une ligne portant un marqueur d'inertie (`disabled`,
 *      `cursor-not-allowed`, `pointer-events-none`, `aria-disabled`) est exemptée : l'opacité y
 *      dé-emphase un contrôle inactif, ce que WCAG autorise. (`disabled:opacity-*` y tombe.)
 *   2. DÉCORATIF / estompage-transitoire-hors-scope — non détectable par une classe : liste
 *      NOMINATIVE ci-dessous (fichier + motif + raison), plafonnée à 5 entrées. Toute autre
 *      occurrence est une régression : on colore le TEXTE **ou** on teinte la SURFACE, jamais les
 *      deux (recette `StatusPill` / P4-180) ; on estompe une CELLULE par `grayscale`, pas `opacity`.
 *
 * PORTÉE = sources `.tsx` de `src/` ; les `*.test.tsx` sont EXCLUS (ils peuvent asserter une
 * classe littérale). Ce fichier est un `.test.ts` — hors du glob `.tsx`, donc hors auto-scan.
 */
const SRC_ROOT = join(import.meta.dirname, "..");

const TEXT_OPACITY = /text-[a-z-]+\/[0-9]+/;
const CELL_OPACITY = /\bopacity-[3-6]0\b/;
// Marqueurs d'un élément INACTIF/INERTE : l'opacité y est une dé-emphase WCAG-légitime.
const INACTIVE = /\b(?:disabled|cursor-not-allowed|pointer-events-none|aria-disabled)\b/;

interface Exemption {
  file: string;
  pattern: RegExp;
  reason: string;
}

// ≤ 5 entrées — décoratif / logo / estompage transitoire hors du scope `grayscale` de ce lot.
const EXEMPTIONS: Exemption[] = [
  {
    file: "features/matches/WeekWorkbench.tsx",
    pattern: /opacity-60/,
    reason: "icône décorative (MousePointerClick, aria-hidden) — aucun texte porté",
  },
  {
    file: "features/matches/WeekendGrid.tsx",
    pattern: /opacity-40/,
    reason: "bloc EXTÉRIEUR inerte en mode échange, fond bg-muted déjà gris — grayscale ne l'estomperait pas",
  },
  {
    file: "features/planning/ClubViewTable.tsx",
    pattern: /opacity-[34]0/,
    reason: "estompage transitoire de cellule (dimmed/lens), même famille que WeekGrid — hors du scope grayscale de ce lot (convergence signalée)",
  },
  {
    file: "features/wizard/steps/PeriodTeams.tsx",
    pattern: /opacity-50/,
    reason: "label d'un découpage INACTIF (!active) — dé-emphase d'un contrôle inactif (WCAG 1.4.3)",
  },
];

function tsxFiles(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...tsxFiles(full));
    } else if (/\.tsx$/.test(entry.name) && !/\.test\.tsx$/.test(entry.name)) {
      out.push(full);
    }
  }
  return out;
}

function isExempt(rel: string, line: string): boolean {
  return EXEMPTIONS.some((e) => rel.endsWith(e.file) && e.pattern.test(line));
}

/**
 * A11Y-25 — un TEXTE de même teinte posé sur une teinte translucide de cette teinte (`text-warning`
 * sur `bg-warning/30`, `text-accent` sur `bg-accent/10`…) tombe sous l'AA (3,1-4,4:1 mesuré), hors de
 * la portée d'A11Y-22 (qui vise l'opacité SUR le texte, pas une teinte de FOND). Doctrine P4-265 : le
 * fond au repos devient une SURFACE OPAQUE (`bg-surface-<ton>`) et le texte redevient `text-foreground`.
 * Ce garde STATIQUE rougit sur une ligne `.tsx` portant, AU REPOS (hors variante `hover:`/`focus:`…),
 * à la fois un fond `bg-<ton>/<NN>` ET un texte `text-<ton>` de la MÊME teinte.
 *
 * PORTÉE : jetons de teinte `warning|accent|destructive|success`. Les lignes de COMMENTAIRE sont
 * ignorées (elles citent le motif). Exemption : un badge d'ICÔNE décoratif (`aria-hidden`), régi par
 * le seuil graphique 1.4.11 (≥ 3:1), pas par l'AA du texte.
 */
const TINT_HUES = ["warning", "accent", "destructive", "success"] as const;
const COMMENT_LINE = /^\s*(?:\/\/|\*|\/\*|\{\/\*|\*\/)/;
const TINT_PAIR_EXEMPTIONS: Exemption[] = [
  {
    file: "features/matches/FbiEntryList.tsx",
    pattern: /bg-(?:success|warning)\/\d/,
    reason: "badges d'ICÔNE décoratifs (aria-hidden) — seuil graphique 1.4.11 (≥ 3:1), pas du texte",
  },
];

describe("A11Y-25 — pas de texte de même teinte sur une teinte translucide", () => {
  it("aucune ligne ne pose, au repos, bg-<ton>/NN ET text-<ton> de la même teinte (hors icône décorative)", () => {
    const offenders: string[] = [];
    for (const file of tsxFiles(SRC_ROOT)) {
      const rel = file.slice(SRC_ROOT.length + 1);
      const lines = readFileSync(file, "utf8").split("\n");
      lines.forEach((line, index) => {
        if (COMMENT_LINE.test(line)) return;
        // Tokeniser : une variante (`hover:bg-accent/10`) porte un `:` et n'est PAS un état de repos.
        const tokens = line.split(/[\s"'`{}()]+/);
        for (const hue of TINT_HUES) {
          const restTint = tokens.some((t) => new RegExp(`^bg-${hue}/\\d`).test(t));
          const restText = tokens.some((t) => t === `text-${hue}`);
          if (restTint && restText && !TINT_PAIR_EXEMPTIONS.some((e) => rel.endsWith(e.file) && e.pattern.test(line))) {
            offenders.push(`src/${rel}:${index + 1} → bg-${hue}/NN + text-${hue}`);
          }
        }
      });
    }
    expect(
      offenders,
      "Texte de même teinte sur teinte translucide (sous AA) : poser une surface OPAQUE `bg-surface-<ton>` et un texte `text-foreground` (P4-265) ; un badge d'icône décoratif (aria-hidden) entre dans TINT_PAIR_EXEMPTIONS.",
    ).toEqual([]);
  });
});

describe("A11Y-22 — pas d'opacité comme dé-emphase de texte/cellule", () => {
  it("aucune classe text-<jeton>/<NN> ni opacity-[3-6]0, hors inactif ou exemption nominative", () => {
    const offenders: string[] = [];
    for (const file of tsxFiles(SRC_ROOT)) {
      const rel = file.slice(SRC_ROOT.length + 1); // « features/… »
      const lines = readFileSync(file, "utf8").split("\n");
      lines.forEach((line, index) => {
        if (!TEXT_OPACITY.test(line) && !CELL_OPACITY.test(line)) return;
        if (INACTIVE.test(line)) return; // inactif → exempté (WCAG 1.4.3)
        if (isExempt(rel, line)) return; // décoratif / transitoire hors scope
        offenders.push(`src/${rel}:${index + 1} → ${line.trim().slice(0, 100)}`);
      });
    }
    expect(
      offenders,
      "Opacité posée sur du texte / une cellule : colorer le TEXTE ou teinter la SURFACE, jamais les deux (recette StatusPill / P4-180) ; estomper une cellule par `grayscale`. Un cas inactif porte `disabled`/`cursor-not-allowed`/`pointer-events-none` ; un cas décoratif/logo entre dans EXEMPTIONS (≤ 5).",
    ).toEqual([]);
  });
});
