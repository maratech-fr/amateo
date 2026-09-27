import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * En-tête de page unifié (feat/entete-page-signaler) — retour fondateur 2026-09-27 : « les titres
 * ne sont pas sous la même brique ; je veux partout le trait rouge + titre à gauche et, tout à
 * droite, le bouton Signaler, comme dans le Planning ». La primitive `PageHeader`
 * (`shared/components/ui/page-header.tsx`) est cette brique unique : un `h1` à trait d'accent + un
 * bouton « Signaler » TOUJOURS visible. Ce garde STATIQUE tient deux promesses, sur le modèle de
 * `textOpacityGuard.test.ts` :
 *
 *   1. Chaque PAGE PRINCIPALE (liste nominative) rend `<PageHeader …>` et ne pose plus de `<h1`
 *      brut — sinon un écran retombe dans le patron « titre plus petit, Signaler invisible » que
 *      le fondateur a signalé.
 *   2. AUCUN autre fichier de `src/` ne pose de `<h1` brut hors des exemptions nominatives (wizard,
 *      console admin, écran système, RouteErrorBoundary, la primitive elle-même) : le `h1` de
 *      l'application passe par `PageHeader`, ou il est justifié ici.
 *
 * PORTÉE = sources `.tsx` de `src/` ; les `*.test.tsx` sont EXCLUS. Ce fichier est un `.test.ts`.
 */
const SRC_ROOT = join(import.meta.dirname, "..");

// Les pages qui portent l'en-tête d'écran principal — chacune DOIT rendre `PageHeader`.
const PRINCIPAL_PAGES = [
  "features/planning/PlanningPage.tsx",
  "features/matches/MatchesLayout.tsx",
  "features/club/ClubPage.tsx",
  "features/profile/ProfilePage.tsx",
  "features/release-notes/ReleaseNotesPage.tsx",
  "features/legal/PrivacyPage.tsx",
  "features/cockpit/CockpitPage.tsx",
];

// Fichiers autorisés à poser un `<h1` sans `PageHeader` — raison nominative.
const H1_EXEMPTIONS: { file: string; reason: string }[] = [
  { file: "shared/components/ui/page-header.tsx", reason: "la primitive elle-même : c'est ELLE qui pose le h1 à trait" },
  { file: "features/wizard/", reason: "le wizard porte son propre en-tête d'étape (StepRail + titre), hors du patron page" },
  { file: "features/admin/", reason: "console superadmin — en-têtes propres (AdminAuthLayout, AdminDashboardPage), hors app club" },
  { file: "shared/components/ui/system-screen.tsx", reason: "écran système hors providers (503/offline/500) — marque en BrandMark, pas de PageHeader" },
  { file: "app/RouteErrorBoundary.tsx", reason: "garde-fou d'erreur de route, monté hors du shell applicatif" },
];

const H1 = /<h1[\s>]/;
const PAGE_HEADER = /<PageHeader[\s/>]/;

function isH1Exempt(rel: string): boolean {
  return H1_EXEMPTIONS.some((e) => rel.includes(e.file));
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

describe("PageHeader — brique d'en-tête unique (trait + titre + Signaler)", () => {
  it("chaque page principale rend PageHeader et ne pose plus de <h1 brut", () => {
    const offenders: string[] = [];
    for (const rel of PRINCIPAL_PAGES) {
      const source = readFileSync(join(SRC_ROOT, rel), "utf8");
      if (!PAGE_HEADER.test(source)) {
        offenders.push(`${rel} → ne rend pas <PageHeader …> (l'en-tête d'écran doit passer par la brique partagée)`);
      }
      if (H1.test(source)) {
        offenders.push(`${rel} → pose encore un <h1 brut (le titre doit venir de PageHeader)`);
      }
    }
    expect(
      offenders,
      "Une page principale doit rendre PageHeader (trait + titre + Signaler) et ne plus poser de <h1 brut. Voir shared/components/ui/page-header.tsx.",
    ).toEqual([]);
  });

  it("aucun <h1 brut ailleurs dans src/, hors exemptions nominatives", () => {
    const offenders: string[] = [];
    for (const file of tsxFiles(SRC_ROOT)) {
      const rel = file.slice(SRC_ROOT.length + 1);
      if (isH1Exempt(rel)) continue;
      if (H1.test(readFileSync(file, "utf8"))) {
        offenders.push(`src/${rel}`);
      }
    }
    expect(
      offenders,
      "Un <h1 doit venir de PageHeader (brique unique) ou entrer dans H1_EXEMPTIONS avec sa raison (wizard/admin/système/route-error/primitive).",
    ).toEqual([]);
  });
});
