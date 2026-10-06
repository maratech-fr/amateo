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
 *    démo (table par défaut ci-dessous + `SCRUB_FILE` optionnel), (b) retire TOUS les
 *    BLASONS du club, où qu'ils soient (en-tête d'app, en-tête d'écran du Planning,
 *    page Club…) — ciblés par la SOURCE `/api/clubs/{id}/logo` (`App\Storage\LogoUrl`),
 *    à ne pas confondre avec le logo FÉDÉRAL d'un adversaire (`/api/opponents/{code}/logo`,
 *    donnée publique autorisée, conservée). L'app retombe alors sur le monogramme produit
 *    neutre qu'elle affiche quand un club n'a pas de logo (patron P5-26 : blason retiré du
 *    DOM). Coachs déjà fictifs (surnoms du seed) ; gymnases et clubs adverses = données
 *    publiques autorisées.
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
// `FORCE_IMPORT=1` court-circuite la garde d'idempotence (ci-dessous, `runImport`) : on réimporte
// même si la saison a déjà des rencontres. Sans ça, la garde saute l'import dès qu'une rencontre
// existe (l'import serveur dédoublonne de toute façon).
const FORCE_IMPORT = process.env.FORCE_IMPORT ?? "";
// Appariement Division → équipe pour l'import FBI (cf. `runImport`/`applyDivisionMap`). Le bac à
// sable n'a PAS les engagements FFBB qui produiraient les suggestions FFBB (`suggestedTeamId`
// reste `null` partout) : sans appariement explicite, l'écran laisse TOUTES les divisions « à
// associer » et l'import ne crée AUCUNE rencontre. On fournit donc l'appariement validé par le
// fondateur pour le BCCL (le nom à DROITE = libellé d'équipe du club tel qu'il s'affiche dans le
// sélecteur « Associer à… », c.-à-d. `team.name` — vérifié contre `BcclSeeder::$newTeamsData`).
// Les divisions ABSENTES de la table sont laissées non associées (l'import les ignore, avec la
// confirmation « Importer quand même » déjà gérée). `DIVISION_MAP` (objet JSON inline) et
// `DIVISION_MAP_FILE` (chemin d'un JSON local, relatif = depuis la racine du dépôt) sont FUSIONNÉS
// par-dessus ce défaut (fichier puis inline) : ils surchargent/complètent, ils ne remplacent pas.
const DEFAULT_DIVISION_MAP = {
  PNM: "SM1",
  RM2: "SM2",
  PRM: "SM3",
  DM2: "SM4",
  PNF: "SF1",
  RF3: "SF2",
  DF2: "SF3",
  RMU21: "U21M1",
};
const DIVISION_MAP_INLINE = process.env.DIVISION_MAP ?? "";
const DIVISION_MAP_FILE = process.env.DIVISION_MAP_FILE ?? "";
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
    // 900 (et non 585) : à 585 le cadre s'arrêtait sous l'en-tête + les onglets + les rangées de
    // filtres, la grille week-end ne commençant qu'en tout bas. 900 fait entrer la grille et ses
    // matchs placés sans masquer les filtres (deviceScaleFactor 2 ⇒ image 2160 × 1800 px).
    height: 900,
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

/** Objet JSON `{ "Division": "Nom d'équipe" }` — valeurs string non vides. */
function assertDivisionMap(parsed, origin) {
  if (null === parsed || "object" !== typeof parsed || Array.isArray(parsed)) {
    throw new Error(`${origin} doit être un objet JSON { "Division": "Nom d'équipe" }.`);
  }
  for (const [division, team] of Object.entries(parsed)) {
    if ("string" !== typeof team || "" === team) {
      throw new Error(`${origin} : l'équipe de « ${division} » doit être une chaîne non vide.`);
    }
  }
}

/**
 * Appariement Division → équipe pour l'import FBI : défaut fondateur (`DEFAULT_DIVISION_MAP`),
 * complété/surchargé par `DIVISION_MAP_FILE` (fichier) puis `DIVISION_MAP` (inline).
 */
async function loadDivisionMap() {
  const map = { ...DEFAULT_DIVISION_MAP };
  if ("" !== DIVISION_MAP_FILE) {
    const path = isAbsolute(DIVISION_MAP_FILE) ? DIVISION_MAP_FILE : resolve(REPO_ROOT, DIVISION_MAP_FILE);
    const parsed = JSON.parse(await readFile(path, "utf8"));
    assertDivisionMap(parsed, `DIVISION_MAP_FILE (${path})`);
    Object.assign(map, parsed);
  }
  if ("" !== DIVISION_MAP_INLINE) {
    const parsed = JSON.parse(DIVISION_MAP_INLINE);
    assertDivisionMap(parsed, "DIVISION_MAP");
    Object.assign(map, parsed);
  }
  return map;
}

/**
 * Anonymise le DOM rendu AVANT la capture (patron P5-26) :
 *  - retire TOUS les blasons du club, où qu'ils soient dans la page (en-tête d'app,
 *    en-tête d'écran du Planning, page Club…) — ciblés par la SOURCE
 *    `/api/clubs/{id}/logo` (`App\Storage\LogoUrl`). Le logo FÉDÉRAL d'un ADVERSAIRE
 *    (`/api/opponents/{code}/logo`) est une donnée publique autorisée : on le CONSERVE.
 *    L'icône produit est un `<svg>`, jamais un `<img>` : elle reste. L'app retombe sur
 *    le monogramme produit neutre là où un blason club a été retiré ;
 *  - remplace, insensible à la casse, chaque entrée de `scrubMap` dans le TEXTE.
 *
 * NB : l'horloge simulée DEV de l'en-tête est retirée à part, par `hideDevChrome`, appelée juste
 * avant celle-ci dans `captureTheme` (chrome de dev, pas une donnée de club à anonymiser).
 */
async function anonymize(page, scrubMap) {
  await page.evaluate((pairs) => {
    // (a) blason(s) du club → retirés partout (monogramme produit conservé). On cible la
    // source servie par le backend pour le logo CLUB, jamais celle d'un logo d'adversaire.
    const CLUB_LOGO = /\/api\/clubs\/[^/]+\/logo/;
    for (const img of Array.from(document.querySelectorAll("img"))) {
      if (CLUB_LOGO.test(img.src)) {
        img.remove();
      }
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

/**
 * Retire l'HORLOGE SIMULÉE (dev) de l'en-tête AVANT la capture (toutes les captures) : la stack
 * dev tourne sous `import.meta.env.DEV`, qui monte le widget `DevClock` (pastille « ⏱ 06/10/2026
 * 23:32 », à côté de « BÊTA ») — un artefact de développement qui n'a rien à faire sur la vitrine.
 * Ciblé par le `title` STABLE de son bouton déclencheur (`frontend/src/app/DevClock.tsx:75`), jamais
 * par le texte de la date (variable) : on retire la racine `div.relative` du widget (le bouton +
 * son éventuel popover). À ne pas confondre avec l'horloge de DÉMO (`DemoClockWidget`, title
 * « Horloge simulée de la démo … »), absente ici (le club dev n'est pas un compte démo).
 */
async function hideDevChrome(page) {
  await page.evaluate(() => {
    const trigger = document.querySelector('button[title="Horloge simulée (dev) — cliquer pour modifier"]');
    if (null !== trigger) {
      (trigger.closest("div.relative") ?? trigger.parentElement ?? trigger).remove();
    }
  });
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
 * Applique l'appariement Division → équipe DANS la modale d'import, AVANT le clic « Importer ».
 *
 * Les divisions sont rangées en onglets de FAMILLE (`ImportFbiDialog` : Départemental / Régional /
 * Brassage…). Chaque `TeamSelect` est bâti sur la primitive `Listbox` (pas un `<select>` natif) :
 *  - le TEXTE de chaque division vit dans un `<span class="sr-only">` « Équipe pour <division> »
 *    référencé par l'`aria-labelledby` du bouton déclencheur (`button[aria-haspopup="listbox"]`) ;
 *  - un panneau de famille INACTIF est rendu `hidden` → ses sélecteurs sont incliquables, d'où
 *    l'activation de chaque onglet tour à tour (`role="tab"`) avant de le traiter ;
 *  - ouvrir un sélecteur porte son panneau d'options dans un PORTAL (`document.body`) ; chaque
 *    `role="option"` a pour nom accessible le `team.name` (aucune méta ici) — on clique celui qui
 *    correspond au libellé cible.
 *
 * Pour chaque division VISIBLE : dans la table ⇒ on sélectionne l'équipe ; absente ⇒ on la laisse
 * (ignorée à l'import). On journalise : associées / ignorées / équipe introuvable.
 */
async function applyDivisionMap(page, divisionMap) {
  const paired = [];
  const ignored = [];
  const notFound = [];

  // Onglets de famille (absents si une seule famille implicite — alors une seule passe).
  const tabCount = await page.getByRole("tab").count();
  const passes = tabCount > 0 ? tabCount : 1;

  for (let t = 0; t < passes; t++) {
    if (tabCount > 0) {
      await page.getByRole("tab").nth(t).click();
      await page.locator('[role="tabpanel"]:not([hidden])').first().waitFor({ state: "visible", timeout: NAV_TIMEOUT });
    }
    // Les sélecteurs du SEUL panneau visible (les autres familles sont `hidden`).
    const panel = tabCount > 0 ? page.locator('[role="tabpanel"]:not([hidden])') : page.locator("body");
    const triggers = panel.locator('button[aria-haspopup="listbox"]');
    const count = await triggers.count();
    for (let i = 0; i < count; i++) {
      const trigger = triggers.nth(i);
      // Division portée par le libellé sr-only « Équipe pour <division> » (on retire un éventuel
      // « (libellé FBI) » suffixé quand deux équipes du club partagent la division).
      const divisionLabel = await trigger.evaluate((btn) => {
        const id = (btn.getAttribute("aria-labelledby") ?? "").split(" ")[0];
        const span = "" !== id ? document.getElementById(id) : null;
        return (span?.textContent ?? "").replace(/^Équipe pour /, "").trim();
      });
      const division = divisionLabel.replace(/\s*\(.*\)\s*$/, "").trim();
      const teamLabel = divisionMap[division];
      if (undefined === teamLabel) {
        ignored.push(division);
        continue;
      }
      await trigger.click();
      await page.locator('[role="listbox"]').first().waitFor({ state: "visible", timeout: NAV_TIMEOUT });
      const option = page.getByRole("option", { name: teamLabel, exact: true });
      if (0 === (await option.count())) {
        notFound.push(`${division} → ${teamLabel}`);
        await page.keyboard.press("Escape");
        continue;
      }
      await option.first().click();
      paired.push(`${division} → ${teamLabel}`);
    }
  }

  console.log(`• appariement Division→équipe : ${paired.length} associée(s)${paired.length > 0 ? ` (${paired.join(", ")})` : ""}`);
  if (ignored.length > 0) {
    console.log(`• ${ignored.length} division(s) ignorée(s) (hors table, laissées non associées) : ${ignored.join(", ")}`);
  }
  if (notFound.length > 0) {
    console.warn(`⚠ ${notFound.length} appariement(s) sans équipe correspondante dans le sélecteur (libellé introuvable) : ${notFound.join(", ")}`);
  }
}

/**
 * Apparie les libellés de salle FBI NON APPARIÉS à un gymnase du club, APRÈS l'import. Sans ça,
 * les domiciles importés n'ont pas de `venueId` : ils n'apparaissent pas sur la grille du
 * Calendrier (bandeau « N libellés de salle non appariés — M domiciles n'apparaissent pas »,
 * `UnpairedVenueLabelsBanner`), et la grille reste vide.
 *
 * Voie API (plus robuste que piloter le `VenueSelect`/`Listbox` de l'écran d'appariement) : on
 * réutilise les COOKIES de la page connectée via `page.request` (JWT httpOnly, aucune en-tête
 * spéciale — pas de CSRF, `X-Request-Id` régénéré côté serveur s'il manque, cf.
 * `RequestIdListener`). Trois appels, exactement ceux de `features/matches/api/venues.ts` :
 *   - `GET /api/venues/fbi-labels` → inventaire `{ labels: [...] }`, `venueId: null` = non apparié ;
 *   - `GET /api/venues` → les gymnases du club (JSON-LD `{ member: [...] }` ou tableau) ;
 *   - `POST /api/venues/{venueId}/external-labels` `{ label }` → attache + backfill des domiciles
 *     encore sans salle (management-gated : le gestionnaire du club dev est autorisé).
 * Cible = `suggestedVenueId` quand le serveur en propose une (gymnase unanime des placés), sinon le
 * premier gymnase du club (il suffit d'UN gymnase pour sortir les domiciles du néant). IDEMPOTENT :
 * rien à faire si aucun libellé n'est non apparié. On journalise chaque appariement.
 */
async function pairVenueLabels(page) {
  const invResp = await page.request.get(`${BASE_URL}/api/venues/fbi-labels`);
  if (!invResp.ok()) {
    console.warn(`⚠ appariement salles : lecture de l'inventaire impossible (HTTP ${invResp.status()}) — étape ignorée.`);
    return;
  }
  const inv = await invResp.json().catch(() => ({}));
  const labels = Array.isArray(inv?.labels) ? inv.labels : [];
  const unpaired = labels.filter((row) => null === row.venueId);
  if (0 === unpaired.length) {
    console.log("• appariement salles : rien à apparier (tous les libellés sont déjà appariés).");
    return;
  }

  const venuesResp = await page.request.get(`${BASE_URL}/api/venues`);
  if (!venuesResp.ok()) {
    console.warn(`⚠ appariement salles : lecture des gymnases impossible (HTTP ${venuesResp.status()}) — étape ignorée.`);
    return;
  }
  const venuesRaw = await venuesResp.json().catch(() => null);
  const venues = Array.isArray(venuesRaw)
    ? venuesRaw
    : Array.isArray(venuesRaw?.member)
      ? venuesRaw.member
      : [];
  if (0 === venues.length) {
    console.warn("⚠ appariement salles : le club n'a AUCUN gymnase — impossible d'apparier. Vérifiez le seed.");
    return;
  }

  const paired = [];
  const failed = [];
  for (const row of unpaired) {
    const target = venues.find((v) => v.id === row.suggestedVenueId) ?? venues[0];
    const resp = await page.request.post(`${BASE_URL}/api/venues/${target.id}/external-labels`, { data: { label: row.labelKey } });
    if (resp.ok()) {
      const result = await resp.json().catch(() => ({}));
      paired.push(`${row.displayLabel} → ${target.name} (${result.attached ?? "?"} domicile(s) rattaché(s))`);
    } else {
      failed.push(`${row.displayLabel} → ${target.name} (HTTP ${resp.status()})`);
    }
  }
  if (paired.length > 0) {
    console.log(`✓ appariement salles : ${paired.length} libellé(s) apparié(s) — ${paired.join(" ; ")}`);
  }
  if (failed.length > 0) {
    console.warn(`⚠ appariement salles : ${failed.length} échec(s) — ${failed.join(" ; ")}`);
  }
}

/**
 * Import FBI OPTIONNEL via l'UI réelle (`/matchs/importer` → modale « Importer FBI »).
 * La modale analyse le fichier puis AFFICHE les appariements Division↔équipe : le bac à sable
 * n'ayant pas les engagements FFBB, aucune suggestion n'est proposée (`suggestedTeamId: null`),
 * donc `applyDivisionMap` pose l'appariement explicite (défaut fondateur BCCL ou `DIVISION_MAP`)
 * AVANT le clic « Importer ». Les divisions laissées sans équipe déclenchent une confirmation
 * « Importer quand même » (leurs rencontres sont simplement laissées de côté). Idempotent sur la
 * PRÉSENCE de rencontres (pas sur la date du dernier dépôt FBI) : si la saison a déjà des
 * rencontres, on ne réimporte pas (et l'import serveur dédoublonne de toute façon).
 * `FORCE_IMPORT=1` passe outre.
 */
async function runImport(browser, divisionMap) {
  const filePath = isAbsolute(IMPORT_FBI) ? IMPORT_FBI : resolve(REPO_ROOT, IMPORT_FBI);
  const context = await browser.newContext({ baseURL: BASE_URL });
  const page = await context.newPage();
  page.setDefaultTimeout(NAV_TIMEOUT);
  try {
    await login(page);

    let skipImport = false;
    // Idempotence sur la PRÉSENCE de rencontres. On atterrit sur le CALENDRIER (le paramètre
    // d'URL force l'atterrissage calendrier — MatchesLanding : sinon un éventuel conflit
    // renverrait vers Conflits) et on lit l'état vide « Aucun match importé ». Il n'apparaît
    // QUE quand la saison n'a AUCUNE rencontre (`resolveActiveWeekend` → null ⟺ liste de
    // week-ends vide, cf. useWeekView/weekendGrid). Présent ⇒ on importe ; absent ⇒ des
    // rencontres existent déjà, on saute. (Le vieux critère « Dernier dépôt FBI : … » mentait :
    // une date de dépôt pouvait exister sans AUCUNE rencontre en base.)
    if ("1" !== FORCE_IMPORT) {
      await page.goto(`${BASE_URL}/matchs?semaine=${encodeURIComponent(MATCHS_WEEKEND)}`, { waitUntil: "domcontentloaded", timeout: NAV_TIMEOUT });
      await waitForStability(page);
      const noFixtures = await page.getByText("Aucun match importé").isVisible().catch(() => false);
      if (!noFixtures) {
        console.log("• import FBI ignoré — la saison a déjà des rencontres (état « Aucun match importé » absent). FORCE_IMPORT=1 pour réimporter.");
        // On N'IMPORTE pas, mais on PASSE à l'appariement des salles (idempotent) : des rencontres
        // existantes peuvent très bien avoir un libellé de salle resté non apparié.
        skipImport = true;
      }
    }

    if (skipImport) {
      // Rien à (ré)importer : on apparie quand même (idempotent) puis on sort.
      await pairVenueLabels(page);
      return;
    }

    await page.goto(`${BASE_URL}/matchs/importer`, { waitUntil: "domcontentloaded", timeout: NAV_TIMEOUT });
    await waitForStability(page);

    await page.getByRole("button", { name: "Importer FBI" }).click();
    await page.locator('input[aria-label="Fichier FBI"]').setInputFiles(filePath);

    // L'analyse (dry-run) part toute seule au dépôt du fichier ; le bouton « Importer »
    // du pied de modale s'active quand elle aboutit.
    const importBtn = page.getByRole("button", { name: "Importer", exact: true });
    await expect(importBtn).toBeEnabled({ timeout: NAV_TIMEOUT });

    // Appariement explicite AVANT l'import : sans lui, aucune division n'est associée et l'import
    // ne crée AUCUNE rencontre (le bac à sable n'a pas les engagements FFBB qui suggèrent l'équipe).
    await applyDivisionMap(page, divisionMap);

    // La réponse HTTP de l'import est la SEULE preuve fiable. Le texte « mis à jour » figure DÉJÀ
    // dans la description de la modale (« …les matchs connus sont mis à jour, jamais dupliqués. »),
    // donc l'attendre à l'écran matcherait instantanément et fermerait le contexte PENDANT le POST
    // en vol (nginx logue un 499, 0 rencontre importée). On arme l'attente de la réponse AVANT de
    // cliquer — le POST peut partir soit du bouton « Importer », soit de la confirmation
    // « Importer quand même » (divisions sans équipe) : le prédicat couvre les deux chemins.
    const importResponse = page.waitForResponse(
      (r) =>
        r.url().includes("/api/fixtures/import") &&
        !r.url().includes("/analyze") &&
        "POST" === r.request().method(),
      { timeout: 180_000 },
    );

    await importBtn.click();

    // Divisions sans équipe ⇒ confirmation (celles hors table restent non associées).
    await page
      .getByRole("button", { name: /importer quand même/i })
      .click({ timeout: 3_000 })
      .catch(() => {});

    const response = await importResponse;
    const status = response.status();
    const body = await response.text();
    if (status < 200 || status >= 300) {
      throw new Error(`POST /api/fixtures/import a échoué (HTTP ${status}) : ${body.slice(0, 500)}`);
    }

    // Les compteurs viennent du corps JSON (ImportFixturesController → created · updated ·
    // unchanged), jamais plus du texte de l'écran.
    const report = JSON.parse(body);
    const created = Number(report.created ?? 0);
    const updated = Number(report.updated ?? 0);
    const unchanged = Number(report.unchanged ?? 0);
    const summary = `${created} créé · ${updated} mis à jour · ${unchanged} inchangé`;

    await waitForStability(page);
    if (created > 0) {
      console.log(`✓ import FBI depuis ${filePath} — ${summary}`);
    } else {
      console.warn(
        `⚠ import FBI depuis ${filePath} : 0 rencontre CRÉÉE (${summary}). ` +
          (updated > 0
            ? "Des rencontres ont été mises à jour (ré-import ?) — vérifier que le calendrier n'est pas vide."
            : "Vérifier l'appariement Division→équipe et le fichier d'import avant de garder les captures matchs."),
      );
    }

    // Appariement des salles après l'import (idempotent). Sans lui, les domiciles importés
    // restent sans gymnase et la grille du Calendrier est vide.
    await pairVenueLabels(page);
  } finally {
    await context.close();
  }
}

/**
 * Fait défiler la grille du Planning (WeekGrid) jusqu'aux heures du SOIR : la grille démarre
 * à 09:00 et serait vide dans le cadre de capture. On agit sur le VRAI conteneur scrollable de
 * WeekGrid (le `div.overflow-auto` parent de la grille CSS — cf. frontend/src/features/planning/
 * WeekGrid.tsx, en-tête/colonne figés via un transform piloté par l'event `scroll`), jamais la
 * page. On amène près du haut le premier libellé de soirée de la colonne d'heures (libellés sur
 * les demi-heures : « 17:30 », « 18:00 »).
 */
async function scrollWeekGridToEvening(page) {
  await page.evaluate(() => {
    const grid = document.querySelector('div.grid[style*="grid-template-rows"]');
    const container = grid?.parentElement ?? null;
    if (null === container) {
      return;
    }
    const labels = Array.from(container.querySelectorAll("div"));
    const evening =
      labels.find((d) => "18:00" === (d.textContent ?? "").trim())
      ?? labels.find((d) => "17:30" === (d.textContent ?? "").trim())
      ?? labels.find((d) => "17:00" === (d.textContent ?? "").trim());
    if (null != evening) {
      const cRect = container.getBoundingClientRect();
      const eRect = evening.getBoundingClientRect();
      container.scrollTop += eRect.top - cRect.top - 48;
    } else {
      container.scrollTop = container.scrollHeight;
    }
    // Resynchronise l'en-tête/colonne figés (le transform est posé par le handler onScroll).
    container.dispatchEvent(new Event("scroll"));
  });
}

/**
 * Prépare la capture du PLANNING :
 *  (a) ouvre la DERNIÈRE version si le bandeau « version antérieure » l'offre (le bouton
 *      SÉLECTIONNE localement la dernière version — aucune écriture, aucune redirection) ;
 *  (b) masque les bandeaux d'ÉTAT restants — décision 2026-10-06, c'est une capture marketing :
 *      on retire les NŒUDS NoticeBanner des deux messages d'état par leur TEXTE (« périmé »,
 *      ton warning ; « version antérieure », ton muted role=status), JAMAIS tout le DOM ;
 *  (c) retire la pastille « Diagnostics du système (N) · M erreurs » (signal négatif sur une
 *      page de vente) — un `<button>` repéré par le TEXTE de son `<span>` (PlanningPage.tsx) ;
 *  (d) fait défiler la grille jusqu'aux heures du soir (sinon cadre vide).
 */
async function preparePlanning(page) {
  const openLatest = page.getByRole("button", { name: "Ouvrir la dernière version" });
  if ((await openLatest.count()) > 0) {
    await openLatest.first().click().catch(() => {});
    await waitForStability(page);
  }
  await page.evaluate(() => {
    const NEEDLES = ["Depuis la génération de ce planning", "Vous regardez une version antérieure du planning"];
    for (const p of Array.from(document.querySelectorAll("p"))) {
      if (NEEDLES.some((n) => (p.textContent ?? "").includes(n))) {
        // Le <p> du message vit dans la BOÎTE NoticeBanner (`rounded-md border`) — on retire
        // la boîte entière (y compris l'éventuel bouton d'action), pas seulement le texte.
        const banner = p.closest("div.rounded-md.border") ?? p.parentElement;
        banner?.remove();
      }
    }
    // (c) la pastille repliée « Diagnostics du système (N) » : son libellé vit dans un <span>,
    // on retire le <button> qui le porte (sa rangée `empty:hidden` se replie si elle se vide).
    for (const span of Array.from(document.querySelectorAll("button span"))) {
      if ((span.textContent ?? "").startsWith("Diagnostics du système")) {
        span.closest("button")?.remove();
      }
    }
  });
  await scrollWeekGridToEvening(page);
}

/**
 * Calendrier matchs : amène le cadre sur un week-end dont la grille est BIEN REMPLIE. Le conteneur
 * `#matches-week-grid` peut exister pour une semaine 100 % extérieurs (grille vide), ou l'écran
 * rendre « Aucun match importé »/« Aucun match cette semaine » ; et une semaine à une seule carte
 * fait une capture maigre. On PRÉFÈRE donc une semaine portant plusieurs cartes de match
 * (`[data-fixture-id]`, domicile placé — cf. WeekendGrid) : on avance via « Semaine suivante »
 * (borné, on ne garde JAMAIS de boucle non bornée — CLAUDE.md §10.4) jusqu'à en trouver une à
 * ≥ `PREFERRED` cartes ; faute de mieux, on retombe sur la MIEUX remplie vue (≥ 1 carte) en revenant
 * en arrière via « Semaine précédente ». Juger sur les CARTES rendues, pas sur la seule présence du
 * conteneur : une semaine sans domicile sur grille resterait un cadre vide.
 */
async function ensureNonEmptyWeek(page) {
  const MAX_WEEKS = 12;
  const PREFERRED = 3; // « plusieurs » cartes : une grille lisible plutôt qu'une carte isolée.
  const cards = page.locator("#matches-week-grid [data-fixture-id]");
  let pos = 0; // nombre d'avances « Semaine suivante » depuis le point de départ.
  let best = { pos: 0, count: -1 }; // meilleure semaine VUE, pour y revenir si ≥ PREFERRED est hors d'atteinte.

  for (let i = 0; i <= MAX_WEEKS; i++) {
    const count = await cards.count();
    if (count >= PREFERRED) {
      return; // idéale : plusieurs cartes sur la grille.
    }
    if (count > best.count) {
      best = { pos, count };
    }
    if (i === MAX_WEEKS) {
      break; // dernière semaine évaluée : on n'avance plus.
    }
    const next = page.getByRole("button", { name: "Semaine suivante" });
    if (0 === (await next.count()) || (await next.isDisabled().catch(() => true))) {
      break; // navigation épuisée.
    }
    await next.click();
    await waitForStability(page);
    pos++;
  }

  if (best.count <= 0) {
    console.warn(`⚠ calendrier matchs : aucune semaine avec un match sur la grille atteignable à partir de ${MATCHS_WEEKEND} — capture laissée en l'état.`);
    return;
  }

  // On a vu au moins une semaine avec des cartes, mais aucune à ≥ PREFERRED : repli sur la mieux
  // remplie, en revenant en arrière (borné par le nombre d'avances déjà faites).
  for (let back = pos; back > best.pos; back--) {
    const prev = page.getByRole("button", { name: "Semaine précédente" });
    if (0 === (await prev.count()) || (await prev.isDisabled().catch(() => true))) {
      break;
    }
    await prev.click();
    await waitForStability(page);
  }
  console.warn(`⚠ calendrier matchs : aucune semaine à ≥ ${PREFERRED} carte(s) atteignable à partir de ${MATCHS_WEEKEND} — repli sur la mieux remplie vue (${best.count} carte(s)).`);
}

/**
 * Masque les deux bandeaux d'ÉTAT du Calendrier (capture marketing, même technique que le
 * Planning : on retire la BOÎTE NoticeBanner `rounded-md border` par le TEXTE, pas tout le DOM) —
 * le « gardien » (« Depuis votre dernière visite : … ») et le rattrapage ligue
 * (« … restent à traiter (ni heure ni gymnase… ») qui, empilés, repoussaient la grille sous le
 * cadre. À appeler APRÈS `ensureNonEmptyWeek` : la navigation de semaine re-rend React et
 * ré-afficherait des bandeaux retirés trop tôt.
 */
async function removeCalendarBanners(page) {
  await page.evaluate(() => {
    // Le « gardien » (ModuleVisitBanner, « Depuis votre dernière visite… ») et TOUTES les variantes
    // du rattrapage ligue (LeagueValidationBanner : « … restent à traiter », « … sont prêtes — à
    // confirmer validé ligue », « … sans échéance ») : toutes portent « championnat ». L'appariement
    // des salles (`pairVenueLabels`) peut faire basculer la variante « à traiter » (ni heure ni
    // gymnase) vers « prêtes » — d'où un ciblage par la BOÎTE `NoticeBanner` (`rounded-md border`) et
    // son TEXTE, robuste quel que soit le balisage interne (<p> OU <span>, selon la variante).
    const NEEDLES = ["Depuis votre dernière visite", "championnat"];
    const parents = new Set();
    for (const box of Array.from(document.querySelectorAll("div.rounded-md.border"))) {
      if (NEEDLES.some((n) => (box.textContent ?? "").includes(n))) {
        if (null !== box.parentElement) {
          parents.add(box.parentElement);
        }
        box.remove();
      }
    }
    // L'enveloppe ligue (`flex flex-col gap-2`) devenue vide → retirée pour ne pas laisser d'espace
    // mort au-dessus de la grille (le conteneur du « gardien » est la racine de page, jamais vidé).
    for (const parent of parents) {
      if (parent.isConnected && "" === (parent.textContent ?? "").trim() && 0 === parent.querySelectorAll("button, a, svg, img, input").length) {
        parent.remove();
      }
    }
  });
}

/**
 * Conflits : prévient si la saison n'a AUCUN conflit (capture vide), puis DÉPLIE le premier
 * groupe (les accordéons sont tous repliés par défaut, cadre quasi vide) — un `<button
 * aria-expanded>` dans `[data-conflicts-entries]` (ConflictsPage/AccordionSection).
 *
 * L'accordéon ouvert est porté par `?ouvert=<clé>` dans l'URL (posé en `replace` par le clic,
 * ConflictsPage.tsx:263-271). L'effet de seed/re-synchro de la page (ConflictsPage.tsx:205-258)
 * peut re-synchroniser l'URL juste APRÈS le clic et laisser retomber `?ouvert` — le groupe se
 * referme et la capture est quasi vide. D'où un dépliage ROBUSTE :
 *   1. déjà ouvert (groupe unique ouvert d'office) ⇒ ne rien faire (un clic le replierait) ;
 *   2. cliquer l'en-tête, re-tenter (borné) après stabilisation tant qu'aucun groupe n'est ouvert,
 *      en CAPTURANT la clé `?ouvert` dès que le clic l'écrit (patch synchrone de l'historique :
 *      l'écriture est synchrone dans le handler, l'effet qui la défait tourne dans un useEffect) ;
 *   3. repli : RECHARGER la route avec `&ouvert=<clé>` — à la navigation, la passe de seed
 *      PRÉSERVE `?ouvert` (elle ne nettoie une clé que lors de la re-synchro, où elle existe), le
 *      groupe s'ouvre d'office ;
 *   4. échec persistant ⇒ avertir BRUYAMMENT (la capture montrera des accordéons repliés).
 */
async function prepareConflicts(page) {
  const empty = await page.getByText("Aucun conflit sur la saison").isVisible().catch(() => false);
  if (empty) {
    console.warn("⚠ conflits : « Aucun conflit sur la saison » — la capture Conflits sera un écran VIDE. Vérifiez l'import / le week-end avant de garder cette image.");
    return;
  }
  const firstHeader = page.locator("[data-conflicts-entries] button[aria-expanded]").first();
  if (0 === (await firstHeader.count())) {
    return;
  }
  const expanded = page.locator('[data-conflicts-entries] button[aria-expanded="true"]');
  const isOpen = async () => (await expanded.count()) > 0;

  // (1) Déjà déplié (un groupe unique s'ouvre d'office) : ne pas cliquer.
  if (await isOpen()) {
    return;
  }

  // Patch l'historique pour CAPTURER la clé `?ouvert` à l'instant où React Router l'écrit (push/
  // replaceState synchrone), avant que l'effet de re-synchro puisse la retirer. Idempotent.
  await page.evaluate(() => {
    const w = /** @type {any} */ (window);
    if (w.__ouvertCaptureInstalled) {
      return;
    }
    w.__ouvertCaptureInstalled = true;
    w.__capturedOuvert = null;
    const record = (url) => {
      try {
        const v = new URL(url ?? location.href, location.origin).searchParams.get("ouvert");
        if (null !== v && "" !== v) {
          w.__capturedOuvert = v;
        }
      } catch (_) {
        // URL non analysable : on ignore.
      }
    };
    for (const name of ["pushState", "replaceState"]) {
      const orig = history[name].bind(history);
      history[name] = (state, title, url) => {
        const r = orig(state, title, url);
        record(url);
        return r;
      };
    }
  });

  // (2) Cliquer, re-tenter (borné) après stabilisation tant qu'aucun groupe n'est ouvert.
  const MAX_CLICKS = 3;
  for (let attempt = 0; attempt < MAX_CLICKS && !(await isOpen()); attempt++) {
    await firstHeader.click().catch(() => {});
    await waitForStability(page);
  }
  if (await isOpen()) {
    return;
  }

  // (3) Repli : recharger la route avec la clé capturée en `?ouvert`.
  const capturedKey = await page.evaluate(() => /** @type {any} */ (window).__capturedOuvert ?? null);
  if (null !== capturedKey) {
    const target = new URL(page.url());
    target.searchParams.set("ouvert", capturedKey);
    await page.goto(target.toString(), { waitUntil: "domcontentloaded", timeout: NAV_TIMEOUT });
    await waitForStability(page);
  }

  // (4) Toujours replié : avertir bruyamment.
  if (!(await isOpen())) {
    console.warn(
      "⚠ conflits : impossible de DÉPLIER le premier groupe (il reste replié après reclics et " +
        "navigation directe `?ouvert`). La capture Conflits montrera des accordéons REPLIÉS — à vérifier avant de la garder.",
    );
  }
}

/** Préparations propres à un écran (version en vigueur, semaine non vide, bandeaux, groupe ouvert). */
async function prepareShot(page, shot) {
  if ("planning.png" === shot.base) {
    await preparePlanning(page);
  } else if ("matchs.jpg" === shot.base) {
    // Naviguer d'abord (re-rend React), masquer les bandeaux ENSUITE (sinon ré-affichés).
    await ensureNonEmptyWeek(page);
    await removeCalendarBanners(page);
  } else if ("matchs-conflits.jpg" === shot.base) {
    await prepareConflicts(page);
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
      // Préparation propre à l'écran AVANT l'anonymisation (elle peut re-rendre la page :
      // ouverture de la dernière version, navigation de semaine…).
      await prepareShot(page, shot);
      // Retire l'horloge simulée dev de l'en-tête (toutes les captures) AVANT l'anonymisation.
      await hideDevChrome(page);
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
  const divisionMap = await loadDivisionMap();
  console.log(`Cible : ${BASE_URL}`);
  console.log(`Sortie : ${OUT_DIR}`);
  console.log(`Import FBI : ${"" === IMPORT_FBI ? "aucun" : IMPORT_FBI}`);
  console.log(`Week-end matchs : ${"" === MATCHS_WEEKEND ? "auto" : MATCHS_WEEKEND}`);
  console.log(`Anonymisation : ${Object.keys(scrubMap).length} remplacement(s) + blason club retiré de l'en-tête`);
  if ("" !== IMPORT_FBI) {
    console.log(`Appariement Division→équipe : ${Object.keys(divisionMap).length} entrée(s) (${Object.entries(divisionMap).map(([d, t]) => `${d}→${t}`).join(", ")})`);
  }

  const browser = await chromium.launch();
  try {
    if ("" !== IMPORT_FBI) {
      await runImport(browser, divisionMap);
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
