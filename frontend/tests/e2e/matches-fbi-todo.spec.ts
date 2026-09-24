import { expect, test } from "./fixtures";

/**
 * La liste « FBI — à faire » du Calendrier, de bout en bout, sous la stack réelle.
 *
 * ⚠ Le spec S'AUTO-PROVISIONNE (patron `matches-consulter.spec.ts` /
 * `a11y-contrast.spec.ts`) : il crée SA rencontre (un domicile PLACÉ) via l'API et la
 * nettoie en `finally`, donc il ne suppose RIEN du seed. Le compteur global « FBI à
 * faire » compte alors AU MOINS cette rencontre ; on ouvre la liste, on coche « saisi »,
 * on éprouve le toast. Un geste qui n'exerce rien laisserait le toast absent.
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

test("fbi — le compteur « FBI à faire » ouvre la liste ; cocher marque saisi dans FBI", async ({ page }) => {
  test.setTimeout(240_000); // l'onboarding peut lancer une vraie génération CP-SAT

  await login(page);

  // Le club seedé a au moins une équipe + un gymnase (garanti par le seed/onboarding).
  const teamsRes = await page.request.get("/api/teams?itemsPerPage=100");
  expect(teamsRes.ok(), "GET /api/teams").toBeTruthy();
  const firstTeam = (await teamsRes.json()).member?.[0] as { id?: string } | undefined;
  const teamId = firstTeam?.id;
  const venuesRes = await page.request.get("/api/venues?itemsPerPage=100");
  expect(venuesRes.ok(), "GET /api/venues").toBeTruthy();
  const venueId = ((await venuesRes.json()).member?.[0]?.id ?? undefined) as string | undefined;
  expect(teamId, "le club seedé a au moins une équipe").toBeTruthy();
  expect(venueId, "le club seedé a au moins un gymnase").toBeTruthy();

  // On POSTe NOTRE domicile PLACÉ (amical, competitionId null → pas de fenêtre d'accès
  // à respecter) : il compte dans « à saisir dans FBI » (domiciles PLACED).
  const opp = `E2E-FBI-${Date.now()}`;
  const created = await page.request.post("/api/fixtures", {
    data: { teamId, matchDate: nextSaturdayAtLeast(7), homeAway: "HOME", opponentLabel: opp, venueId, kickoffTime: "15:00", status: "PLACED", competitionId: null },
  });
  expect(created.ok(), "POST /api/fixtures").toBeTruthy();
  const id = (await created.json()).id as string;

  try {
    await page.goto("/matchs");
    // UXS-07 — l'index peut renvoyer sur Conflits (atterrissage conditionnel, store vierge après
    // `goto`) : on rejoint explicitement le Calendrier, qui porte le compteur « FBI à faire »
    // (patron déterministe, clic idempotent si on y est déjà).
    await page.getByRole("link", { name: "Calendrier" }).click();

    // Le compteur global « FBI à faire » (hors du groupe « Semaine affichée ») porte au
    // moins NOTRE rencontre.
    const counter = page.getByRole("button", { name: /FBI à faire/ });
    await expect(counter).toBeVisible({ timeout: 20_000 });
    await counter.click();

    // La liste s'ouvre ; NOTRE domicile est « à saisir ».
    const dialog = page.getByRole("dialog", { name: "FBI — à faire" });
    await expect(dialog).toBeVisible();
    const submitBtn = dialog.getByRole("button", { name: new RegExp(`Marquer saisi.*${opp}`) });
    await expect(submitBtn).toBeVisible();
    await submitBtn.click();

    // Le toast confirme la saisie.
    await expect(page.getByText("Match marqué saisi dans FBI")).toBeVisible();
  } finally {
    // Base dev CI non remise à zéro : on nettoie NOTRE rencontre, sans supposer l'état.
    await page.request.delete(`/api/fixtures/${id}`).catch(() => undefined);
  }
});
