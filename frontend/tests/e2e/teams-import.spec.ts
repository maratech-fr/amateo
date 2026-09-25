import { expect, test } from "@playwright/test";

import { registerAndVerify, uniqueAra } from "./support";
import { buildTeamsXlsx } from "./support-xlsx";

const XLSX_MIME = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";

/**
 * P3-7 — l'import FBI des équipes (onboarding), bout en bout : on inscrit un club neuf, on
 * crée UNE équipe à la main, on dépose un export FBI qui la contient (doublon) plus deux
 * nouvelles, on VOIT le doublon marqué « déjà présente » et décoché, puis on n'importe que
 * les nouvelles.
 *
 * ⚠ Un fixture statique est IMPOSSIBLE : le `ffbbClubCode` du club vaut son ARA, unique par
 * run — l'Organisme du fichier doit être construit AVEC cet ARA (`buildTeamsXlsx`).
 */
test("importe les nouvelles équipes d'un export FBI et évite le doublon déjà présent", async ({ page }) => {
  const ara = uniqueAra("IMP");
  await registerAndVerify(page, {
    email: `import-${ara}@e2e.fr`,
    ara,
    firstName: "Ida",
    lastName: "Import",
    clubName: "E2E Import Club",
  });

  // Onboarding — étape 1/6 « Équipes ».
  await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });

  // Une équipe créée À LA MAIN — c'est elle qui devra ressortir « déjà présente » à l'analyse.
  await page.getByLabel("Nom de l'équipe").fill("U13-1");
  await page.getByLabel("Catégorie").selectOption({ label: "Senior" });
  await page.getByRole("button", { name: "Ajouter l'équipe" }).click();
  // ⏱ 20 s, PAS le sélecteur : ce point précis (équipe ajoutée juste après l'inscription) a
  // flaqué sur quatre PR à cause du voile posé par WhatsNewModal — cf. journey.spec.ts:27-42.
  // `page.locator('input[value=…]')` : Playwright n'a pas de `getByDisplayValue` (Testing Library).
  await expect(page.locator('input[value="U13-1"]')).toBeVisible({ timeout: 20_000 });

  // Le fichier FBI : le doublon (U13-1) + deux nouvelles, tous au code club du run.
  const organisme = `${ara} - CLUB E2E`;
  const buffer = buildTeamsXlsx([
    { nom: "U13-1", categorie: "U13", numero: "1", organisme },
    { nom: "U13-2", categorie: "U13", numero: "2", organisme },
    { nom: "SM1", categorie: "Senior", numero: "3", organisme },
  ]);

  await page.getByRole("button", { name: "Importer depuis FBI" }).click();
  const dialog = page.getByRole("dialog", { name: "Importer vos équipes (export FBI)" });
  await expect(dialog).toBeVisible();

  await dialog.getByLabel("Fichier FBI (.xlsx)").setInputFiles({ name: "equipes.xlsx", mimeType: XLSX_MIME, buffer });

  // L'analyse rend les trois lignes ; U13-1 est marquée « déjà présente » et décochée.
  const dupe = dialog.getByRole("checkbox", { name: /U13-1/ });
  await expect(dupe).toBeVisible({ timeout: 15_000 });
  await expect(dupe).toHaveAccessibleName(/déjà présente/);
  await expect(dupe).not.toBeChecked();
  // Les deux nouvelles sont cochées par défaut.
  await expect(dialog.getByRole("checkbox", { name: /U13-2/ })).toBeChecked();
  await expect(dialog.getByRole("checkbox", { name: /SM1/ })).toBeChecked();

  // Importer les deux nouvelles (le doublon reste décoché).
  await dialog.getByRole("button", { name: /^Importer \d+ équipe/ }).click();
  await expect(dialog.getByText(/équipes? créées?/)).toBeVisible({ timeout: 15_000 });
  // La primitive Modal porte AUSSI une croix « Fermer » (aria-label) : on prend le bouton
  // TEXTE du pied (le dernier des deux dans le DOM).
  await dialog.getByRole("button", { name: "Fermer" }).last().click();

  // Les deux nouvelles rejoignent la liste, et U13-1 n'y est PAS dupliquée.
  await expect(page.locator('input[value="U13-2"]')).toBeVisible({ timeout: 20_000 });
  await expect(page.locator('input[value="SM1"]')).toBeVisible();
  await expect(page.locator('input[value="U13-1"]')).toHaveCount(1);
});
