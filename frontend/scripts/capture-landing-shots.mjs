// @ts-nocheck
/**
 * capture-landing-shots.mjs — prise reproductible des visuels de la vitrine (P5-27).
 *
 * ⚠ CE FICHIER N'EST PAS UN TEST. Il vit dans `frontend/scripts/` (hors
 * `frontend/tests/e2e/`, le `testDir` de `playwright.config.ts`) EXPRÈS : il ne
 * doit JAMAIS tourner en CI. On le lance À LA MAIN, sur une stack dev déjà
 * debout, pour reprendre les 4 captures de `landing/` en clair ET en sombre.
 *
 * Il écrit 8 fichiers dans un dossier gitignoré (`captures/landing-shots/` par
 * défaut) : la copie des `*.png`/`*.jpg` vers `landing/assets/` reste un geste
 * MANUEL, APRÈS validation visuelle (voir README à côté). Le script ne touche ni
 * `landing/` ni `frontend/src/`.
 *
 * Il s'appuie sur du câblage DÉJÀ en place, rien de neuf côté app :
 *  - le thème de l'app est persisté sous `localStorage["cs-theme"]`, forme
 *    `{"state":{"mode":"light"|"dark"}}` (frontend/src/shared/stores/themeStore.ts) ;
 *    on le pose AVANT le premier rendu via `addInitScript`, pas de clic UI ;
 *  - la vitrine dérive `nom.ext → nom-dark.ext` toute seule (landing/index.html) :
 *    il suffit donc de produire la paire claire + sombre aux bons noms.
 *
 * Résolution ESM : `import { chromium } from "@playwright/test"` se résout depuis
 * `frontend/node_modules` PARCE QUE ce fichier vit sous `frontend/` — la
 * résolution ESM part du fichier, pas du cwd. Ne pas le déplacer hors de `frontend/`.
 *
 * Lancement : `cd frontend && DEMO_EMAIL=… DEMO_PASSWORD=… node scripts/capture-landing-shots.mjs`
 */

import { mkdir, readFile } from "node:fs/promises";
import { dirname, isAbsolute, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import { chromium } from "@playwright/test";

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url)); // <repo>/frontend/scripts
const REPO_ROOT = resolve(SCRIPT_DIR, "..", ".."); // <repo>

// --- Configuration par environnement (jamais de secret en dur) --------------

const BASE_URL = (process.env.BASE_URL ?? "http://localhost:5173").replace(/\/+$/, "");
const DEMO_EMAIL = process.env.DEMO_EMAIL ?? "";
const DEMO_PASSWORD = process.env.DEMO_PASSWORD ?? "";
const OUT_DIR = process.env.OUT_DIR
  ? isAbsolute(process.env.OUT_DIR)
    ? process.env.OUT_DIR
    : resolve(REPO_ROOT, process.env.OUT_DIR)
  : join(REPO_ROOT, "captures", "landing-shots");
// Fichier JSON optionnel, local et JAMAIS commité : { "Vrai Nom": "Nom Démo", … }.
// Remplace les noms non génériques dans le DOM au moment de la prise (patron P5-26,
// `.claude/rules/landing.md`). Absent ⇒ aucun scrub (cas du seed démo, déjà anonyme).
const SCRUB_FILE = process.env.SCRUB_FILE ?? "";
// Pour la capture du calendrier des matchs : pin d'un week-end riche (samedi ISO)
// afin d'éviter le vide ET la bascule d'atterrissage vers Conflits (lien profond
// prioritaire, MatchesLanding). Laisser vide pour l'auto (premier week-end ≥ aujourd'hui).
const MATCHS_WEEKEND = process.env.MATCHS_WEEKEND ?? "";

if ("" === DEMO_EMAIL || "" === DEMO_PASSWORD) {
  console.error(
    "DEMO_EMAIL et DEMO_PASSWORD sont requis (identifiants du gestionnaire du club démo).\n" +
      "Exemple : DEMO_EMAIL=demo@example.test DEMO_PASSWORD='…' node scripts/capture-landing-shots.mjs",
  );
  process.exit(1);
}

// --- Définition des 4 écrans de la vitrine ----------------------------------
// `base` = nom du fichier CLAIR (identique aux assets actuels de `landing/`).
// La variante sombre y colle `-dark` avant l'extension (convention `.claude/rules/landing.md`).
// Hauteurs reprises du plan P5-27 (deviceScaleFactor 2 ⇒ images de 2160 px de large).
const SHOTS = [
  { base: "planning.png", route: "/planning", width: 1080, height: 608 },
  {
    base: "matchs.jpg",
    route: "" !== MATCHS_WEEKEND ? `/matchs?semaine=${encodeURIComponent(MATCHS_WEEKEND)}` : "/matchs",
    width: 1080,
    height: 585,
  },
  { base: "matchs-importer.jpg", route: "/matchs/importer", width: 1080, height: 750 },
  // Conflits est déjà regroupé par coach par défaut ; on le fige explicitement.
  { base: "matchs-conflits.jpg", route: "/matchs/conflits?pivot=coach", width: 1080, height: 750 },
];

const THEMES = /** @type {const} */ (["light", "dark"]);
const DEVICE_SCALE_FACTOR = 2;
const JPEG_QUALITY = 80;
const NAV_TIMEOUT = 30_000;

/** Nom de sortie : `planning.png` en clair, `planning-dark.png` en sombre. */
function outName(base, theme) {
  if ("light" === theme) {
    return base;
  }
  const dot = base.lastIndexOf(".");
  return `${base.slice(0, dot)}-dark${base.slice(dot)}`;
}

/** Script d'init : pose le thème AVANT le premier rendu (pas de flash, pas de clic). */
function themeInitScript(mode) {
  // Forme exacte du store zustand-persist (version 1) — cf. themeStore.ts.
  const value = JSON.stringify({ state: { mode }, version: 1 });
  return `try { localStorage.setItem("cs-theme", ${JSON.stringify(value)}); } catch (_) {}`;
}

async function loadScrubMap() {
  if ("" === SCRUB_FILE) {
    return null;
  }
  const path = isAbsolute(SCRUB_FILE) ? SCRUB_FILE : resolve(REPO_ROOT, SCRUB_FILE);
  const raw = await readFile(path, "utf8");
  const map = JSON.parse(raw);
  if (null === map || "object" !== typeof map || Array.isArray(map)) {
    throw new Error(`SCRUB_FILE doit être un objet JSON { "Vrai Nom": "Nom Démo" } : ${path}`);
  }
  return map;
}

/** Remplace dans le DOM rendu tout nom non générique (patron P5-26). */
async function scrubDom(page, scrubMap) {
  if (null === scrubMap) {
    return;
  }
  await page.evaluate((pairs) => {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const nodes = [];
    for (let n = walker.nextNode(); null !== n; n = walker.nextNode()) {
      nodes.push(n);
    }
    for (const node of nodes) {
      let text = node.nodeValue ?? "";
      for (const [from, to] of pairs) {
        if ("" !== from && text.includes(from)) {
          text = text.split(from).join(to);
        }
      }
      if (text !== node.nodeValue) {
        node.nodeValue = text;
      }
    }
  }, Object.entries(scrubMap));
}

/** Attend la stabilité : réseau calme + plus aucun spinner visible (aria-label="Chargement"). */
async function waitForStability(page) {
  await page.waitForLoadState("networkidle", { timeout: NAV_TIMEOUT });
  await page
    .locator('[aria-label="Chargement"]')
    .last()
    .waitFor({ state: "hidden", timeout: NAV_TIMEOUT })
    .catch(() => {
      // Aucun spinner présent : rien à attendre.
    });
}

async function login(page) {
  await page.goto(`${BASE_URL}/login`, { waitUntil: "domcontentloaded", timeout: NAV_TIMEOUT });
  await page.locator("#email").fill(DEMO_EMAIL);
  await page.locator("#password").fill(DEMO_PASSWORD);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.startsWith("/login"), { timeout: NAV_TIMEOUT }),
    page.getByRole("button", { name: /se connecter/i }).click(),
  ]);
  await waitForStability(page);
}

async function captureTheme(browser, theme, scrubMap) {
  const context = await browser.newContext({
    baseURL: BASE_URL,
    viewport: { width: 1080, height: 800 },
    deviceScaleFactor: DEVICE_SCALE_FACTOR,
    reducedMotion: "reduce",
  });
  await context.addInitScript(themeInitScript(theme));
  const page = await context.newPage();
  page.setDefaultTimeout(NAV_TIMEOUT);

  try {
    await login(page);
    for (const shot of SHOTS) {
      const name = outName(shot.base, theme);
      await page.setViewportSize({ width: shot.width, height: shot.height });
      await page.goto(`${BASE_URL}${shot.route}`, { waitUntil: "domcontentloaded", timeout: NAV_TIMEOUT });
      await waitForStability(page);
      await scrubDom(page, scrubMap);
      // Laisse les webfonts/images se poser après le scrub (deviceScaleFactor 2).
      await waitForStability(page);

      const isJpeg = name.endsWith(".jpg") || name.endsWith(".jpeg");
      const dest = join(OUT_DIR, name);
      await page.screenshot({
        path: dest,
        clip: { x: 0, y: 0, width: shot.width, height: shot.height },
        ...(isJpeg ? { type: "jpeg", quality: JPEG_QUALITY } : { type: "png" }),
      });
      console.log(`✓ ${theme.padEnd(5)} → ${name}`);
    }
  } finally {
    await context.close();
  }
}

async function main() {
  await mkdir(OUT_DIR, { recursive: true });
  const scrubMap = await loadScrubMap();
  console.log(`Cible : ${BASE_URL}`);
  console.log(`Sortie : ${OUT_DIR}`);
  console.log(`Scrub DOM : ${null === scrubMap ? "aucun" : `${Object.keys(scrubMap).length} remplacement(s)`}`);

  const browser = await chromium.launch();
  try {
    for (const theme of THEMES) {
      await captureTheme(browser, theme, scrubMap);
    }
  } finally {
    await browser.close();
  }
  console.log(`\nTerminé. 8 fichiers dans ${OUT_DIR}.`);
  console.log("Vérifier chaque image (aucune personne réelle, en-tête « Démo Basket Club ») AVANT de copier dans landing/assets/.");
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
