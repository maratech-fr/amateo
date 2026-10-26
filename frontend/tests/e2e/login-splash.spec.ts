import { expect, test } from "./fixtures";

import { SEEDED_CLUB } from "./support";

/**
 * D11 — le logo « Signature » du splash de connexion est centré HORIZONTALEMENT sur son ENCRE dans
 * le viewport. La CAUSE du décalage à droite était la scène qui RÉTRÉCISSAIT à la largeur du viewport
 * (item flex posé en coordonnées 1920 mais collapsé par le conteneur flex) alors que ses enfants
 * restaient posés en 1920 — corrigée par `flexShrink: 0` sur la scène. AUCUNE translation de groupe :
 * sans décalage, le logo est centré au pixel (mesure Playwright réelle, 2026-10-26 — l'hypothèse
 * d'une encre décalée de ~16 unités à droite, qui aurait justifié un `groupDx`, était fausse).
 *
 * Ce que seul le navigateur voit : la mise en page RÉELLE (fonte « Poppins Signature » chargée,
 * largeur du mot, encre des arcs). jsdom n'a aucun moteur de layout — le câblage
 * y est prouvé par `brand-splash.test.tsx` / `brand-splash.math.test.ts`, le CENTRAGE RÉEL ici.
 *
 * On mesure l'ENCRE : `getBoundingClientRect()` des 3 `<circle>` (inclut le trait) pour l'icône, la
 * boîte du mot pour le texte — JAMAIS la boîte du `<svg>` (plus large que l'encre) ni la boîte
 * nominale de l'icône. `data-testid="splash-icon"` est posé sur le svg par le correctif D11.
 */
test.use({ viewport: { width: 1280, height: 720 } });

test("splash de connexion : l'union mot + icône est centrée sur son encre (±4 px)", async ({ page }) => {
  test.setTimeout(60_000);

  // Retenir /api/me APRÈS le login tient la RESPIRATION du splash le temps de mesurer : en
  // respiration la géométrie est figée (FULL_LAYOUT), seule l'opacité oscille. Sur /login la query
  // `me` est désactivée (non authentifié) → seule la requête post-login est ralentie.
  await page.route("**/api/me", async (route) => {
    await new Promise((resolve) => setTimeout(resolve, 6000));
    await route.continue();
  });

  await page.goto("/login");
  await page.getByLabel("Email").fill(SEEDED_CLUB.email);
  await page.getByLabel("Mot de passe", { exact: true }).fill(SEEDED_CLUB.password);
  await page.getByRole("button", { name: "Se connecter" }).click();

  // Témoin : le splash DOIT apparaître au clic (sinon la mesure suivante serait vide et VERTE).
  const splash = page.getByTestId("login-splash");
  await expect(splash, "le splash « Signature » ne s'est pas affiché au clic « Se connecter »").toBeVisible({ timeout: 10_000 });
  // Le svg de l'icône porte son repère de test (posé par le correctif D11).
  await expect(page.getByTestId("splash-icon"), 'le svg du splash doit porter data-testid="splash-icon"').toBeVisible();

  // Union des boîtes d'ENCRE visibles (mot + les 3 arcs), centrée dans le viewport (±4 px). On attend
  // un état POSÉ (position de l'icône stable entre deux lectures à 200 ms) pour ne pas mesurer une
  // image transitoire — sinon le poll redemande. La SEULE source de mouvement APRÈS le clic est le
  // glissé d'intro (grand déplacement, bien au-delà du seuil) : en respiration la scène est figée
  // (plus de bascule `wordW`/`groupDx` — le centrage vient de la scène NON RÉTRÉCIE, pas d'une
  // translation de groupe). 200 ms laissent le glissé se poser entre deux lectures ; la tolérance
  // 1 px absorbe le sub-pixel du `scale`, sans toucher le ±4 px final.
  await expect
    .poll(
      async () =>
        page.evaluate(async () => {
          const word = document.querySelector('[data-testid="splash-word"]');
          const svg = document.querySelector('[data-testid="splash-icon"]');
          if (null === word || null === svg) {
            return null;
          }
          const inkUnion = () => {
            const rects = [word.getBoundingClientRect(), ...Array.from(svg.querySelectorAll("circle")).map((c) => c.getBoundingClientRect())];
            const left = Math.min(...rects.map((r) => r.left));
            const right = Math.max(...rects.map((r) => r.right));
            return { left, right, anchor: svg.getBoundingClientRect().left };
          };
          const a = inkUnion();
          await new Promise((resolve) => setTimeout(resolve, 200));
          const b = inkUnion();
          if (Math.abs(a.anchor - b.anchor) > 1) {
            return null; // scène encore en mouvement (glissé d'intro) → on redemande
          }
          return Math.round(Math.abs((b.left + b.right) / 2 - window.innerWidth / 2));
        }),
      { message: "l'union mot + icône n'est jamais revenue centrée (±4 px) sur son encre", timeout: 8000 },
    )
    .toBeLessThanOrEqual(4);
});
