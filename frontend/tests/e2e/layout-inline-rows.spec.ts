import type { Locator } from "@playwright/test";

import { expect, test } from "./fixtures";

import { ensureValidated, loginSeededClub, registerAndVerify, uniqueAra } from "./support";

/**
 * P4-271 — les lignes compactes tiennent sur UNE ligne à la largeur bureau (1280 px). jsdom
 * ne calcule aucune mise en page (`boundingBox` y vaut 0), donc le reflow d'une ligne ne se
 * mesure qu'en Playwright. Deux régressions gardées :
 *  - l'étape Coachs : équipe / rôle / Lier partagent le MÊME offsetTop (le `wrapperClassName`
 *    de la régression 2026-09-06 : un TeamSelect pleine largeur poussait « Lier » à la ligne
 *    suivante) ;
 *  - une contrainte au repos tient sur une seule ligne (résumé + ✎ + 🗑, jamais un formulaire
 *    déplié en permanence).
 */
test.use({ viewport: { width: 1280, height: 900 } });

/** Le haut de la boîte englobante d'un locator (-1 si absente). */
async function topOf(locator: Locator): Promise<number> {
  const box = await locator.boundingBox();
  return box?.y ?? -1;
}

test("étape Coachs : équipe, rôle et « Lier » sur la même ligne (1280 px)", async ({ page }) => {
  test.setTimeout(120_000);

  const ara = uniqueAra("E2EL");
  await registerAndVerify(page, { email: `layout-${ara}@e2e.fr`, ara, firstName: "Lay", lastName: "Out", clubName: "E2E Layout Club" });
  await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });

  // Étape 1 — une équipe (le TeamSelect « Lier » a besoin d'au moins une équipe).
  await page.getByLabel("Nom de l'équipe").fill("SM1");
  await page.getByLabel("Catégorie").selectOption({ label: "Senior" });
  await page.getByRole("button", { name: "Ajouter l'équipe" }).click();
  await expect(page.locator('input[value="SM1"]')).toBeVisible({ timeout: 20_000 });
  await page.getByRole("button", { name: "Suivant" }).click();

  // Étape 2 — un gymnase + un créneau, puis on avance.
  await expect(page.getByRole("heading", { name: /Étape 2\/6/ })).toBeVisible();
  await page.getByLabel("Nom du gymnase").fill("Gymnase E2E");
  await page.getByRole("button", { name: "Ajouter un gymnase" }).click();
  await expect(page.getByLabel("Gymnase", { exact: true })).toContainText("Gymnase E2E");
  await page.getByRole("button", { name: "Lun 18:00", exact: true }).click();
  await page.getByRole("button", { name: "Suivant" }).click();

  // Étape 3 — un coach, qu'on passe en édition pour révéler la ligne « Lier ».
  await expect(page.getByRole("heading", { name: /Étape 3\/6/ })).toBeVisible();
  await page.getByLabel("Prénom").fill("Coa");
  await page.getByLabel("Nom", { exact: true }).fill("Ch");
  await page.getByRole("button", { name: "Ajouter le coach" }).click();
  await expect(page.getByText("Coa Ch", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Éditer le coach" }).click();

  const team = page.getByRole("button", { name: /^Équipe/ });
  const role = page.getByRole("combobox", { name: "Rôle" });
  const lier = page.getByRole("button", { name: "Lier" });
  await expect(team).toBeVisible();
  await expect(lier).toBeVisible();

  const [teamTop, roleTop, lierTop] = await Promise.all([topOf(team), topOf(role), topOf(lier)]);
  // items-center : les trois contrôles partagent la même ligne → même offsetTop (tolérance 4 px).
  expect(Math.abs(teamTop - roleTop)).toBeLessThanOrEqual(4);
  expect(Math.abs(teamTop - lierTop)).toBeLessThanOrEqual(4);
});

test("contraintes : une règle au repos tient sur une seule ligne (1280 px)", async ({ page }) => {
  test.setTimeout(240_000);

  await loginSeededClub(page);
  await ensureValidated(page);

  await page.goto("/matchs/contraintes?section=club");
  // On crée une règle « pas après 21h » (samedi coché par défaut) via la ligne d'ajout.
  await page.getByLabel("Pas après (heure de fin)").fill("21:00");
  await page.getByRole("button", { name: "Ajouter" }).click();

  // La règle apparaît au repos, compacte : un résumé + « Modifier » + « Supprimer ».
  const modifier = page.getByRole("button", { name: "Modifier" }).first();
  await expect(modifier).toBeVisible({ timeout: 15_000 });

  // Le résumé (span) et le bouton « Modifier » partagent la même ligne → même offsetTop.
  const summaryTop = await page.locator("span", { hasText: /Obligatoire/ }).first().boundingBox().then((b) => b?.y ?? -1);
  const modifierTop = await modifier.boundingBox().then((b) => b?.y ?? -1);
  expect(summaryTop).toBeGreaterThan(0);
  expect(Math.abs(summaryTop - modifierTop)).toBeLessThanOrEqual(6);

  // Nettoyage : on lève la règle qu'on vient de créer (la base de DEV n'est pas réinitialisée).
  await page.getByRole("button", { name: "Supprimer" }).first().click();
  await page.getByRole("dialog").getByRole("button", { name: "Supprimer", exact: true }).click();
});
