import { expect, test } from "./fixtures";
import { settleVeil, watchFailedApiCalls } from "./support";

/**
 * **Re-dater une fermeture depuis le cockpit (D3 v1 PR-2) — et le planning se dit « à régénérer ».**
 *
 * Le pendant ÉCRIVANT de `club-life.spec.ts` (qui, lui, « n'écrit RIEN » — convention respectée :
 * ce trajet vit dans SON propre fichier). Il exerce le seul chemin UI du `PUT` re-datage :
 * cockpit → jour de l'incident → « Modifier les dates » → nouvelle fenêtre → le serveur déplace le
 * plan, la version pointée survit **marquée à régénérer**, et `PlanningPage` l'annonce
 * (`stalenessMessage`, cause « les données du club ont changé »).
 *
 * ⚠ **IDEMPOTENT malgré l'écriture** : la base e2e (amateo_dev) n'est JAMAIS réinitialisée et
 * `club-life.spec.ts` s'appuie sur la FENÊTRE de l'incident (« 31-08-2026 → 16-10-2026 »). Ce
 * trajet RE-DATE puis RE-DATE EN SENS INVERSE vers la fenêtre d'origine — la fenêtre est donc
 * restaurée à la sortie, quel que soit l'ordre des fichiers (`workers: 1`, `fullyParallel: false` :
 * aucune course avec club-life). Le seul résidu — la version marquée « à régénérer » — n'est
 * asserté par aucun autre spec. Le témoin (`watchFailedApiCalls` + l'assertion sur le toast de
 * succès) fait ROUGIR le trajet si le PUT n'a pas réellement écrit.
 *
 * L'incident du seed : la fermeture du gymnase Matéo (31 août → 16 octobre 2026), racine `closure`
 * à plan validé, donc RE-DATABLE (prédicat serveur `redatable`). On la désigne par un fragment de
 * son titre, stable d'un seed à l'autre.
 */

const EMAIL = "mara.mb@bccl.fr";
const PASSWORD = "maraboubccl";
const INCIDENT = "Matéo";

// Fenêtre d'origine (club-life en dépend) et la fenêtre temporaire de l'aller. Toutes deux dans la
// saison et ≥ aujourd'hui (le seed vit dans l'ère 2026 — même parti que club-life).
const ORIGINAL_END = "2026-10-16";
const REDATED_END = "2026-10-09";

async function login(page: import("./fixtures").Page): Promise<void> {
  await page.goto("/login");
  await page.getByLabel("Email").fill(EMAIL);
  await page.getByLabel("Mot de passe", { exact: true }).fill(PASSWORD);
  await page.getByRole("button", { name: "Se connecter" }).click();
  await expect(page.getByRole("button", { name: "Saison de travail" })).toBeVisible({ timeout: 20_000 });
}

async function openCockpit(page: import("./fixtures").Page): Promise<void> {
  await page.goto("/");
  await expect(page.getByRole("button", { name: /Tous les plannings/ })).toBeVisible({ timeout: 30_000 });
  await settleVeil(page);
}

/**
 * Ouvre le dialogue du jour sur une case du calendrier COUVERTE par l'incident (donc portant son
 * titre dans son `aria-label` composé, cf. `MonthCalendar` A11Y-07) et NON passée (une case passée
 * n'a pas de `onClick`). On vise une case, jamais la carte radar (un `<p>`, pas un bouton).
 */
async function openIncidentDay(page: import("./fixtures").Page): Promise<void> {
  const dayCell = page
    .getByRole("button", { name: new RegExp(`${INCIDENT}.*(?<!passé \\(non modifiable\\))$`) })
    .filter({ hasNotText: "passé" });
  await expect(dayCell.first(), "une case-jour couverte par l'incident doit être cliquable").toBeVisible({ timeout: 30_000 });
  await dayCell.first().click();
  await expect(page.getByRole("dialog")).toBeVisible({ timeout: 15_000 });
}

/** Re-date l'incident depuis le dialogue déjà ouvert : change « Jusqu'au » et enregistre. */
async function redateTo(page: import("./fixtures").Page, newEnd: string): Promise<void> {
  await page.getByRole("button", { name: new RegExp(`Modifier les dates de.*${INCIDENT}`, "i") }).click();
  const endInput = page.getByLabel("Jusqu'au");
  await expect(endInput).toBeVisible({ timeout: 10_000 });
  await endInput.fill(newEnd);
  await page.getByRole("button", { name: "Enregistrer" }).click();
}

test("re-dater l'incident depuis le cockpit marque son planning « à régénérer », puis se restaure", async ({ page }) => {
  test.setTimeout(120_000);
  const failed = watchFailedApiCalls(page);

  await login(page);
  await openCockpit(page);
  await openIncidentDay(page);

  // --- 1 · L'ALLER : re-dater la fin de l'incident. Le témoin de VÉRITÉ = le toast de succès
  //         (phrase unique) : il ne s'affiche que si le PUT a réellement re-daté (200).
  await redateTo(page, REDATED_END);
  await expect(
    page.getByText(/Fermeture re-datée du .+ — planning à régénérer/),
    `le PUT de re-datage doit réussir et l'annoncer${failed.length ? ` — échecs API: ${failed.join(", ")}` : ""}`,
  ).toBeVisible({ timeout: 30_000 });

  // --- 2 · LE PLANNING DE L'OVERLAY SE DIT PÉRIMÉ. On l'ouvre par la carte radar (« Voir le
  //         planning », comme club-life) et on lit la bannière de péremption : re-dater a changé
  //         une donnée du club, la version pointée survit mais décrit un état antérieur.
  await openCockpit(page);
  const radar = page.getByRole("complementary").filter({ hasText: "À traiter" });
  const card = radar.locator("div.rounded-md.border").filter({ hasText: INCIDENT });
  await card.first().getByRole("button", { name: /Voir le planning/ }).first().click();
  await expect(page).toHaveURL(/\/planning/, { timeout: 30_000 });
  await settleVeil(page);
  await expect(
    page.getByText(/il est périmé/),
    "après le re-datage, /planning de l'overlay doit afficher la bannière « périmé » (données du club changées)",
  ).toBeVisible({ timeout: 30_000 });

  // --- 3 · RESTAURATION (idempotence pour club-life) : re-dater la fin vers la fenêtre d'origine.
  await openCockpit(page);
  await openIncidentDay(page);
  await redateTo(page, ORIGINAL_END);
  await expect(page.getByText(/Fermeture re-datée du .+ — planning à régénérer/)).toBeVisible({ timeout: 30_000 });
});
