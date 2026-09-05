import { expect, test } from "./fixtures";
import { settleVeil, watchFailedApiCalls } from "./support";

/**
 * **Re-dater une fermeture depuis le cockpit (D3 v1) — et le planning se dit « à régénérer ».**
 *
 * Le pendant ÉCRIVANT de `club-life.spec.ts`. Il exerce le seul chemin UI du `PUT` re-datage :
 * cockpit → jour de la fermeture → « Modifier les dates » → nouvelle fenêtre → le serveur déplace
 * le plan et l'annonce (`stalenessMessage` du toast : « planning à régénérer »).
 *
 * ⚑ **SA PROPRE fermeture, pas l'incident du seed** : depuis le découpage début·milieu·fin
 * (fondateur 2026-09-05), l'incident Matéo est DÉCOUPÉ en deux enfants — sa racine porte des
 * semaines-enfants, elle n'est donc plus re-datable (fenêtre gelée). Ce trajet crée donc SA PROPRE
 * fermeture d'UN SEUL bout (lun 19/10 → ven 23/10, une seule semaine entamée = un seul segment,
 * re-datable), la re-date vers un AUTRE bout d'un seul segment (jeu 22/10 : toujours la même
 * semaine calendaire → un seul segment, la règle de re-datage l'accepte), puis la SUPPRIME en
 * `finally` — le trajet est IDEMPOTENT quelle que soit l'ordre des fichiers et ne laisse aucun
 * résidu dans la base e2e (amateo_dev).
 *
 * ⚠ Re-dater vers un vendredi (semaine entamée + semaines pleines = plusieurs segments) serait
 * REFUSÉ (422) par la règle début·milieu·fin — d'où le choix de jeu 22/10, qui reste dans la
 * semaine du 19/10 (un seul segment).
 */

const EMAIL = "mara.mb@bccl.fr";
const PASSWORD = "maraboubccl";
const TITLE = "Fermeture e2e à re-dater";
// Un seul bout : lundi → vendredi (une semaine entamée = un segment), bien après l'incident du seed
// (fin 18/10) et dans la saison. Re-datage vers jeudi = toujours la même semaine (un segment).
const START = "2026-10-19";
const ORIGINAL_END = "2026-10-23";
const REDATED_END = "2026-10-22";

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

/** Crée la fermeture (entrée période) + son plan « d'un bloc » via l'API — la matière du re-datage. */
async function createClosureWithPlan(page: import("./fixtures").Page): Promise<string> {
  const created = await page.request.post("/api/calendar_entries", {
    headers: { "Content-Type": "application/json" },
    data: { kind: "period", periodType: "closure", title: TITLE, startDate: START, endDate: ORIGINAL_END },
  });
  expect(created.ok(), `création de la fermeture (${created.status()})`).toBeTruthy();
  const entryId = (await created.json()).id as string;

  const plan = await page.request.post("/api/schedule_plans", {
    headers: { "Content-Type": "application/json" },
    data: { calendarEntryId: entryId },
  });
  expect(plan.ok(), `adaptation « d'un bloc » (${plan.status()})`).toBeTruthy();

  return entryId;
}

/** Amène le calendrier sur octobre 2026 (la fenêtre de la fermeture) — il s'ouvre sur le mois courant. */
async function goToOctober(page: import("./fixtures").Page): Promise<void> {
  for (let i = 0; i < 6; i += 1) {
    if (await page.getByText("Octobre 2026", { exact: true }).isVisible().catch(() => false)) {
      return;
    }
    await page.getByRole("button", { name: "Mois suivant" }).click();
  }
  await expect(page.getByText("Octobre 2026", { exact: true })).toBeVisible({ timeout: 10_000 });
}

/** Ouvre le dialogue du jour sur une case COUVERTE par la fermeture (son titre est dans l'aria-label). */
async function openClosureDay(page: import("./fixtures").Page): Promise<void> {
  await goToOctober(page);
  const dayCell = page.getByRole("button", { name: new RegExp(`${TITLE}.*(?<!passé \\(non modifiable\\))$`) }).filter({ hasNotText: "passé" });
  await expect(dayCell.first(), "une case-jour couverte par la fermeture doit être cliquable").toBeVisible({ timeout: 30_000 });
  await dayCell.first().click();
  await expect(page.getByRole("dialog")).toBeVisible({ timeout: 15_000 });
}

/** Re-date depuis le dialogue déjà ouvert : change « Jusqu'au » et enregistre. */
async function redateTo(page: import("./fixtures").Page, newEnd: string): Promise<void> {
  await page.getByRole("button", { name: new RegExp(`Modifier les dates de.*${TITLE}`, "i") }).click();
  const endInput = page.getByLabel("Jusqu'au");
  await expect(endInput).toBeVisible({ timeout: 10_000 });
  await endInput.fill(newEnd);
  await page.getByRole("button", { name: "Enregistrer" }).click();
}

test("re-dater une fermeture d'un bloc depuis le cockpit marque son planning « à régénérer »", async ({ page }) => {
  test.setTimeout(120_000);
  const failed = watchFailedApiCalls(page);

  await login(page);
  let entryId = "";
  try {
    entryId = await createClosureWithPlan(page);

    await openCockpit(page);
    await openClosureDay(page);

    // Le témoin de VÉRITÉ = le toast de succès : il ne s'affiche que si le PUT a réellement re-daté
    // (200) — et il porte « planning à régénérer ». Re-datage vers jeudi 22/10 : toujours la même
    // semaine (un seul segment) → la règle début·milieu·fin l'accepte.
    await redateTo(page, REDATED_END);
    await expect(
      page.getByText(/Fermeture re-datée du .+ — planning à régénérer/),
      `le PUT de re-datage doit réussir et l'annoncer${failed.length ? ` — échecs API: ${failed.join(", ")}` : ""}`,
    ).toBeVisible({ timeout: 30_000 });
  } finally {
    // IDEMPOTENCE : on retire la fermeture créée (cascade son plan) — aucun résidu dans la base e2e.
    if ("" !== entryId) {
      await page.request.delete(`/api/calendar_entries/${entryId}`);
    }
  }
});
