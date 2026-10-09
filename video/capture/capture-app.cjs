// Capture Playwright de l'application (club de DEMONSTRATION, thème clair sauf plan 8).
// Lance par capture/run.sh (SOUS with-sandbox, base amateo_dev).
// Args : JWT(gestionnaire), WISH_TOKEN, MAILID, MEMBER_JWT(membre lecture seule).
//
// FILTRE DE PLANS : env CAPTURE_PLANS (ex. « P04 P07 ») ne capture QUE ces plans + leurs prerequis
// (P07 exige un planning genere -> P06 ; les plans « valides » exigent la validation). Vide = tout.
//
// Ordre (corrige 2026-10-09) : P04 d'abord sur un wizard EDITABLE (plan pas encore valide : la
// regle d'entrainement s'enregistre VRAIMENT) ; puis P06 (generation) ; puis P07 (deplacement reel
// « Déplacer » -> « Placer ici » + verrou) ; la VALIDATION n'a lieu que si un plan « valide » est
// demande (accueil/matchs/doleances/lecture). Clip video pour ce qui BOUGE ; PNG HD pour un fond de
// zoom. Chaque plan ISOLE (try/catch). Faux curseur lisse injecte. Attentes bornees. Aucun secret en dur.
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

// Filtre de plans (P04, P05, P06, P07, P08, P09, P10, P00). « P05 » couvre P05a/b/c.
const WANT = (process.env.CAPTURE_PLANS || "").split(/[ ,]+/).filter(Boolean);
const want = (id) => WANT.length === 0 || WANT.includes(id);
const needValidated = want("P00") || want("P08") || want("P09") || want("P10") || want("P05");
// P04 (contraintes EDITABLES) exige aussi un planning genere : les contraintes ne sont editables
// ET atteignables qu'APRES generation (guided=false) et AVANT validation (seasonEditLocked=false).
const needGeneration = want("P06") || want("P07") || want("P04") || needValidated;

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
// le MEME jour futur, coups d'envoi < 105 min -> « Personne en double ». Exige le socle VALIDE.
async function seedMatchConflict(jwt) {
  const teams = (await api(jwt, "GET", "/teams?itemsPerPage=200")).json;
  const tm = (teams && teams.member) || [];
  if (tm.length < 2) return { ok: false, note: "pas assez d'equipes" };
  const iA = tm.length > 6 ? 5 : 0, iB = tm.length > 6 ? 6 : 1;
  const TA = tm[iA].id, TB = tm[iB].id;
  const venues = (await api(jwt, "GET", "/venues?itemsPerPage=50")).json;
  const vm = (venues && venues.member) || [];
  const VA = vm.length ? vm[0].id : undefined;
  const VB = vm.length > 1 ? vm[1].id : VA;
  const coach = (await api(jwt, "POST", "/coaches", { firstName: "Alex", lastName: "Martin", isActive: true })).json;
  const coachId = coach && coach.id;
  if (coachId) {
    await api(jwt, "POST", "/team_coaches", { teamId: TA, coachId, role: "ASSISTANT" });
    await api(jwt, "POST", "/team_coaches", { teamId: TB, coachId, role: "ASSISTANT" });
  }
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

// Valider le planning principal (trigger « Valider le planning » -> confirmation « Valider »).
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

// Le club demo est genere PILE a capacite (81 seances pour 81 places) -> zero creneau vide, donc
// aucune case « Placer ici » pour un deplacement. On ajoute UN creneau d'entrainement de secours
// (capacite +1) AVANT la generation : la generation laisse alors un creneau vide (demande < places),
// cible du deplacement du plan 7. Best-effort : on essaie plusieurs heures (disponibilite gymnase).
async function seedSpareSlot(jwt) {
  const ts = (await api(jwt, "GET", "/venue_training_slots?itemsPerPage=400")).json;
  const list = (ts && ts.member) || [];
  if (!list.length) return { ok: false, note: "aucun creneau existant" };
  list.sort((a, b) => (String(a.startTime) < String(b.startTime) ? -1 : 1));
  const ref = list[0]; // venue+jour du creneau le plus tot -> on ajoute AVANT, meme gymnase
  const dur = ref.durationMinutes || 90;
  const taken = new Set(list.filter((s) => s.venueId === ref.venueId && s.dayOfWeek === ref.dayOfWeek).map((s) => String(s.startTime).slice(0, 5)));
  for (const t of ["15:00", "14:00", "16:00", "13:00", "12:00", "11:00", "10:00"]) {
    if (taken.has(t)) continue;
    // eslint-disable-next-line no-await-in-loop
    const r = await api(jwt, "POST", "/venue_training_slots", { venueId: ref.venueId, dayOfWeek: ref.dayOfWeek, startTime: t, durationMinutes: dur, capacity: 1 });
    if (r.status < 300) return { ok: true, added: { venueId: ref.venueId, dayOfWeek: ref.dayOfWeek, startTime: t }, status: r.status };
  }
  return { ok: false, note: "aucune heure addable (disponibilite gymnase)" };
}

let HAS_VERSION = false, VALIDATED = false;
let SPARE = null;

(async () => {
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || undefined });

  // P04 (contraintes EDITABLES) est capture APRES P06 (generation) et AVANT validation : c'est le
  // SEUL etat ou les contraintes sont a la fois ATTEIGNABLES (guided=false, hasFinishedVersion) et
  // EDITABLES (seasonEditLocked=false, pas valide). Voir le bloc juste apres la generation.

  // Creneau de secours pour P07 (une case vide a viser) — AVANT la generation.
  if (want("P07")) { await run("P07-spare-slot", async () => { SPARE = await seedSpareSlot(JWT); return SPARE; }); }

  // ===== P06 : generation (clip + vue dense + boites) ===========================================
  if (needGeneration) {
    await run("P06-generation", async () => {
      const ctx = await authContext(browser, JWT, { record: true });
      const p = await ctx.newPage();
      await p.goto(`${FRONT}/wizard?step=generate`, { waitUntil: "domcontentloaded" });
      await settle(p, 3000);
      await debugShot(p, "P06-wizard-landing.png");
      let gen = p.getByRole("button", { name: /Lancer la génération|Générer le planning de période|Régénérer/ });
      if (!(await gen.count())) {
        const cont = p.getByRole("button", { name: /Continuer vers la génération/ });
        if (await cont.count()) { await cont.first().click(); await settle(p, 2500); }
      }
      gen = p.getByRole("button", { name: /Lancer la génération|Générer le planning de période|Régénérer/ });
      if (!(await gen.count())) { await debugShot(p, "P06-no-generate.png"); await ctx.close(); return { ok: false, note: "bouton de generation absent" }; }
      await moveToward(p, gen.first());
      await p.waitForTimeout(500);
      await gen.first().click();
      try { await p.waitForSelector("[data-slot-id]", { timeout: 240000 }); HAS_VERSION = true; } catch (e) { /* */ }
      await p.waitForTimeout(2500);
      const view = await denseView(p);
      await p.waitForTimeout(1200);
      await p.screenshot({ path: path.join(CAPTURES, "P06-grille-finale.png") });
      const nb = await dumpBoxes(p, "[data-slot-id]", "P06-boxes.json");
      let demanded = "";
      try { demanded = (await p.getByText(/séances demandées pour/).first().innerText()).trim(); } catch (e) { /* */ }
      await saveVideo(p, ctx, "P06-generation.webm"); // PAS de validation ici (editable pour P07)
      return { ok: HAS_VERSION, slots: nb, view, demanded, note: HAS_VERSION ? "grille remplie (vue dense)" : "pas de [data-slot-id] sous 240 s" };
    });
  }

  // ===== P04 : contrainte d'ENTRAINEMENT (wizard EDITABLE : apres generation, avant validation) ===
  // On enregistre VRAIMENT une regle de famille SIMPLE (club : « pas d'entrainement apres 20h30 »,
  // preferee) et on filme l'ajout. Deep-link + repli clic sur l'entree de nav « Contraintes ».
  if (want("P04")) {
    await run("P04-contraintes", async () => {
      if (!HAS_VERSION) return { ok: false, note: "pas de planning genere -> contraintes non editables (guided)" };
      const ctx = await authContext(browser, JWT, { record: true });
      const p = await ctx.newPage();
      await p.goto(`${FRONT}/wizard?step=constraints`, { waitUntil: "domcontentloaded" });
      await settle(p, 3000);
      let heading = (await pageInfo(p)).heading;
      if (!/Contraintes/.test(heading)) {
        const nav = p.getByRole("button", { name: "Contraintes", exact: true });
        if (await nav.count()) { await moveToward(p, nav.first()); await nav.first().click(); await settle(p, 2200); heading = (await pageInfo(p)).heading; }
      }
      await debugShot(p, "P04-wizard-constraints.png");
      let added = false, editable = false, before = 0, after = 0;
      try {
        const tab = p.getByRole("button", { name: /^Horaires/ });
        if (await tab.count()) { await moveToward(p, tab.first()); await tab.first().click(); await p.waitForTimeout(900); }
        await p.screenshot({ path: path.join(CAPTURES, "P04-contraintes-formulaire.png") });
        const maxTime = p.getByLabel("Pas après");
        if (await maxTime.count()) {
          editable = !(await maxTime.first().isDisabled().catch(() => true));
          await moveToward(p, maxTime.first());
          await maxTime.first().fill("20:30").catch(() => {});
          await p.waitForTimeout(500);
        }
        const regle = p.getByLabel("Règle");
        if (await regle.count()) { await regle.first().selectOption({ label: "Préféré" }).catch(() => {}); }
        before = await p.locator("[aria-label^='Supprimer la contrainte']").count().catch(() => 0);
        const addBtn = p.getByRole("button", { name: /^Ajouter la contrainte$/ });
        if ((await addBtn.count()) && !(await addBtn.first().isDisabled().catch(() => true))) {
          await moveToward(p, addBtn.first());
          await p.waitForTimeout(400);
          await addBtn.first().click();
          await p.waitForTimeout(1800);
          after = await p.locator("[aria-label^='Supprimer la contrainte']").count().catch(() => 0);
          added = after > before;
        }
      } catch (e) { log("   P04 add: " + e.message); }
      await p.screenshot({ path: path.join(CAPTURES, "P04-contraintes.png") }); // liste AVEC la regle
      await saveVideo(p, ctx, "P04-contrainte-ajout.webm");
      return { ok: /Contraintes/.test(heading), heading, editable, added, before, after };
    });
  }

  // ===== P07 : deplacer une seance (select -> « Déplacer » -> « Placer ici ») puis verrouiller ====
  // On cherche une seance SIMPLE (une equipe), NON reservee : son volet offre « Déplacer » (exact)
  // et « Verrouiller ». On ecarte les seances de GROUPE (« Déplacer le groupe ») et reservees (le
  // verrou y ouvre une confirmation). On itere les premieres seances jusqu'a reussir.
  if (want("P07")) {
    await run("P07-drag-lock", async () => {
      if (!HAS_VERSION) return { ok: false, note: "pas de planning a manipuler (generation non aboutie)" };
      const ctx = await authContext(browser, JWT, { record: true });
      const p = await ctx.newPage();
      await p.goto(`${FRONT}/wizard?step=generate`, { waitUntil: "domcontentloaded" });
      await settle(p, 3500);
      const hasSlots = await p.waitForSelector("[data-slot-id]", { timeout: 60000 }).then(() => true).catch(() => false);
      await debugShot(p, "P07-before.png");
      const slots = p.locator("[data-slot-id]");
      const total = await slots.count().catch(() => 0);
      let selected = false, armed = false, moved = false, locked = false, placerCount = 0, triedMove = 0, moveKind = null;
      // 1) deplacer une seance SIMPLE, DEVERROUILLEE et NON RESERVEE (son volet offre « Déplacer »
      // ET « Verrouiller ») vers la CASE VIDE, en VERIFIANT que le moteur l'ACCEPTE (une seance
      // reservee ou mal placee est refusee -> on passe a la suivante). On ecarte les groupes.
      for (let i = 0; i < Math.min(total, 24) && !moved; i++) {
        try {
          const s = slots.nth(i);
          await s.scrollIntoViewIfNeeded();
          await moveToward(p, s);
          await p.waitForTimeout(450);
          await s.click();
          selected = true;
          await p.waitForTimeout(800);
          const mv = p.getByRole("button", { name: "Déplacer", exact: true });
          const unlocked = await p.getByRole("button", { name: "Verrouiller", exact: true }).count(); // deverrouillee + non reservee
          if (!unlocked || !(await mv.count()) || (await mv.first().isDisabled().catch(() => true))) continue; // reservee/verrouillee/groupe -> suivante
          triedMove++;
          await moveToward(p, mv.first());
          await p.waitForTimeout(350);
          await mv.first().click();
          armed = true;
          await p.waitForTimeout(1400);
          const target = p.getByRole("button", { name: /^Placer ici/ });
          placerCount = await target.count();
          if (placerCount > 0) {
            const t = target.first();
            await t.scrollIntoViewIfNeeded();
            await moveToward(p, t);
            await p.waitForTimeout(450);
            await t.click();
            await p.waitForTimeout(3500); // le moteur tranche
            if ((await p.getByText(/Déplacement refusé/).count().catch(() => 0)) > 0) {
              await p.keyboard.press("Escape").catch(() => {}); await p.waitForTimeout(500); continue; // regle cassee -> autre seance
            }
            moved = true; moveKind = "vide";
            // Verrouiller la seance qu'on vient de deplacer (meme volet) -> « move puis verrou ».
            const lkNow = p.getByRole("button", { name: "Verrouiller", exact: true });
            if ((await lkNow.count()) && !(await lkNow.first().isDisabled().catch(() => true))) {
              await moveToward(p, lkNow.first());
              await p.waitForTimeout(400);
              await lkNow.first().click();
              await p.waitForTimeout(900);
              const dlg = p.getByRole("dialog");
              if (await dlg.count()) { await dlg.getByRole("button", { name: /^Annuler$/ }).first().click().catch(() => {}); }
              else { locked = true; await p.waitForTimeout(800); }
            }
          } else {
            await p.keyboard.press("Escape").catch(() => {});
            await p.waitForTimeout(400);
          }
        } catch (e) { log("   move try " + i + ": " + e.message); }
      }
      // 2) verrouiller une seance DEVERROUILLEE (« Verrouiller » = immediat, hors reservation).
      for (let i = 0; i < Math.min(total, 14) && !locked; i++) {
        try {
          const s = slots.nth(i);
          await s.scrollIntoViewIfNeeded();
          await moveToward(p, s);
          await p.waitForTimeout(400);
          await s.click();
          await p.waitForTimeout(700);
          const lk = p.getByRole("button", { name: "Verrouiller", exact: true });
          if ((await lk.count()) && !(await lk.first().isDisabled().catch(() => true))) {
            await moveToward(p, lk.first());
            await p.waitForTimeout(300);
            await lk.first().click();
            await p.waitForTimeout(900);
            const dlg = p.getByRole("dialog");
            if (await dlg.count()) { await dlg.getByRole("button", { name: /^Annuler$/ }).first().click().catch(() => {}); await p.waitForTimeout(400); }
            else { locked = true; await p.waitForTimeout(700); }
          }
        } catch (e) { log("   lock try " + i + ": " + e.message); }
      }
      await debugShot(p, "P07-after.png");
      if (needValidated) await validatePlanning(p);
      await saveVideo(p, ctx, "P07-drag-lock.webm");
      return { ok: moved || locked, selected, armed, moved, moveKind, locked, placerCount, triedMove, total, hasSlots, spare: SPARE, validated: VALIDATED };
    });
  }

  // Filet : si un plan aval est demande et que la validation n'a pas eu lieu, on la rejoue.
  if (needValidated && HAS_VERSION && !VALIDATED) {
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

  // ===== Conflit de matchs (P09) : apres validation (SocleGuard) ==================================
  if (want("P09")) {
    if (VALIDATED) { await run("P09-seed-conflict", async () => seedMatchConflict(JWT)); }
    else { diag["P09-seed-conflict"] = { ok: false, note: "socle NON valide -> P09 sans conflit" }; }
  }

  // ===== Contexte gestionnaire (app debloquee) ====================================================
  if (want("P00") || want("P08") || want("P09") || want("P05")) {
    const mgr = await authContext(browser, JWT);
    const m = await mgr.newPage();

    if (want("P00")) {
      await run("P00-home", async () => {
        await m.goto(`${FRONT}/`, { waitUntil: "domcontentloaded" });
        await settle(m, 2500);
        await debugShot(m, "home.png");
        await shot(m, "P00-home.png");
        const info = await pageInfo(m);
        return { ...info, note: info.url.includes("/wizard") ? "ENCORE /wizard" : "cockpit" };
      });
    }

    if (want("P08")) {
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
    }

    if (want("P09")) {
      await run("P09-matchs", async () => {
        await m.goto(`${FRONT}/matchs`, { waitUntil: "domcontentloaded" });
        await settle(m, 2500);
        const locked = (await m.getByText(/Matchs verrouill/i).count()) > 0;
        const calTab = m.getByRole("link", { name: /^Calendrier/ });
        if (await calTab.count()) { await moveToward(m, calTab.first()); await calTab.first().click(); await settle(m, 2500); }
        try { const am = m.getByRole("button", { name: /^Amical$/ }); if (await am.count()) { await moveToward(m, am.first()); await am.first().click(); await m.waitForTimeout(900); } } catch (e) { /* */ }
        try { const mois = m.getByRole("button", { name: /^Mois$/ }); if (await mois.count()) { await mois.first().click(); await m.waitForTimeout(1300); } } catch (e) { /* */ }
        await debugShot(m, "P09-calendrier.png");
        await shot(m, "P09a-calendrier.png");
        await m.goto(`${FRONT}/matchs/conflits`, { waitUntil: "domcontentloaded" });
        await settle(m, 2500);
        try {
          const grp = m.getByRole("button", { name: /Alex Martin/ }).first();
          if (await grp.count()) { await moveToward(m, grp); await grp.click(); await m.waitForTimeout(1300); }
        } catch (e) { /* */ }
        await debugShot(m, "P09-conflits.png");
        await shot(m, "P09b-conflits.png");
        const traiter = await m.getByRole("button", { name: "Traiter le conflit" }).count();
        const aucun = (await m.getByText(/Aucun conflit/i).count()) > 0;
        return { ok: !locked, locked, traiter, aucunConflit: aucun };
      });
    }

    if (want("P05")) {
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
    }

    await mgr.close();
  }

  // P10 : vue d'un membre du bureau en LECTURE SEULE.
  if (want("P10")) {
    await run("P10-lecture", async () => {
      if (!MEMBER_JWT) return { ok: false, note: "pas de MEMBER_JWT", SIGNAL: "Aucun compte membre lecture seule fourni." };
      const ctx = await authContext(browser, MEMBER_JWT);
      const p = await ctx.newPage();
      await p.goto(`${FRONT}/planning`, { waitUntil: "domcontentloaded" });
      await settle(p, 3000);
      const info = await pageInfo(p);
      await debugShot(p, "P10-planning-membre.png");
      await denseView(p).catch(() => {});
      await p.waitForTimeout(1000);
      await p.screenshot({ path: path.join(CAPTURES, "P10-lecture.png") });
      const regen = await p.getByRole("button", { name: /Régénérer|Valider le planning/ }).count();
      await ctx.close();
      return { ok: true, ...info, editButtons: regen, SIGNAL: "Role 'member' = edition MASQUEE (canManage faux). Pas de badge « Lecture » sur le role membre." };
    });
  }

  if (want("P05")) {
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
  }

  await browser.close();
  fs.writeFileSync(path.join(ROOT, "out", "diag.json"), JSON.stringify(diag, null, 2));
  log("\n=== DIAGNOSTIC (filtre: " + (WANT.length ? WANT.join(",") : "TOUT") + ") ===");
  for (const [k, v] of Object.entries(diag)) log(k, "->", JSON.stringify(v).slice(0, 280));
})().catch((e) => { console.error(e); process.exit(1); });
