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
 * ── Cible : le club BCCL de DÉVELOPPEMENT du bac à sable (décision fondateur
 *    2026-10-06, option A). `app:bccl:seed` (profil dev) sème le club
 *    « B CHARPENNES CROIX LUIZET » (ARA0069036, gestionnaire FICTIF
 *    `dev-bccl@amateo.local`, planning de saison VALIDÉ) — PAS le club démo, dont
 *    la remise à zéro le laisse avant génération et sans matchs. Le club dev est
 *    un VRAI club (non-démo) : on N'y pose JAMAIS d'horloge simulée (règle
 *    fondateur 2026-10-02) — les captures matchs utilisent l'horloge RÉELLE et
 *    `MATCHS_WEEKEND` choisit le week-end affiché.
 *
 * ── Anonymisation à l'écran (OBLIGATOIRE, `.claude/rules/landing.md` §captures) :
 *    un club RÉEL n'apparaît jamais sur la vitrine. Avant chaque capture le script
 *    (a) remplace dans le DOM le nom du club et son code FFBB par des valeurs de
 *    démo (table par défaut ci-dessous + `SCRUB_FILE` optionnel), (b) retire le
 *    BLASON du club de l'en-tête (`<img>` du logo club) — l'app retombe alors sur
 *    le monogramme produit neutre qu'elle affiche quand un club n'a pas de logo
 *    (patron P5-26 : blason retiré du DOM). Coachs déjà fictifs (surnoms du seed) ;
 *    gymnases et clubs adverses = données publiques autorisées.
 *
 * Il s'appuie sur du câblage DÉJÀ en place, rien de neuf côté app :
 *  - le thème de l'app est persisté sous `localStorage["cs-theme"]`, forme
 *    `{"state":{"mode":"light"|"dark"}}` (frontend/src/shared/stores/themeStore.ts) ;
 *    on le pose AVANT le premier rendu via `addInitScript`, pas de clic UI ;
 *  - la vitrine dérive `nom.ext → nom-dark.ext` toute seule (landing/index.html) :
 *    il suffit donc de produire la paire claire + sombre aux bons noms ;
 *  - l'import FBI passe par l'UI réelle (`/matchs/importer`), qui applique seule
 *    les appariements Division↔équipe proposés par l'écran.
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

import { chromium, expect } from "@playwright/test";

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url)); // <repo>/frontend/scripts
const REPO_ROOT = resolve(SCRIPT_DIR, "..", ".."); // <repo>

// --- Configuration par environnement (jamais de secret en dur) --------------

const BASE_URL = (process.env.BASE_URL ?? "http://localhost:5173").replace(/\/+$/, "");
// Identifiants du gestionnaire du club BCCL de développement (seed dev → défauts
// fictifs `dev-bccl@amateo.local` / `charge-load-test-pwd`, cf. BcclSeedProfile::dev()).
const DEMO_EMAIL = process.env.DEMO_EMAIL ?? "";
const DEMO_PASSWORD = process.env.DEMO_PASSWORD ?? "";
const OUT_DIR = process.env.OUT_DIR
  ? isAbsolute(process.env.OUT_DIR)
    ? process.env.OUT_DIR
    : resolve(REPO_ROOT, process.env.OUT_DIR)
  : join(REPO_ROOT, "captures", "landing-shots");
// Fichier JSON optionnel, local et JAMAIS commité : { "À remplacer": "Valeur démo", … }.
// FUSIONNÉ par-dessus la table par défaut (ci-dessous) — il SURCHARGE/COMPLÈTE, il ne
// remplace pas. Utile pour anonymiser un nom supplémentaire repéré sur une capture.
const SCRUB_FILE = process.env.SCRUB_FILE ?? "";
// Import FBI OPTIONNEL avant les captures matchs : chemin d'un export .xlsx. Vide ⇒
// on ne touche pas aux rencontres (les écrans matchs capturent l'état en place).
// Fichier recommandé : backend/tests/Fixtures/fbi/rechercherRencontre.xlsx (124 rencontres 2026-27).
const IMPORT_FBI = process.env.IMPORT_FBI ?? "";
// Calendrier des matchs : samedi ISO du week-end affiché. Défaut = un week-end de
// novembre 2026 (saison en cours sur l'horloge RÉELLE du club). Épingler le week-end
// évite le vide ET la bascule d'atterrissage vers Conflits (lien profond prioritaire,
// MatchesLanding). À ajuster si ce samedi est pauvre en rencontres sur vos données.
const MATCHS_WEEKEND = process.env.MATCHS_WEEKEND ?? "2026-11-14";

// Anonymisation PAR DÉFAUT (toujours appliquée, même sans SCRUB_FILE) — le club dev
// est un VRAI club, sa marque ne doit jamais atteindre la vitrine. Remplacements
// INSENSIBLES À LA CASSE (le nom peut apparaître en capitales, en casse de titre…).
const DEFAULT_SCRUB = {
  "B CHARPENNES CROIX LUIZET": "Démo Basket Club",
  ARA0069036: "ARA9999999",
};

if ("" === DEMO_EMAIL || "" === DEMO_PASSWORD) {
  console.error(
    "DEMO_EMAIL et DEMO_PASSWORD sont requis (gestionnaire du club BCCL de développement).\n" +
      "Exemple : DEMO_EMAIL=dev-bccl@amateo.local DEMO_PASSWORD='charge-load-test-pwd' node scripts/capture-landing-shots.mjs",
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

/** Échappe une chaîne pour un RegExp littéral (remplacement insensible à la casse). */
function escapeRegExp(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

async function loadScrubMap() {
  // Toujours au moins la table par défaut ; le fichier la complète/surcharge.
  const map = { ...DEFAULT_SCRUB };
  if ("" !== SCRUB_FILE) {
    const path = isAbsolute(SCRUB_FILE) ? SCRUB_FILE : resolve(REPO_ROOT, SCRUB_FILE);
    const raw = await readFile(path, "utf8");
    const parsed = JSON.parse(raw);
    if (null === parsed || "object" !== typeof parsed || Array.isArray(parsed)) {
      throw new Error(`SCRUB_FILE doit être un objet JSON { "À remplacer": "Valeur démo" } : ${path}`);
    }
    Object.assign(map, parsed);
  }
  return map;
}

/**
 * Anonymise le DOM rendu AVANT la capture (patron P5-26) :
 *  - retire tout blason de club de l'en-tête (`header img` — l'icône produit est un
 *    `<svg>`, jamais un `<img>` : on ne retire donc QUE le logo club, l'app retombe
 *    sur le monogramme produit neutre) ;
 *  - remplace, insensible à la casse, chaque entrée de `scrubMap` dans le TEXTE.
 */
async function anonymize(page, scrubMap) {
  await page.evaluate((pairs) => {
    // (a) blason(s) de club dans l'en-tête → retirés (monogramme produit conservé).
    for (const img of Array.from(document.querySelectorAll("header img"))) {
      img.remove();
    }
    // (b) remplacements texte, insensibles à la casse.
    const regexes = pairs.map(([pattern, to]) => [new RegExp(pattern, "gi"), to]);
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const nodes = [];
    for (let n = walker.nextNode(); null !== n; n = walker.nextNode()) {
      nodes.push(n);
    }
    for (const node of nodes) {
      let text = node.nodeValue ?? "";
      for (const [re, to] of regexes) {
        text = text.replace(re, to);
      }
      if (text !== node.nodeValue) {
        node.nodeValue = text;
      }
    }
  }, Object.entries(scrubMap).map(([from, to]) => [escapeRegExp(from), to]));
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

/**
 * Import FBI OPTIONNEL via l'UI réelle (`/matchs/importer` → modale « Importer FBI »).
 * La modale analyse le fichier, PRÉ-REMPLIT les appariements Division↔équipe depuis les
 * mappings persistés ET les suggestions FFBB (que `buildMappings` envoie telles quelles) :
 * un clic « Importer » suffit donc, sans toucher un seul sélecteur. Les divisions sans
 * appariement effectif déclenchent une confirmation « Importer quand même » (leurs
 * rencontres sont simplement laissées de côté). Idempotent : si un dépôt FBI a déjà eu
 * lieu, on ne réimporte pas (et l'import serveur dédoublonne de toute façon).
 */
async function runImport(browser) {
  const filePath = isAbsolute(IMPORT_FBI) ? IMPORT_FBI : resolve(REPO_ROOT, IMPORT_FBI);
  const context = await browser.newContext({ baseURL: BASE_URL });
  const page = await context.newPage();
  page.setDefaultTimeout(NAV_TIMEOUT);
  try {
    await login(page);
    await page.goto(`${BASE_URL}/matchs/importer`, { waitUntil: "domcontentloaded", timeout: NAV_TIMEOUT });
    await waitForStability(page);

    // Idempotence : un dépôt FBI déjà présent ⇒ on ne rejoue pas l'import.
    const alreadyDeposited = await page.getByText(/Dernier dépôt FBI\s*:/).count();
    if (alreadyDeposited > 0) {
      console.log("• import FBI ignoré — un dépôt existe déjà pour cette saison.");
      return;
    }

    await page.getByRole("button", { name: "Importer FBI" }).click();
    await page.locator('input[aria-label="Fichier FBI"]').setInputFiles(filePath);

    // L'analyse (dry-run) part toute seule au dépôt du fichier ; le bouton « Importer »
    // du pied de modale s'active quand elle aboutit.
    const importBtn = page.getByRole("button", { name: "Importer", exact: true });
    await expect(importBtn).toBeEnabled({ timeout: NAV_TIMEOUT });
    await importBtn.click();

    // Divisions sans équipe ⇒ confirmation (best-effort : absente si tout est apparié).
    await page
      .getByRole("button", { name: /importer quand même/i })
      .click({ timeout: 3_000 })
      .catch(() => {});

    // Le rapport d'import (« … créé · … mis à jour · … inchangé ») apparaît en place.
    await page.getByText(/mis à jour/).first().waitFor({ timeout: NAV_TIMEOUT });
    await waitForStability(page);
    console.log(`✓ import FBI depuis ${filePath}`);
  } finally {
    await context.close();
  }
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
      await anonymize(page, scrubMap);
      // Laisse les webfonts/images se poser après l'anonymisation (deviceScaleFactor 2).
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
  console.log(`Import FBI : ${"" === IMPORT_FBI ? "aucun" : IMPORT_FBI}`);
  console.log(`Week-end matchs : ${"" === MATCHS_WEEKEND ? "auto" : MATCHS_WEEKEND}`);
  console.log(`Anonymisation : ${Object.keys(scrubMap).length} remplacement(s) + blason club retiré de l'en-tête`);

  const browser = await chromium.launch();
  try {
    if ("" !== IMPORT_FBI) {
      await runImport(browser);
    }
    for (const theme of THEMES) {
      await captureTheme(browser, theme, scrubMap);
    }
  } finally {
    await browser.close();
  }
  console.log(`\nTerminé. 8 fichiers dans ${OUT_DIR}.`);
  console.log(
    "Vérifier chaque image (aucun club/personne réel, en-tête « Démo Basket Club », blason absent) AVANT de copier dans landing/assets/.",
  );
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
