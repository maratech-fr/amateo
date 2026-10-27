import { expect, test } from "./fixtures";
import type { Page } from "./fixtures";
import { isoLocal, loginSeededClub, schoolHolidayWindows, settleVeil, watchFailedApiCalls } from "./support";

/**
 * **Prolonger une fermeture À TRAVERS les vacances depuis « Tous les plannings » (PR2).**
 *
 * Le pendant NAVIGATEUR du rail serveur de PR1 (NR bloquant `ClosureWindowExtendTest` + Behat). Ce
 * que SEUL le navigateur prouve ici : le CHEMIN UI complet — « Tous les plannings » → « Dates » (sur
 * la ligne de la fermeture) → dialogue → nouvelle fin au-delà des vacances → « Voir les effets » →
 * « Confirmer » → la liste se repeuple des plannings NÉS de la prolongation. Le serveur reste le juge
 * (l'aperçu == l'appliqué est prouvé en PHPUnit) ; on n'illustre ici que le geste de bout en bout.
 *
 * ⚑ **SA PROPRE fermeture, calculée depuis aujourd'hui, ABOUTANT à de VRAIES vacances servies**
 * (`schoolHolidayWindows`, feed `/api/school-holidays` — jamais un calendrier recopié en dur, bombe
 * à date P4-353) : la fenêtre d'origine finit JUSTE AVANT une vacance future, et on la prolonge
 * APRÈS cette vacance → le serveur insère la pause de vacances et fait NAÎTRE des copies de part et
 * d'autre. Le décor (entrée + plan transcrit du socle + version VALIDÉE) est monté par l'API, puis
 * NETTOYÉ en `finally` — leçon Behat PR1 : une fermeture aux plans VALIDÉS ne se supprime pas sans
 * DÉPOINTER d'abord (reopen de la version pointée), sinon la cascade refuse. Idempotent, aucun
 * résidu dans amateo_dev.
 */

const TITLE = "Fermeture e2e à prolonger";

function addDays(iso: string, days: number): string {
  const [y, m, d] = iso.split("-").map(Number);
  return isoLocal(new Date(y, m - 1, d + days));
}

interface ExtendWindow {
  start: string;
  originalEnd: string;
  newEnd: string;
}

/**
 * Une fenêtre FUTURE aboutant à une vraie vacance scolaire : [start, originalEnd] finit la veille
 * d'une vacance, newEnd la dépasse. Calculée depuis aujourd'hui et le feed serveur (jamais figée).
 */
async function computeExtendWindow(page: Page): Promise<ExtendWindow> {
  const holidays = await schoolHolidayWindows(page);
  const today = isoLocal(new Date());
  // La 1ʳᵉ vacance dont le DÉBUT laisse ≥ 21 jours de marge pour poser la fermeture avant elle.
  const target = holidays
    .filter(([hs]) => hs > addDays(today, 20))
    .sort((a, b) => a[0].localeCompare(b[0]))[0];
  if (undefined === target) {
    throw new Error("P4-353 : aucune vacance scolaire future servie avec 3 semaines de marge — décor injouable");
  }
  const [hs, he] = target;
  return {
    start: addDays(hs, -14), // deux semaines avant la vacance, bien futur
    originalEnd: addDays(hs, -1), // la veille de la vacance (la fermeture aboute la vacance)
    newEnd: addDays(he, 7), // une semaine APRÈS la vacance → la prolongation la traverse
  };
}

async function openAllPlannings(page: Page): Promise<void> {
  await page.goto("/");
  await page.getByRole("button", { name: /Tous les plannings/ }).click();
  await expect(page.getByRole("dialog")).toBeVisible({ timeout: 15_000 });
}

/** Crée la fermeture + son plan transcrit du socle + VALIDE la version (plan pointé). Rend l'entryId. */
async function createValidatedClosure(page: Page, win: ExtendWindow): Promise<string> {
  const entry = await page.request.post("/api/calendar_entries", {
    headers: { "Content-Type": "application/json" },
    data: { kind: "period", periodType: "closure", title: TITLE, startDate: win.start, endDate: win.originalEnd },
  });
  expect(entry.ok(), `création de la fermeture (${entry.status()})`).toBeTruthy();
  const entryId = (await entry.json()).id as string;

  const plan = await page.request.post("/api/schedule_plans", {
    headers: { "Content-Type": "application/json" },
    data: { calendarEntryId: entryId },
  });
  expect(plan.ok(), `création du plan de période (${plan.status()})`).toBeTruthy();
  const planId = (await plan.json()).id as string;

  const transcribed = await page.request.post(`/api/schedule_plans/${planId}/transcribe-from-socle`, { headers: { "Content-Type": "application/json" } });
  expect(transcribed.ok(), `transcription depuis le socle (${transcribed.status()})`).toBeTruthy();
  const versionId = (await transcribed.json()).id as string;

  await expect
    .poll(async () => {
      const r = await page.request.get(`/api/schedules/${versionId}`);
      return r.ok() ? ((await r.json()).status as string) : `HTTP ${r.status()}`;
    }, { timeout: 60_000, intervals: [500, 1000, 2000] })
    .toBe("COMPLETED");

  // VALIDER = pointer la version (plan « en vigueur ») : l'incident est alors TERMINÉ, et la
  // prolongation doit RE-COPIER ce planning validé par-delà les vacances (verdict birth_copy).
  const validated = await page.request.post(`/api/schedules/${versionId}/validate`, { headers: { "Content-Type": "application/json" } });
  expect(validated.ok(), `validation de la version (${validated.status()})`).toBeTruthy();

  return entryId;
}

/**
 * Nettoyage idempotent (leçon Behat PR1) : chaque plan de la fermeture (racine + segments NÉS) peut
 * pointer une version VALIDÉE. On DÉPOINTE d'abord (reopen de `chosenScheduleId`), puis on supprime
 * chaque entrée de calendrier — sinon la cascade refuse de retirer une fermeture aux plans validés.
 */
async function cleanupClosure(page: Page): Promise<void> {
  const res = await page.request.get("/api/schedule_plans");
  if (!res.ok()) {
    return;
  }
  const body = await res.json();
  const plans = (Array.isArray(body) ? body : (body.member ?? body["hydra:member"] ?? [])) as { calendarEntryId?: string | null; chosenScheduleId?: string | null; closureTitle?: string; name?: string }[];
  const mine = plans.filter((p) => TITLE === p.closureTitle || (p.name ?? "").includes(TITLE));
  for (const p of mine) {
    if ("string" === typeof p.chosenScheduleId && "" !== p.chosenScheduleId) {
      await page.request.post(`/api/schedules/${p.chosenScheduleId}/reopen`, { headers: { "Content-Type": "application/json" }, data: { confirmDeleteOverlays: true } }).catch(() => undefined);
    }
  }
  for (const p of mine) {
    if ("string" === typeof p.calendarEntryId && "" !== p.calendarEntryId) {
      await page.request.delete(`/api/calendar_entries/${p.calendarEntryId}`).catch(() => undefined);
    }
  }
}

test("prolonger une fermeture à travers les vacances depuis « Tous les plannings » fait naître les plannings", async ({ page }) => {
  test.setTimeout(180_000);
  const failed = watchFailedApiCalls(page);

  await loginSeededClub(page);
  const win = await computeExtendWindow(page);
  let created = false;
  try {
    await createValidatedClosure(page, win);
    created = true;

    // --- Parcours : cockpit → « Tous les plannings » → ligne de la fermeture → « Dates ». ---
    await openAllPlannings(page);
    const row = page.getByRole("listitem").filter({ hasText: TITLE });
    await expect(row.first(), "la fermeture validée doit figurer dans « Tous les plannings »").toBeVisible({ timeout: 15_000 });
    const before = await page.getByRole("listitem").filter({ hasText: TITLE }).count();

    await page.getByRole("button", { name: new RegExp(`Modifier les dates de.*${TITLE}`, "i") }).click();

    // --- Dialogue : nouvelle fin AU-DELÀ des vacances → aperçu → confirmation. ---
    const endInput = page.getByLabel("Jusqu'au");
    await expect(endInput).toBeVisible({ timeout: 10_000 });
    await endInput.fill(win.newEnd);
    await page.getByRole("button", { name: "Voir les effets" }).click();
    // L'aperçu chargé : au moins un effet listé (servi), et « Confirmer » devient actif.
    await expect(page.getByRole("region", { name: /Ce qui va changer/i }).getByRole("listitem").first()).toBeVisible({ timeout: 20_000 });
    const confirm = page.getByRole("button", { name: "Confirmer" });
    await expect(confirm).toBeEnabled({ timeout: 10_000 });
    await confirm.click();

    // --- Les plannings NÉS de la prolongation apparaissent (la liste se repeuple). ---
    await settleVeil(page);
    await expect
      .poll(async () => page.getByRole("listitem").filter({ hasText: TITLE }).count(), {
        timeout: 30_000,
        message: `la prolongation doit faire NAÎTRE des plannings-segments${failed.length ? ` — échecs API: ${failed.join(", ")}` : ""}`,
      })
      .toBeGreaterThan(before);
  } finally {
    if (created) {
      await cleanupClosure(page);
    }
  }
});
