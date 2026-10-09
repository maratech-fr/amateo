import { readFileSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Échec de lecture rendu comme du vide (AUD-UXS-09 / UXS-10) — retour d'audit 2026-10-03 : quand
 * un GET échoue SANS cache (F5 pendant une panne, 500, redéploiement), un écran de route rendait un
 * VIDE crédible (« Rien à l'horizon. Tout roule. », « Aucun planning — passez par l'assistant »,
 * étape Équipes à zéro) au lieu de « Le chargement a échoué. [Réessayer] ». Le patron maison existe
 * (`readState` + `LoadErrorHint`, `shared/lib/readState.ts` / `shared/components/ui/load-error-hint.tsx`)
 * et avait déjà été appliqué deux fois (zone matchs, UXS-05/08) : ce garde STATIQUE empêche la 4ᵉ
 * récidive, sur le modèle de `pageHeaderGuard.test.ts` (liste NOMINATIVE + exemptions motivées).
 *
 * Il tient deux promesses :
 *
 *   1. Chaque PAGE DE ROUTE qui rend les données d'un club (liste nominative `READ_STATE_PAGES`)
 *      référence le patron (`readState`/`readFailed`/`readLoading`/`LoadErrorHint`, ou l'union
 *      `PeriodAnchor` qui en est le pendant pour le plan d'une période) — sinon un échec de lecture
 *      y repasse pour du vide.
 *   2. TOUT module de page cité dans `app/routes.tsx` est CLASSÉ (requis OU exempté avec raison) :
 *      une nouvelle route de données ne peut pas échapper au garde en silence.
 *
 * PORTÉE = modules de page de `app/routes.tsx`. Une exemption dit POURQUOI la page n'a pas de lecture
 * rendue comme vide (404 statique, layout dont les lectures vivent dans les enfants, fail-open
 * documenté, store mémoire, écran d'auth/public/légal hors shell club authentifié, console admin).
 */
const SRC_ROOT = join(import.meta.dirname, "..");

/** Le patron maison de l'échec de lecture — l'une de ces références suffit. */
const READ_STATE = /readState|readFailed|readLoading|LoadErrorHint|PeriodAnchor/;

// Les pages de route qui rendent les données d'un club : chacune DOIT référencer le patron.
const READ_STATE_PAGES = [
  "features/cockpit/CockpitPage.tsx",
  "features/planning/PlanningPage.tsx",
  "features/wizard/WizardLayout.tsx",
  "features/profile/ProfilePage.tsx",
  "features/club/ClubPage.tsx",
  "features/release-notes/ReleaseNotesPage.tsx",
  "features/mailbox/MailboxPage.tsx",
  // Zone matchs — déjà migrée (UXS-05/08), gardée ici contre une régression.
  "features/matches/ImportPage.tsx",
  "features/matches/ConfigurationPage.tsx",
  "features/matches/ConstraintsPage.tsx",
  "features/matches/OpponentsPage.tsx",
  "features/matches/TypicalWeekPage.tsx",
  "features/matches/ConflictsPage.tsx",
];

// Modules de page exemptés — raison nominative (pas de lecture rendue comme vide).
const EXEMPTIONS: { file: string; reason: string }[] = [
  { file: "app/NotFoundPage.tsx", reason: "404 statique — aucune lecture de données" },
  {
    file: "features/matches/MatchesLayout.tsx",
    reason: "layout (garde socle + navigation) ; les lectures de données vivent dans ses pages enfants, déjà gardées",
  },
  {
    file: "features/matches/MatchesLanding.tsx",
    reason: "route d'atterrissage fail-open DOCUMENTÉE vers le Calendrier (lui-même gardé) — routes.tsx:150-157",
  },
  {
    file: "features/matches/ReconciliationView.tsx",
    reason: "vit d'un payload mémoire (store) ; un accès direct sans payload redirige proprement — routes.tsx:201-206",
  },
  {
    file: "features/legal/PrivacyPage.tsx",
    reason: "page légale statique — aucune lecture de données",
  },
  {
    file: "features/coach-wishes/PublicWishPage.tsx",
    reason: "page publique à token, hors shell club authentifié (le token EST l'identité) — son échec de lecture est hors du périmètre de ce lot",
  },
  {
    file: "features/coach-wishes/PreviewWishPage.tsx",
    reason: "aperçu gestionnaire standalone (D2) — rend le formulaire public ; son query.isError affiche « Aperçu indisponible », jamais un vide crédible",
  },
  // Écrans d'AUTH / approbation hors shell club : formulaires + mutations, pas de GET rendu comme vide.
  { file: "features/auth/LoginPage.tsx", reason: "écran d'auth hors shell club — formulaire, pas de lecture rendue comme vide" },
  { file: "features/auth/RegisterPage.tsx", reason: "écran d'auth hors shell club — formulaire" },
  { file: "features/auth/ForgotPasswordPage.tsx", reason: "écran d'auth hors shell club — formulaire" },
  { file: "features/auth/ResetPasswordPage.tsx", reason: "écran d'auth hors shell club — formulaire à token" },
  { file: "features/auth/VerifyEmailPage.tsx", reason: "écran d'auth à token hors shell club — action, pas d'écran de données de club" },
  { file: "features/auth/ConfirmEmailChangePage.tsx", reason: "écran d'auth à token hors shell club — action" },
  { file: "features/auth/ClubApprovalPage.tsx", reason: "page publique d'approbation à token (le token EST l'identité), hors shell club" },
  { file: "features/auth/InvitationPage.tsx", reason: "page publique d'invitation à token (le token EST l'identité), hors shell club — son info.isError rend « Lien invalide ou expiré », pas un vide crédible" },
  { file: "features/auth/WaitingApprovalPage.tsx", reason: "écran d'attente d'approbation hors shell club" },
  // Console superadmin — même exemption que les autres gardes (hors app club).
  { file: "features/admin/AdminLoginPage.tsx", reason: "console superadmin — hors app club" },
  { file: "features/admin/AdminShell.tsx", reason: "console superadmin — hors app club" },
  { file: "features/admin/AdminDashboardPage.tsx", reason: "console superadmin — hors app club" },
];

const ROUTES = readFileSync(join(SRC_ROOT, "app/routes.tsx"), "utf8");

/** Les modules de page cités dans routes.tsx : les `import("@/…")` paresseux + les pages EAGER. */
function routeModuleFiles(): string[] {
  const files = new Set<string>();
  for (const m of ROUTES.matchAll(/import\("(@\/(?:features|app)\/[^"]+)"\)/g)) {
    files.add(`${m[1].slice(2)}.tsx`);
  }
  // Pages EAGER (importées en tête de routes.tsx, pas en `lazy`) — nominatives.
  files.add("features/auth/LoginPage.tsx");
  files.add("app/NotFoundPage.tsx");
  return [...files];
}

function isExempt(rel: string): boolean {
  return EXEMPTIONS.some((e) => e.file === rel);
}

describe("readState — échec de lecture jamais rendu comme du vide (UXS-09/10)", () => {
  it("chaque page de données de club référence le patron readState / LoadErrorHint", () => {
    const offenders: string[] = [];
    for (const rel of READ_STATE_PAGES) {
      const source = readFileSync(join(SRC_ROOT, rel), "utf8");
      if (!READ_STATE.test(source)) {
        offenders.push(`${rel} → ne référence ni readState/readFailed/readLoading, ni LoadErrorHint, ni PeriodAnchor`);
      }
    }
    expect(
      offenders,
      "Une page de route qui rend les données d'un club doit traiter l'échec de lecture (readState + LoadErrorHint), sinon un GET en panne repasse pour du vide. Voir shared/lib/readState.ts.",
    ).toEqual([]);
  });

  it("tout module de page cité dans routes.tsx est classé (requis ou exempté motivé)", () => {
    const required = new Set(READ_STATE_PAGES);
    const unclassified: string[] = [];
    for (const rel of routeModuleFiles()) {
      if (required.has(rel) || isExempt(rel)) {
        continue;
      }
      unclassified.push(rel);
    }
    expect(
      unclassified,
      "Une page de route n'est ni dans READ_STATE_PAGES (elle rend des données de club → traite l'échec de lecture) ni dans EXEMPTIONS (avec sa raison). Classez-la.",
    ).toEqual([]);
  });
});
