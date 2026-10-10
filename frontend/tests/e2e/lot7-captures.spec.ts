import { test, expect, type APIRequestContext, type Page } from "@playwright/test";
import path from "node:path";

import { forceTheme, loginSeededClub, settleVeil } from "./support";

/**
 * P2-63 lot 7 PR 5 — SCRIPT DE CAPTURE (pas un test de régression) pour montrer au fondateur
 * l'interaction « Transférer au planning » (D-b) et l'avertissement « un autre coach a déjà
 * répondu » (option A). Sauvegarde des PNG dans `<repo>/captures/lot7-*.png`.
 *
 * ⚠ À LANCER SUR LE DÉPÔT PRINCIPAL, SOUS with-sandbox (stack + dev lancés) — JAMAIS depuis un
 * worktree (le :8081 sert un dist cuit ; les cibles Docker montent le dépôt principal). Commande :
 *   backend/scripts/with-sandbox.sh sh -c 'cd frontend && npx playwright test tests/e2e/lot7-captures.spec.ts --project=chromium'
 * (ou la cible e2e du projet, en ne ciblant QUE ce fichier). Non validé depuis un worktree : les
 * sélecteurs peuvent demander un ajustement à l'exécution.
 *
 * Best-effort : chaque capture est encadrée pour qu'un échec n'empêche pas les suivantes, et les
 * PNG partiels restent utiles. Le script SEEDE ses données via l'API (cookie JWT du club seedé)
 * puis nettoie la période créée en fin de run.
 */
const OUT = path.resolve(__dirname, "../../../captures");

const shot = (page: Page, name: string) => page.screenshot({ path: path.join(OUT, name), animations: "disabled" });

/** Un lundi futur (dans l'horizon du cockpit) pour la période de captures. */
function nextMonday(offsetDays: number): string {
  const d = new Date();
  d.setUTCDate(d.getUTCDate() + offsetDays);
  while (0 !== d.getUTCDay() % 7 && 1 !== d.getUTCDay()) {
    d.setUTCDate(d.getUTCDate() + 1);
  }
  // normalise au lundi
  while (1 !== d.getUTCDay()) {
    d.setUTCDate(d.getUTCDate() + 1);
  }
  return d.toISOString().slice(0, 10);
}

async function firstId(api: APIRequestContext, path_: string): Promise<string | null> {
  const res = await api.get(path_);
  if (!res.ok()) {
    return null;
  }
  const json = (await res.json()) as { member?: { id: string }[] } & { id?: string }[];
  const members = (json as { member?: { id: string }[] }).member ?? (json as unknown as { id: string }[]);
  return Array.isArray(members) && members[0]?.id ? members[0].id : null;
}

test("lot7 — captures du transfert et de l'avertissement", async ({ page }) => {
  test.setTimeout(300_000);
  await forceTheme(page, "light");
  await page.setViewportSize({ width: 1280, height: 900 });
  await loginSeededClub(page);
  const api = page.request;

  const monday = nextMonday(35);
  const end = new Date(monday);
  end.setUTCDate(end.getUTCDate() + 13);
  const endStr = end.toISOString().slice(0, 10);

  // ── Seed : période de vacances + planning + campagne + deux doléances ─────────────────────────
  let motherId: string | null = null;
  const teamId = await firstId(api, "/api/teams?itemsPerPage=1");
  const coachId = await firstId(api, "/api/coaches?itemsPerPage=1");
  try {
    const entry = await api.post("/api/calendar_entries", {
      data: { kind: "period", periodType: "holiday", title: "Vacances (captures lot 7)", startDate: monday, endDate: endStr },
    });
    motherId = entry.ok() ? ((await entry.json()) as { id: string }).id : null;

    if (motherId) {
      await api.post("/api/schedule_plans", { data: { calendarEntryId: motherId } });
      if (teamId && coachId) {
        await api.post("/api/coach_wishes", {
          data: { calendarEntryId: motherId, weekStart: monday, teamId, coachId, slotsWanted: 3, unavailableDays: [4, 5], wishedDays: [1], comment: "Indispo jeu/ven", done: false },
        });
      }
    }

    // ── Capture 1/2 : la todo avec cases de sélection + bouton « Transférer » ────────────────────
    await page.goto("/cockpit").catch(() => page.goto("/"));
    await settleVeil(page).catch(() => undefined);
    const doleancesBtn = page.getByRole("button", { name: /Doléances/ }).first();
    if (await doleancesBtn.isVisible().catch(() => false)) {
      await doleancesBtn.click();
      await expect(page.getByRole("button", { name: /Transférer au planning/ })).toBeVisible({ timeout: 15_000 });
      await shot(page, "lot7-transfert-liste.png");

      // ── Capture 2/2 : le récap après transfert (toast) ─────────────────────────────────────────
      await page.getByRole("button", { name: /Transférer au planning/ }).click();
      await page.waitForTimeout(1500); // laisser le toast de récap s'afficher
      await shot(page, "lot7-transfert-recap.png");
    } else {
      // Repli : la carte Doléances n'a pas été trouvée dans l'horizon — capturer le cockpit tel quel.
      await shot(page, "lot7-cockpit-fallback.png");
    }
  } finally {
    if (motherId) {
      await api.delete(`/api/calendar_entries/${motherId}`).catch(() => undefined);
    }
  }
});
