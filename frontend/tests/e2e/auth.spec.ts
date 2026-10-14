import { expect, test } from "./fixtures";

import { registerAndVerify, submitRegister, uniqueAra } from "./support";

/**
 * **Les autres portes de l'entrée (P8).** La porte NOMINALE « inscription → vérification → wizard »
 * est prouvée bout en bout par `journey.spec.ts` (ex-`auth.spec.ts:5`, retiré ici comme doublon
 * reconnu) ; ce parcours garde les TROIS portes restantes, enchaînées, chacune avec sa promesse :
 *  1. inscrire N'AUTHENTIFIE pas (A3) — on reste sur /register, « check your email », pas de shell ;
 *  2. rejoindre un club EXISTANT mène à l'écran d'attente d'approbation (jamais directement dedans) ;
 *  3. un login erroné est refusé SANS boucle de redirection, focus rendu au champ e-mail (P4-252).
 */
test("les autres portes de l'entrée : check-email, attente d'approbation, login refusé", async ({ page }) => {
  // --- Porte 1 · inscrire montre « vérifiez votre e-mail » et n'ouvre AUCUNE session (A3).
  const pendingAra = uniqueAra("E2EC");
  await submitRegister(page, { email: `pending-${pendingAra}@e2e.fr`, ara: pendingAra, clubName: "E2E Pending" });
  await expect(page).toHaveURL(/\/register$/);
  await expect(page.getByRole("button", { name: "Saison de travail" })).toBeHidden();

  // --- Porte 2 · rejoindre un club EXISTANT → écran d'attente d'approbation.
  const joinAra = uniqueAra("E2EJ");
  // Le propriétaire crée + vérifie le club (devient admin actif → wizard).
  await registerAndVerify(page, { email: `owner-${joinAra}@e2e.fr`, ara: joinAra, firstName: "Owner", lastName: "Admin", clubName: "E2E Existing" });
  await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });
  // Une 2ᵉ personne sur le même ARA → en attente → écran « demande en attente » (après vérification).
  await registerAndVerify(page, { email: `joiner-${joinAra}@e2e.fr`, ara: joinAra, firstName: "Joiner", lastName: "Two" });
  await expect(page.getByText(/demande en attente/i)).toBeVisible();

  // --- Porte 3 · login erroné refusé, sans boucle, focus rendu au champ e-mail.
  await page.goto("/login");
  await page.getByLabel("Email", { exact: true }).fill("nobody@e2e.fr");
  await page.getByLabel("Mot de passe", { exact: true }).fill("wrongpassword");
  await page.getByRole("button", { name: /se connecter/i }).click();
  await expect(page.getByText(/erreur|invalid|identifiants|credentials/i)).toBeVisible();
  await expect(page).toHaveURL(/\/login$/);
  // P4-252 — le splash « Signature » lancé au clic s'efface à l'échec et rend le focus au champ
  // e-mail (le geste à reprendre), le message d'erreur inchangé au-dessus du formulaire.
  await expect(page.getByLabel("Email", { exact: true })).toBeFocused();
});
