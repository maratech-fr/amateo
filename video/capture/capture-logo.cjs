// Export image par image de l'animation du logo (regle 4 du prompt maitre). Lit le HTML LOCAL
// public/logo/amateo-motion-02-creneaux.html en mode ?export (API : window.__ready, window.__seek(t),
// window.__film.duration). Capture le canvas #stage via toDataURL (pixels exacts 1920x1080, sans UI
// du lecteur). Le logo n'est JAMAIS redessine ; on ne fait qu'exporter l'animation existante.
//
// N'a besoin NI du bac a sable NI de la stack : c'est un fichier HTML statique. Lance par
// `npm run logo` (ou via capture/run.sh). Chromium : node_modules de video/, binaire du cache
// partage ~/.cache/ms-playwright (PW_CHROME le force si besoin).
const fs = require("node:fs");
const path = require("node:path");
const { chromium } = require("@playwright/test");

const ROOT = path.resolve(__dirname, "..");
const HTML = path.resolve(ROOT, "public", "logo", "amateo-motion-02-creneaux.html");
const OUT = path.resolve(ROOT, "out", "logo");
const FPS = 60;

const pad = (n) => String(n).padStart(4, "0");

async function exportRange(page, label, startSec, endSec) {
  const dir = path.join(OUT, label);
  fs.mkdirSync(dir, { recursive: true });
  const firstFrame = Math.round(startSec * FPS);
  const lastFrame = Math.round(endSec * FPS);
  let count = 0;
  for (let f = firstFrame; f <= lastFrame; f++) {
    const t = f / FPS;
    // eslint-disable-next-line no-await-in-loop
    await page.evaluate((tt) => window.__seek(tt), t);
    // eslint-disable-next-line no-await-in-loop
    const dataUrl = await page.evaluate(() =>
      document.getElementById("stage").toDataURL("image/png"),
    );
    const b64 = dataUrl.slice(dataUrl.indexOf(",") + 1);
    const idx = f - firstFrame;
    fs.writeFileSync(path.join(dir, `${label}-${pad(idx)}.png`), Buffer.from(b64, "base64"));
    count++;
  }
  return { label, startSec, endSec, firstFrame, lastFrame, frames: count, dir };
}

(async () => {
  if (!fs.existsSync(HTML)) {
    throw new Error(`HTML du logo introuvable : ${HTML}`);
  }
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({
    executablePath: process.env.PW_CHROME || undefined,
    args: ["--force-color-profile=srgb"],
  });
  const ctx = await browser.newContext({
    viewport: { width: 1920, height: 1080 },
    deviceScaleFactor: 1,
    colorScheme: "light",
  });
  const page = await ctx.newPage();
  await page.goto(`file://${HTML}?export`);
  await page.waitForFunction(() => window.__ready === true, { timeout: 30000 });

  const dims = await page.evaluate(() => {
    const c = document.getElementById("stage");
    return { w: c.width, h: c.height, duration: window.__film.duration, T: window.__film.T };
  });
  console.log("canvas:", dims.w + "x" + dims.h, "| duree film:", dims.duration, "s | T:", JSON.stringify(dims.T));
  if (dims.w !== 1920 || dims.h !== 1080) {
    console.warn(`ATTENTION: canvas ${dims.w}x${dims.h} (attendu 1920x1080) — verifier le viewport.`);
  }

  const T = dims.T; // { intro, loop, outro }
  const introEnd = T.intro; // 1,5 s
  const outroStart = T.intro + T.loop; // 7,5 s
  const outroEnd = T.intro + T.loop + T.outro; // 9,0 s

  const manifest = { source: "amateo-motion-02-creneaux.html", fps: FPS, film: dims, ranges: [] };
  // Plan 3 (ouverture) : intro 0 -> 1,5 s.
  manifest.ranges.push(await exportRange(page, "intro", 0, introEnd));
  // Image tenue (le « logo tenu » apres l'intro, plan 3) : fin d'intro.
  fs.mkdirSync(OUT, { recursive: true });
  await page.evaluate((tt) => window.__seek(tt), introEnd);
  const poster = await page.evaluate(() => document.getElementById("stage").toDataURL("image/png"));
  fs.writeFileSync(path.join(OUT, "poster.png"), Buffer.from(poster.slice(poster.indexOf(",") + 1), "base64"));
  // Plan 12 (fermeture) : sortie 7,5 -> 9,0 s.
  manifest.ranges.push(await exportRange(page, "outro", outroStart, outroEnd));

  fs.writeFileSync(path.join(OUT, "manifest.json"), JSON.stringify(manifest, null, 2));
  console.log("Images du logo ecrites dans", OUT);
  for (const r of manifest.ranges) {
    console.log(`  ${r.label}: ${r.frames} images (${r.startSec}s -> ${r.endSec}s)`);
  }
  console.log("  poster.png (fin d'intro)");

  await ctx.close();
  await browser.close();
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
