import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Uniformité des écrans (série « uniformité », PR 5/7) — norme fondateur : une pastille d'ÉTAT
 * (bordure/fond teinté + texte court) passe par la primitive partagée `StatusPill`
 * (`shared/components/ui/badge.tsx`, variantes warning/accent/accent-solid/neutral), jamais un
 * `<span>` arrondi recodé à la main. Sinon chaque écran réinvente sa pastille et la famille UXC
 * « même chose, au même endroit, de la même façon » se défait.
 *
 * Ce garde STATIQUE (sur le modèle de `deleteConfirmGuard`/`textOpacityGuard`) rougit, LIGNE à
 * LIGNE, dès qu'une ligne de `src/features/**` porte à la fois la FORME d'une pastille
 * (`rounded-full` + `text-xs`) ET une classe de TON (teinte ou bordure `warning|destructive|accent|
 * success`, ou une surface opaque `bg-surface-*`) — la signature d'une pastille d'état faite main.
 *
 * PORTÉE délibérée :
 *  - Les pastilles NEUTRES `bg-muted`/`border-border` ne sont PAS attrapées (classes trop communes
 *    pour un grep fiable) ; leur passage à `StatusPill` relève de la revue, pas du garde.
 *  - `features/admin/**` est EXEMPTÉ (console superadmin, hors écrans de l'app club — même
 *    exemption de périmètre que `pageHeaderGuard`/`deleteConfirmGuard`).
 *  - Les `*.test.tsx` sont EXCLUS (ils peuvent asserter une classe littérale). Ce fichier est un
 *    `.test.ts`, hors du glob `.tsx`.
 *
 * Regex LITTÉRALES uniquement (pas de `new RegExp(<variable>)`, gate Semgrep).
 */
const FEATURES_ROOT = join(import.meta.dirname, "..", "features");

interface Exemption {
  path: string;
  reason: string;
}

const EXEMPTIONS: Exemption[] = [
  { path: "features/admin/", reason: "console superadmin — hors périmètre des écrans de l'app club (même exemption de périmètre que pageHeaderGuard/deleteConfirmGuard)" },
  { path: "features/wizard/steps/CoachesStep.tsx", reason: "pastilles de LIAISON coach/joueur ↔ équipe (certaines avec un bouton « Retirer ») — hors contrat StatusPill, geste réversible : exemptées nominativement, exactement comme dans deleteConfirmGuard (N2)" },
  { path: "features/coach-wishes/CampaignDialog.tsx", reason: "puces de FILTRE interactives (`<button>` `aria-pressed`, bascule) — relèvent de FilterChip, pas de la pastille d'état statique StatusPill" },
  { path: "features/planning/DriftBanner.tsx", reason: "bouton-bascule (`<button>` à fond de survol `hover:bg-warning/20`) — un contrôle interactif, pas une pastille d'état" },
];

// FORME d'une pastille : coins ronds + petit texte.
const PILL_SHAPE = /rounded-full/;
const PILL_TEXT = /text-xs/;
// TON : teinte/bordure d'un jeton de ton, ou surface opaque teintée. `bg-accent` attrape aussi
// `bg-accent/10`, `border-accent` aussi `border-accent/50` — c'est voulu (toute intensité de ton).
const PILL_TONE = /bg-surface-|border-warning|border-destructive|border-accent|border-success|bg-warning|bg-destructive|bg-accent|bg-success/;

/** Une ligne est une pastille de TON faite main si elle porte la forme, le petit texte ET un ton. */
function isHandMadeTonePill(line: string): boolean {
  return PILL_SHAPE.test(line) && PILL_TEXT.test(line) && PILL_TONE.test(line);
}

function isExempt(rel: string): boolean {
  return EXEMPTIONS.some((e) => rel.includes(e.path));
}

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

describe("Pastilles de ton faites main → StatusPill (série uniformité, PR 5/7)", () => {
  it("aucune pastille de ton inline (rounded-full + text-xs + teinte/bordure de ton) hors StatusPill dans features/, hors exemption nominative", () => {
    const offenders: string[] = [];
    for (const file of tsxFiles(FEATURES_ROOT)) {
      const rel = `features/${file.slice(FEATURES_ROOT.length + 1)}`;
      if (isExempt(rel)) continue;
      const lines = readFileSync(file, "utf8").split("\n");
      lines.forEach((line, index) => {
        if (isHandMadeTonePill(line)) {
          offenders.push(`src/${rel}:${index + 1} → ${line.trim().slice(0, 100)}`);
        }
      });
    }
    expect(
      offenders,
      "Une pastille d'état teintée doit passer par StatusPill (shared/components/ui/badge.tsx, variante warning/accent/accent-solid), ou entrer dans EXEMPTIONS avec sa raison (liaison, filtre interactif, bascule).",
    ).toEqual([]);
  });

  it("le garde mord : une pastille de ton inline est repérée, une pastille sur StatusPill / sans ton ne l'est pas", () => {
    const handMadeAccent = '<span className="rounded-full bg-accent/15 px-2 py-0.5 text-xs">Source : API FFBB</span>';
    const handMadeWarning = '<span className="rounded-full border border-warning/50 px-2 py-0.5 text-xs text-foreground">À refaire</span>';
    const handMadeSurface = '<span className="rounded-full bg-surface-accent px-1.5 py-0.5 text-xs">partagée</span>';
    const onPrimitive = '<StatusPill variant="accent-solid">Votre offre</StatusPill>';
    // bg-muted NEUTRE n'est PAS un ton attrapé (largeur documentée) :
    const neutralMuted = '<span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">Info</span>';

    expect(isHandMadeTonePill(handMadeAccent)).toBe(true);
    expect(isHandMadeTonePill(handMadeWarning)).toBe(true);
    expect(isHandMadeTonePill(handMadeSurface)).toBe(true);
    expect(isHandMadeTonePill(onPrimitive)).toBe(false);
    expect(isHandMadeTonePill(neutralMuted)).toBe(false);
  });
});
