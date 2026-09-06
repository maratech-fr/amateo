import AxeBuilder from "@axe-core/playwright";
import { expect, type Page } from "@playwright/test";

import { THEME_STORAGE_KEY } from "../../src/shared/stores/themeStore";

/** Unique ARA per run — the dev DB is not rolled back between e2e runs. */
export function uniqueAra(prefix: string): string {
  const rand = Math.floor(Math.random() * 1_000_000).toString(36);
  return (prefix + Date.now().toString(36) + rand).toUpperCase().replace(/[^A-Z0-9]/g, "").slice(0, 20);
}

const MAILPIT_URL = process.env.MAILPIT_WEB_URL ?? "http://127.0.0.1:8025";

interface RegisterOpts {
  email: string;
  ara: string;
  firstName?: string;
  lastName?: string;
  password?: string;
  /** Provide for a NEW club; omit when joining an existing ARA. */
  clubName?: string;
}

/** Fill and submit the register form, then assert the "check your email" state. */
export async function submitRegister(page: Page, opts: RegisterOpts): Promise<void> {
  // ⚑ Boîte VIDÉE avant d'inscrire. `fetchVerificationToken` cherche `to:{email}` et prend le
  // PREMIER résultat : sur une Mailpit qui s'accumule (des dizaines de runs locaux, la base dev
  // n'étant jamais réinitialisée), la recherche finit par ne plus rendre le bon message et le
  // parcours échoue dans la lecture du mail — un échec qui ressemble à un bug produit et n'en
  // est pas. Constaté le 2026-08-21 en local. Best-effort : en CI la boîte est neuve, c'est un
  // no-op ; si Mailpit ne répond pas, on continue — ce n'est pas le sujet du test.
  await page.request.delete(`${MAILPIT_URL}/api/v1/messages`).catch(() => undefined);
  await page.goto("/register");
  // Étape 1 = choix du sport (basket) → étape 2 = les champs (retour fondateur 2026-07-18).
  await page.getByRole("button", { name: /continuer/i }).click();
  const password = opts.password ?? "Password123!";
  await page.getByLabel("Prénom").fill(opts.firstName ?? "Jean");
  await page.getByLabel("Nom", { exact: true }).fill(opts.lastName ?? "Dupont");
  await page.getByLabel("Email", { exact: true }).fill(opts.email);
  await page.getByLabel("Mot de passe", { exact: true }).fill(password);
  // Champ de confirmation obligatoire (le bouton reste désarmé sans lui).
  await page.getByLabel("Confirmer le mot de passe").fill(password);
  await page.getByLabel(/code ara/i).fill(opts.ara);
  if (undefined !== opts.clubName) {
    await page.getByLabel(/nom du club/i).fill(opts.clubName);
  }
  // RGPD : consentement CGU/confidentialité obligatoire (le bouton reste
  // désarmé sans la case).
  await page.getByRole("checkbox", { name: /j'accepte/i }).check();
  await page.getByRole("button", { name: /créer le compte/i }).click();
  await expect(page.getByText(/email de confirmation/i)).toBeVisible({ timeout: 15_000 });
}

/**
 * Registration no longer authenticates (A3): the JWT is issued only via the emailed
 * verification link. Pull that email out of Mailpit, extract the raw token, and visit
 * /verify-email/:token on the e2e origin (the email's absolute FRONTEND_BASE_URL may
 * differ from the e2e base URL, so only the token is reused).
 */
async function fetchVerificationToken(page: Page, email: string): Promise<string> {
  let token = "";
  await expect
    .poll(
      async () => {
        const search = await page.request.get(`${MAILPIT_URL}/api/v1/search`, { params: { query: `to:${email}` } });
        if (!search.ok()) return "";
        const first = (await search.json()).messages?.[0];
        if (undefined === first) return "";
        const detail = await page.request.get(`${MAILPIT_URL}/api/v1/message/${first.ID}`);
        const body: string = (await detail.json()).Text ?? "";
        token = body.match(/verify-email\/([a-f0-9]{64})/)?.[1] ?? "";
        return token;
      },
      { timeout: 15_000 },
    )
    .not.toBe("");
  return token;
}

/** Register a fresh account and follow its verification link — lands in the app. */
export async function registerAndVerify(page: Page, opts: RegisterOpts): Promise<void> {
  await submitRegister(page, opts);
  const token = await fetchVerificationToken(page, opts.email);
  await page.goto(`/verify-email/${token}`);

  // P3-4 : la vérification ne matérialise plus le club — la demande attend
  // l'approbation du club (mail FFBB) ou du superadmin. Les specs testent
  // l'APRÈS-création : on approuve via le relais dev (le vrai service
  // d'approbation, 404 en prod), puis on recharge pour entrer dans l'app.
  // SEC-16 : le JWT n'est plus lisible depuis le JS (cookie httpOnly). On attend
  // le DRAPEAU de session, et l'appel API part sans en-tête : `page.request`
  // partage le bocal à cookies du contexte, donc il est authentifié tout seul —
  // exactement comme le navigateur du gestionnaire.
  await page.waitForFunction(() => {
    const raw = window.localStorage.getItem("cs-auth");
    return null !== raw && true === (JSON.parse(raw) as { state?: { isAuthenticated?: boolean } })?.state?.isAuthenticated;
  });
  const approved = await page.request.post("/api/dev/approve-club-request");
  // 404 = pas de demande en attente (ex. adhésion à un club existant) — flux inchangé.
  if (approved.ok()) {
    await page.goto("/");
  }
}

/**
 * WCAG 2.2 AA colour-contrast gate (1.4.3) — real browser only. jsdom (vitest) has
 * no layout engine and cannot compute contrast; this runs axe-core inside Playwright
 * Chromium against the live app. Scoped to the `color-contrast` rule so a regression
 * is a precise, actionable failure (structural WCAG is the jsx-a11y + vitest-axe job).
 * `label` names the screen (+ theme) in the failure output.
 */
export async function expectNoContrastViolations(page: Page, label: string, include?: string): Promise<void> {
  const builder = new AxeBuilder({ page }).withRules(["color-contrast"]);
  // `include` scopes the scan to one subtree — e.g. a modal's own dialog, so what sits BEHIND
  // it (a grid with its own, pre-existing contrast debt) is not attributed to this screen.
  const results = await (include === undefined ? builder : builder.include(include)).analyze();
  const offenders = results.violations.flatMap((v) =>
    v.nodes.map((n) => `  ${n.target.join(" ")} — ${(n.failureSummary ?? "").split("\n").join(" ")}\n    HTML: ${n.html}`),
  );
  expect(offenders, `${label}: colour-contrast (WCAG 1.4.3) violations:\n${offenders.join("\n")}`).toEqual([]);
}

/**
 * Persist the theme mode before the app boots (zustand-persist key `cs-theme`)
 * AND kill transitions/animations, so axe samples settled colours — a
 * `transition-colors` mid-flight briefly reads intermediate, sub-AA values.
 */
export async function forceTheme(page: Page, mode: "dark" | "light"): Promise<void> {
  await page.addInitScript(
    ({ key, m }) => {
      window.localStorage.setItem(key, JSON.stringify({ state: { mode: m, accent: null }, version: 1 }));
      const style = document.createElement("style");
      style.textContent = "*,*::before,*::after{transition:none!important;animation:none!important}";
      document.documentElement.appendChild(style);
    },
    { key: THEME_STORAGE_KEY, m: mode },
  );
}

/**
 * Attend que le VOILE BLOQUANT (lot C, `app/ActionVeil`) se soit LEVÉ avant d'interagir.
 *
 * ⚠ RÉSERVE, plus utilisé dans `journey.spec` : depuis le 2026-08-21, le contexte « page » ne
 * BLOQUE qu'à 250 ms (quand le voile est visible), donc une saisie rapide (< 250 ms après une
 * transition) rentre AVANT tout `inert` — le parcours n'a plus besoin d'attendre. Ce helper reste
 * pour un chargement LENT (> 250 ms) : là, le contenu passe `inert` et un `fill` tombé pendant est
 * perdu EN SILENCE — Playwright n'attend PAS l'inert pour un `fill` (contrairement à un `click`, qui
 * attend le hit-test de l'overlay). On respecte alors le voile, on ne le contourne pas : on attend
 * qu'il se lève, comme un humain qui attend que la page réponde. Idempotent : sans voile, ne coûte
 * que la petite fenêtre de détection.
 */
export async function settleVeil(page: Page): Promise<void> {
  // Laisse la transition armer le voile (montage + démarrage des premiers chargements de la
  // destination), puis attend sa levée. La temporisation borne la course « le voile n'est pas
  // encore apparu » — sans elle, un `toBeHidden` immédiat passerait AVANT que le voile ne s'arme.
  await page.waitForTimeout(120);
  await expect(page.getByTestId("action-veil")).toBeHidden({ timeout: 20_000 });
}

/**
 * **Enregistre les appels d'API qui ÉCHOUENT, pour qu'un parcours qui casse dise POURQUOI.**
 *
 * ⚑ Écrit après l'avoir payé deux fois dans la même journée (2026-08-21, PR #684 puis #687) :
 * `journey.spec.ts` a échoué sur `expect(page.locator('input[value="SM1"]')).toBeVisible()` —
 * « element(s) not found », trois tentatives, puis une relance complète verte. Ce message dit ce
 * qui MANQUE à l'écran ; il ne dit pas si la création d'équipe a été refusée par le serveur, si
 * elle a répondu 422, ou si c'est la liste qui ne s'est pas rafraîchie. Deux enquêtes pour rien.
 *
 * ⚠ **Ce n'est pas une assertion, et c'est délibéré.** On n'échoue PAS sur un appel en erreur :
 * certains parcours en provoquent exprès (fail-closed, 404 attendus, refus de rôle). On COLLECTE,
 * et l'appelant attache la liste au rapport quand le test tombe — le diagnostic arrive avec
 * l'échec, sans transformer un 4xx légitime en faux rouge.
 *
 * Retourne le tableau vivant : il se remplit au fil du test.
 */
export function watchFailedApiCalls(page: Page): string[] {
  const failures: string[] = [];
  page.on("response", (response) => {
    const url = new URL(response.url());
    if (!url.pathname.startsWith("/api/") || response.status() < 400) {
      return;
    }
    failures.push(`${response.request().method()} ${url.pathname}${url.search} → ${response.status()}`);
  });

  return failures;
}
