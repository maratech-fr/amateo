import { expect, test, type Page } from "./fixtures";
import { firstHolidayFreeMonday, forceTheme, frMonthLabel, isoLocal, loginSeededClub, settleVeil, watchFailedApiCalls } from "./support";

/** Une fenêtre de calendrier FUTURE (lun→ven), hors vacances, calculée depuis aujourd'hui. */
interface DatedWindow {
  start: string;
  end: string;
  monthLabel: string;
}

/**
 * **Lot 9 F3 — le bouton « Mutualiser… » EST atteignable par un vrai parcours (preuve bac à sable).**
 *
 * Le doute à lever (cadrage) : si la seule façon d'atteindre la grille d'un plan de PÉRIODE était un
 * overlay VALIDÉ (lecture seule, `/planning` via `viewOverlay`, gardé sur `chosenScheduleId`), le
 * bouton serait TOUJOURS désactivé → F3 inutilisable. Le vrai chemin d'un plan NON validé passe par
 * le wizard : cockpit → « Ajuster » (plan sans `chosenScheduleId`) → mode période → étape
 * « Génération » → grille EMBARQUÉE (`PlanningPage embedded scopePlanId=…`), où « Mutualiser… » est
 * OFFERT (`onMutualize` fourni) ET `readOnly` faux. Ce trajet le prouve de bout en bout. (Lot 2 : le
 * geste est désormais aussi offert au planning de SAISON ; ce spec garde la preuve du chemin période.)
 *
 * Décor DÉTERMINISTE (patron `TrainingBlockContext::unPlanDePeriodeGenere`) : une fermeture jetable
 * + son plan, transcrits DEPUIS LE SOCLE (copie de la grille de saison, séances PLACÉES, sans
 * dépendre d'un solve frais). La source et la joiner sont deux équipes SOLO sur deux cases SOLO —
 * la case d'ancrage n'ayant qu'un occupant (la source), la garde de capacité du backend
 * (`occupations = 1`) passe, donc la mutualisation aboutit (200). Tout est retiré en `finally`
 * (DELETE de l'entrée de calendrier → cascade plan/version/bloc) : aucun résidu dans amateo_dev.
 */

const TITLE = `Mutualiser F3 e2e ${Date.now()}`;

/** La fenêtre de 5 jours (lun→ven) hors vacances, FUTURE : calculée depuis aujourd'hui (P4-353). */
async function futureClosureWindow(page: Page): Promise<DatedWindow> {
  const monday = await firstHolidayFreeMonday(page, 1);
  const friday = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + 4);
  return { start: isoLocal(monday), end: isoLocal(friday), monthLabel: frMonthLabel(monday) };
}

interface SlotRow {
  id: string;
  teamId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
}

/** Deux séances SOLO d'équipes distinctes, chacune seule sur sa case — reproduit `pickCleanSoloSlot`. */
function pickCleanSoloPair(slots: SlotRow[]): { source: SlotRow; joiner: SlotRow } {
  const perTeam = new Map<string, number>();
  const perCase = new Map<string, number>();
  const caseKey = (s: SlotRow): string => `${s.venueId}|${s.dayOfWeek}|${s.startTime}`;
  for (const s of slots) {
    perTeam.set(s.teamId, (perTeam.get(s.teamId) ?? 0) + 1);
    perCase.set(caseKey(s), (perCase.get(caseKey(s)) ?? 0) + 1);
  }
  const soloSlots = slots.filter((s) => 1 === perTeam.get(s.teamId) && 1 === perCase.get(caseKey(s)));
  const source = soloSlots[0];
  const joiner = soloSlots.find((s) => s.teamId !== source?.teamId && caseKey(s) !== caseKey(source));
  if (undefined === source || undefined === joiner) {
    throw new Error("décor non tenu : moins de deux séances SOLO exploitables dans le plan transcrit");
  }
  return { source, joiner };
}

async function createTranscribedPeriod(page: Page, win: DatedWindow): Promise<{ entryId: string; versionId: string; slots: SlotRow[] }> {
  const entry = await page.request.post("/api/calendar_entries", {
    headers: { "Content-Type": "application/json" },
    data: { kind: "period", periodType: "closure", title: TITLE, startDate: win.start, endDate: win.end },
  });
  expect(entry.ok(), `création de la fermeture jetable (HTTP ${entry.status()})`).toBeTruthy();
  const entryId = (await entry.json()).id as string;

  const plan = await page.request.post("/api/schedule_plans", {
    headers: { "Content-Type": "application/json" },
    data: { calendarEntryId: entryId },
  });
  expect(plan.ok(), `création du plan de période (HTTP ${plan.status()})`).toBeTruthy();
  const planId = (await plan.json()).id as string;

  const transcribed = await page.request.post(`/api/schedule_plans/${planId}/transcribe-from-socle`, {
    headers: { "Content-Type": "application/json" },
  });
  expect(transcribed.ok(), `transcription depuis le socle (HTTP ${transcribed.status()})`).toBeTruthy();
  const versionId = (await transcribed.json()).id as string;

  // La transcription garnit la V1 de façon synchrone mais passe par un statut : on attend COMPLETED.
  await expect
    .poll(
      async () => {
        const r = await page.request.get(`/api/schedules/${versionId}`);
        return r.ok() ? ((await r.json()).status as string) : `HTTP ${r.status()}`;
      },
      { timeout: 60_000, intervals: [500, 1000, 2000] },
    )
    .toBe("COMPLETED");

  const slotsResp = await page.request.get(`/api/schedule_slot_templates?scheduleId=${versionId}`);
  expect(slotsResp.ok(), `lecture des créneaux de la version (HTTP ${slotsResp.status()})`).toBeTruthy();
  const body = await slotsResp.json();
  const rows = (Array.isArray(body) ? body : (body.member ?? body["hydra:member"] ?? [])) as Record<string, unknown>[];
  const slots: SlotRow[] = rows
    .filter((r) => "string" === typeof r.teamId && null === (r.sharedTrainingBlockId ?? null))
    .map((r) => ({
      id: r.id as string,
      teamId: r.teamId as string,
      venueId: r.venueId as string,
      dayOfWeek: r.dayOfWeek as number,
      startTime: r.startTime as string,
    }));
  expect(slots.length, "la version transcrite doit porter des séances placées").toBeGreaterThan(1);

  return { entryId, versionId, slots };
}

async function teamNameById(page: Page, teamId: string): Promise<string> {
  const resp = await page.request.get(`/api/teams/${teamId}`);
  expect(resp.ok(), `lecture de l'équipe ${teamId} (HTTP ${resp.status()})`).toBeTruthy();
  return (await resp.json()).name as string;
}

/** Amène le calendrier du cockpit sur un mois donné (il s'ouvre sur le mois courant). */
async function goToMonth(page: Page, label: string): Promise<void> {
  const monthHeading = page.getByRole("heading", {
    level: 2,
    name: /^(janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre) \d{4}$/i,
  });
  for (let i = 0; i < 18; i += 1) {
    await expect(monthHeading).toBeVisible({ timeout: 20_000 });
    const current = (await monthHeading.textContent())?.trim();
    if (label === current) {
      return;
    }
    await page.getByRole("button", { name: "Mois suivant" }).click();
    await expect.poll(async () => (await monthHeading.textContent().catch(() => current))?.trim(), { timeout: 20_000 }).not.toBe(current);
  }
  await expect(monthHeading).toHaveText(label, { timeout: 10_000 });
}

test("lot 9 F3 — « Mutualiser… » est atteignable et actif sur un plan de période NON validé (wizard)", async ({ page }) => {
  test.setTimeout(240_000);
  await forceTheme(page, "light");
  const failed = watchFailedApiCalls(page);
  await page.setViewportSize({ width: 1280, height: 900 });

  await loginSeededClub(page);

  let entryId = "";
  try {
    // --- Décor déterministe (API) : une fermeture + sa V1 transcrite du socle, NON validée. ---
    //     Fenêtre FUTURE hors vacances, calculée depuis aujourd'hui (P4-353, plus de mois figé).
    const win = await futureClosureWindow(page);
    const built = await createTranscribedPeriod(page, win);
    entryId = built.entryId;
    const { source, joiner } = pickCleanSoloPair(built.slots);
    const joinerName = await teamNameById(page, joiner.teamId);

    // --- Parcours gestionnaire : cockpit → jour de la fermeture → « Ajuster » (plan non validé). ---
    await page.goto("/");
    await expect(page.getByRole("button", { name: /Tous les plannings/ })).toBeVisible({ timeout: 30_000 });
    await settleVeil(page);
    await goToMonth(page, win.monthLabel);
    const dayCell = page.getByRole("button", { name: new RegExp(TITLE) }).filter({ hasNotText: "passé" });
    await expect(dayCell.first(), "une case-jour couverte par la fermeture doit être cliquable").toBeVisible({ timeout: 30_000 });
    await dayCell.first().click();
    const dialog = page.getByRole("dialog");
    await expect(dialog).toBeVisible({ timeout: 15_000 });
    // Plan NON validé (pas de chosenScheduleId) → le geste proposé est « Ajuster », pas « Consulter ».
    const ajuster = dialog.getByRole("button", { name: "Ajuster" });
    await expect(ajuster, "un plan de période non validé s'AJUSTE (jamais seulement se consulte)").toBeVisible({ timeout: 10_000 });
    await ajuster.click();

    // --- Wizard en mode période → étape « Génération » → grille EMBARQUÉE du plan de période. ---
    await expect(page).toHaveURL(/\/wizard/, { timeout: 15_000 });
    await settleVeil(page);
    const railGenerate = page.locator("nav").getByRole("button", { name: /Génération/ });
    await expect(railGenerate).toBeEnabled({ timeout: 15_000 });
    await railGenerate.click();
    await settleVeil(page);

    // La grille embarquée rend la séance source comme carte cliquable (data-slot-id). Le geste
    // « Mutualiser… » y est offert (`onMutualize` fourni) ET readOnly faux (brouillon) → disponible.
    const sourceCard = page.locator(`[data-slot-id="${source.id}"]`);
    await expect(sourceCard, "la séance source doit être rendue dans la grille de période").toBeVisible({ timeout: 30_000 });
    await sourceCard.scrollIntoViewIfNeeded();
    await sourceCard.click();

    const mutualizeBtn = page.getByRole("button", { name: "Mutualiser…" });
    await expect(mutualizeBtn, "le bouton « Mutualiser… » doit être présent sur la fiche de séance").toBeVisible({ timeout: 10_000 });
    await expect(mutualizeBtn, "il doit être ACTIF (plan de période non validé)").toBeEnabled();

    // --- La fenêtre Mutualiser : rattacher la 2ᵉ équipe, nommer le groupe « U11 ». ---
    await mutualizeBtn.click();
    const mutualizeDialog = page.getByRole("dialog", { name: /Mutualiser des équipes sur ce créneau/ });
    await expect(mutualizeDialog).toBeVisible({ timeout: 10_000 });
    await mutualizeDialog.getByRole("button", { name: /Ajouter une équipe/ }).click();
    const listbox = page.getByRole("listbox");
    await expect(listbox).toBeVisible({ timeout: 10_000 });
    const search = page.getByPlaceholder("Rechercher");
    if (await search.isVisible().catch(() => false)) {
      await search.fill(joinerName);
    }
    await page.getByRole("option", { name: new RegExp(joinerName.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")) }).first().click();
    await expect(mutualizeDialog.getByText(joinerName)).toBeVisible();
    await mutualizeDialog.getByLabel(/Nom du groupe/).fill("U11");

    // --- Confirmer : le backend crée le bloc et la grille affiche le nom du groupe. ---
    await mutualizeDialog.getByRole("button", { name: "Mutualiser", exact: true }).click();
    await expect(mutualizeDialog, `la modale doit se fermer sur un succès${failed.length ? ` — échecs API: ${failed.join(", ")}` : ""}`).toBeHidden({ timeout: 30_000 });
    await expect(page.getByText(/Groupe « U11 »|U11/).first(), "le nom du groupe doit apparaître sur la grille").toBeVisible({ timeout: 20_000 });

    // --- #11 (améliorations légères 5/6) : à l'étape Équipes de la période, la sous-ligne d'équipe
    //     nomme le NOMBRE de séances mutualisées (« Mutualisée : 1 séance sur N avec … »), pas un
    //     simple « Mutualisée ». Seconde preuve (NAVIGATEUR) du point sensible mutualisation. ---
    const teamsStep = page.locator("nav").getByRole("button", { name: /Équipes/ });
    await expect(teamsStep, "le rail du wizard de période doit offrir l'étape Équipes").toBeVisible({ timeout: 15_000 });
    await teamsStep.click();
    await settleVeil(page);
    await expect(
      page.getByText(/Mutualisée\s*:\s*\d+ séances? sur \d+ avec/).first(),
      "la sous-ligne d'équipe doit afficher le nombre de séances mutualisées (#11)",
    ).toBeVisible({ timeout: 20_000 });
  } finally {
    if ("" !== entryId) {
      await page.request.delete(`/api/calendar_entries/${entryId}`);
    }
  }
});
