// Export image par image des animations du logo (regle 4 du prompt maitre). Lit un HTML LOCAL de
// public/logo/ en mode ?export (API : window.__ready, window.__seek(t), window.__film.{duration,T}),
// capture le canvas #stage via toDataURL (pixels exacts 1920x1080, sans UI du lecteur, fond compris).
// Le logo n'est JAMAIS redessine : on ne fait qu'exporter l'animation existante du graphiste.
//
// DEUX animations, choisies cote montage par src/logo.ts (LOGO_ANIMATION) :
//   - "orbite"   -> amateo-motion-01-orbite.html   (fond NUIT, lumiere/cometes) — DEFAUT 2026-10-09.
//   - "creneaux" -> amateo-motion-02-creneaux.html (fond PAPIER, matiere).
// On exporte, par animation, la phase d'INTRO (0 -> 1,5 s) + un POSTER (fin d'intro = logo complet
// tenu). Les plans 3 et 12 jouent l'intro puis TIENNENT le logo complet (pas d'implosion de sortie).
//
// Sorties : out/logo/<anim>/intro/intro-XXXX.png + out/logo/<anim>/poster.png + manifest.json.
// out/ est pointe par public/media (lien), donc Remotion lit staticFile("media/logo/<anim>/...").
//
// N'a besoin NI du bac a sable NI de la stack : HTML statiques. Lance par `npm run logo`.
// Chromium : binaire du cache partage ~/.cache/ms-playwright (PW_CHROME le force si besoin).
const fs = require("node:fs");
const path = require("node:path");
const { chromium } = require("@playwright/test");

const ROOT = path.resolve(__dirname, "..");
const LOGO_DIR = path.resolve(ROOT, "public", "logo");
const OUT = path.resolve(ROOT, "out", "logo");
const FPS = 60;

// anim -> fichier HTML (source copiee dans public/logo/, cf. README « Provenance »).
const ANIMS = {
  orbite: "amateo-motion-01-orbite.html",
  creneaux: "amateo-motion-02-creneaux.html",
};

const pad = (n) => String(n).padStart(4, "0");

async function exportRange(page, dir, label, startSec, endSec) {
  fs.mkdirSync(dir, { recursive: true });
  const firstFrame = Math.round(startSec * FPS);
  const lastFrame = Math.round(endSec * FPS);
  let count = 0;
  for (let f = firstFrame; f <= lastFrame; f++) {
    const t = f / FPS;
    // eslint-disable-next-line no-await-in-loop
    await page.evaluate((tt) => window.__seek(tt), t);
    // eslint-disable-next-line no-await-in-loop
    const dataUrl = await page.evaluate(() => document.getElementById("stage").toDataURL("image/png"));
    const b64 = dataUrl.slice(dataUrl.indexOf(",") + 1);
    fs.writeFileSync(path.join(dir, `${label}-${pad(f - firstFrame)}.png`), Buffer.from(b64, "base64"));
    count++;
  }
  return { label, startSec, endSec, firstFrame, lastFrame, frames: count };
}

async function exportAnim(browser, anim, htmlFile) {
  const html = path.join(LOGO_DIR, htmlFile);
  if (!fs.existsSync(html)) throw new Error(`HTML du logo introuvable : ${html}`);
  const outAnim = path.join(OUT, anim);
  fs.mkdirSync(outAnim, { recursive: true });

  const ctx = await browser.newContext({ viewport: { width: 1920, height: 1080 }, deviceScaleFactor: 1, colorScheme: "light" });
  const page = await ctx.newPage();
  await page.goto(`file://${html}?export`);
  await page.waitForFunction(() => window.__ready === true, { timeout: 30000 });

  const dims = await page.evaluate(() => {
    const c = document.getElementById("stage");
    return { w: c.width, h: c.height, duration: window.__film.duration, T: window.__film.T };
  });
  console.log(`[${anim}] canvas: ${dims.w}x${dims.h} | duree: ${dims.duration}s | T: ${JSON.stringify(dims.T)}`);
  if (dims.w !== 1920 || dims.h !== 1080) {
    console.warn(`[${anim}] ATTENTION: canvas ${dims.w}x${dims.h} (attendu 1920x1080).`);
  }

  const introEnd = dims.T.intro; // 1,5 s
  const manifest = { source: htmlFile, anim, fps: FPS, film: dims, ranges: [] };
  // Plan 3 (ouverture) et base du plan 12 : intro 0 -> 1,5 s.
  manifest.ranges.push(await exportRange(page, path.join(outAnim, "intro"), "intro", 0, introEnd));
  // Poster = fin d'intro = logo complet tenu (plans 3 et 12 tiennent cet etat).
  await page.evaluate((tt) => window.__seek(tt), introEnd);
  const poster = await page.evaluate(() => document.getElementById("stage").toDataURL("image/png"));
  fs.writeFileSync(path.join(outAnim, "poster.png"), Buffer.from(poster.slice(poster.indexOf(",") + 1), "base64"));

  fs.writeFileSync(path.join(outAnim, "manifest.json"), JSON.stringify(manifest, null, 2));
  await ctx.close();
  for (const r of manifest.ranges) console.log(`  [${anim}] ${r.label}: ${r.frames} images (${r.startSec}s -> ${r.endSec}s)  + poster.png`);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  // Sous-ensemble via LOGO_ANIMS (ex. "orbite"), sinon les deux (le fondateur tranche orbite/creneaux).
  const wanted = (process.env.LOGO_ANIMS || "orbite creneaux").split(/[ ,]+/).filter(Boolean);
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || undefined, args: ["--force-color-profile=srgb"] });
  for (const anim of wanted) {
    if (!ANIMS[anim]) { console.warn(`anim inconnue, ignoree : ${anim}`); continue; }
    // eslint-disable-next-line no-await-in-loop
    await exportAnim(browser, anim, ANIMS[anim]);
  }
  await browser.close();
  console.log("Images du logo ecrites dans", OUT, "(anims:", wanted.join(", ") + ")");
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
