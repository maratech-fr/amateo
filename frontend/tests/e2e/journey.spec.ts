import { expect, test } from "./fixtures";

import { registerAndVerify, uniqueAra } from "./support";

/**
 * THE end-to-end journey (audit P0.2, FRT-05): a fresh club walks the whole
 * wizard (team → venue + slot → coach → constraints → recap), launches a REAL
 * generation (CP-SAT solves the 1-team instance), sees the placed planning,
 * validates it, and lands on the unlocked cockpit. This is the promise of the
 * product exercised as a user.
 */
test("full journey: wizard → generation → validated planning → cockpit", async ({ page }) => {
  test.setTimeout(240_000); // includes a real solve (small instance, seconds)

  // --- Register a fresh club + verify by email → onboarding wizard.
  const ara = uniqueAra("E2EF");
  await registerAndVerify(page, { email: `journey-${ara}@e2e.fr`, ara, firstName: "Flo", lastName: "Journey", clubName: "E2E Journey Club" });
  await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });

  // --- Step 1 · team (2 sessions/week by default ; la CATÉGORIE se choisit).
  // Plus de catégorie par défaut depuis la revue #347 : `categories[0]` valait « Vétéran »
  // pour tous les clubs depuis que le catalogue est ordonné, et un club de jeunes y
  // classait toute sa saison. Le choix persiste d'un ajout au suivant.
  await page.getByLabel("Nom de l'équipe").fill("SM1");
  await page.getByLabel("Catégorie").selectOption({ label: "Senior" });
  await page.getByRole("button", { name: "Ajouter l'équipe" }).click();
  // ⏱ Budget explicite : la ligne apparaît après un POST puis un refetch — les 5 s par défaut
  // suffisent sur une machine calme, pas sur un runner chargé.
  //
  // ⚑ **Ce point a flaké en CI sur QUATRE PR (#684, #687, #689, #694) et la cause n'était NI le
  // sélecteur NI le budget** — deux diagnostics successifs ont été faux avant que le trace
  // Playwright tranche (2026-08-22). La vraie chaîne : pour un nouvel inscrit, le filigrane des
  // nouveautés se pose en silence (`POST /release-notes/seen`, WhatsNewModal) ~1,5 s après
  // l'arrivée sur le wizard — pile pendant ce `fill`. Cette mutation sans exemption `meta.veil`
  // faisait passer l'app `inert` à 0 ms (ActionVeil, voile invisible sous 250 ms) : les frappes
  // de `fill` étaient avalées, `selectOption` survivait (événement programmatique), et le clic
  // validait un nom VIDE. D'où la signature : échec ici sans AUCUN appel ≥ 400 attaché, nom
  // vide mais catégorie choisie sur la capture. Corrigé à la SOURCE (`meta: { veil: false }`
  // sur `useMarkReleaseNotesSeen` + test unitaire) — c'était un bug produit : un vrai
  // utilisateur perdait ses frappes pareil. Si ce point re-flake un jour, ne pas retoucher le
  // sélecteur : lire le trace (réseau + snapshots), c'est lui qui a parlé.
  await expect(page.locator('input[value="SM1"]')).toBeVisible({ timeout: 20_000 });
  await page.getByRole("button", { name: "Suivant" }).click();

  // --- Step 2 · venue + two weekly slots (2 sessions to place).
  await expect(page.getByRole("heading", { name: /Étape 2\/6/ })).toBeVisible();
  await page.getByLabel("Nom du gymnase").fill("Gymnase E2E");
  await page.getByRole("button", { name: "Ajouter un gymnase" }).click();
  // Created venue is auto-selected in the venue picker; the grid is open.
  await expect(page.getByLabel("Gymnase", { exact: true })).toHaveValue(/./);
  // P4-37 : la barre « À poser » dit enfin ce qu'on en fait — rien ne l'indiquait. Elle
  // ne vit qu'une fois un gymnase sélectionné, d'où sa place ICI et pas avant l'ajout.
  await expect(page.getByText(/cliquez la grille pour ajouter un créneau/i)).toBeVisible();
  // P4-37 (revue #349) — le mode SAISON pose et édite des créneaux par deux appels à
  // `slotPlacementError` qu'AUCUN test ne couvrait : il n'existe pas de VenuesStep.test.tsx
  // et le harnais y coûterait plus qu'il ne rapporte. On garde donc le geste ici, où le
  // parcours passe déjà. Le refus doit être VISIBLE et la grille rester intacte.
  await page.getByLabel("Durée à poser").selectOption("150");
  await page.getByRole("button", { name: "Lun 22:45", exact: true }).click();
  await expect(page.getByText(/finirait après minuit/i)).toBeVisible();
  await page.getByLabel("Durée à poser").selectOption("90");

  // Add two weekly slots (2 sessions to place) on the availability grid.
  await page.getByRole("button", { name: "Lun 18:00", exact: true }).click();
  await page.getByRole("button", { name: "Mer 18:00", exact: true }).click();
  await page.getByRole("button", { name: "Suivant" }).click();

  // --- Step 3 · coach.
  await expect(page.getByRole("heading", { name: /Étape 3\/6/ })).toBeVisible();
  await page.getByLabel("Prénom").fill("Coa");
  await page.getByLabel("Nom", { exact: true }).fill("Ch");
  await page.getByRole("button", { name: "Ajouter le coach" }).click();
  // Lot A: coach cards are read-only by default (name as text, edit on demand).
  await expect(page.getByText("Coa Ch", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Suivant" }).click();

  // --- Step 4 · constraints (none — skip).
  await expect(page.getByRole("heading", { name: /Étape 4\/6/ })).toBeVisible();
  await page.getByRole("button", { name: "Suivant" }).click();

  // --- Step 5 · recap → generation.
  await expect(page.getByRole("heading", { name: /Étape 5\/6/ })).toBeVisible();
  await page.getByRole("button", { name: "Continuer vers la génération" }).click();

  // --- Step 6 · launch a REAL generation and wait for the placed planning.
  await expect(page.getByRole("heading", { name: /Étape 6\/6/ })).toBeVisible();

  // P4-168 — TÉMOIN Mercure, volet (a) : armé AVANT le clic, il prouve qu'un flux SSE s'est
  // OUVERT (réponse `/.well-known/mercure`, `text/event-stream`, 200) sur le lancement — pas
  // que le hub est simplement joignable. Sans lui, un hub mort passerait par le repli polling
  // sans que rien ne le dise (D1 : hub éteint/muet = ÉCHEC, jamais un skip).
  const mercureStream = page
    .waitForResponse(
      (r) =>
        /\/\.well-known\/mercure/.test(r.url())
        && 200 === r.status()
        && (r.headers()["content-type"] ?? "").includes("text/event-stream"),
      { timeout: 15_000 },
    )
    .catch(() => null);
  await page.getByRole("button", { name: "Lancer la génération" }).click();
  expect(
    await mercureStream,
    "Mercure : aucun flux SSE ouvert après le lancement — hub absent ou bloqué (D1 : échec, pas skip)",
  ).not.toBeNull();

  // The embedded planning replaces the launcher once a schedule is COMPLETED.
  await expect(page.getByText("SM1").first()).toBeVisible({ timeout: 180_000 });

  // P4-168 — TÉMOIN Mercure, volet (b) : le planning est là. Le compteur d'événements SSE
  // (`data-schedule-stream-events`, exposé par ScheduleStreamWitness) prouve que la livraison
  // est passée par le FLUX et non par le repli polling. C'est le témoin ROBUSTE : le flux se
  // relâche dès la fin de génération, donc `data-schedule-stream` est déjà « disconnected » ici
  // (état lu pour enrichir le message), tandis que le compteur, monotone, survit. `events === 0`
  // ⇒ livré par polling, hub muet : ni jamais ouvert, ni ouvert-puis-coupé-avant-livraison
  // (les deux régressions D2 retombent sur un compteur à zéro).
  const witness = page.locator("[data-schedule-stream]").first();
  await expect(witness).toHaveCount(1);
  const streamState = await witness.getAttribute("data-schedule-stream");
  const eventsReceived = Number(await witness.getAttribute("data-schedule-stream-events"));
  expect(
    eventsReceived,
    `Planning livré SANS événement Mercure : le repli polling a masqué un hub muet (D2). État du flux à l'arrivée : ${streamState}, événements SSE reçus : ${eventsReceived}.`,
  ).toBeGreaterThanOrEqual(1);

  // --- Valider INSIDE the embedded wizard (generation step) — Valider is the
  // workspace's EXIT (2026-08-20, symétrie stricte Valider ↔ Rouvrir). The confirm
  // dialog (role=dialog "Valider le planning") always opens; confirm inside it.
  await page.getByRole("button", { name: "Valider" }).click();
  const dialog = page.getByRole("dialog", { name: "Valider le planning" });
  await expect(dialog).toBeVisible();
  await dialog.getByRole("button", { name: "Valider", exact: true }).click();

  // --- Success LANDS on /planning, the screen of the version IN FORCE. The URL
  // assertion is the falsifiable one: drop the navigation and the test goes red.
  // « Rouvrir visible » alone would be a FALSE GREEN — it shows in both screens.
  await expect(page).toHaveURL(/\/planning/, { timeout: 15_000 });

  // --- Consultation contract on /planning: the just-validated planning is displayed
  // (SM1 still on the grid), the status badge is visible, AND the version selector is
  // ABSENT — that absence is what proves we left the embedded workspace for /planning
  // (the selector is a work gesture, embedded-only), not merely that a button flipped.
  await expect(page.getByText("SM1").first()).toBeVisible();
  await expect(page.getByText("Terminé").first()).toBeVisible();
  await expect(page.getByRole("combobox", { name: /version du planning/i })).toHaveCount(0);
  const reopen = page.getByRole("button", { name: "Rouvrir" });
  await expect(reopen).toBeVisible();

  // --- Rouvrir closes the cycle: back to the wizard's generation step, the planning
  // editable again (« Valider » is offered once more, the selector is back). Thus
  // valider → consulter → rouvrir is proved end to end.
  await reopen.click();
  await expect(page).toHaveURL(/\/wizard/, { timeout: 15_000 });
  await expect(page.getByRole("heading", { name: /Étape 6\/6/ })).toBeVisible();
  await expect(page.getByText("SM1").first()).toBeVisible({ timeout: 15_000 });
  await expect(page.getByRole("button", { name: "Valider" })).toBeVisible({ timeout: 15_000 });

  // --- The home now opens on the temporal cockpit (month calendar), not the
  // work-loop gate: the month navigation is the cockpit's stable marker.
  await page.goto("/");
  await expect(page.getByRole("button", { name: "Mois suivant" })).toBeVisible({ timeout: 15_000 });
});
