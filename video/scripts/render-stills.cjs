// Rend UNE image fixe representative par plan (out/stills/planNN.png) puis une PLANCHE des 12
// (out/stills-sheet.png, copiee dans captures/video-stills.png). Deterministe : `remotion still` au
// numero d'image choisi pour chaque plan (milieu / moment parlant). execFile + tableau d'arguments
// (jamais d'interpolation shell). Chromium : PW_CHROME (binaire du cache Playwright).
const fs = require("node:fs");
const path = require("node:path");
const { execFileSync } = require("node:child_process");
const { chromium } = require("@playwright/test");

const ROOT = path.resolve(__dirname, "..");
const STILLS = path.join(ROOT, "out", "stills");
const REMOTION = path.join(ROOT, "node_modules", ".bin", "remotion");
const PW = process.env.PW_CHROME || "";
const REPO_CAPTURES = "/home/marabou/projects/scheduler/captures";

// Image representative par plan (frames absolues ; 60 i/s, 120 i/mesure — cf. src/timing.ts).
const FRAMES = {
  "01": 200, // post-it poses (charge mentale)
  "02": 520, // ratures en cascade
  "03": 700, // logo Orbite complet tenu (nuit)
  "04": 980, // contraintes : liste + « Ajouter la contrainte »
  "05": 1480, // fenetre Doleances (tout remonte dans la todo)
  "06": 1740, // grille generee + cascade + zoom zone dense
  "07": 1920, // clip deplacement/verrou
  "08": 2230, // bascule clair -> sombre (sans texte)
  "09": 2640, // onglet Conflits + encadres (Alex Martin / Personne en double)
  "10": 2900, // bureau en lecture (encadre teal + icone d'oeil)
  "11": 3130, // silhouette France + repere Paris
  "12": 3340, // logo Orbite + appel final (nuit)
};

fs.mkdirSync(STILLS, { recursive: true });
// Ordre NUMERIQUE des plans (les cles « 10 »/« 11 »/« 12 » sont des cles-entier JS et passeraient
// avant « 01 »..« 09 » sans ce tri — planche dans le desordre sinon).
const entries = Object.entries(FRAMES).sort((a, b) => Number(a[0]) - Number(b[0]));
for (const [nn, frame] of entries) {
  const out = path.join(STILLS, `plan${nn}.png`);
  const args = ["still", "src/index.ts", "AmateoPromo", out, `--frame=${frame}`];
  if (PW) args.push(`--browser-executable=${PW}`);
  console.log(`plan${nn} @ frame ${frame} ...`);
  execFileSync(REMOTION, args, { cwd: ROOT, stdio: ["ignore", "ignore", "inherit"] });
}

// Planche des 12 : page HTML (grille 3x4, legendee) photographiee par Chromium.
const CAPTIONS = {
  "01": "1 · Charge mentale", "02": "2 · Tout recommencer", "03": "3 · Logo (Orbite)",
  "04": "4 · Contraintes", "05": "5 · Doléances coachs", "06": "6 · Génération",
  "07": "7 · Déplacer / verrouiller", "08": "8 · Thème (sans texte)", "09": "9 · Matchs / conflits",
  "10": "10 · Bureau en lecture", "11": "11 · Données en France", "12": "12 · Appel final",
};
const cards = entries
  .map(([nn]) => `<figure><img src="file://${path.join(STILLS, `plan${nn}.png`)}" /><figcaption>${CAPTIONS[nn]}</figcaption></figure>`)
  .join("\n");
const html = `<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>
  :root{color-scheme:light} body{margin:0;background:#faf9f7;color:#191714;font:15px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif}
  header{padding:24px 32px 6px} h1{font-size:24px;margin:0 0 2px} .sub{color:#6d675d;font-size:13px}
  .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;padding:18px 32px 34px}
  figure{margin:0;background:#fff;border:1px solid #e7e3dc;border-radius:12px;overflow:hidden;box-shadow:0 12px 30px -18px rgba(25,23,20,.4)}
  img{width:100%;height:auto;display:block;border-bottom:1px solid #e7e3dc}
  figcaption{padding:9px 14px;font-size:14px;color:#2e7876;font-weight:650}
</style></head><body><header><h1>Brouillon monté — 12 plans (une image par plan)</h1>
<div class="sub">video promotionnelle Amateo · etape 3 (brouillon) · a commenter plan par plan</div></header>
<div class="grid">${cards}</div></body></html>`;
const sheetHtml = path.join(ROOT, "out", "stills-sheet.html");
fs.writeFileSync(sheetHtml, html, "utf8");

(async () => {
  const browser = await chromium.launch({ executablePath: PW || undefined });
  const ctx = await browser.newContext({ viewport: { width: 1500, height: 1400 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  await page.goto(`file://${sheetHtml}`);
  await page.waitForTimeout(400);
  const sheet = path.join(ROOT, "out", "stills-sheet.png");
  await page.screenshot({ path: sheet, fullPage: true });
  await ctx.close();
  await browser.close();
  try {
    fs.mkdirSync(REPO_CAPTURES, { recursive: true });
    fs.copyFileSync(sheet, path.join(REPO_CAPTURES, "video-stills.png"));
  } catch (e) {
    console.warn("copie vers captures/ impossible :", e.message);
  }
  console.log("Planche ecrite :", sheet, "(+ captures/video-stills.png)");
})().catch((e) => { console.error(e); process.exit(1); });
