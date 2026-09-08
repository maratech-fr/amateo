import { expect, test } from "./fixtures";

/**
 * PR-3b — l'onglet « Importer » : les entrées de données (dépôt FBI, canal API,
 * engagements) et la FILE de traitement des rencontres, par équipe. Sous la stack
 * réelle : on amène le club seedé à l'état 3 (plan validé → matchs déverrouillés),
 * on éprouve la présence de l'onglet, l'absence de badge quand rien n'est à
 * traiter, l'allègement de la Configuration (les données FBI/FFBB ont migré ici),
 * puis — auto-provisionné — qu'une rencontre créée (traitée par le geste de
 * saisie) apparaît dans les traitées une fois l'interrupteur activé.
 *
 * ⚠ Le test S'AUTO-PROVISIONNE (comme `matches-consulter.spec.ts`) : il crée sa
 * rencontre via l'API et la nettoie en `finally`, donc il ne suppose RIEN des
 * données du seed. Le TÉMOIN (la rencontre INVISIBLE avant l'interrupteur, VISIBLE
 * après) échoue si le geste n'exerce rien.
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
 * Copie du helper de `matches-consulter.spec.ts` (même base, mêmes pièges là-bas).
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

/** Un nom d'équipe devient un motif littéral (« U11M1 (bis) » ne doit pas casser la RegExp). */
function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

/** Local Y-m-d (jamais toISOString, qui bascule en UTC). */
function ymd(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

/** Le prochain samedi (Y-m-d) à au moins `minAhead` jours d'aujourd'hui. */
function nextSaturdayAtLeast(minAhead: number): string {
  const d = new Date();
  d.setHours(0, 0, 0, 0);
  d.setDate(d.getDate() + minAhead);
  while (6 !== d.getDay()) {
    d.setDate(d.getDate() + 1);
  }
  return ymd(d);
}

test("importer: onglet, badge absent, configuration allégée, file de traitement", async ({ page }) => {
  test.setTimeout(240_000); // l'onboarding peut lancer une vraie génération CP-SAT

  await login(page);
  await ensureValidated(page);

  // ── L'onglet Importer est atteint, sans badge (le club CI n'a rien à traiter) ──
  // Le club CI n'a rien à traiter ; un sandbox avec des rencontres importées (état
  // « nouveau ») en a. Le spec lit l'API et attend l'écran correspondant : jamais
  // « Importer · 0 », et un badge exactement égal au compte serveur sinon.
  const fixturesRes = await page.request.get("/api/fixtures?itemsPerPage=100");
  expect(fixturesRes.ok(), "GET /api/fixtures").toBeTruthy();
  const existing = ((await fixturesRes.json()).member ?? []) as { reviewState: string }[];
  const openBefore = existing.filter((f) => "NEW" === f.reviewState || "OUT_OF_SYNC" === f.reviewState).length;
  const tabLabel = 0 === openBefore ? "Importer" : `Importer · ${openBefore}`;

  await page.goto("/matchs");
  const importerTab = page.getByRole("link", { name: /^Importer/ });
  await expect(importerTab).toBeVisible();
  await expect(importerTab).toHaveText(tabLabel);
  await importerTab.click();
  await expect(page.getByRole("link", { name: /^Importer/ })).toHaveAttribute("aria-current", "page");

  // Les entrées de données de match sont là.
  await expect(page.getByRole("button", { name: /Importer FBI/ })).toBeVisible();
  await expect(page.getByRole("button", { name: /Vérifier via l'API FFBB/i })).toBeVisible();
  await expect(page.getByRole("button", { name: /Engagements FFBB/ })).toBeVisible();
  // File vide → « Rien à traiter » ; sinon au moins une équipe en tête de file.
  if (0 === openBefore) {
    await expect(page.getByText("Rien à traiter")).toBeVisible();
  } else {
    await expect(page.getByRole("button", { name: /à valider/ }).first()).toBeVisible();
  }

  // ── Configuration allégée : les données FBI/FFBB ont migré vers Importer ──────
  await page.goto("/matchs/configuration");
  await expect(page.getByRole("button", { name: "Accès match" })).toBeVisible();
  await expect(page.getByText("Dépôt saisonnier FBI")).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Engagements FFBB" })).toHaveCount(0);

  // ── Auto-provisionnement : une rencontre créée (= traitée par la saisie) ──────
  const teamsRes = await page.request.get("/api/teams?itemsPerPage=100");
  expect(teamsRes.ok(), "GET /api/teams").toBeTruthy();
  const firstTeam = (await teamsRes.json()).member?.[0] as { id?: string; name?: string } | undefined;
  const teamId = firstTeam?.id;
  const teamName = firstTeam?.name ?? "";
  const venuesRes = await page.request.get("/api/venues?itemsPerPage=100");
  expect(venuesRes.ok(), "GET /api/venues").toBeTruthy();
  const venueId = ((await venuesRes.json()).member?.[0]?.id ?? undefined) as string | undefined;
  expect(teamId, "le club seedé a au moins une équipe").toBeTruthy();

  const opp = `E2E-IMPORTER-${Date.now()}`;
  const createRes = await page.request.post("/api/fixtures", {
    data: { teamId, matchDate: nextSaturdayAtLeast(7), homeAway: "HOME", opponentLabel: opp, venueId, kickoffTime: "15:00", competitionId: null },
  });
  expect(createRes.ok(), "POST /api/fixtures").toBeTruthy();
  const id = (await createRes.json()).id as string;

  try {
    await page.goto("/matchs/importer");
    const oppCell = page.getByText(opp, { exact: false });

    // TÉMOIN — la rencontre créée est TRAITÉE : masquée par défaut (la file ne
    // montre que les ouvertes), et le badge n'a pas bougé.
    await expect(page.getByRole("link", { name: /^Importer/ })).toHaveText(tabLabel);
    if (0 === openBefore) {
      await expect(page.getByText("Rien à traiter")).toBeVisible();
    }
    await expect(oppCell).toHaveCount(0);

    // Révéler les traitées → l'équipe de la rencontre apparaît ; on l'ouvre → elle est là.
    await page.getByRole("checkbox", { name: "Afficher les traitées" }).check();
    const teamHeader = page.getByRole("button", { name: new RegExp(`^${escapeRegExp(teamName)} · `) });
    await expect(teamHeader).toBeVisible();
    if ("true" !== (await teamHeader.getAttribute("aria-expanded"))) {
      await teamHeader.click();
    }
    await expect(oppCell).toBeVisible();
  } finally {
    await page.request.delete(`/api/fixtures/${id}`).catch(() => undefined);
  }
});
