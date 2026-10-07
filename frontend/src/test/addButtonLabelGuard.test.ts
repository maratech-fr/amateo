import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * P4-285 (« premier contact bêta », décision fondateur 2026-10-07) — un bouton d'AJOUT porte
 * toujours un libellé VISIBLE (« + Ajouter … »), jamais une icône « + » seule. Un bouton réduit
 * à son icône n'annonce rien au survol d'un œil qui découvre l'écran et oblige à deviner le geste.
 *
 * Ce garde STATIQUE (modèle `genericAccessibleNameGuard`) rougit dès qu'un `<Button>` de
 * `src/features/**` ou `src/app/**` déclare `size="icon"` ou `size="icon-sm"` ET un `aria-label`
 * commençant par « Ajouter » — la forme exacte d'un bouton d'ajout réduit à son icône. Le bouton
 * conforme porte un `size` textuel (`sm`/`default`) + l'icône + le texte, et laisse tomber
 * l'`aria-label` (le texte visible devient le nom accessible) — patron `ConstraintsPage`
 * (`<Button size="sm"><Plus className="size-3.5" />Ajouter</Button>`).
 *
 * Analyse par ÉLÉMENT, pas par ligne : le `<Button>` d'un ajout s'étale souvent sur plusieurs
 * lignes (`ConstraintsStep`). On isole sa balise ouvrante en suivant la profondeur d'accolades,
 * pour ne pas s'arrêter au `>` d'un `=>` (`onClick={() => …}`) ou d'une expression `{…}`.
 *
 * PORTÉE délibérée : `src/features/admin/**` (console superadmin) et les `*.test.ts(x)` exclus.
 */
const ROOTS = [join(import.meta.dirname, "..", "features"), join(import.meta.dirname, "..", "app")];

const ICON_SIZE = /size="icon(-sm)?"/;
const ADD_LABEL = /aria-label=("Ajouter|\{`Ajouter)/;

/** Balises ouvrantes `<Button …>`, en suivant la profondeur d'accolades (JSX multi-ligne). */
function buttonOpeningTags(source: string): string[] {
  const tags: string[] = [];
  const re = /<Button\b/g;
  let m: RegExpExecArray | null;
  while (null !== (m = re.exec(source))) {
    let depth = 0;
    let i = m.index;
    for (; i < source.length; i++) {
      const c = source[i];
      if ("{" === c) {
        depth++;
      } else if ("}" === c) {
        depth--;
      } else if (">" === c && 0 === depth) {
        break;
      }
    }
    tags.push(source.slice(m.index, i + 1));
  }
  return tags;
}

function hasIconOnlyAddButton(source: string): boolean {
  return buttonOpeningTags(source).some((tag) => ICON_SIZE.test(tag) && ADD_LABEL.test(tag));
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

describe("Boutons d'ajout toujours libellés (P4-285)", () => {
  it("aucun bouton d'ajout réduit à une icône dans features/ ou app/, hors admin", () => {
    const offenders: string[] = [];
    for (const root of ROOTS) {
      for (const file of sourceFiles(root)) {
        const rel = file.slice(file.indexOf("/src/") + 5);
        if (isExempt(rel)) continue;
        if (hasIconOnlyAddButton(readFileSync(file, "utf8"))) {
          offenders.push(rel);
        }
      }
    }
    expect(
      offenders,
      "Bouton d'ajout réduit à une icône : donner un libellé visible (`<Button size=\"sm\"><Plus className=\"size-3.5\" />Ajouter …</Button>`, patron ConstraintsPage) — P4-285, décision fondateur 2026-10-07.",
    ).toEqual([]);
  });

  it("le garde mord : une icône-ajout est repérée, un bouton libellé ne l'est pas", () => {
    expect(hasIconOnlyAddButton('<Button size="icon" aria-label="Ajouter l\'équipe"><Plus /></Button>')).toBe(true);
    expect(hasIconOnlyAddButton('<Button size="icon-sm" aria-label="Ajouter un gymnase"><Plus /></Button>')).toBe(true);
    // Multi-ligne avec `=>` dans onClick : le scan par accolades ne tronque pas la balise.
    expect(
      hasIconOnlyAddButton('<Button\n  size="icon-sm"\n  onClick={() => add()}\n  aria-label="Ajouter la fenêtre match"\n>\n  <Plus />\n</Button>'),
    ).toBe(true);
    // Conforme : size textuel + texte visible, pas d'aria-label générique.
    expect(hasIconOnlyAddButton('<Button size="sm"><Plus className="size-3.5" />Ajouter</Button>')).toBe(false);
    // Bouton icône mais PAS un ajout (ex. modifier) : hors périmètre de ce garde.
    expect(hasIconOnlyAddButton('<Button size="icon" aria-label="Modifier le créneau"><Pencil /></Button>')).toBe(false);
  });
});
