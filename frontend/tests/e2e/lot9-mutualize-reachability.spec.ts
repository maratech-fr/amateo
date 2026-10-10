import { expect, test, type Page } from "./fixtures";
import { forceTheme, loginSeededClub, settleVeil, watchFailedApiCalls } from "./support";

/**
 * **Lot 9 F3 — le bouton « Mutualiser… » EST atteignable par un vrai parcours (preuve bac à sable).**
 *
 * Le doute à lever (cadrage) : si la seule façon d'atteindre la grille d'un plan de PÉRIODE était un
 * overlay VALIDÉ (lecture seule, `/planning` via `viewOverlay`, gardé sur `chosenScheduleId`), le
 * bouton serait TOUJOURS désactivé → F3 inutilisable. Le vrai chemin d'un plan NON validé passe par
 * le wizard : cockpit → « Ajuster » (plan sans `chosenScheduleId`) → mode période → étape
 * « Génération » → grille EMBARQUÉE (`PlanningPage embedded scopePlanId=…`), où `isPeriodPlan` est
 * vrai ET `readOnly` faux. Ce trajet le prouve de bout en bout.
 *
 * Décor DÉTERMINISTE (patron `TrainingBlockContext::unPlanDePeriodeGenere`) : une fermeture jetable
 * + son plan, transcrits DEPUIS LE SOCLE (copie de la grille de saison, séances PLACÉES, sans
 * dépendre d'un solve frais). La source et la joiner sont deux équipes SOLO sur deux cases SOLO —
 * la case d'ancrage n'ayant qu'un occupant (la source), la garde de capacité du backend
 * (`occupations = 1`) passe, donc la mutualisation aboutit (200). Tout est retiré en `finally`
 * (DELETE de l'entrée de calendrier → cascade plan/version/bloc) : aucun résidu dans amateo_dev.
 */

const TITLE = `Mutualiser F3 e2e ${Date.now()}`;
// Fenêtre de 5 jours (lun→ven) SANS vacances scolaires, bien dans la saison 2026-2027 et loin des
// périodes du seed (octobre) : lun 7 → ven 11 décembre 2026 (Noël ne commence que le 19).
const START = "2026-12-07";
const END = "2026-12-11";
const MONTH_LABEL = "Décembre 2026";

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

async function createTranscribedPeriod(page: Page): Promise<{ entryId: string; versionId: string; slots: SlotRow[] }> {
  const entry = await page.request.post("/api/calendar_entries", {
    headers: { "Content-Type": "application/json" },
    data: { kind: "period", periodType: "closure", title: TITLE, startDate: START, endDate: END },
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
    const built = await createTranscribedPeriod(page);
    entryId = built.entryId;
    const { source, joiner } = pickCleanSoloPair(built.slots);
    const joinerName = await teamNameById(page, joiner.teamId);

    // --- Parcours gestionnaire : cockpit → jour de la fermeture → « Ajuster » (plan non validé). ---
    await page.goto("/");
    await expect(page.getByRole("button", { name: /Tous les plannings/ })).toBeVisible({ timeout: 30_000 });
    await settleVeil(page);
    await goToMonth(page, MONTH_LABEL);
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

    // La grille embarquée rend la séance source comme carte cliquable (data-slot-id). isPeriodPlan
    // y est vrai (version de période affichée) ET readOnly faux (brouillon) → geste disponible.
    const sourceCard = page.locator(`[data-slot-id="${source.id}"]`);
    await expect(sourceCard, "la séance source doit être rendue dans la grille de période").toBeVisible({ timeout: 30_000 });
    await sourceCard.scrollIntoViewIfNeeded();
    await sourceCard.click();

    const mutualizeBtn = page.getByRole("button", { name: "Mutualiser…" });
    await expect(mutualizeBtn, "le bouton « Mutualiser… » doit être présent sur la fiche de séance").toBeVisible({ timeout: 10_000 });
    await expect(mutualizeBtn, "il doit être ACTIF (plan de période non validé)").toBeEnabled();

    // CAPTURE 1 — la fiche d'une séance avec le bouton « Mutualiser… » actif.
    await page.screenshot({ path: "test-results/lot9-1-fiche-seance-bouton-mutualiser.png" });

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

    // CAPTURE 2 — la fenêtre Mutualiser remplie (2ᵉ équipe + nom « U11 »).
    await page.screenshot({ path: "test-results/lot9-2-fenetre-mutualiser-remplie.png" });

    // --- Confirmer : le backend crée le bloc et la grille affiche le nom du groupe. ---
    await mutualizeDialog.getByRole("button", { name: "Mutualiser", exact: true }).click();
    await expect(mutualizeDialog, `la modale doit se fermer sur un succès${failed.length ? ` — échecs API: ${failed.join(", ")}` : ""}`).toBeHidden({ timeout: 30_000 });
    await expect(page.getByText(/Groupe « U11 »|U11/).first(), "le nom du groupe doit apparaître sur la grille").toBeVisible({ timeout: 20_000 });

    // CAPTURE 3 — la grille après confirmation, avec le nom du groupe.
    await page.screenshot({ path: "test-results/lot9-3-grille-apres-mutualisation-nom-groupe.png" });
  } finally {
    if ("" !== entryId) {
      await page.request.delete(`/api/calendar_entries/${entryId}`);
    }
  }
});
