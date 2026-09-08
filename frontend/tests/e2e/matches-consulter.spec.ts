import { expect, test } from "./fixtures";

/**
 * PR-2a — l'onglet « Consulter » (lecture seule, temporalité Semaine). Sous la stack
 * réelle : on amène le club seedé à l'état 3 (plan validé → matchs déverrouillés),
 * on ouvre `/matchs/consulter`, et on éprouve les chips (type de compétition), l'
 * interrupteur « Semaine type », et le filtre par famille de conflit (décocher
 * « Passerelle » retire sa carte du radar).
 *
 * ⚠ Écrit, PAS lancé par l'agent d'implémentation (le fondateur le joue). Suppose que
 * le seed BCCL porte au moins une passerelle (TEAM_LINK_OVERLAP) — sinon la chip
 * « Passerelle » n'apparaît pas et le test échoue en le disant (témoin volontaire,
 * pas un faux vert).
 */
const EMAIL = "mara.mb@bccl.fr";
const PASSWORD = "maraboubccl";

type Page = import("@playwright/test").Page;

async function login(page: Page): Promise<void> {
  await page.goto("/login");
  await page.getByLabel("Email").fill(EMAIL);
  await page.getByLabel("Mot de passe", { exact: true }).fill(PASSWORD);
  await page.getByRole("button", { name: "Se connecter" }).click();
  await expect(page.getByRole("button", { name: "Saison de travail" })).toBeVisible({ timeout: 15_000 });
}

/**
 * Amène le club seedé à l'état 3 (plan principal validé) — matchs déverrouillés.
 * Idempotent (base de dev non réinitialisée) : si la nav Matchs est déjà un lien,
 * le socle est validé → rien à faire ; sinon on emprunte le wizard. Copie du helper
 * de `matches.spec.ts` (même base, mêmes pièges documentés là-bas).
 */
async function ensureValidated(page: Page): Promise<void> {
  await page.goto("/");
  const validated = await page
    .getByRole("link", { name: "Matchs" })
    .waitFor({ state: "visible", timeout: 5_000 })
    .then(() => true)
    .catch(() => false);
  if (validated) {
    return;
  }

  await page.goto("/wizard");
  await page.waitForLoadState("networkidle");

  const railGenerate = page.locator("nav").getByRole("button", { name: /Génération/ });
  if (await railGenerate.isEnabled({ timeout: 3_000 }).catch(() => false)) {
    await railGenerate.click();
  }

  const cont = page.getByRole("button", { name: "Continuer vers la génération" });
  const launch = page.getByRole("button", { name: "Lancer la génération" });
  const onGenerationPath = await Promise.race([
    cont.waitFor({ state: "visible", timeout: 10_000 }).then(() => true),
    launch.waitFor({ state: "visible", timeout: 10_000 }).then(() => true),
  ]).catch(() => false);

  if (!onGenerationPath) {
    const landed = await page
      .locator("[aria-current]")
      .first()
      .textContent()
      .then((t) => t?.trim() ?? "inconnue")
      .catch(() => "inconnue");
    throw new Error(
      `ensureValidated: le wizard a atterri sur « ${landed} » au lieu du chemin de génération. `
      + "La base de DEV a dérivé. Rejouez `make -C backend fixtures`, puis relancez.",
    );
  }

  if (await cont.isVisible().catch(() => false)) {
    if (!(await cont.isEnabled({ timeout: 5_000 }).catch(() => false))) {
      throw new Error("ensureValidated: « Continuer vers la génération » est DÉSACTIVÉ (gate du récap refuse).");
    }
    await cont.click();
  }
  const validate = page.getByRole("button", { name: "Valider" });
  const alreadyGenerated = await validate.isVisible({ timeout: 3_000 }).catch(() => false);
  if (!alreadyGenerated && (await launch.isEnabled({ timeout: 5_000 }).catch(() => false))) {
    await launch.click();
  }
  await expect(validate).toBeVisible({ timeout: 180_000 });
  await validate.click();
  const dialog = page.getByRole("dialog", { name: "Valider le planning" });
  await expect(dialog).toBeVisible();
  await dialog.getByRole("button", { name: "Valider", exact: true }).click();
  await expect(page.getByRole("link", { name: "Matchs" })).toBeVisible({ timeout: 15_000 });
}

test("consulter: chips, semaine type, et filtre par famille de conflit", async ({ page }) => {
  test.setTimeout(240_000); // l'onboarding peut lancer une vraie génération CP-SAT

  await login(page);
  await ensureValidated(page);

  await page.goto("/matchs/consulter");
  await expect(page.getByRole("link", { name: "Consulter" })).toHaveAttribute("aria-current", "page");

  // Chips type de compétition — présentes, cochées par défaut.
  for (const label of ["Amical", "Championnat", "Coupe", "Brassage"]) {
    await expect(page.getByRole("button", { name: label, exact: true })).toHaveAttribute("aria-pressed", "true");
  }

  // Interrupteur « Semaine type » (role switch), coché par défaut ; on le bascule.
  const semaineType = page.getByRole("switch", { name: /Semaine type/ });
  await expect(semaineType).toHaveAttribute("aria-checked", "true");
  await semaineType.click();
  await expect(semaineType).toHaveAttribute("aria-checked", "false");
  await expect(page).toHaveURL(/[?&]type_semaine=0/);

  // ── Filtre par famille de conflit, sur la semaine affichée (bornage hebdo) ────
  // On lit la PREMIÈRE famille PRÉSENTE (le contenu dépend de la semaine courante
  // du sandbox : décocher-la doit retirer son GROUPE du radar, sans vider le test).
  const familiesGroup = page.getByRole("group", { name: "Familles de conflits" });
  // ⚠ `.getByRole({pressed:true}).first()` est PARESSEUX : après le clic il résout la
  // puce SUIVANTE encore pressée. On fige donc la première puce par son NOM (label
  // hors compteur) avant tout clic, puis on re-localise CETTE puce pour les gestes.
  const firstPressed = familiesGroup.getByRole("button", { pressed: true }).first();
  await expect(firstPressed).toBeVisible();
  const firstText = (await firstPressed.innerText()).trim(); // ex. « Coach en double 1 »
  const chipCount = firstText.match(/\d+/)?.[0] ?? "";
  const label = firstText.replace(/\s*\d+\s*$/, "").trim(); // « Coach en double »
  expect(chipCount).not.toBe("");
  expect(label).not.toBe("");
  const escaped = label.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const chip = familiesGroup.getByRole("button", { name: new RegExp("^" + escaped) });

  // Les titres de GROUPE du radar (h3), hors bande extérieure (constante).
  const radarTitles = page.getByRole("heading", { level: 3 }).filter({ hasNotText: "À l'extérieur ce week-end" });
  const before = await radarTitles.allInnerTexts();

  // (2) Décocher → (3) un titre de groupe disparaît, la puce garde son compteur.
  await chip.click();
  await expect(chip).toHaveAttribute("aria-pressed", "false");
  await expect(chip).toContainText(chipCount);
  const after = await radarTitles.allInnerTexts();
  const gone = before.filter((t) => !after.includes(t));
  expect(gone.length, "décocher une famille retire son groupe du radar").toBeGreaterThanOrEqual(1);
  await expect(page).toHaveURL(/[?&]conflits=/);

  // (4) Recocher → le(s) titre(s) reviennent.
  await chip.click();
  await expect(chip).toHaveAttribute("aria-pressed", "true");
  const restored = await radarTitles.allInnerTexts();
  for (const title of gone) {
    expect(restored, "recocher restaure le groupe").toContain(title);
  }

  // ── Témoin VIVANT du bornage à la semaine : la « Passerelle » (seul TEAM_LINK_
  // OVERLAP réel = mer. 2 sept.) n'existe pas sur la semaine courante, mais apparaît
  // en reculant jusqu'à la semaine du 31 août au 6 sept. Assertion forte, pas un if.
  const prevWeek = page.getByRole("button", { name: "Semaine précédente" });
  for (let i = 0; i < 8; i++) {
    if ((await page.getByText(/^Semaine du/).innerText()).includes("31 août")) {
      break;
    }
    if (!(await prevWeek.isEnabled())) {
      break;
    }
    await prevWeek.click();
  }
  await expect(page.getByText("Semaine du 31 août au 6 sept.")).toBeVisible();
  await expect(familiesGroup.getByRole("button", { name: /^Passerelle/ })).toBeVisible();
});
