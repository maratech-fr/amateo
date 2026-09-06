import { expect, test } from "./fixtures";

import { expectNoContrastViolations, forceTheme, registerAndVerify, settleVeil, uniqueAra } from "./support";

/**
 * The shared `Listbox` primitive (P4-164) exercised on a REAL screen — the wizard's
 * « Réserver ce créneau » modal, whose team picker is the richest instance (colour dots, a
 * residu count, keyboard-reachable disabled options). Two axes jsdom cannot see:
 *
 *  - interaction inside a modal: Escape closes ONLY the list (the dialog stays), Tab closes
 *    without selecting, focus returns to the trigger — the decisions the primitive's docblock
 *    carries, and the exact bug (Escape closing the whole modal) they exist to prevent;
 *  - colour-contrast (WCAG 1.4.3) on the list while it is OPEN and rendered to the user — axe
 *    SKIPS an `inert`/veiled subtree, so we `settleVeil` first and guard with an empty-list
 *    witness (an empty list would let the scan pass while verifying nothing), in both themes,
 *    plus reflow (WCAG 1.4.10) at 375 px: no horizontal scroll on the modal body, the active
 *    option inside the viewport, the whole list within the viewport (the flip's guarantee).
 *
 * No reservation is committed (Escape/Tab never validate, Valider is never clicked), so there
 * is nothing to clean up.
 */

/** Walk a fresh club to the wizard's « Réserver ce créneau » modal, list closed, trigger ready. */
async function reachReservationPicker(page: import("@playwright/test").Page): Promise<void> {
  const ara = uniqueAra("LBX");
  await registerAndVerify(page, { email: `listbox-${ara}@e2e.fr`, ara, firstName: "List", lastName: "Box", clubName: "Listbox Club" });
  await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });

  // Step 1 · a team (the only offerable team → a non-empty picker).
  await page.getByLabel("Nom de l'équipe").fill("SM1");
  await page.getByLabel("Catégorie").selectOption({ label: "Senior" });
  await page.getByRole("button", { name: "Ajouter l'équipe" }).click();
  await expect(page.locator('input[value="SM1"]')).toBeVisible({ timeout: 20_000 });
  await page.getByRole("button", { name: "Suivant" }).click();

  // Step 2 · a venue + one weekly slot (a slot to open in the Réserver grid).
  await expect(page.getByRole("heading", { name: /Étape 2\/6/ })).toBeVisible();
  await page.getByLabel("Nom du gymnase").fill("Gymnase LBX");
  await page.getByRole("button", { name: "Ajouter un gymnase" }).click();
  await expect(page.getByLabel("Gymnase", { exact: true })).toHaveValue(/./);
  await page.getByRole("button", { name: "Lun 18:00", exact: true }).click();
  await page.getByRole("button", { name: "Suivant" }).click();

  // Step 3 · coach — skipped (an empty team→coach set is a loaded, valid state).
  await expect(page.getByRole("heading", { name: /Étape 3\/6/ })).toBeVisible();
  await page.getByRole("button", { name: "Suivant" }).click();

  // Step 4 · constraints → the « Réserver » family tab holds the per-venue slot grid.
  await expect(page.getByRole("heading", { name: /Étape 4\/6/ })).toBeVisible();
  await page.getByRole("button", { name: /Réserver/ }).first().click();
  await page.getByRole("button", { name: /Gymnase LBX.*cliquer pour gérer/ }).first().click();
  await expect(page.getByRole("dialog", { name: "Réserver ce créneau" })).toBeVisible();
}

test("listbox — Échap garde la modale, Tab ne sélectionne pas, focus rendu au trigger", async ({ page }) => {
  test.setTimeout(120_000);
  await reachReservationPicker(page);

  const dialog = page.getByRole("dialog", { name: "Réserver ce créneau" });
  const trigger = page.getByRole("button", { name: /Ajouter une équipe/ });
  await trigger.click();
  const listbox = page.getByRole("listbox");
  await expect(listbox).toBeVisible();
  // TÉMOIN : une liste vide rendrait tout ce qui suit vide de sens.
  expect(await page.getByRole("option").count(), "la liste doit contenir au moins une option").toBeGreaterThan(0);

  // Échap ferme SEULEMENT la liste — la modale, elle, reste ouverte (sinon le gestionnaire perd
  // tout le dialogue en voulant fermer une simple liste déroulante) — et le focus revient au trigger.
  await page.keyboard.press("ArrowDown");
  await page.keyboard.press("Escape");
  await expect(listbox).toBeHidden();
  await expect(dialog).toBeVisible();
  await expect(trigger).toBeFocused();

  // Tab depuis une option ferme SANS sélectionner (un listbox n'est pas un menu) : rien n'est réservé.
  await trigger.click();
  await expect(page.getByRole("listbox")).toBeVisible();
  await page.keyboard.press("Tab");
  await expect(page.getByRole("listbox")).toBeHidden();
  await expect(dialog).toBeVisible();
  await expect(page.getByText("à valider")).toHaveCount(0);
});

for (const mode of ["dark", "light"] as const) {
  test(`listbox — contraste liste OUVERTE + reflow 375px (${mode})`, async ({ page }) => {
    test.setTimeout(120_000);
    await forceTheme(page, mode);
    await reachReservationPicker(page);

    // Attendre que l'écran soit RENDU (pas inert/voilé) : axe saute un sous-arbre inert.
    await settleVeil(page);
    const trigger = page.getByRole("button", { name: /Ajouter une équipe/ });
    await trigger.click();
    const listbox = page.getByRole("listbox");
    await expect(listbox).toBeVisible();
    // TÉMOIN : sans option, le scan axe passerait en ne vérifiant RIEN.
    expect(await page.getByRole("option").count(), "témoin : liste vide = scan vide").toBeGreaterThan(0);
    await expectNoContrastViolations(page, `listbox ouverte (${mode})`);

    // Reflow (WCAG 1.4.10) à 375×667 : rouvrir à la nouvelle taille (le flip se recalcule au resize).
    await page.keyboard.press("Escape");
    await page.setViewportSize({ width: 375, height: 667 });
    await trigger.click();
    await expect(page.getByRole("listbox")).toBeVisible();

    // Le corps de modale ne défile jamais horizontalement.
    const noHScroll = await page.getByRole("dialog", { name: "Réserver ce créneau" }).evaluate((dialog) => {
      const body = dialog.querySelector<HTMLElement>(".overflow-y-auto");
      return null === body ? true : body.scrollWidth <= body.clientWidth;
    });
    expect(noHScroll, `le corps de modale déborde horizontalement à 375px (${mode})`).toBe(true);

    // L'option active est visible, et la liste tient DANS le viewport (la garantie du flip mesuré).
    await expect(page.locator('[role="option"]:focus')).toBeInViewport();
    await expect(page.getByRole("listbox")).toBeInViewport();
  });
}
