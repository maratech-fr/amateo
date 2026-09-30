import { expect, test } from "./fixtures";

import { registerAndVerify, submitRegister, uniqueAra } from "./support";

test("register then verify by email lands a new club in the wizard", async ({ page }) => {
  const ara = uniqueAra("E2EN");
  await registerAndVerify(page, { email: `new-${ara}@e2e.fr`, ara, clubName: "E2E Club" });

  // A fresh club is routed to the onboarding wizard (AuthGuard) with the
  // season selector mounted in the header — the app shell is alive.
  await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });
  await expect(page.getByRole("button", { name: "Saison de travail" })).toBeVisible();
});

test("register shows the check-your-email state and never authenticates immediately", async ({ page }) => {
  const ara = uniqueAra("E2EC");
  // A3: submitting register does NOT create a session — it shows the confirmation
  // state and stays on /register (no redirect into the app shell).
  await submitRegister(page, { email: `pending-${ara}@e2e.fr`, ara, clubName: "E2E Pending" });
  await expect(page).toHaveURL(/\/register$/);
  await expect(page.getByRole("button", { name: "Saison de travail" })).toBeHidden();
});

test("joining an existing club shows the waiting-for-approval screen", async ({ page }) => {
  const ara = uniqueAra("E2EJ");

  // Owner creates + verifies the club (becomes active admin -> wizard).
  await registerAndVerify(page, { email: `owner-${ara}@e2e.fr`, ara, firstName: "Owner", lastName: "Admin", clubName: "E2E Existing" });
  await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });

  // A second person on the same ARA -> pending -> waiting screen (after verifying).
  await registerAndVerify(page, { email: `joiner-${ara}@e2e.fr`, ara, firstName: "Joiner", lastName: "Two" });
  await expect(page.getByText(/demande en attente/i)).toBeVisible();
});

test("login rejects invalid credentials without redirect loop", async ({ page }) => {
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
