import { expect, test, type Page } from "./fixtures";

import { expectNoContrastViolations, forceTheme } from "./support";

/**
 * P5-22 — la SCÈNE commune des écrans système (le demi-terrain où des cartes flottent). jsdom n'a
 * aucun moteur de mise en page (`.claude/rules/frontend.md`) : ni le CONTRASTE ni la STATIQUE réelle
 * sous `prefers-reduced-motion` ne s'attestent qu'ici, dans un vrai navigateur.
 *
 * On atteint un écran système SANS onboarding : `AuthGuard` rend lui-même `ServerErrorScreen`
 * (scène comprise) quand `/api/me` échoue en 5xx (AuthGuard.tsx:64-75). On pose donc le DRAPEAU de
 * session (indice d'UI, pas une autorisation — SEC-16) et on force `/api/me` en 500. Pas de club à
 * onboarder, pas de moteur : l'écran d'erreur est le chemin le plus court et le plus stable.
 */
const AUTH_SEED = JSON.stringify({ state: { isAuthenticated: true }, version: 2 });

async function seedErrorScreen(page: Page): Promise<void> {
  await page.addInitScript((seed) => window.localStorage.setItem("cs-auth", seed), AUTH_SEED);
  await page.route("**/api/me", (route) => route.fulfill({ status: 500, contentType: "application/json", body: JSON.stringify({ error: "e2e-forced" }) }));
}

const MODES = ["dark", "light"] as const;

for (const mode of MODES) {
  test(`scène système — contraste de l'écran d'erreur (${mode})`, async ({ page }) => {
    await forceTheme(page, mode);
    await seedErrorScreen(page);

    await page.goto("/");
    // L'écran système est rendu : son titre stable ET la scène décorative.
    await expect(page.getByRole("heading", { name: /Arrêt de jeu imprévu/i })).toBeVisible();
    await expect(page.getByTestId("system-scene")).toBeVisible();

    // axe saute la scène (aria-hidden) ; on vérifie que l'écran ENTIER — titre, corps, gestes
    // par-dessus le décor — passe le contraste AA dans les deux thèmes.
    await expectNoContrastViolations(page, `écran système · 500 (${mode})`);
  });
}

/**
 * Le PIÈGE reduced-motion, prouvé sur le rendu réel : sous `prefers-reduced-motion: reduce`, la
 * scène doit rester VISIBLE et STATIQUE (cartes posées) sous le titre d'erreur — jamais masquée,
 * jamais un faux état « plein ». Témoin intégré : SANS la préférence, l'animation TOURNE ; AVEC
 * elle, elle est coupée (`animation-name: none`). Un test qui verrait `none` dans les deux cas
 * (ex. animations jamais posées) échouerait sur le témoin.
 */
test("scène système — reduced-motion : visible et immobile (avec témoin)", async ({ page }) => {
  await seedErrorScreen(page);

  // TÉMOIN — sans préférence : les cartes flottent réellement (animation active).
  await page.emulateMedia({ reducedMotion: "no-preference" });
  await page.goto("/");
  await expect(page.getByRole("heading", { name: /Arrêt de jeu imprévu/i })).toBeVisible();
  await expect(page.getByTestId("system-scene")).toBeVisible();
  const running = await page.locator('[data-testid="system-scene"] .ss-anim').first().evaluate((el) => getComputedStyle(el).animationName);
  expect(running, "sans reduced-motion la scène doit s'animer (témoin) — sinon le test ne prouve rien").toMatch(/ss-/);

  // Avec la préférence : animation coupée, mais la scène reste VISIBLE et les cartes POSÉES.
  await page.emulateMedia({ reducedMotion: "reduce" });
  await page.reload();
  await expect(page.getByRole("heading", { name: /Arrêt de jeu imprévu/i })).toBeVisible();
  await expect(page.getByTestId("system-scene")).toBeVisible();

  const frozen = await page.locator('[data-testid="system-scene"] .ss-anim').first().evaluate((el) => ({
    animationName: getComputedStyle(el).animationName,
    opacity: getComputedStyle(el).opacity,
  }));
  expect(frozen.animationName, "sous reduced-motion l'animation doit être coupée").toBe("none");
  // Élément animé toujours PEINT (opacité de repos non nulle) : la scène n'est jamais masquée
  // sous reduced-motion — elle reste posée, pas escamotée.
  expect(Number(frozen.opacity)).toBeGreaterThan(0);
});
