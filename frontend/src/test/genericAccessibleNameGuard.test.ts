import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * A11Y-28 (audit 2026-10-03) — un bouton d'action en liste doit porter un nom accessible
 * CONTEXTUALISÉ, jamais un verbe générique répété. Quatorze boutons annonçaient « Supprimer » /
 * « Modifier » / « Retirer » à l'identique, d'une ligne à l'autre : un lecteur d'écran qui tabule
 * une liste de dix équipes entend « Supprimer » dix fois sans savoir laquelle.
 *
 * Ce garde STATIQUE (modèle `dayPickerGuard`) rougit dès qu'un fichier de `src/features/**` ou
 * `src/app/**` porte un `aria-label` LITTÉRAL générique nu (`aria-label="Supprimer"` etc.). La
 * forme sanctionnée est un gabarit : `aria-label={`Supprimer l'équipe ${team.name}`}` (nom qui
 * nomme SA cible). Un `title` générique reste permis (infobulle souris, pas le nom accessible).
 *
 * PORTÉE délibérée : `src/features/admin/**` (console, hors écrans de l'app club) et les
 * `*.test.ts(x)` sont exclus. Regex LITTÉRALES uniquement (gate Semgrep).
 */
const ROOTS = [join(import.meta.dirname, "..", "features"), join(import.meta.dirname, "..", "app")];

// Verbes nus interdits en nom accessible (`aria-label="<verbe>"` sans cible).
const GENERIC_LABEL = /aria-label="(Supprimer|Modifier|Retirer|Éditer|Supprimer cette ligne)"/;

function hasGenericName(source: string): boolean {
  return GENERIC_LABEL.test(source);
}

function isExempt(rel: string): boolean {
  return rel.includes("/admin/");
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

describe("Noms accessibles génériques → contextualisés (A11Y-28)", () => {
  it("aucun aria-label générique nu dans features/ ou app/, hors admin", () => {
    const offenders: string[] = [];
    for (const root of ROOTS) {
      for (const file of sourceFiles(root)) {
        const rel = file.slice(file.indexOf("/src/") + 5);
        if (isExempt(rel)) continue;
        if (hasGenericName(readFileSync(file, "utf8"))) {
          offenders.push(rel);
        }
      }
    }
    expect(
      offenders,
      "Nom accessible générique : contextualiser (`aria-label={`Supprimer l'équipe ${name}`}`) pour qu'un lecteur d'écran nomme SA cible (A11Y-28, audit 2026-10-03).",
    ).toEqual([]);
  });

  it("le garde mord : un verbe nu est repéré, un nom contextualisé ne l'est pas", () => {
    expect(hasGenericName('<button aria-label="Supprimer">')).toBe(true);
    expect(hasGenericName("<button aria-label={`Supprimer l'équipe ${team.name}`}>")).toBe(false);
    expect(hasGenericName('<button aria-label="Supprimer la fenêtre d\'accès">')).toBe(false);
  });
});
