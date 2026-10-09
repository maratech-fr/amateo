// Capture Playwright de l'application (club de DEMONSTRATION, thème clair sauf plan 8).
// Lance par capture/run.sh (SOUS with-sandbox, base amateo_dev).
// Args : JWT(gestionnaire), WISH_TOKEN, MAILID, MEMBER_JWT(membre lecture seule).
//
// Le club de demo est onboarde mais SANS generation en vigueur (CockpitPage renvoie vers /wizard ;
// « Matchs verrouilles » tant que le socle n'est pas valide). Phase 0 = BOOTSTRAP : on genere le
// planning (clip P06, vue dense), on deplace+verrouille une seance (clip P07), puis on VALIDE
// (debloque accueil + matchs). Ensuite on capture les plans sur l'app debloquee.
//
// Clip video pour ce qui BOUGE ; PNG HD (1920x1080, deviceScaleFactor 2) pour un fond de zoom.
// Chaque plan est ISOLE (try/catch). Faux curseur lisse injecte. Attentes bornees. Aucun secret en dur.
const fs = require("node:fs");
const path = require("node:path");
const { chromium } = require("@playwright/test");

const [JWT, WISH_TOKEN, MAILID, MEMBER_JWT] = process.argv.slice(2);
const FRONT = "http://localhost:5173";
const MAILPIT = "http://localhost:8025";
const ROOT = path.resolve(__dirname, "..");
const CAPTURES = path.join(ROOT, "out", "captures");
const DEBUG = path.join(ROOT, "out", "debug");
const TMP = path.join(ROOT, "out", "tmp");
for (const d of [CAPTURES, DEBUG, TMP]) fs.mkdirSync(d, { recursive: true });

const diag = {};
const log = (...a) => console.log(...a);

const FAKE_CURSOR = () => {
  const d = document.createElement("div");
  d.id = "__fake_cursor";
  d.style.cssText =
    "position:fixed;z-index:2147483647;width:22px;height:22px;margin:-11px 0 0 -11px;border-radius:50%;background:rgba(46,120,118,.35);border:2px solid #2e7876;pointer-events:none;transition:transform .05s linear;left:0;top:0";
  document.documentElement.appendChild(d);
  window.addEventListener("mousemove", (e) => { d.style.left = e.clientX + "px"; d.style.top = e.clientY + "px"; }, true);
};

function ctxOpts({ colorScheme = "light", record = false } = {}) {
  const o = { viewport: { width: 1920, height: 1080 }, deviceScaleFactor: record ? 1 : 2, colorScheme, locale: "fr-FR" };
  if (record) o.recordVideo = { dir: TMP, size: { width: 1920, height: 1080 } };
  return o;
}
async function authContext(browser, jwt, opts = {}) {
  const ctx = await browser.newContext(ctxOpts(opts));
  await ctx.addCookies([{ name: "BEARER", value: jwt, domain: "localhost", path: "/api", httpOnly: true, secure: false, sameSite: "Lax" }]);
  const mode = opts.colorScheme === "dark" ? "dark" : "light";
  await ctx.addInitScript((m) => {
    try {
      localStorage.setItem("cs-auth", JSON.stringify({ state: { isAuthenticated: true }, version: 2 }));
      localStorage.setItem("cs-theme", JSON.stringify({ state: { mode: m, accent: null }, version: 1 }));
    } catch (e) { /* ignore */ }
  }, mode);
  await ctx.addInitScript(FAKE_CURSOR);
  return ctx;
}
async function settle(page, ms = 1500) { await page.waitForLoadState("domcontentloaded").catch(() => {}); await page.waitForTimeout(ms); }
async function shot(page, name) { await page.screenshot({ path: path.join(CAPTURES, name) }); log("  capture:", name); }
async function debugShot(page, name) { try { await page.screenshot({ path: path.join(DEBUG, name), fullPage: true }); } catch (e) { /* */ } }
async function pageInfo(page) {
  let heading = "";
  try { heading = (await page.locator("h1,h2").first().innerText({ timeout: 2000 })).slice(0, 120); } catch (e) { /* */ }
  return { url: page.url(), heading };
}
async function moveToward(page, locator) {
  try { const b = await locator.boundingBox(); if (b) await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2, { steps: 18 }); } catch (e) { /* */ }
}
async function saveVideo(page, ctx, name) {
  await page.waitForTimeout(400);
  const v = page.video();
  await ctx.close();
  if (v) { try { fs.renameSync(await v.path(), path.join(CAPTURES, name)); } catch (e) { /* */ } }
}
async function dumpBoxes(page, selector, file) {
  try {
    const boxes = await page.$$eval(selector, (els) => els.map((el) => {
      const r = el.getBoundingClientRect();
      return { id: el.getAttribute("data-slot-id") || null, x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) };
    }));
    fs.writeFileSync(path.join(CAPTURES, file), JSON.stringify({ selector, count: boxes.length, boxes }, null, 2));
    log(`  boxes: ${file} (${boxes.length})`); return boxes.length;
  } catch (e) { log("  boxes echec:", file, e.message); return 0; }
}
async function denseView(page) {
  // Vue qui PACKE les seances (le « Par gymnase » les eparpille). On essaie « Par jour » puis « Par club ».
  for (const name of ["Par jour", "Par club", "Par équipe"]) {
    const b = page.getByRole("button", { name });
    if (await b.count()) { await b.first().click().catch(() => {}); await page.waitForTimeout(1500); return name; }
  }
  return null;
}
async function run(name, fn) {
  try { log(`== ${name} ==`); diag[name] = (await fn()) || { ok: true }; }
  catch (e) { log(`   ECHEC ${name}: ${e.message}`); diag[name] = { ok: false, error: e.message }; }
}

// Appel API (Bearer du gestionnaire). clubId/seasonId sont injectes cote serveur (membership).
async function api(jwt, method, p, body) {
  const headers = { Authorization: `Bearer ${jwt}`, Accept: "application/ld+json" };
  if (body) headers["Content-Type"] = "application/ld+json";
  const res = await fetch(`http://localhost:8080/api${p}`, { method, headers, body: body ? JSON.stringify(body) : undefined });
  const text = await res.text();
  let json; try { json = JSON.parse(text); } catch (e) { json = text; }
  return { status: res.status, json };
}

// Cree le conflit de MATCHS (P09) : un coach partage par deux equipes + deux rencontres a domicile
// le MEME jour futur, coups d'envoi < 105 min -> « Personne en double ». Exige le socle VALIDE
// (SocleGuard) : a lancer APRES la validation (P07). Aucune donnee reelle (adversaires fictifs).
async function seedMatchConflict(jwt) {
  const teams = (await api(jwt, "GET", "/teams?itemsPerPage=200")).json;
  const tm = (teams && teams.member) || [];
  if (tm.length < 2) return { ok: false, note: "pas assez d'equipes" };
  // Deux equipes NON touchees par la campagne doleances (indices >= 5) -> un conflit coach PROPRE
  // (une seule « Personne en double » : Alex Martin, pas aussi le coach des doleances).
  const iA = tm.length > 6 ? 5 : 0, iB = tm.length > 6 ? 6 : 1;
  const TA = tm[iA].id, TB = tm[iB].id;
  const venues = (await api(jwt, "GET", "/venues?itemsPerPage=50")).json;
  const vm = (venues && venues.member) || [];
  const VA = vm.length ? vm[0].id : undefined;
  const VB = vm.length > 1 ? vm[1].id : VA; // gymnases DIFFERENTS -> pas de « collision de gymnase » parasite
  // Coach FICTIF partage (ASSISTANT sur les deux equipes -> suffit pour « Personne en double »).
  const coach = (await api(jwt, "POST", "/coaches", { firstName: "Alex", lastName: "Martin", isActive: true })).json;
  const coachId = coach && coach.id;
  if (coachId) {
    await api(jwt, "POST", "/team_coaches", { teamId: TA, coachId, role: "ASSISTANT" });
    await api(jwt, "POST", "/team_coaches", { teamId: TB, coachId, role: "ASSISTANT" });
  }
  // Date FUTURE (demain) : les rencontres deja jouees sont ecartees du radar ; demain reste
  // dans la semaine courante du calendrier.
  const d = new Date(); d.setDate(d.getDate() + 1);
  const date = d.toISOString().slice(0, 10);
  const base = { matchDate: date, homeAway: "HOME", status: "PLACED" };
  const b1 = { ...base, teamId: TA, opponentLabel: "Club adverse Nord", kickoffTime: "18:00" };
  const b2 = { ...base, teamId: TB, opponentLabel: "Club adverse Sud", kickoffTime: "19:00" };
  if (VA) b1.venueId = VA;
  if (VB) b2.venueId = VB;
  const f1 = await api(jwt, "POST", "/fixtures", b1);
  const f2 = await api(jwt, "POST", "/fixtures", b2);
  const conf = (await api(jwt, "GET", "/fixtures/conflicts")).json;
  const list = Array.isArray(conf) ? conf : (conf && conf.conflicts) || (conf && conf.member) || [];
  const mm = (Array.isArray(list) ? list : []).filter((c) => c && c.type === "MATCH_MATCH").length;
  return { ok: f1.status < 300 && f2.status < 300, date, f1: f1.status, f2: f2.status, coachId: coachId || null, matchMatch: mm, f1err: f1.status >= 300 ? JSON.stringify(f1.json).slice(0, 200) : undefined };
}

// Valider le planning principal (trigger « Valider le planning » -> confirmation « Valider » dans la
// boite de dialogue). Pose VALIDATED. Debloque l'accueil, les matchs et la creation de fixtures.
async function validatePlanning(p) {
  try {
    let vtrig = p.getByRole("button", { name: "Valider le planning" });
    if (!(await vtrig.count())) vtrig = p.getByRole("button", { name: /^Valider$/ });
    if (!(await vtrig.count())) { log("   validation: pas de bouton Valider"); return; }
    await moveToward(p, vtrig.first());
    await vtrig.first().click();
    const dlg = p.getByRole("dialog");
    await dlg.waitFor({ timeout: 8000 }).catch(() => {});
    const confirm = dlg.getByRole("button", { name: /^Valider$/ });
    await confirm.waitFor({ timeout: 8000 }).catch(() => {});
    for (let i = 0; i < 20 && (await confirm.isDisabled().catch(() => true)); i++) await p.waitForTimeout(500);
    if ((await confirm.count()) && !(await confirm.isDisabled().catch(() => true))) { await confirm.click(); await p.waitForTimeout(3500); VALIDATED = true; }
  } catch (e) { log("   validation: " + e.message); }
}

let HAS_VERSION = false, VALIDATED = false;

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || undefined });

  // ===== P06 : generation (clip + vue dense + boites) ===========================================
  await run("P06-generation", async () => {
    const ctx = await authContext(browser, JWT, { record: true });
    const p = await ctx.newPage();
    await p.goto(`${FRONT}/wizard?step=generate`, { waitUntil: "domcontentloaded" });
    await settle(p, 3000);
    await debugShot(p, "P06-wizard-landing.png");
    const gen = p.getByRole("button", { name: /Lancer la génération|Générer le planning de période|Régénérer/ });
    if (!(await gen.count())) {
      // peut-etre sur l'etape recap : avancer
      const cont = p.getByRole("button", { name: /Continuer vers la génération/ });
      if (await cont.count()) { await cont.first().click(); await settle(p, 2500); }
    }
    const gen2 = p.getByRole("button", { name: /Lancer la génération|Générer le planning de période|Régénérer/ });
    if (!(await gen2.count())) { await debugShot(p, "P06-no-generate.png"); await ctx.close(); return { ok: false, note: "bouton de generation absent" }; }
    await moveToward(p, gen2.first());
    await p.waitForTimeout(500);
    await gen2.first().click();
    try { await p.waitForSelector("[data-slot-id]", { timeout: 240000 }); HAS_VERSION = true; } catch (e) { /* */ }
    await p.waitForTimeout(2500);
    const view = await denseView(p); // vue dense pour « le planning en quelques minutes »
    await p.waitForTimeout(1200);
    await p.screenshot({ path: path.join(CAPTURES, "P06-grille-finale.png") });
    const nb = await dumpBoxes(p, "[data-slot-id]", "P06-boxes.json");
    let demanded = "";
    try { demanded = (await p.getByText(/séances demandées pour/).first().innerText()).trim(); } catch (e) { /* */ }
    // PAS de validation ici : le planning doit rester EDITABLE pour le deplacement + le verrou (P07).
    // La validation se fait a la fin de P07 (ou via le filet ensure-validated).
    await saveVideo(p, ctx, "P06-generation.webm");
    return { ok: HAS_VERSION, slots: nb, view, demanded, note: HAS_VERSION ? "grille remplie (vue dense)" : "pas de [data-slot-id] sous 240 s" };
  });

  // ===== P07 : deplacer une seance (clic -> « Placer ici ») puis la verrouiller (clip) + VALIDER =
  await run("P07-drag-lock", async () => {
    if (!HAS_VERSION) return { ok: false, note: "pas de planning a manipuler (generation non aboutie)" };
    const ctx = await authContext(browser, JWT, { record: true });
    const p = await ctx.newPage();
    await p.goto(`${FRONT}/wizard?step=generate`, { waitUntil: "domcontentloaded" });
    await settle(p, 3500);
    const hasSlots = await p.waitForSelector("[data-slot-id]", { timeout: 60000 }).then(() => true).catch(() => false);
    await debugShot(p, "P07-before.png");
    let moved = false, locked = false, slotsSeen = 0;
    try { slotsSeen = await p.locator("[data-slot-id]").count(); } catch (e) { /* */ }
    try {
      const slot = p.locator("[data-slot-id]").first();
      await slot.scrollIntoViewIfNeeded();
      await moveToward(p, slot);
      await p.waitForTimeout(600);
      await slot.click(); // selectionne la seance (mode cible : les cases vides deviennent « Placer ici »)
      await p.waitForTimeout(1200);
      const target = p.getByRole("button", { name: /^Placer ici/ });
      if (await target.count()) { await moveToward(p, target.first()); await p.waitForTimeout(500); await target.first().click(); moved = true; await p.waitForTimeout(1500); }
    } catch (e) { log("   move: " + e.message); }
    try {
      const slot2 = p.locator("[data-slot-id]").first();
      await slot2.scrollIntoViewIfNeeded();
      await moveToward(p, slot2);
      await slot2.hover().catch(() => {});
      await p.waitForTimeout(700);
      const lockBtn = p.getByRole("button", { name: /^(Verrouiller|Déverrouiller) / }).first();
      if (await lockBtn.count()) { await lockBtn.click(); locked = true; await p.waitForTimeout(1300); }
    } catch (e) { log("   lock: " + e.message); }
    await debugShot(p, "P07-after.png");
    // Valider MAINTENANT (planning encore editable) -> debloque accueil + matchs + creation de fixtures.
    await validatePlanning(p);
    await saveVideo(p, ctx, "P07-drag-lock.webm");
    return { ok: moved || locked, moved, locked, hasSlots, slotsSeen, validated: VALIDATED };
  });

  // Filet de securite : si P06 n'a pas valide (bouton masque par un etat transitoire), on reessaie
  // dans un contexte propre — le deblocage de l'accueil/matchs/fixtures en depend.
  if (HAS_VERSION && !VALIDATED) {
    await run("ensure-validated", async () => {
      const ctx = await authContext(browser, JWT);
      const p = await ctx.newPage();
      await p.goto(`${FRONT}/wizard?step=generate`, { waitUntil: "domcontentloaded" });
      await settle(p, 3000);
      await p.waitForSelector("[data-slot-id]", { timeout: 60000 }).catch(() => {});
      await validatePlanning(p);
      await ctx.close();
      return { validated: VALIDATED };
    });
  }

  // ===== Conflit de matchs (P09) : cree APRES la validation (SocleGuard exige le socle valide) ==
  if (VALIDATED) { await run("P09-seed-conflict", async () => seedMatchConflict(JWT)); }
  else { diag["P09-seed-conflict"] = { ok: false, note: "socle NON valide (P07) -> POST /fixtures renverrait 409 ; P09 restera sans match ni conflit" }; log("P09-seed-conflict -> saute (socle non valide)"); }

  // ===== Manager context (app debloquee) ========================================================
  const mgr = await authContext(browser, JWT);
  const m = await mgr.newPage();

  await run("P00-home", async () => {
    await m.goto(`${FRONT}/`, { waitUntil: "domcontentloaded" });
    await settle(m, 2500);
    await debugShot(m, "home.png");
    await shot(m, "P00-home.png");
    const info = await pageInfo(m);
    return { ...info, note: info.url.includes("/wizard") ? "ENCORE /wizard" : "cockpit" };
  });

  await run("P08-theme", async () => {
    const ctx = await authContext(browser, JWT, { colorScheme: "light", record: true });
    const p = await ctx.newPage();
    await p.goto(`${FRONT}/`, { waitUntil: "domcontentloaded" });
    await settle(p, 2500);
    const toggle = p.getByRole("button", { name: /Activer le thème (clair|sombre)/ });
    const found = await toggle.count();
    if (found) {
      await moveToward(p, toggle.first());
      await p.waitForTimeout(700);
      await p.screenshot({ path: path.join(CAPTURES, "P08-clair.png") });
      await toggle.first().click();
      await p.waitForTimeout(1300);
      await p.screenshot({ path: path.join(CAPTURES, "P08-sombre.png") });
    }
    await saveVideo(p, ctx, "P08-theme.webm");
    return { ok: found > 0, toggleFound: found };
  });

  // P04 : contraintes d'ENTRAINEMENT (etape Contraintes du wizard) — une regle ajoutee en direct.
  await run("P04-contraintes", async () => {
    const ctx = await authContext(browser, JWT, { record: true });
    const p = await ctx.newPage();
    await p.goto(`${FRONT}/wizard?step=constraints`, { waitUntil: "domcontentloaded" });
    await settle(p, 3000);
    const info = await pageInfo(p);
    await debugShot(p, "P04-wizard-constraints.png");
    await p.screenshot({ path: path.join(CAPTURES, "P04-contraintes.png") });
    // Ouvrir le formulaire d'ajout et enregistrer une regle (best effort, clip).
    let added = false;
    try {
      const addToggle = p.getByRole("button", { name: /^Ajouter la contrainte$/ });
      if (await addToggle.count()) { await moveToward(p, addToggle.first()); await addToggle.first().click(); await p.waitForTimeout(1000); }
      await p.screenshot({ path: path.join(CAPTURES, "P04-contraintes-formulaire.png") });
      const save = p.getByRole("button", { name: "Enregistrer la contrainte" });
      if (await save.count() && !(await save.first().isDisabled().catch(() => true))) {
        await moveToward(p, save.first()); await save.first().click(); await p.waitForTimeout(1800); added = true;
        await p.screenshot({ path: path.join(CAPTURES, "P04-contraintes-ajoutee.png") });
      }
    } catch (e) { log("   ajout contrainte: " + e.message); }
    await saveVideo(p, ctx, "P04-contrainte-ajout.webm");
    return { ok: info.url.includes("step=constraints"), ...info, added, source: "/wizard?step=constraints (contraintes d'entrainement)" };
  });

  await run("P09-matchs", async () => {
    await m.goto(`${FRONT}/matchs`, { waitUntil: "domcontentloaded" });
    await settle(m, 2500);
    const locked = (await m.getByText(/Matchs verrouill/i).count()) > 0;
    // /matchs renvoie vers Conflits quand il y a des conflits : cliquer explicitement « Calendrier ».
    const calTab = m.getByRole("link", { name: /^Calendrier/ });
    if (await calTab.count()) { await moveToward(m, calTab.first()); await calTab.first().click(); await settle(m, 2500); }
    // Les rencontres creees sont AMICALES (sans competition) ; le filtre par defaut masque « Amical ».
    // On active « Amical » + vue « Mois » pour que les deux matchs (demain) apparaissent au calendrier.
    try { const am = m.getByRole("button", { name: /^Amical$/ }); if (await am.count()) { await moveToward(m, am.first()); await am.first().click(); await m.waitForTimeout(900); } } catch (e) { /* */ }
    try { const mois = m.getByRole("button", { name: /^Mois$/ }); if (await mois.count()) { await mois.first().click(); await m.waitForTimeout(1300); } } catch (e) { /* */ }
    await debugShot(m, "P09-calendrier.png");
    await shot(m, "P09a-calendrier.png");
    // Conflits : ouvrir le premier groupe pour montrer le detail (les deux rencontres, le chevauchement).
    await m.goto(`${FRONT}/matchs/conflits`, { waitUntil: "domcontentloaded" });
    await settle(m, 2500);
    try {
      const grp = m.getByRole("button", { name: /Alex Martin/ }).first();
      if (await grp.count()) { await moveToward(m, grp); await grp.click(); await m.waitForTimeout(1300); }
    } catch (e) { /* */ }
    await debugShot(m, "P09-conflits.png");
    await shot(m, "P09b-conflits.png");
    const conflictsContainer = await m.locator("[data-conflicts-entries]").count();
    const traiter = await m.getByRole("button", { name: "Traiter le conflit" }).count();
    const aucun = (await m.getByText(/Aucun conflit/i).count()) > 0;
    const aucunMatch = (await m.getByText(/Aucun match import/i).count()) > 0;
    return { ok: !locked, locked, conflictsContainer, traiter, aucunConflit: aucun, aucunMatch, note: locked ? "verrouille" : "ok" };
  });

  await run("P05c-doleances-hub", async () => {
    await m.goto(`${FRONT}/`, { waitUntil: "domcontentloaded" });
    await settle(m, 2500);
    const btn = m.getByRole("button", { name: "Doléances", exact: true });
    if (!(await btn.count())) { await debugShot(m, "P05c-no-button.png"); return { ok: false, note: "bouton Doleances absent" }; }
    await moveToward(m, btn.first());
    await btn.first().click();
    const dlg = m.getByRole("dialog");
    await dlg.waitFor({ timeout: 15000 });
    await m.waitForTimeout(1600);
    await shot(m, "P05c-doleances-hub.png");
    return { ok: true, tabs: await dlg.getByRole("tab").allInnerTexts().catch(() => []) };
  });

  await mgr.close();

  // P10 : vue d'un membre du bureau en LECTURE SEULE (compte membre dedie).
  await run("P10-lecture", async () => {
    if (!MEMBER_JWT) return { ok: false, note: "pas de MEMBER_JWT (compte membre non cree) — plan 10 non capture", SIGNAL: "Aucun compte membre lecture seule fourni." };
    const ctx = await authContext(browser, MEMBER_JWT);
    const p = await ctx.newPage();
    await p.goto(`${FRONT}/planning`, { waitUntil: "domcontentloaded" });
    await settle(p, 3000);
    const info = await pageInfo(p);
    await debugShot(p, "P10-planning-membre.png");
    await denseView(p).catch(() => {});
    await p.waitForTimeout(1000);
    await p.screenshot({ path: path.join(CAPTURES, "P10-lecture.png") });
    // Indices de lecture seule : pas de bouton Regenerer/Valider, badge « Lecture » si present.
    const regen = await p.getByRole("button", { name: /Régénérer|Valider le planning/ }).count();
    const badgeLecture = await p.getByText(/Lecture/i).count();
    await ctx.close();
    return { ok: true, ...info, editButtons: regen, badgeLecture, SIGNAL: "Role 'member' = edition MASQUEE (canManage faux). L'app n'a pas de role president/tresorier ; badge « Lecture » = seulement sur saison archivee, pas sur le role membre." };
  });

  await run("P05a-email", async () => {
    if (!MAILID) return { ok: false, note: "pas de MAILID" };
    const msg = await (await fetch(`${MAILPIT}/api/v1/message/${MAILID}`)).json();
    const html = msg.HTML || msg.Text || "";
    if (!html) return { ok: false, note: "mail sans HTML" };
    const file = path.join(TMP, "email.html");
    fs.writeFileSync(file, html, "utf8");
    const ctx = await browser.newContext(ctxOpts({}));
    const p = await ctx.newPage();
    await p.goto(`file://${file}`, { waitUntil: "domcontentloaded" });
    await p.waitForTimeout(800);
    await p.screenshot({ path: path.join(CAPTURES, "P05a-email.png"), fullPage: true });
    await ctx.close();
    log("  capture: P05a-email.png");
    return { ok: true, subject: msg.Subject || "", from: (msg.From && msg.From.Address) || "" };
  });

  await run("P05b-page-coach", async () => {
    if (!WISH_TOKEN) return { ok: false, note: "pas de WISH_TOKEN" };
    const ctx = await browser.newContext(ctxOpts({}));
    await ctx.addInitScript(() => { try { localStorage.setItem("cs-theme", JSON.stringify({ state: { mode: "light", accent: null }, version: 1 })); } catch (e) { /* */ } });
    await ctx.addInitScript(FAKE_CURSOR);
    const p = await ctx.newPage();
    await p.goto(`${FRONT}/doleances/${WISH_TOKEN}`, { waitUntil: "domcontentloaded" });
    await settle(p, 3000);
    await debugShot(p, "P05b-coach-landing.png");
    const cta = p.getByRole("button", { name: /Commencer|Réviser mes réponses/ });
    if (await cta.count()) { await moveToward(p, cta.first()); await cta.first().click().catch(() => {}); await p.waitForTimeout(6500); }
    await shot(p, "P05b-page-coach.png");
    const info = await pageInfo(p);
    await ctx.close();
    return { ok: true, ...info };
  });

  await browser.close();
  fs.writeFileSync(path.join(ROOT, "out", "diag.json"), JSON.stringify(diag, null, 2));
  log("\n=== DIAGNOSTIC ===");
  for (const [k, v] of Object.entries(diag)) log(k, "->", JSON.stringify(v).slice(0, 280));
})().catch((e) => { console.error(e); process.exit(1); });
