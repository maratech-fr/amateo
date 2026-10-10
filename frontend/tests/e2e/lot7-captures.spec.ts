import path from "node:path";
import { fileURLToPath } from "node:url";

import { test, expect, type APIRequestContext, type Page } from "@playwright/test";

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
// `package.json` est `"type": "module"` : `__dirname` n'existe pas (le spec ne se chargeait
// même pas). Patron maison : `import.meta.url` (cf. `support-admin.ts`).
const OUT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../../captures");

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
  // Script de CAPTURE pour le fondateur, pas un test de régression : il dépend d'un club seedé,
  // de l'horizon du cockpit et d'une carte « Doléances » — trop fragile pour gater la CI. Hors CI
  // seulement (il tourne en local sous with-sandbox). Le reste de l'e2e reste couvert normalement.
  test.skip(Boolean(process.env.CI), "script de capture — hors CI (lancé en local sous with-sandbox)");
  test.setTimeout(300_000);
  await forceTheme(page, "light");
  await page.setViewportSize({ width: 1280, height: 900 });
  await loginSeededClub(page);
  const api = page.request;

  const addDays = (iso: string, n: number): string => {
    const d = new Date(iso);
    d.setUTCDate(d.getUTCDate() + n);
    return d.toISOString().slice(0, 10);
  };
  const monday = nextMonday(7); // DANS l'horizon du radar (< 30 j) — sinon la carte est masquée.
  const endStr = addDays(monday, 13); // deux semaines pleines
  const week2Monday = addDays(monday, 7);
  const week2End = addDays(monday, 13);

  // ── Seed : vacances (2 semaines) + une semaine DÉTACHÉE (fait naître SON plan, rail 1 entrée =
  //    1 plan) + une doléance sur cette semaine. Une mère scindée affiche au cockpit sa carte avec
  //    le bouton « Doléances » (patron du flux Behat RepriseWeekContext). ───────────────────────
  let motherId: string | null = null;
  const teamId = await firstId(api, "/api/teams?itemsPerPage=1");
  const coachId = await firstId(api, "/api/coaches?itemsPerPage=1");
  try {
    const entry = await api.post("/api/calendar_entries", {
      data: { kind: "period", periodType: "holiday", title: "Vacances (captures lot 7)", startDate: monday, endDate: endStr },
    });
    motherId = entry.ok() ? ((await entry.json()) as { id: string }).id : null;

    if (motherId) {
      // Détacher la 2ᵉ semaine (enfant de la mère) : le provisioning fait naître son plan.
      await api.post("/api/calendar_entries", {
        data: { kind: "period", periodType: "holiday", title: "Semaine détachée (captures lot 7)", startDate: week2Monday, endDate: week2End, parentEntryId: motherId },
      });
      if (teamId && coachId) {
        await api.post("/api/coach_wishes", {
          data: { calendarEntryId: motherId, weekStart: week2Monday, teamId, coachId, slotsWanted: 3, unavailableDays: [4, 5], wishedDays: [1], comment: "Indispo jeu/ven", done: false },
        });
      }
    }

    // ── Capture 1/2 : la todo avec cases de sélection + bouton « Transférer » ────────────────────
    // Le cockpit (radar « À traiter ») vit à la racine `/` (CockpitPage), pas sur `/cockpit`.
    await page.goto("/");
    await settleVeil(page).catch(() => undefined);
    await page.waitForLoadState("networkidle").catch(() => undefined);
    const doleancesBtn = page.getByRole("button", { name: /Doléances/ }).first();
    if (await doleancesBtn.isVisible().catch(() => false)) {
      await doleancesBtn.click();
      // Le bouton « Transférer au planning (N) » vit désormais dans le PIED de la fenêtre (lot 7 PR 5,
      // retour fondateur 2026-10-11) : même sélecteur, il est juste en bas à droite.
      await expect(page.getByRole("button", { name: /Transférer au planning/ })).toBeVisible({ timeout: 15_000 });
      // ⚠ Attendre que les ÉQUIPES soient chargées avant la capture : sinon la liste rend « Équipe »
      // (nom de repli) le temps du chargement, et c'est ce qu'avait saisi la capture précédente. On
      // attend le commentaire déterministe de la doléance seedée ET la fin des requêtes réseau (teams).
      await expect(page.getByText("Indispo jeu/ven")).toBeVisible({ timeout: 15_000 });
      await page.waitForLoadState("networkidle").catch(() => undefined);
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
