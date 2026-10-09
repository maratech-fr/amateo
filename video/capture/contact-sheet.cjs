// Planche contact : une vignette legendee par capture, pour validation des noms visibles par le
// fondateur AVANT tout montage (regle 2 du prompt maitre). Zero dependance d'image : on construit
// une page HTML (grille de vignettes + legendes) et on la photographie avec le Chromium de
// Playwright deja present. Sortie : out/contact-sheet.png (+ copie dans captures/ a la racine).
const fs = require("node:fs");
const path = require("node:path");
const { chromium } = require("@playwright/test");

const ROOT = path.resolve(__dirname, "..");
const OUT = path.resolve(ROOT, "out");
const CAPTURES = path.join(OUT, "captures");
const REPO_CAPTURES = "/home/marabou/projects/scheduler/captures";

// Collecte des vignettes : chaque capture d'app (out/captures/*.png) + un repere du logo + le still.
function collect() {
  const items = [];
  const pushIf = (file, caption) => {
    if (fs.existsSync(file)) {
      items.push({ file, caption });
    }
  };
  if (fs.existsSync(CAPTURES)) {
    for (const name of fs.readdirSync(CAPTURES).sort()) {
      if (/\.(png|jpg|jpeg)$/i.test(name)) {
        pushIf(path.join(CAPTURES, name), name);
      }
    }
  }
  // Reperes du logo (images exportees) et still de la composition.
  pushIf(path.join(OUT, "logo", "poster.png"), "logo/poster.png (fin d'intro)");
  pushIf(path.join(OUT, "logo", "intro", "intro-0045.png"), "logo/intro-0045.png (mi-intro)");
  pushIf(path.join(OUT, "logo", "outro", "outro-0045.png"), "logo/outro-0045.png (mi-sortie)");
  pushIf(path.join(OUT, "stills", "plan01.png"), "stills/plan01.png (squelette, plan 1)");
  return items;
}

function buildHtml(items) {
  const cards = items
    .map(
      (it) => `
      <figure>
        <img src="file://${it.file}" />
        <figcaption>${it.caption.replace(/</g, "&lt;")}</figcaption>
      </figure>`,
    )
    .join("\n");
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8" />
  <style>
    :root { color-scheme: light; }
    body { margin: 0; background: #faf9f7; color: #191714;
           font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
    header { padding: 28px 36px 8px; }
    h1 { font-size: 26px; margin: 0 0 4px; }
    .sub { color: #6d675d; font-size: 14px; }
    .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; padding: 24px 36px 40px; }
    figure { margin: 0; background: #fff; border: 1px solid #e7e3dc; border-radius: 12px;
             overflow: hidden; box-shadow: 0 12px 30px -18px rgba(25,23,20,.4); }
    img { width: 100%; height: auto; display: block; background: #f3f1ec; border-bottom: 1px solid #e7e3dc; }
    figcaption { padding: 10px 14px; font-size: 13px; color: #2e7876; font-weight: 650; word-break: break-all; }
  </style></head><body>
  <header>
    <h1>Planche contact — video promotionnelle (club de DEMONSTRATION)</h1>
    <div class="sub">${items.length} capture(s) · a valider par le fondateur avant montage · voir out/noms-visibles.md</div>
  </header>
  <div class="grid">${cards}</div>
  </body></html>`;
}

(async () => {
  const items = collect();
  if (items.length === 0) {
    console.warn("Aucune capture trouvee dans out/captures/ ni out/logo/ — planche contact vide.");
  }
  fs.mkdirSync(OUT, { recursive: true });
  const htmlPath = path.join(OUT, "contact-sheet.html");
  fs.writeFileSync(htmlPath, buildHtml(items), "utf8");

  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || undefined });
  const ctx = await browser.newContext({ viewport: { width: 1600, height: 1200 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  await page.goto(`file://${htmlPath}`);
  await page.waitForTimeout(400);
  const outPng = path.join(OUT, "contact-sheet.png");
  await page.screenshot({ path: outPng, fullPage: true });
  await ctx.close();
  await browser.close();

  // Copie pour le fondateur (dossier captures/ a la racine, gitignore via .git/info/exclude).
  try {
    fs.mkdirSync(REPO_CAPTURES, { recursive: true });
    fs.copyFileSync(outPng, path.join(REPO_CAPTURES, "video-contact-sheet.png"));
  } catch (e) {
    console.warn("Copie vers captures/ impossible :", e.message);
  }
  console.log(`Planche contact ecrite : ${outPng} (${items.length} vignettes)`);
  for (const it of items) {
    console.log("  -", it.caption);
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
