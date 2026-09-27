import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * P4-265 — une surface qui porte du texte est OPAQUE sur le fond à motifs du body : `bg-card` ou un
 * jeton `--surface-*`, jamais une teinte semi-transparente `bg-<jeton>/NN` comme FOND AU REPOS. Le
 * piège que ce garde ferme : `bg-card bg-warning/10` ne compose pas (Tailwind n'empile pas deux
 * background-color) et un texte « lisible » sur `background-image` par hasard de couleur passerait le
 * scan de contraste (`a11y-contrast.spec.ts`) sans jamais avoir de fond réel — or jsdom ne calcule ni
 * layout ni fond, donc seul un garde STATIQUE (grep des classes) rougit dans Vitest, comme
 * `textOpacityGuard.test.ts` (A11Y-22).
 *
 * PORTÉE = les PRIMITIVES `src/shared/components/ui/*.tsx` (les maisons uniques : une teinte `/NN`
 * en fond au repos d'une primitive se propage partout). Les `*.test.tsx` sont EXCLUS (ils peuvent
 * asserter une classe littérale). Ce fichier est un `.test.ts` — hors du glob `.tsx`, hors auto-scan.
 *
 * Le lookbehind `(?<!:)` n'attrape QUE le fond AU REPOS : toute variante préfixée
 * (`hover:bg-accent/10`, `focus-visible:bg-…`, `group-hover:bg-…`) est un ÉTAT interactif, exempté —
 * la surbrillance reste une teinte semi-transparente (décision fondateur P4-265 : le survol garde
 * `hover:bg-accent/10`, seul le fond au repos devient plein).
 */
const UI_ROOT = join(import.meta.dirname, "..", "shared", "components", "ui");

const RESTING_TINT = /(?<!:)\bbg-(?:accent|warning|destructive|success|muted|diff|card|background|foreground)\/\d+/;

interface Exemption {
  file: string;
  pattern: RegExp;
  reason: string;
}

// ≤ 5 entrées — un fond teinté LÉGITIME hors du périmètre « surface qui porte du texte ».
const EXEMPTIONS: Exemption[] = [
  {
    file: "badge.tsx",
    pattern: /bg-(?:warning|accent)\/10/,
    reason: "StatusPill — pastille inline (bordure + fond teinté + icône + texte), pas une surface/tuile ; contrastes gardés par a11y-contrast",
  },
];

function uiFiles(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    if (entry.isFile() && /\.tsx$/.test(entry.name) && !/\.test\.tsx$/.test(entry.name)) {
      out.push(join(dir, entry.name));
    }
  }
  return out;
}

function isExempt(name: string, line: string): boolean {
  return EXEMPTIONS.some((e) => name === e.file && e.pattern.test(line));
}

describe("P4-265 — pas de fond teinté /NN au repos sur une primitive de surface", () => {
  it("aucune classe bg-<jeton>/NN comme fond AU REPOS dans shared/components/ui/*.tsx, hors exemption nominative", () => {
    const offenders: string[] = [];
    for (const file of uiFiles(UI_ROOT)) {
      const name = file.slice(UI_ROOT.length + 1);
      const lines = readFileSync(file, "utf8").split("\n");
      lines.forEach((line, index) => {
        if (!RESTING_TINT.test(line)) return;
        if (isExempt(name, line)) return;
        offenders.push(`shared/components/ui/${name}:${index + 1} → ${line.trim().slice(0, 100)}`);
      });
    }
    expect(
      offenders,
      "Fond teinté semi-transparent au repos sur une primitive : poser `bg-card` ou un jeton `--surface-*` OPAQUE (P4-265). Une surbrillance interactive garde sa teinte via une variante préfixée (`hover:bg-…`). Un fond teinté légitime hors périmètre entre dans EXEMPTIONS (≤ 5).",
    ).toEqual([]);
  });
});

/**
 * Retour terrain 2026-09-27 — un CONTRÔLE DE SAISIE partagé (champ, sélecteur, zone de texte) est
 * OPAQUE au repos. `bg-transparent` laissait traverser le fond à motifs du body : le placeholder
 * (« Rechercher un club ou un gymnase ») devenait illisible. La contrainte est plus stricte que le
 * garde P4-265 (qui ne vise que les teintes `/NN`) — `bg-transparent` doit aussi tomber, MAIS
 * seulement sur les contrôles de saisie : un bouton `ghost`/`outline` reste transparent par
 * décision fondateur (il vit sur une surface déjà opaque). D'où une LISTE NOMINATIVE de fichiers
 * de contrôle de saisie, pas un scan large.
 *
 * Le lookbehind `(?<!:)` n'attrape que le fond AU REPOS (une variante `disabled:hover:bg-transparent`
 * est un état, exempté). Contraste placeholder `text-muted-foreground` sur `--card` = 5,60 (mesuré).
 */
const INPUT_CONTROL_FILES = ["input.tsx", "select.tsx", "listbox.tsx", "password-input.tsx", "team-select.tsx", "venue-select.tsx", "new-password-fields.tsx"];

const RESTING_TRANSPARENT = /(?<!:)\bbg-transparent\b/;

// ≤ 5 entrées — un `bg-transparent` LÉGITIME parce qu'il vit DÉJÀ sur une surface opaque.
const TRANSPARENT_EXEMPTIONS: Exemption[] = [
  {
    file: "listbox.tsx",
    pattern: /border-0 bg-transparent/,
    reason: "le champ de recherche du panneau Listbox est imbriqué dans un wrapper `bg-background` opaque (POPUP_FRAME `bg-card`) — pas de fond à motifs à traverser",
  },
];

describe("Contrôle de saisie partagé opaque — pas de bg-transparent au repos", () => {
  it("aucun bg-transparent au repos dans les fichiers de contrôle de saisie partagés, hors exemption nominative", () => {
    const offenders: string[] = [];
    for (const name of INPUT_CONTROL_FILES) {
      const lines = readFileSync(join(UI_ROOT, name), "utf8").split("\n");
      lines.forEach((line, index) => {
        if (!RESTING_TRANSPARENT.test(line)) return;
        if (TRANSPARENT_EXEMPTIONS.some((e) => name === e.file && e.pattern.test(line))) return;
        offenders.push(`shared/components/ui/${name}:${index + 1} → ${line.trim().slice(0, 100)}`);
      });
    }
    expect(
      offenders,
      "Contrôle de saisie transparent au repos : poser `bg-card` (ou `bg-background`) — un champ sur le fond à motifs du body rend son placeholder illisible. Un fond transparent légitime (contrôle déjà sur une surface opaque) entre dans TRANSPARENT_EXEMPTIONS (≤ 5).",
    ).toEqual([]);
  });
});
