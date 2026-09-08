import { expect, test } from "./fixtures";

/**
 * PR-2a — l'onglet « Consulter » (lecture seule, temporalité Semaine). Sous la stack
 * réelle : on amène le club seedé à l'état 3 (plan validé → matchs déverrouillés),
 * on ouvre `/matchs/consulter`, et on éprouve les chips (type de compétition), l'
 * interrupteur « Semaine type », et le filtre par famille de conflit (décocher
 * « Passerelle » retire sa carte du radar).
 *
 * ⚠ Le test S'AUTO-PROVISIONNE (comme `matches.spec.ts`) : il crée ses propres
 * rencontres via l'API et les nettoie en `finally`, donc il ne suppose RIEN des
 * données du seed (le club CI n'a aucune rencontre). Déterministe et sans dépendance
 * au sandbox.
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

/** Local Y-m-d (jamais toISOString, qui bascule en UTC et peut changer le jour). */
function ymd(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

function addDays(ymdStr: string, n: number): string {
  const d = new Date(`${ymdStr}T00:00:00`);
  d.setDate(d.getDate() + n);
  return ymd(d);
}

/** Le prochain samedi (Y-m-d) à au moins `minAhead` jours d'aujourd'hui. */
function nextSaturdayAtLeast(minAhead: number): string {
  const d = new Date();
  d.setHours(0, 0, 0, 0);
  d.setDate(d.getDate() + minAhead);
  while (6 !== d.getDay()) {
    d.setDate(d.getDate() + 1); // 6 = samedi
  }
  return ymd(d);
}

/** Amène la semaine affichée sur celle qui contient `text`, en cliquant `btn`
 * (‹ ou ›) au plus `max` fois. Retourne vrai si `text` est visible à la fin. */
async function stepUntilVisible(btn: import("@playwright/test").Locator, target: import("@playwright/test").Locator, max: number): Promise<boolean> {
  if (await target.isVisible().catch(() => false)) {
    return true;
  }
  for (let i = 0; i < max; i++) {
    if (!(await btn.isEnabled())) {
      break;
    }
    await btn.click();
    if (await target.isVisible().catch(() => false)) {
      return true;
    }
  }
  return false;
}

test("consulter: chips, semaine type, et filtre par famille de conflit", async ({ page }) => {
  test.setTimeout(240_000); // l'onboarding peut lancer une vraie génération CP-SAT

  await login(page);
  await ensureValidated(page);

  // ── Auto-provisionnement (le club CI n'a AUCUNE rencontre) ───────────────────
  // Les cookies du contexte suivent `page.request` (JWT httpOnly). On prend la 1ʳᵉ
  // équipe + le 1ᵉʳ gymnase du club, puis on crée nos propres rencontres sur DEUX
  // semaines FUTURES vides (le sandbox n'a rien au-delà de +7 j) — nettoyées en
  // `finally`. Le détecteur émet VENUE_OVERLAP dès qu'un gymnase + un coup d'envoi
  // sont posés (statut UNPLACED compris — `MatchConflictDetector::venueOverlapConflicts`).
  const teamsRes = await page.request.get("/api/teams?itemsPerPage=100");
  expect(teamsRes.ok(), "GET /api/teams").toBeTruthy();
  const teamId = ((await teamsRes.json()).member?.[0]?.id ?? undefined) as string | undefined;
  const venuesRes = await page.request.get("/api/venues?itemsPerPage=100");
  expect(venuesRes.ok(), "GET /api/venues").toBeTruthy();
  const venueId = ((await venuesRes.json()).member?.[0]?.id ?? undefined) as string | undefined;
  expect(teamId, "le club seedé a au moins une équipe").toBeTruthy();
  expect(venueId, "le club seedé a au moins un gymnase").toBeTruthy();

  const ts = Date.now();
  // W_clean = samedi ≥ aujourd'hui + 7 j (une rencontre isolée → un bucket SANS
  // collision) ; W_overlap = le samedi suivant (deux domiciles même gymnase, même
  // heure → VENUE_OVERLAP). Les deux sont futures ⇒ vierges de données sandbox.
  const cleanSat = nextSaturdayAtLeast(7);
  const overlapSat = addDays(cleanSat, 7);
  const cleanOpp = `E2E-CONSULTER-${ts}-CLEAN`;
  const overlapA = `E2E-CONSULTER-${ts}-A`;
  const overlapB = `E2E-CONSULTER-${ts}-B`;

  const createFixture = async (matchDate: string, opponentLabel: string): Promise<string> => {
    const res = await page.request.post("/api/fixtures", {
      data: { teamId, matchDate, homeAway: "HOME", opponentLabel, venueId, kickoffTime: "15:00", competitionId: null },
    });
    expect(res.ok(), `POST /api/fixtures ${opponentLabel}`).toBeTruthy();
    return (await res.json()).id as string;
  };

  const createdIds: string[] = [];
  try {
    createdIds.push(await createFixture(cleanSat, cleanOpp));
    createdIds.push(await createFixture(overlapSat, overlapA));
    createdIds.push(await createFixture(overlapSat, overlapB));

    await page.goto("/matchs/consulter");
    await expect(page.getByRole("link", { name: "Consulter" })).toHaveAttribute("aria-current", "page");

    // Chips type de compétition — STATIQUES (les 4 `KINDS`), donc présentes même
    // sans données ; cochées par défaut.
    for (const label of ["Amical", "Championnat", "Coupe", "Brassage"]) {
      await expect(page.getByRole("button", { name: label, exact: true })).toHaveAttribute("aria-pressed", "true");
    }

    // Interrupteur « Semaine type » (role switch), coché par défaut ; on le bascule.
    const semaineType = page.getByRole("switch", { name: /Semaine type/ });
    await expect(semaineType).toHaveAttribute("aria-checked", "true");
    await semaineType.click();
    await expect(semaineType).toHaveAttribute("aria-checked", "false");
    await expect(page).toHaveURL(/[?&]type_semaine=0/);

    // Va sur la semaine de la collision (repérée par notre rencontre créée). On
    // cherche › puis ‹ : indépendant de l'horloge (potentiellement pilotée serveur).
    const nextWeek = page.getByRole("button", { name: "Semaine suivante" });
    const prevWeek = page.getByRole("button", { name: "Semaine précédente" });
    const overlapCell = page.getByText(overlapA, { exact: false });
    const found = (await stepUntilVisible(nextWeek, overlapCell, 12)) || (await stepUntilVisible(prevWeek, overlapCell, 24));
    expect(found, "la semaine de la collision créée est atteignable").toBeTruthy();
    await expect(overlapCell).toBeVisible();

    // Puce « Collision de gymnase » présente, compteur ≥ 1.
    const familiesGroup = page.getByRole("group", { name: "Familles de conflits" });
    const collisionChip = familiesGroup.getByRole("button", { name: /^Collision de gymnase/ });
    await expect(collisionChip).toBeVisible();
    await expect(collisionChip).toHaveAttribute("aria-pressed", "true");
    const chipCount = (await collisionChip.innerText()).match(/\d+/)?.[0] ?? "";
    expect(Number(chipCount), "compteur de collisions ≥ 1").toBeGreaterThanOrEqual(1);

    // Radar : le groupe « Collision de gymnase » (h3, sévérité 1). Décocher la puce
    // le retire, la puce garde son compteur ; recocher le fait revenir.
    const radarGroup = page.getByRole("heading", { level: 3, name: "Collision de gymnase" });
    await expect(radarGroup).toBeVisible();
    await collisionChip.click();
    await expect(collisionChip).toHaveAttribute("aria-pressed", "false");
    await expect(collisionChip).toContainText(chipCount);
    await expect(radarGroup).toHaveCount(0);
    await expect(page).toHaveURL(/[?&]conflits=/);
    await collisionChip.click();
    await expect(collisionChip).toHaveAttribute("aria-pressed", "true");
    await expect(radarGroup).toBeVisible();

    // Témoin du bornage à la SEMAINE : sur la semaine de la rencontre ISOLÉE
    // (W_clean, un cran plus tôt), aucune collision. Assertion forte (`toHaveCount(0)`).
    const cleanCell = page.getByText(cleanOpp, { exact: false });
    expect(await stepUntilVisible(prevWeek, cleanCell, 24), "la semaine de la rencontre isolée est atteignable").toBeTruthy();
    await expect(cleanCell).toBeVisible();
    await expect(familiesGroup.getByRole("button", { name: /^Collision de gymnase/ })).toHaveCount(0);
  } finally {
    for (const id of createdIds) {
      await page.request.delete(`/api/fixtures/${id}`).catch(() => undefined);
    }
  }
});
