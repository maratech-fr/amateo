import { expect, test } from "./fixtures";

/** Seeded dev club (BasketballInit) — full data, but INCOMPLETE onboarding
 * (cockpit state 1: no plan generated yet). Matches are locked until the main
 * plan is validated, so this spec onboards the club first (idempotent). */
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

/**
 * Bring the seeded club to cockpit state 3 (main plan validated) so matches are
 * unlocked. Idempotent across runs (the dev DB is not reset): if the Matchs nav
 * is already an enabled link, the socle is validated → nothing to do. Otherwise
 * drive the wizard's génération step: launch a generation if needed, then
 * validate the resulting plan.
 */
async function ensureValidated(page: Page): Promise<void> {
  await page.goto("/");
  // State 3 → the Matchs nav is a real link. State 1/2 → a disabled span / redirect.
  // isVisible() does NOT wait (its timeout is ignored), so use waitFor to actually
  // give the SPA time to render before deciding the club still needs onboarding.
  const validated = await page
    .getByRole("link", { name: "Matchs" })
    .waitFor({ state: "visible", timeout: 5_000 })
    .then(() => true)
    .catch(() => false);
  if (validated) {
    return;
  }

  await page.goto("/wizard");
  await page.waitForLoadState("networkidle");

  // P4-71 — DIAGNOSTIQUER VITE, ne pas attendre 3 minutes dans le noir.
  //
  // Ce helper SUPPOSAIT l'atterrissage sur Récap (le wizard y mène quand toutes les
  // données sont là). Sur une base de dev qui a dérivé — une étape redevenue
  // incomplète au fil des tests du jour — `WizardLayout` ramène de force sur cette
  // étape (`jumpTo(gap)`), les deux boutons ci-dessous n'existent pas, et l'attente
  // de « Valider » expirait après 180 s sur un message qui n'apprenait rien.
  //
  // ⚠ Naviguer directement vers Génération ne marcherait PAS : le rail n'est ouvert
  // que pour un club ayant DÉJÀ une version finie (`guided` dans WizardLayout) — or
  // un club en état 1/2 n'en a par définition aucune. La bonne réponse n'est donc pas
  // de contourner l'atterrissage, mais de NOMMER la dérive qui l'a provoqué.
  // Deux mondes, et le helper n'en gérait AUCUN :
  //  - club SANS version terminée → wizard « guidé » : atterrissage sur le premier
  //    trou de données, rail VERROUILLÉ (on ne peut que suivre le flux) ;
  //  - club AVEC une version terminée → `guided` faux : aucun atterrissage, on reste
  //    sur l'étape par défaut du store (Équipes) mais le rail est OUVERT.
  // Mesuré le 2026-08-07 : le club seedé tombe dans le SECOND cas dès qu'un
  // la feature Behat de génération a tourné (2 plannings COMPLETED) — d'où l'échec, qui n'était donc
  // pas la « dérive de données » annoncée. On emprunte le rail quand il est ouvert.
  // ⚠ Le nom accessible du bouton d'étape n'est PAS son libellé : la pastille de
  // progression y entre (« 6 Génération »), et une étape terminée devient
  // « Génération — étape terminée ». Matcher la chaîne exacte — ou même son début —
  // ne trouve rien. On cherche donc le libellé N'IMPORTE OÙ dans le nom, scopé au
  // rail pour ne pas attraper un homonyme du contenu.
  const railGenerate = page.locator("nav").getByRole("button", { name: /Génération/ });
  if (await railGenerate.isEnabled({ timeout: 3_000 }).catch(() => false)) {
    await railGenerate.click();
  }

  const cont = page.getByRole("button", { name: "Continuer vers la génération" });
  const launch = page.getByRole("button", { name: "Lancer la génération" });
  const onGenerationPath = await Promise.race([
    cont.waitFor({ state: "visible", timeout: 10_000 }).then(() => true),
    launch.waitFor({ state: "visible", timeout: 10_000 }).then(() => true),
  ]).catch(() => false);

  if (!onGenerationPath) {
    // L'étape atteinte EST le diagnostic : le wizard nous a ramenés sur le premier
    // trou de données. On le lit dans le rail (l'entrée courante porte aria-current).
    const landed = await page
      .locator("[aria-current]")
      .first()
      .textContent()
      .then((t) => t?.trim() ?? "inconnue")
      .catch(() => "inconnue");
    throw new Error(
      `ensureValidated: le wizard a atterri sur « ${landed} » au lieu du chemin de génération. `
      + "La base de DEV a dérivé (une étape est redevenue incomplète — souvent un gymnase sans créneau, "
      + "laissé par un autre spec). Rejouez `make -C backend fixtures`, puis relancez. "
      + "En CI (base fraîche) ce cas ne se produit pas.",
    );
  }

  if (await cont.isVisible().catch(() => false)) {
    // ⚠ `isVisible()` rend VRAI pour un bouton désactivé — cliquer dessus attend
    // alors le timeout ENTIER du test (240 s), × 3 tentatives = 12,6 min de CI
    // pour un échec qui ne dit rien (mesuré sur #432). Même défaut que P4-71 avait
    // corrigé sur le bouton « Lancer » juste en dessous, laissé sur celui-ci.
    // On échoue en 5 s, en NOMMANT ce qu'un « Continuer » grisé veut dire : le
    // gate du récap refuse — une contrainte invalide, ou un bloqueur non résolu.
    if (!(await cont.isEnabled({ timeout: 5_000 }).catch(() => false))) {
      throw new Error(
        "ensureValidated: « Continuer vers la génération » est DÉSACTIVÉ. Le gate du récap refuse : "
        + "une contrainte du club est invalide (config hors liste blanche, SEC-13) ou un bloqueur "
        + "subsiste (réservation orpheline, sur-capacité). Ouvrir /wizard → Récap pour lire le motif, "
        + "ou auditer les contraintes du club seedé.",
      );
    }
    await cont.click();
  }
  // On ne LANCE une génération que s'il n'y a rien à valider : le club seedé a
  // souvent déjà des versions COMPLETED (la feature Behat de génération a tourné). Cliquer
  // « Lancer » dans ce cas attendait un bouton DÉSACTIVÉ jusqu'au timeout — le gate
  // pré-solve refuse à juste titre tant qu'un diagnostic d'erreur subsiste, alors
  // qu'une version parfaitement validable était déjà à l'écran.
  const validate = page.getByRole("button", { name: "Valider" });
  const alreadyGenerated = await validate.isVisible({ timeout: 3_000 }).catch(() => false);
  if (!alreadyGenerated && (await launch.isEnabled({ timeout: 5_000 }).catch(() => false))) {
    await launch.click();
  }
  // COMPLETED → le bouton « Valider » apparaît ; on valide via la confirmation.
  await expect(validate).toBeVisible({ timeout: 180_000 });
  await validate.click();
  const dialog = page.getByRole("dialog", { name: "Valider le planning" });
  await expect(dialog).toBeVisible();
  await dialog.getByRole("button", { name: "Valider", exact: true }).click();
  await expect(page.getByRole("link", { name: "Matchs" })).toBeVisible({ timeout: 15_000 });
}

/**
 * End-to-end of the placement loop (module matchs PR-3) under the real stack:
 * create a fixture manually → it lands in the "à placer" list → place it (venue +
 * kickoff) → it leaves the list. The conflict radar renders throughout.
 */
test("matches: create a fixture, place it, radar renders", async ({ page }) => {
  test.setTimeout(240_000); // onboarding runs a real CP-SAT generation
  await login(page);
  await ensureValidated(page);

  // A unique opponent so the assertions target THIS run's fixture (dev DB is not reset).
  const opponent = `E2E-${Date.now().toString(36).toUpperCase()}`;

  await page.getByRole("link", { name: "Matchs" }).click();
  await expect(page.getByRole("heading", { name: "Matchs" })).toBeVisible();

  // RMM-1 PR3 — l'écran est désormais une BOUCLE GUIDÉE : un rail de 5 vues (le
  // même geste que le wizard). Le radar vit dans la vue « Conflits » ; la liste
  // « à placer », le panneau et la grille dans « Domiciles posés ». On NAVIGUE
  // donc explicitement (les boutons du rail portent le libellé de l'étape). Le
  // radar reste un témoin : présent dans sa vue.
  await page.getByRole("button", { name: /Conflits/ }).click();
  // Le titre de la carte radar (un <h2>) — distinct du bouton « Conflits (n) » du rail.
  await expect(page.getByRole("heading", { name: /Conflits/, level: 2 })).toBeVisible();

  // Manual entry (« Nouveau match » est dans la barre du haut, dispo partout).
  await page.getByRole("button", { name: /Nouveau match/i }).click();
  await expect(page.getByRole("heading", { name: "Nouveau match" })).toBeVisible();
  await page.getByLabel("Équipe").selectOption({ index: 0 });
  await page.getByLabel("Date").fill("2027-03-06"); // a Saturday
  await page.getByLabel("Adversaire").fill(opponent);
  await page.getByRole("button", { name: "Créer" }).click();

  // La liste « à placer » + le panneau + la grille vivent dans la vue « Domiciles
  // posés » (rail⇄vue). On l'ouvre avant de manipuler le placement.
  await page.getByRole("button", { name: /Domiciles posés/ }).click();

  // The new home fixture shows in the to-do list; open its placement panel.
  // Names disambiguate the two buttons carrying the opponent: the to-do entry
  // reads « date · vs X », the grid cell (PR E1) reads « HH:MM · X ».
  const todo = page.getByRole("button", { name: new RegExp(`vs ${opponent}`) });
  await expect(todo).toBeVisible({ timeout: 15_000 });
  await todo.click();

  // Place it: pick a venue + kickoff. Whether "Placer" is enabled depends on the
  // team's league envelope (real seeded data) — assert BOTH real outcomes:
  //  - in-envelope (or unmapped) → placement succeeds → leaves the to-do list;
  //  - out-of-envelope → the HARD guard disables placement and warns.
  await page.getByLabel("Gymnase").selectOption({ index: 1 });
  await page.getByLabel("Heure de coup d'envoi").fill("15:00");
  // exact: the page also carries the "Placer automatiquement" solver button (PR D).
  const place = page.getByRole("button", { name: "Placer", exact: true });

  if (await place.isEnabled()) {
    await place.click();
    await expect(page.getByRole("button", { name: new RegExp(`vs ${opponent}`) })).toHaveCount(0, { timeout: 15_000 });

    // ── Manual loop (P1-4 PR E1): the placed match is a clickable grid cell. ──
    const cell = page.getByRole("button", { name: new RegExp(`\\d\\d:\\d\\d · ${opponent}`) });
    await expect(cell).toBeVisible({ timeout: 15_000 });
    await cell.click();

    // Lock round-trip: a manual placement is an anchor → hand it back to the
    // solver, the button flips to Verrouiller; lock it again, it flips back.
    await page.getByRole("button", { name: "Rendre au système" }).click();
    await expect(page.getByRole("button", { name: "Verrouiller" })).toBeVisible({ timeout: 15_000 });
    await page.getByRole("button", { name: "Verrouiller" }).click();
    await expect(page.getByRole("button", { name: "Rendre au système" })).toBeVisible({ timeout: 15_000 });

    // Dé-placer: the match leaves the grid and returns to the to-do list.
    await page.getByRole("button", { name: "Dé-placer" }).click();
    await expect(page.getByRole("button", { name: new RegExp(`vs ${opponent}`) })).toBeVisible({ timeout: 15_000 });
  } else {
    await expect(page.getByText(/Hors fenêtre autorisée/)).toBeVisible();
  }
});
