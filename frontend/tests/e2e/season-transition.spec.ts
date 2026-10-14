import { expect, test } from "./fixtures";

import { registerAndVerify, uniqueAra } from "./support";

async function registerClub(page: import("@playwright/test").Page): Promise<void> {
  const ara = uniqueAra("E2ES");
  await registerAndVerify(page, { email: `season-${ara}@e2e.fr`, ara, firstName: "Sonia", lastName: "Saison", clubName: "E2E Saison Club" });
}

// P1-5 — la bascule est réservée aux saisons PAYÉES : le relais dev du geste
// support (`/api/dev/mark-season-paid`, 404 en prod) ouvre le gate pour le club
// fraîchement inscrit. Sans lui, chaque « Préparer » rendrait le 409 du gate.
async function markNextSeasonPaid(page: import("@playwright/test").Page): Promise<void> {
  // SEC-16 : plus de jeton à lire — `page.request` partage les cookies du contexte,
  // donc l'appel est authentifié par le cookie httpOnly, sans en-tête.
  const response = await page.request.post("/api/dev/mark-season-paid");
  expect(response.ok()).toBeTruthy();
}

// « Préparer la saison suivante » n'est proposé que du 1er mai (année 2) à la fin de
// la saison courante (P2-5 D). On fige l'horloge NAVIGATEUR au 1er juin de l'année 2
// de la saison courante (dérivée du pivot 15 juil, comme côté serveur) pour être
// DANS la fenêtre — le serveur, lui, garde sa vraie date (le flux de transition est
// indépendant de l'horloge client).
test.beforeEach(async ({ page }) => {
  const now = new Date();
  const pivotPassed = now.getMonth() > 6 || (6 === now.getMonth() && now.getDate() >= 15);
  const seasonYear = pivotPassed ? now.getFullYear() : now.getFullYear() - 1;
  await page.clock.setFixedTime(new Date(Date.UTC(seasonYear + 1, 5, 1, 12, 0, 0)));
});

/**
 * **La transition de saison, de bout en bout (parcours « transition-de-saison » gardé, fusion 4→1).**
 *
 * Un seul enchaînement rejoue le geste réel : un club neuf n'a qu'UNE saison → la bascule est
 * REFUSÉE tant que N+1 n'est pas réglée → une fois payée, « Préparer » copie la structure en
 * brouillon ET propose de reconduire les événements de N (+364 j) → re-préparer retombe sur le
 * 409 « existe déjà » SANS rouvrir l'étape de reconduction, et il n'y a JAMAIS qu'UN brouillon.
 *
 * Fusionne les quatre ex-tests (état initial · refus impayé/liste des deux · reconduction · anti-
 * doublon) : chaque promesse est gardée, le décor (inscription + horloge dans la fenêtre) n'est
 * monté qu'une fois. Les deux `waitForTimeout(1500)` d'attente molle ont été remplacés par des
 * témoins déterministes (le toast du 409, puis l'absence de l'étape de reconduction).
 */
test("la transition de saison : refus impayé → payer → préparer + reconduire → re-préparer (409), un seul brouillon", async ({ page }) => {
  // La fusion 4→1 enchaîne une inscription (polling Mailpit ~15 s) PUIS quatre tranches de parcours :
  // le budget PAR DÉFAUT de 30 s que chaque ancien test tenait seul ne suffit plus (pas de solve ici,
  // d'où 120 s et non les 300 s de journey). Confirmé en CI #1179 : timeout à 31 s sur la 1ʳᵉ tranche.
  test.setTimeout(120_000);
  await registerClub(page);

  const selectorTrigger = page.getByRole("button", { name: "Saison de travail" });
  await expect(selectorTrigger).toBeVisible({ timeout: 15_000 });

  // Un événement de club dans N (API) — la matière de la reconduction. Le calendrier du cockpit est
  // gaté sur un socle validé (hors périmètre) : on crée via l'API, authentifié par le cookie partagé.
  const created = await page.request.post("/api/calendar_entries", {
    headers: { "Content-Type": "application/ld+json" },
    data: { kind: "event", title: "AG e2e", startDate: "2026-10-03", endDate: "2026-10-03", isDisruptive: true, status: "active" },
  });
  expect(created.status()).toBe(201);

  const openMenu = async (): Promise<void> => {
    await selectorTrigger.click();
  };
  const attemptPrepare = async (): Promise<void> => {
    await openMenu();
    await page.getByRole("menuitem", { name: /Préparer la saison suivante/i }).click();
    await expect(page.getByText("Préparer la saison suivante ?")).toBeVisible();
    await page.getByRole("button", { name: "Préparer", exact: true }).click();
  };
  const reconduireHeading = page.getByRole("heading", { name: "Reconduire les événements" });

  // --- État initial : exactement UNE saison « en cours », aucun brouillon, et « Préparer » offert
  //     (horloge figée dans la fenêtre par le beforeEach).
  await openMenu();
  await expect(page.getByRole("menuitem", { name: /· en cours/i })).toHaveCount(1);
  await expect(page.getByRole("menuitem", { name: /· brouillon/i })).toHaveCount(0);
  await expect(page.getByRole("menuitem", { name: /Préparer la saison suivante/i })).toBeVisible();

  // --- Refus impayé : le motif s'affiche TEL QUEL (P1-5), pas le toast générique « a échoué ».
  await attemptPrepare();
  await expect(page.getByText(/n'est pas réglée/i)).toBeVisible({ timeout: 15_000 });

  // --- Paiement enregistré (relais dev du geste support) → la bascule passe.
  await markNextSeasonPaid(page);
  await attemptPrepare();
  await expect(page.getByText(/structure copiée/i)).toBeVisible({ timeout: 15_000 });

  // --- Reconduction : l'étape s'ouvre avec l'événement de N, date décalée au même jour +1 an.
  await expect(reconduireHeading).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText("AG e2e")).toBeVisible();
  await expect(page.getByLabel("Nouvelle date de début de AG e2e")).toHaveValue("2027-10-02");
  await page.getByRole("button", { name: /Reconduire 1 événement/ }).click();
  await expect(page.getByText(/reconduit/)).toBeVisible({ timeout: 15_000 });
  // L'étape se referme sur le succès — témoin déterministe (remplace un waitForTimeout mou).
  await expect(reconduireHeading).toHaveCount(0);

  // --- Les deux saisons sont listées, exactement UN brouillon.
  await openMenu();
  await expect(page.getByRole("menuitem", { name: /· en cours/i })).toBeVisible();
  await expect(page.getByRole("menuitem", { name: /· brouillon/i })).toHaveCount(1);
  await page.keyboard.press("Escape");

  // --- Re-préparer : 409 « existe déjà », et l'étape de reconduction NE rouvre PAS (le brouillon a
  //     déjà des événements). Le toast du 409 est le témoin déterministe qui remplace waitForTimeout.
  await attemptPrepare();
  await expect(page.getByText(/existe déjà/i)).toBeVisible({ timeout: 15_000 });
  await expect(reconduireHeading).toHaveCount(0);

  // --- Anti-doublon : toujours exactement UN brouillon après la seconde tentative.
  await openMenu();
  await expect(page.getByRole("menuitem", { name: /· brouillon/i })).toHaveCount(1);
});

