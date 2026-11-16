import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Bandeau d'information unifié (série « uniformité des écrans », PR 4/7, 2026-10-01) — retour
 * fondateur : « un même encart d'info doit avoir partout la MÊME boîte ». La primitive
 * `NoticeBanner` (`shared/components/ui/notice-banner.tsx`) est cette maison unique : fond opaque
 * `bg-surface-<ton>`, bordure de ton, rayon, padding et texte `text-foreground` — les ~28 derniers
 * bandeaux faits main y ont été ramenés. Ce garde STATIQUE, sur le modèle de `pageHeaderGuard` et
 * `textOpacityGuard`, empêche qu'un bandeau fait main RÉAPPARAISSE :
 *
 *   un fichier `.tsx` de `src/features/**` (hors console admin) qui, sur UNE MÊME LIGNE, pose
 *   `role="status"` ou `role="alert"` ET une classe de BORDURE DE TON
 *   (`border-warning|destructive|accent|success`) rougit — c'est un bandeau d'info hand-made, il doit
 *   passer par `<NoticeBanner …>`.
 *
 * PORTÉE délibérée :
 *  - `src/features/**` ET `src/shared/**` (UXC-27 — les bandeaux faits main de `shared/`, p.ex.
 *    `CreditsBanner`, étaient hors du grep) ; la console superadmin (`features/admin/`) a sa propre palette.
 *  - `.test.tsx` EXCLUS (ce fichier est un `.test.ts`).
 *  - heuristique MÊME LIGNE : les bandeaux faits main posent toujours `role` et `className` sur la
 *    même balise ouvrante — c'est le patron à bloquer. Un ton rangé dans une VARIABLE puis appliqué
 *    via `cn(..., tone)` (p.ex. `FbiDeadlineCard`, une CARTE de statut, pas un bandeau) n'est donc
 *    pas visé : son `border-warning` ne vit pas sur la ligne du `role`. C'est voulu — le garde vise
 *    le littéral inline, le reste reste à la revue.
 *  - un `<NoticeBanner … role="status">` est insensible : le composant ne pose aucune classe
 *    `border-<ton>` dans la source appelante (elle vient de la primitive).
 *
 * EXEMPTIONS : un vrai faux positif (un élément `role`+`border-<ton>` qui n'est PAS un bandeau —
 * p.ex. une cellule de grille) s'inscrit ici, nominativement et motivé. Vide aujourd'hui : tous les
 * bandeaux à `role` ont été migrés.
 */
const SRC_ROOT = join(import.meta.dirname, "..");
const FEATURES_ROOT = join(SRC_ROOT, "features");
const SHARED_ROOT = join(SRC_ROOT, "shared");

const EXEMPTIONS: { file: string; reason: string }[] = [];

const ROLE = /role="(?:status|alert)"/;
const TONE_BORDER = /border-(?:warning|destructive|accent|success)/;

function isExempt(rel: string): boolean {
  return EXEMPTIONS.some((e) => rel.includes(e.file));
}

function tsxFiles(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      if ("admin" === entry.name) {
        continue; // console superadmin : palette propre, hors norme bandeau.
      }
      out.push(...tsxFiles(full));
    } else if (/\.tsx$/.test(entry.name) && !/\.test\.tsx$/.test(entry.name)) {
      out.push(full);
    }
  }
  return out;
}

describe("bandeau d'info unifié — plus aucun bandeau fait main (NoticeBanner est la maison unique)", () => {
  it("aucun `role=status|alert` + bordure de ton sur une même ligne hors NoticeBanner", () => {
    const offenders: string[] = [];
    for (const file of [...tsxFiles(FEATURES_ROOT), ...tsxFiles(SHARED_ROOT)]) {
      const rel = file.slice(SRC_ROOT.length + 1);
      if (isExempt(rel)) {
        continue;
      }
      const lines = readFileSync(file, "utf8").split("\n");
      lines.forEach((line, i) => {
        if (ROLE.test(line) && TONE_BORDER.test(line)) {
          offenders.push(`${rel}:${i + 1}`);
        }
      });
    }
    expect(offenders, `Bandeau fait main détecté — remplacez le div/p par <NoticeBanner …> :\n${offenders.join("\n")}`).toEqual([]);
  });
});
