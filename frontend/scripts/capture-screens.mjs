// @ts-nocheck
/**
 * capture-screens.mjs — l'outil de capture du contrôle visuel (agent SWITCH).
 *
 * ⚠ CE FICHIER N'EST PAS UN TEST. Il vit dans `frontend/scripts/` (hors `frontend/tests/e2e/`, le
 * `testDir` de `playwright.config.ts`) EXPRÈS : il ne tourne JAMAIS en CI. On le lance à la main,
 * sur une stack déjà debout (par défaut la stack d'épreuve `:8181`).
 *
 * Entrée : un fichier JSON (voir README-capture-screens.md) — base URL, mode de connexion, dossier
 * de sortie, liste d'écrans `{ name, path, viewport?, theme?, waitFor?, fullPage? }`.
 * Sortie : un PNG par écran (`<outDir>/<name>.png`, `outDir` relatif = depuis la racine du dépôt).
 *
 * Chaque écran est capturé seulement une fois RENDU : voile `ActionVeil` retombé, plus aucun spinner
 * `aria-label="Chargement"` visible, témoin `waitFor` visible, pas de renvoi vers `/login`, corps
 * non vide. Sinon : erreur NOMMÉE `✗ <écran> : <raison>`, aucune capture, code de sortie 1. Les
 * autres écrans sont tout de même tentés (un seul passage dit tout ce qui ne va pas).
 *
 * `settleVeil` (`tests/e2e/support.ts`) n'est PAS importé : `support.ts` est du TypeScript qui
 * importe `src/shared/stores/themeStore` sans extension — Node ne le résout pas hors du runner
 * Playwright. Sa règle est reprise telle quelle dans `waitRendered` (120 ms pour laisser le voile
 * s'armer, puis `[data-testid="action-veil"]` caché) ; changer l'un, c'est changer l'autre.
 * Jamais `networkidle` : le hub Mercure garde un flux ouvert, l'attente ne retomberait pas.
 *
 * Identifiants : JAMAIS en dur ni dans le JSON — `CAPTURE_EMAIL` / `CAPTURE_PASSWORD` (mode
 * `login`). Le mode `register` crée un compte de test neuf (club fictif) par l'inscription réelle +
 * Mailpit, comme `registerAndVerify` (`tests/e2e/support.ts`).
 *
 * Résolution ESM : `@playwright/test` se résout depuis `frontend/node_modules` PARCE QUE ce fichier
 * vit sous `frontend/` — ne pas le déplacer. Dans un worktree (sans `node_modules`), repli sur celui
 * du dépôt principal (`loadChromium`).
 *
 * Lancement : `cd frontend && node scripts/capture-screens.mjs <spec.json>`
 */

import { execFileSync } from "node:child_process";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, isAbsolute, join, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url)); // <repo>/frontend/scripts
const REPO_ROOT = resolve(SCRIPT_DIR, "..", "..");

/**
 * `@playwright/test` du projet. Un worktree n'a pas de `frontend/node_modules` : on retombe alors
 * sur celui du dépôt principal (parent du `git-common-dir`) — même version, même Chromium.
 */
async function loadChromium() {
  try {
    return (await import("@playwright/test")).chromium;
  } catch {
    const commonDir = execFileSync("git", ["-C", REPO_ROOT, "rev-parse", "--path-format=absolute", "--git-common-dir"], { encoding: "utf8" }).trim();
    const mainEntry = join(dirname(commonDir), "frontend", "node_modules", "@playwright", "test", "index.mjs");
    try {
      return (await import(pathToFileURL(mainEntry).href)).chromium;
    } catch {
      fail(`@playwright/test introuvable (ni ${REPO_ROOT}/frontend/node_modules, ni ${mainEntry}) — npm ci dans frontend/ d'abord.`);
    }
  }
}

const DEFAULTS = {
  baseUrl: "http://localhost:8181", // stack d'épreuve
  mailpitUrl: "http://localhost:18025", // Mailpit de la stack d'épreuve
  viewport: { width: 1280, height: 800 },
  theme: "light",
};
const TIMEOUT = 20_000;
const THEME_STORAGE_KEY = "cs-theme"; // frontend/src/shared/stores/themeStore.ts
const NAME_RE = /^[a-z0-9][a-z0-9._-]*$/i;

class NamedError extends Error {}

function fromRepo(p) {
  return isAbsolute(p) ? p : resolve(REPO_ROOT, p);
}

function fail(message) {
  console.error(`✗ ${message}`);
  process.exit(1);
}

async function loadSpec(specPath) {
  let spec;
  try {
    spec = JSON.parse(await readFile(specPath, "utf8"));
  } catch (err) {
    fail(`spec illisible (${specPath}) : ${err.message}`);
  }
  if (typeof spec.outDir !== "string" || "" === spec.outDir) fail("spec : `outDir` requis (ex. « captures/mon-chantier »).");
  if (!Array.isArray(spec.screens) || 0 === spec.screens.length) fail("spec : `screens` doit lister au moins un écran.");
  const seen = new Set();
  for (const s of spec.screens) {
    if (typeof s.name !== "string" || !NAME_RE.test(s.name)) fail(`spec : nom d'écran invalide « ${s.name} » (lettres, chiffres, . _ -).`);
    if (seen.has(s.name)) fail(`spec : nom d'écran en double « ${s.name} ».`);
    seen.add(s.name);
    if (typeof s.path !== "string" || !s.path.startsWith("/")) fail(`spec : écran « ${s.name} » — \`path\` doit commencer par « / ».`);
    if (undefined !== s.theme && !["light", "dark"].includes(s.theme)) fail(`spec : écran « ${s.name} » — \`theme\` vaut light ou dark.`);
  }
  const auth = spec.auth ?? { mode: "login" };
  if (!["login", "register", "state", "none"].includes(auth.mode)) fail(`spec : auth.mode inconnu « ${auth.mode} » (login | register | state | none).`);
  return {
    baseUrl: (process.env.CAPTURE_BASE_URL ?? spec.baseUrl ?? DEFAULTS.baseUrl).replace(/\/+$/, ""),
    outDir: fromRepo(spec.outDir),
    viewport: spec.viewport ?? DEFAULTS.viewport,
    theme: spec.theme ?? DEFAULTS.theme,
    auth,
    screens: spec.screens,
  };
}

/** Pose le thème avant le premier rendu et coupe transitions/animations (patron `forceTheme`). */
function themeInit({ key, mode }) {
  try {
    window.localStorage.setItem(key, JSON.stringify({ state: { mode, accent: null }, version: 1 }));
  } catch {
    // stockage indisponible : le thème par défaut s'applique
  }
  const style = document.createElement("style");
  style.textContent = "*,*::before,*::after{transition:none!important;animation:none!important}";
  document.documentElement.appendChild(style);
}

async function goto(page, url) {
  try {
    return await page.goto(url, { waitUntil: "domcontentloaded", timeout: TIMEOUT });
  } catch (err) {
    throw new NamedError(`navigation impossible vers ${url} — ${err.message.split("\n")[0]}`);
  }
}

/** Connexion par le formulaire (identifiants en variables d'environnement). */
async function login(page, baseUrl) {
  const email = process.env.CAPTURE_EMAIL ?? "";
  const password = process.env.CAPTURE_PASSWORD ?? "";
  if ("" === email || "" === password) throw new NamedError("auth login : CAPTURE_EMAIL et CAPTURE_PASSWORD sont requis.");
  await goto(page, `${baseUrl}/login`);
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Mot de passe", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Se connecter" }).click();
  await page
    .waitForURL((u) => !u.pathname.startsWith("/login"), { timeout: TIMEOUT })
    .catch(() => {
      throw new NamedError(`auth login : toujours sur /login après « Se connecter » (identifiants refusés pour ${email} ?).`);
    });
}

/** Lit dans Mailpit le jeton de vérification envoyé à `email` (boucle bornée). */
async function verificationToken(page, mailpitUrl, email) {
  for (let i = 0; i < 30; i++) {
    const search = await page.request.get(`${mailpitUrl}/api/v1/search`, { params: { query: `to:${email}` } }).catch(() => null);
    if (null === search) throw new NamedError(`auth register : Mailpit injoignable (${mailpitUrl}).`);
    const first = search.ok() ? (await search.json()).messages?.[0] : undefined;
    if (undefined !== first) {
      const detail = await (await page.request.get(`${mailpitUrl}/api/v1/message/${first.ID}`)).json();
      const token = (detail.Text ?? "").match(/verify-email\/([a-f0-9]{64})/)?.[1];
      if (undefined !== token) return token;
    }
    await page.waitForTimeout(500);
  }
  throw new NamedError(`auth register : aucun mail de vérification pour ${email} dans Mailpit (${mailpitUrl}).`);
}

/**
 * Crée un compte de test NEUF sur un club FICTIF : inscription réelle → mail Mailpit → lien de
 * vérification → approbation du club par le relais dev (`/api/dev/approve-club-request`, 404 en
 * prod). Même parcours que `registerAndVerify` (`tests/e2e/support.ts`). Aucun nom réel.
 */
async function register(page, baseUrl, auth) {
  const stamp = Date.now().toString(36).toUpperCase();
  const email = `capture-${stamp.toLowerCase()}@e2e.test`;
  const ara = `CAP${stamp}`.slice(0, 20);
  const password = "Password123!";
  await goto(page, `${baseUrl}/register`);
  await page.getByRole("button", { name: /continuer/i }).click();
  await page.getByLabel("Prénom").fill("Camille");
  await page.getByLabel("Nom", { exact: true }).fill("Capture");
  await page.getByLabel("Email", { exact: true }).fill(email);
  await page.getByLabel("Mot de passe", { exact: true }).fill(password);
  await page.getByLabel("Confirmer le mot de passe").fill(password);
  await page.getByLabel(/code club ffbb/i).fill(ara);
  await page.getByLabel(/nom du club/i).fill(auth.clubName ?? "Club Test Capture");
  await page.getByRole("checkbox", { name: /j'accepte/i }).check();
  await page.getByRole("button", { name: /créer le compte/i }).click();
  await page
    .getByText(/email de confirmation/i)
    .waitFor({ timeout: TIMEOUT })
    .catch(() => {
      throw new NamedError("auth register : l'inscription n'a pas abouti (écran « email de confirmation » absent).");
    });
  const token = await verificationToken(page, auth.mailpitUrl ?? DEFAULTS.mailpitUrl, email);
  await goto(page, `${baseUrl}/verify-email/${token}`);
  await page.waitForFunction(
    () => true === JSON.parse(window.localStorage.getItem("cs-auth") ?? "null")?.state?.isAuthenticated,
    null,
    { timeout: TIMEOUT },
  );
  const approved = await page.request.post(`${baseUrl}/api/dev/approve-club-request`);
  if (!approved.ok()) throw new NamedError(`auth register : approbation du club refusée (HTTP ${approved.status()}).`);
  console.log(`• compte de test créé : ${email} / club ${ara} (mot de passe ${password})`);
}

/** Session : renvoie un `storageState` réutilisable par chaque contexte de thème. */
async function authenticate(browser, cfg) {
  const { auth, baseUrl } = cfg;
  if ("none" === auth.mode) return undefined;
  if ("state" === auth.mode) {
    if (typeof auth.file !== "string") throw new NamedError("auth state : `auth.file` requis.");
    return fromRepo(auth.file);
  }
  const context = await browser.newContext({ viewport: cfg.viewport, locale: "fr-FR", timezoneId: "Europe/Paris" });
  const page = await context.newPage();
  try {
    if ("login" === auth.mode) await login(page, baseUrl);
    else await register(page, baseUrl, auth);
    const state = await context.storageState();
    if (typeof auth.saveStateTo === "string") {
      const dest = fromRepo(auth.saveStateTo);
      await mkdir(dirname(dest), { recursive: true });
      await writeFile(dest, JSON.stringify(state));
      console.log(`• session enregistrée dans ${dest} (réutilisable : auth.mode « state »)`);
    }
    return state;
  } finally {
    await context.close();
  }
}

/**
 * Ce qui empêche encore l'écran d'être « rendu », ou `null`. Évalué DANS la page.
 *  - le voile `ActionVeil` (`data-testid="action-veil"`, patron `settleVeil`) ;
 *  - un spinner `aria-label="Chargement"` visible (`Spinner`/`FullPageSpinner`) ;
 *  - un texte d'attente « Chargement… » (barre de navigation `RootShell` — son texte est sr-only —,
 *    indices en texte comme « Chargement des offres… ») ;
 *  - aucun texte VISIBLE (un `sr-only` mesure 1 px : il ne compte pas) — écran vide.
 */
function pendingReason() {
  if (null !== document.querySelector('[data-testid="action-veil"]')) return "voile de chargement (ActionVeil) toujours levé";
  if (Array.from(document.querySelectorAll('[aria-label="Chargement"]')).some((el) => el.getClientRects().length > 0)) {
    return "spinner « Chargement » toujours visible";
  }
  const waiting = (document.body?.innerText ?? "").split("\n").find((line) => /^\s*Chargement\b/.test(line));
  if (undefined !== waiting) return `indicateur « ${waiting.trim()} » toujours affiché`;
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let n = walker.nextNode(); null !== n; n = walker.nextNode()) {
    const rect = "" !== (n.nodeValue ?? "").trim() ? n.parentElement?.getBoundingClientRect() : undefined;
    if (undefined !== rect && rect.width > 1 && rect.height > 1) return null;
  }
  return "écran vide (aucun texte visible)";
}

/** Attend que l'écran soit RENDU à l'utilisateur ; lève une erreur nommée sinon. */
async function waitRendered(page, screen) {
  // Règle de `settleVeil` (tests/e2e/support.ts) : laisser le voile s'armer avant de juger. Le voile
  // de page ne BLOQUE (n'existe dans le DOM) qu'à 250 ms : un seul constat « prêt » peut tomber
  // avant lui. On exige donc DEUX constats « prêt » à 400 ms d'écart, dans la limite de TIMEOUT.
  await page.waitForTimeout(120);
  const deadline = Date.now() + TIMEOUT;
  let reason = null;
  let ready = 0;
  while (ready < 2) {
    if (Date.now() > deadline) throw new NamedError(reason ?? "écran instable (prêt puis de nouveau en attente)");
    reason = await page.evaluate(pendingReason);
    ready = null === reason ? ready + 1 : 0;
    if (ready < 2) await page.waitForTimeout(null === reason ? 400 : 200);
  }
  if (typeof screen.waitFor === "string") {
    await page
      .locator(screen.waitFor)
      .first()
      .waitFor({ state: "visible", timeout: TIMEOUT })
      .catch(() => {
        throw new NamedError(`témoin waitFor « ${screen.waitFor} » jamais visible`);
      });
  }
  const landed = new URL(page.url()).pathname;
  if (landed.startsWith("/login") && !screen.path.startsWith("/login")) {
    throw new NamedError("renvoyé vers /login (session absente ou expirée)");
  }
  await page.evaluate(() => document.fonts.ready);
}

async function captureScreen(browser, cfg, storageState, screen) {
  const theme = screen.theme ?? cfg.theme;
  const context = await browser.newContext({
    viewport: screen.viewport ?? cfg.viewport,
    colorScheme: theme,
    reducedMotion: "reduce",
    locale: "fr-FR",
    timezoneId: "Europe/Paris",
    storageState,
  });
  await context.addInitScript(themeInit, { key: THEME_STORAGE_KEY, mode: theme });
  const page = await context.newPage();
  try {
    const response = await goto(page, `${cfg.baseUrl}${screen.path}`);
    if (null !== response && response.status() >= 400) throw new NamedError(`HTTP ${response.status()} sur ${screen.path}`);
    await waitRendered(page, screen);
    const dest = join(cfg.outDir, `${screen.name}.png`);
    await page.screenshot({ path: dest, fullPage: true === screen.fullPage });
    const landed = new URL(page.url());
    const moved = `${landed.pathname}${landed.search}` !== screen.path ? ` (redirigé vers ${landed.pathname}${landed.search})` : "";
    console.log(`✓ ${screen.name} → ${dest}${moved}`);
  } finally {
    await context.close();
  }
}

async function main() {
  const specArg = process.argv[2];
  if (undefined === specArg) fail("usage : node scripts/capture-screens.mjs <spec.json>");
  const cfg = await loadSpec(resolve(process.cwd(), specArg));
  await mkdir(cfg.outDir, { recursive: true });
  console.log(`Cible : ${cfg.baseUrl} · sortie : ${cfg.outDir} · auth : ${cfg.auth.mode}`);

  const chromium = await loadChromium();
  const browser = await chromium.launch();
  const failures = [];
  try {
    let storageState;
    try {
      storageState = await authenticate(browser, cfg);
    } catch (err) {
      await browser.close();
      fail(err instanceof NamedError ? err.message : `auth ${cfg.auth.mode} : ${err.message}`);
    }
    for (const screen of cfg.screens) {
      try {
        await captureScreen(browser, cfg, storageState, screen);
      } catch (err) {
        const reason = err instanceof NamedError ? err.message : err.message.split("\n")[0];
        failures.push(`${screen.name} : ${reason}`);
        console.error(`✗ ${screen.name} : ${reason}`);
      }
    }
  } finally {
    await browser.close();
  }
  if (failures.length > 0) {
    console.error(`\n${failures.length} écran(s) NON capturé(s) :\n  ${failures.join("\n  ")}`);
    process.exit(1);
  }
  console.log(`\nTerminé : ${cfg.screens.length} capture(s) dans ${cfg.outDir}.`);
}

main().catch((err) => {
  console.error(`✗ ${err.message}`);
  process.exit(1);
});
