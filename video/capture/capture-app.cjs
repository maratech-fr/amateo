// Capture Playwright de l'application (club de DEMONSTRATION, thème clair sauf plan 8).
// Lance par capture/run.sh (SOUS with-sandbox, base amateo_dev). Args : JWT, WISH_TOKEN, MAILID.
//
// Regle de choix (prompt maitre) : clip video (recordVideo) pour ce qui BOUGE (generation, bascule
// de theme, verrou) ; PNG HD (viewport 1920x1080, deviceScaleFactor 2) pour un fond de zoom fixe.
// Chaque plan est ISOLE (try/catch) : un echec n'empeche pas les autres, et laisse une capture de
// debug + un diagnostic (out/diag.json) pour analyse apres restauration du bac a sable.
// Faux curseur lisse injecte (Playwright n'affiche pas le curseur reel).
const fs = require("node:fs");
const path = require("node:path");
const { chromium } = require("@playwright/test");

const [JWT, WISH_TOKEN, MAILID] = process.argv.slice(2);
const FRONT = "http://localhost:5173";
const MAILPIT = "http://localhost:8025";
const ROOT = path.resolve(__dirname, "..");
const CAPTURES = path.join(ROOT, "out", "captures");
const DEBUG = path.join(ROOT, "out", "debug");
const TMP = path.join(ROOT, "out", "tmp");
for (const d of [CAPTURES, DEBUG, TMP]) fs.mkdirSync(d, { recursive: true });

const diag = {};
const log = (...a) => console.log(...a);

// Curseur factice + lissage : un point suit la souris ; utile sur les clips.
const FAKE_CURSOR = () => {
  const d = document.createElement("div");
  d.id = "__fake_cursor";
  d.style.cssText =
    "position:fixed;z-index:2147483647;width:22px;height:22px;margin:-11px 0 0 -11px;border-radius:50%;background:rgba(46,120,118,.35);border:2px solid #2e7876;pointer-events:none;transition:transform .05s linear;left:0;top:0";
  document.documentElement.appendChild(d);
  window.addEventListener(
    "mousemove",
    (e) => {
      d.style.left = e.clientX + "px";
      d.style.top = e.clientY + "px";
    },
    true,
  );
};

async function authContext(browser, { colorScheme = "light", record = false } = {}) {
  const opts = {
    viewport: { width: 1920, height: 1080 },
    deviceScaleFactor: record ? 1 : 2,
    colorScheme,
    locale: "fr-FR",
  };
  if (record) opts.recordVideo = { dir: TMP, size: { width: 1920, height: 1080 } };
  const ctx = await browser.newContext(opts);
  await ctx.addCookies([
    { name: "BEARER", value: JWT, domain: "localhost", path: "/api", httpOnly: true, secure: false, sameSite: "Lax" },
  ]);
  const mode = colorScheme === "dark" ? "dark" : "light";
  await ctx.addInitScript((m) => {
    try {
      localStorage.setItem("cs-auth", JSON.stringify({ state: { isAuthenticated: true }, version: 2 }));
      localStorage.setItem("cs-theme", JSON.stringify({ state: { mode: m, accent: null }, version: 1 }));
    } catch (e) { /* ignore */ }
  }, mode);
  await ctx.addInitScript(FAKE_CURSOR);
  return ctx;
}

async function settle(page, ms = 1200) {
  await page.waitForLoadState("domcontentloaded").catch(() => {});
  await page.waitForTimeout(ms);
}
async function shot(page, name) {
  const p = path.join(CAPTURES, name);
  await page.screenshot({ path: p });
  log("  capture:", name);
  return p;
}
async function debugShot(page, name) {
  try {
    await page.screenshot({ path: path.join(DEBUG, name), fullPage: true });
  } catch (e) { /* ignore */ }
}
async function pageInfo(page) {
  const url = page.url();
  let heading = "";
  try { heading = (await page.locator("h1").first().innerText({ timeout: 2000 })).slice(0, 120); } catch (e) { /* */ }
  return { url, heading };
}
async function dumpBoxes(page, selector, file) {
  try {
    const boxes = await page.$$eval(selector, (els) =>
      els.map((el) => {
        const r = el.getBoundingClientRect();
        return { id: el.getAttribute("data-slot-id") || el.id || null, x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) };
      }),
    );
    fs.writeFileSync(path.join(CAPTURES, file), JSON.stringify({ selector, count: boxes.length, boxes }, null, 2));
    log(`  boxes: ${file} (${boxes.length})`);
    return boxes.length;
  } catch (e) {
    log("  boxes echec:", file, e.message);
    return 0;
  }
}
async function run(name, fn) {
  try {
    log(`== ${name} ==`);
    diag[name] = await fn() || { ok: true };
  } catch (e) {
    log(`   ECHEC ${name}: ${e.message}`);
    diag[name] = { ok: false, error: e.message };
  }
}

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || undefined });

  // ---- Auth sanity + home/cockpit --------------------------------------------------------------
  const mgr = await authContext(browser);
  const m = await mgr.newPage();
  await run("auth+home", async () => {
    await m.goto(`${FRONT}/`, { waitUntil: "domcontentloaded" });
    await settle(m, 2500);
    const info = await pageInfo(m);
    await debugShot(m, "home.png");
    await shot(m, "P00-home.png");
    return { ...info, note: info.url.includes("/wizard") ? "redirige vers /wizard (pas de generation en vigueur)" : "cockpit" };
  });

  // ---- P08 : bascule de theme (clip) -----------------------------------------------------------
  // Le toggle vit dans l'en-tete AppLayout (present sur /wizard aussi) : aria-label « Activer le theme … ».
  await run("P08-theme", async () => {
    const ctx = await authContext(browser, { colorScheme: "light", record: true });
    const p = await ctx.newPage();
    await p.goto(`${FRONT}/`, { waitUntil: "domcontentloaded" });
    await settle(p, 2500);
    const toggle = p.getByRole("button", { name: /Activer le thème (clair|sombre)/ });
    const found = await toggle.count();
    if (found > 0) {
      await toggle.first().scrollIntoViewIfNeeded().catch(() => {});
      const box = await toggle.first().boundingBox();
      if (box) { await p.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 20 }); }
      await p.waitForTimeout(700);
      await p.screenshot({ path: path.join(CAPTURES, "P08-clair.png") });
      await toggle.first().click();
      await p.waitForTimeout(1200);
      await p.screenshot({ path: path.join(CAPTURES, "P08-sombre.png") });
    }
    await p.waitForTimeout(400);
    const video = p.video();
    await ctx.close();
    if (video) { const vp = await video.path(); fs.renameSync(vp, path.join(CAPTURES, "P08-theme.webm")); }
    return { ok: found > 0, toggleFound: found };
  });

  // ---- P04 : ecran des contraintes -------------------------------------------------------------
  await run("P04-contraintes", async () => {
    // D'abord le vrai ecran /matchs/contraintes ; repli sur le wizard si le module matchs est verrouille.
    await m.goto(`${FRONT}/matchs/contraintes`, { waitUntil: "domcontentloaded" });
    await settle(m, 2000);
    let info = await pageInfo(m);
    const locked = (await m.getByText(/Matchs verrouill/i).count()) > 0;
    await debugShot(m, "P04-matchs-contraintes.png");
    if (!locked && !info.url.includes("/wizard")) {
      await shot(m, "P04-contraintes.png");
      // Reveler un formulaire d'ajout (inline) si present.
      const add = m.getByRole("button", { name: /^Ajouter$|^Interdire$/ });
      if (await add.count()) { await add.first().click().catch(() => {}); await m.waitForTimeout(800); await shot(m, "P04-contraintes-ajout.png"); }
      return { ok: true, source: "/matchs/contraintes", ...info };
    }
    // Repli : etape contraintes du wizard.
    await m.goto(`${FRONT}/wizard`, { waitUntil: "domcontentloaded" });
    await settle(m, 2000);
    info = await pageInfo(m);
    await debugShot(m, "P04-wizard.png");
    await shot(m, "P04-contraintes.png");
    return { ok: true, source: "/wizard (matchs verrouille)", locked, ...info };
  });

  // ---- P09 : matchs (calendrier) + conflits ----------------------------------------------------
  await run("P09-matchs", async () => {
    await m.goto(`${FRONT}/matchs`, { waitUntil: "domcontentloaded" });
    await settle(m, 2000);
    const locked = (await m.getByText(/Matchs verrouill/i).count()) > 0;
    const info = await pageInfo(m);
    await debugShot(m, "P09-calendrier.png");
    await shot(m, "P09a-calendrier.png");
    let conflits = {};
    await m.goto(`${FRONT}/matchs/conflits`, { waitUntil: "domcontentloaded" });
    await settle(m, 2000);
    await debugShot(m, "P09-conflits.png");
    await shot(m, "P09b-conflits.png");
    const conflictCount = await m.locator("[data-conflicts-entries]").count();
    conflits = { conflictsContainer: conflictCount, traiter: await m.getByRole("button", { name: "Traiter le conflit" }).count() };
    return { ok: true, locked, ...info, ...conflits, note: locked ? "module matchs verrouille (pas de version de plan en vigueur)" : "ok" };
  });

  // ---- P10 : bureau en lecture (PAS de badge « Lecture » dans l'app — on signale) ---------------
  await run("P10-lecture", async () => {
    await m.goto(`${FRONT}/club`, { waitUntil: "domcontentloaded" });
    await settle(m, 2000);
    const info = await pageInfo(m);
    await debugShot(m, "P10-club.png");
    await shot(m, "P10-club-membres.png");
    return {
      ok: true,
      ...info,
      SIGNAL:
        "L'app n'a NI role president/tresorier NI badge « Lecture » pour un membre (roles = Gestionnaire/Membre ; les actions d'edition sont seulement MASQUEES pour un membre). Capture = section Membres du club. Le plan 10 devra se faire en motion design, OU l'app devra ajouter un badge (hors scope). Rien n'a ete modifie dans l'app.",
    };
  });

  // ---- P05c : fenetre « Doleances des coachs » cote gestionnaire (PR #1137) ---------------------
  await run("P05c-doleances-hub", async () => {
    await m.goto(`${FRONT}/`, { waitUntil: "domcontentloaded" });
    await settle(m, 2500);
    const btn = m.getByRole("button", { name: "Doléances", exact: true });
    if (!(await btn.count())) {
      await debugShot(m, "P05c-no-button.png");
      return { ok: false, note: "bouton Doleances absent (pas de carte de vacances a venir sur le radar, ou / redirige vers /wizard)" };
    }
    await btn.first().click();
    const dlg = m.getByRole("dialog");
    await dlg.waitFor({ timeout: 15000 });
    await m.waitForTimeout(1500);
    await shot(m, "P05c-doleances-hub.png");
    const tabs = await dlg.getByRole("tab").allInnerTexts().catch(() => []);
    return { ok: true, tabs };
  });

  await mgr.close();

  // ---- P05a : e-mail REEL recu par le coach (HTML du mail, pas l'UI Mailpit) --------------------
  await run("P05a-email", async () => {
    if (!MAILID) return { ok: false, note: "pas de MAILID (campagne/envoi non aboutis)" };
    const res = await fetch(`${MAILPIT}/api/v1/message/${MAILID}`);
    const msg = await res.json();
    const html = msg.HTML || msg.Text || "";
    if (!html) return { ok: false, note: "mail sans corps HTML" };
    const file = path.join(TMP, "email.html");
    fs.writeFileSync(file, html, "utf8");
    const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 2, colorScheme: "light" });
    const p = await ctx.newPage();
    await p.goto(`file://${file}`, { waitUntil: "domcontentloaded" });
    await p.waitForTimeout(800);
    await p.screenshot({ path: path.join(CAPTURES, "P05a-email.png"), fullPage: true });
    await ctx.close();
    log("  capture: P05a-email.png");
    return { ok: true, subject: msg.Subject || "", from: (msg.From && msg.From.Address) || "" };
  });

  // ---- P05b : page publique du coach (sans compte) ---------------------------------------------
  await run("P05b-page-coach", async () => {
    if (!WISH_TOKEN) return { ok: false, note: "pas de WISH_TOKEN (campagne non creee)" };
    const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 2, colorScheme: "light", locale: "fr-FR" });
    await ctx.addInitScript(FAKE_CURSOR);
    const p = await ctx.newPage();
    await p.goto(`${FRONT}/doleances/${WISH_TOKEN}`, { waitUntil: "domcontentloaded" });
    await settle(p, 3000);
    await debugShot(p, "P05b-coach-landing.png");
    // Entrer dans le formulaire (CTA « Commencer » / « Reviser mes reponses »).
    const cta = p.getByRole("button", { name: /Commencer|Réviser mes réponses/ });
    if (await cta.count()) { await cta.first().click().catch(() => {}); await p.waitForTimeout(6500); }
    await shot(p, "P05b-page-coach.png");
    const info = await pageInfo(p);
    await ctx.close();
    return { ok: true, ...info };
  });

  // ---- P06 : generation (clip + grille finale + boites). Le plus long/risque : en DERNIER. ------
  await run("P06-generation", async () => {
    const ctx = await authContext(browser, { colorScheme: "light", record: true });
    const p = await ctx.newPage();
    await p.goto(`${FRONT}/wizard`, { waitUntil: "domcontentloaded" });
    await settle(p, 3000);
    await debugShot(p, "P06-wizard-before.png");
    // Bouton de (re)generation : « Lancer la generation » / « Generer le planning de periode » / « Regenerer ».
    const gen = p.getByRole("button", { name: /Lancer la génération|Générer le planning|Régénérer/ });
    const genCount = await gen.count();
    if (genCount === 0) {
      await ctx.close();
      return { ok: false, note: "aucun bouton de generation trouve sur /wizard (etat du wizard ?) — voir debug/P06-wizard-before.png" };
    }
    const box = await gen.first().boundingBox();
    if (box) await p.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 20 });
    await p.waitForTimeout(500);
    await gen.first().click();
    // Attendre la fin : « Génération du planning… » disparait ET des [data-slot-id] apparaissent (borne 240 s).
    let done = false;
    try {
      await p.waitForSelector("[data-slot-id]", { timeout: 240000 });
      done = true;
    } catch (e) { /* timeout */ }
    await p.waitForTimeout(2500);
    await p.screenshot({ path: path.join(CAPTURES, "P06-grille-finale.png") });
    const nb = await dumpBoxes(p, "[data-slot-id]", "P06-boxes.json");
    await p.waitForTimeout(400);
    const video = p.video();
    await ctx.close();
    if (video) { const vp = await video.path(); fs.renameSync(vp, path.join(CAPTURES, "P06-generation.webm")); }
    return { ok: done, slots: nb, note: done ? "grille remplie" : "pas de [data-slot-id] sous 240 s (echec ou etat bloquant)" };
  });

  await browser.close();
  fs.writeFileSync(path.join(ROOT, "out", "diag.json"), JSON.stringify(diag, null, 2));
  log("\n=== DIAGNOSTIC ===");
  for (const [k, v] of Object.entries(diag)) log(k, "->", JSON.stringify(v).slice(0, 240));
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
