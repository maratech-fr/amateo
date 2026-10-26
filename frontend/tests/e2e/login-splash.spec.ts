import { expect, test } from "./fixtures";

import { SEEDED_CLUB } from "./support";

/**
 * D11 — le logo « Signature » du splash de connexion est centré HORIZONTALEMENT sur son ENCRE dans
 * le viewport. Le dessin du handoff est optiquement décalé : l'ENCRE du groupe (icône + mot) a son
 * centre à ~976 pour une scène large de 1920 (centre 960), soit ~16 unités à DROITE (mesure réelle
 * Poppins Signature, 2026-10-26 — l'hypothèse initiale « décalé à gauche » était fausse). Le
 * correctif applique une translation de GROUPE (`groupDx`) sur la scène pour recentrer cette encre.
 *
 * Ce que seul le navigateur voit : la mise en page RÉELLE (fonte « Poppins Signature » chargée,
 * largeur du mot mesurée au runtime, encre des arcs). jsdom n'a aucun moteur de layout — le câblage
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
  // un état POSÉ (position de l'icône stable entre deux lectures à 90 ms) pour ne pas mesurer une
  // image transitoire du glissé d'intro — sinon le poll redemande.
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
          await new Promise((resolve) => setTimeout(resolve, 90));
          const b = inkUnion();
          if (Math.abs(a.anchor - b.anchor) > 0.5) {
            return null; // intro encore en mouvement → on redemande plutôt que mesurer un transitoire
          }
          return Math.round(Math.abs((b.left + b.right) / 2 - window.innerWidth / 2));
        }),
      { message: "l'union mot + icône n'est jamais revenue centrée (±4 px) sur son encre", timeout: 8000 },
    )
    .toBeLessThanOrEqual(4);
});
