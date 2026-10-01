import { expect, type Page, test } from "./fixtures";

import { ensureValidated, forceTheme, landOnMatchesCalendar, loginSeededClub, settleVeil } from "./support";

/**
 * PR 8/8 de la série « uniformité des écrans » — CAPTURES DE RÉFÉRENCE (`toHaveScreenshot`).
 *
 * But : figer l'APPARENCE des écrans principaux retravaillés par la série (bandeaux, pastilles,
 * sélecteurs, hauteurs 36 px, deux-rangées…). La CI compare chaque écran à une image de référence
 * committée et ROUGIT sur tout changement d'apparence non voulu — le filet qui manquait aux sept
 * premières PR, où une régression visuelle passait tant qu'aucun test de contraste/structure ne la
 * touchait.
 *
 * ── DÉTERMINISME (sinon la référence pourrit) ────────────────────────────────────────────────────
 *  - **Thème clair FORCÉ** (`forceTheme` — pose `cs-theme` avant boot ET coupe transitions/animations).
 *  - **`animations: "disabled"`** sur chaque capture (Playwright fige en plus les animations CSS/Web).
 *  - **Horloge GELÉE aux DEUX bouts**, à l'instant canonique des features Behat (`2026-12-01T09:00`,
 *    même saison 2026-2027 que « aujourd'hui » réel → socle en vigueur, génération autorisée) :
 *    `page.clock.setFixedTime` (le `Date.now()` du navigateur : semaines affichées, dates relatives)
 *    ET `POST /api/dev/clock` (l'horloge SERVEUR en Redis, dev-only : résolution de saison, « à venir »).
 *    Le pin serveur est RELÂCHÉ en `finally` — `workers: 1`, un pin qui traîne fausserait les specs
 *    suivantes (a11y-contrast & co. supposent l'heure réelle).
 *  - **Widget `DevClock` MASQUÉ à la capture** (`mask`) + horloge serveur pinnée (valeur CONSTANTE
 *    « 01/12/2026 09:00 ») : c'est du chrome dev-only, dont le MONTAGE est racé (sa requête
 *    `["dev-clock"]` résout parfois avant/parfois après). On attend donc sa PRÉSENCE avant de capturer
 *    (header de composition stable) puis on l'exclut de la comparaison par `mask` — ses pixels ne pèsent
 *    jamais dans le diff, quelle que soit sa valeur.
 *  - **Viewport 1280×900** fixe (on capture le viewport, pas `fullPage` — hauteur stable).
 *  - **Écran POSÉ avant capture** (`settleScreen`) : voile levé (`settleVeil`) PUIS plus aucun spinner
 *    (`Loader2 .animate-spin`, `spinner.tsx`), PLUS un témoin de DONNÉE propre à l'écran (jamais la
 *    seule coquille : un titre d'étape / un onglet prouve le chrome, pas que les données sont arrivées —
 *    germe des faux verts « coach vide »/« spinner » de la 1ʳᵉ génération). On N'utilise PAS
 *    `networkidle` : le flux Mercure (SSE) garde une connexion ouverte, le réseau n'est jamais « idle ».
 *  - **`maxDiffPixelRatio: 0.01`** — motivé : environnement de génération et de comparaison IDENTIQUES
 *    (runner `ubuntu-latest` + `npx playwright install chromium` épinglé par le lock, dans les DEUX
 *    cas), donc le bruit attendu se limite à l'anti-crénelage sub-pixel. Un vrai changement de layout
 *    ou de couleur déplace bien plus de 1 % des pixels ; un seuil plus bas rougirait sur du bruit.
 *
 * ── RE-BASELINE (UNE procédure, UNE commande) ────────────────────────────────────────────────────
 * Les PNG de référence DOIVENT être produits dans l'environnement EXACT de la CI (même image/OS/polices),
 * sinon diff de rendu de polices. On ne les génère donc JAMAIS en local : un workflow dédié le fait.
 *   1. `gh workflow run visual-baselines.yml --ref <branche> -f update=true`
 *      (ou, sans accès dispatch : pousser un commit dont le message contient `[update-snapshots]`).
 *   2. `gh run download <run-id> -n visual-baselines` → récupérer le dossier
 *      `frontend/tests/e2e/visual-reference.spec.ts-snapshots/`.
 *   3. Committer les PNG, re-pousser : le job `e2e` (ci.yml) et le workflow en mode COMPARAISON
 *      valident alors les références. Le job `e2e` normal NE réécrit JAMAIS (il compare, pas de
 *      `--update-snapshots`) — voir `.github/workflows/visual-baselines.yml`.
 *
 * ── HORS PÉRIMÈTRE délibéré ───────────────────────────────────────────────────────────────────────
 * La page publique des vœux (`/doleances/:token`) : l'atteindre exige de forger un token de doléance
 * (coach + lien personnel), donnée qu'aucun seed CI ne garantit — écartée (« si atteignable sans base
 * réelle »). Le thème SOMBRE : une passe ultérieure si le besoin se confirme (coût × par capture).
 */
const PINNED_NOW = "2026-12-01T09:00:00Z";
const SHOT = { animations: "disabled" as const, maxDiffPixelRatio: 0.01 };

/** Le widget DevClock (dev-only) du header — ASCII (préfixe du titre « Horloge simulée (dev)… »). */
const devClock = (page: Page) => page.locator('[title^="Horloge simul"]');

/** Gèle l'horloge NAVIGATEUR (`Date.now()`) — à appeler AVANT toute navigation. */
async function freezeBrowser(page: Page): Promise<void> {
  await page.clock.setFixedTime(new Date(PINNED_NOW));
}

/**
 * Gèle aussi l'horloge SERVEUR (Redis, dev-only, sans expiry). ⚠ À appeler APRÈS le login : la route
 * `/api/dev/clock` exige le cookie JWT (un POST anonyme → 401, le pin ne prenait pas). Gèle la saison
 * et les gardes de date côté serveur — les écrans capturés sont de toute façon indépendants de la date
 * (récurrents / vides / gabarits), c'est une défense qui protège d'un futur élément daté qu'on raterait.
 */
async function freezeServer(page: Page): Promise<void> {
  await page.request.post("/api/dev/clock", { data: { at: PINNED_NOW } });
}

/** Relâche le pin serveur (retour à l'heure réelle) — impératif, les specs suivantes la supposent. */
async function releaseServerClock(page: Page): Promise<void> {
  await page.request.post("/api/dev/clock", { data: { at: null } }).catch(() => undefined);
}

/**
 * Écran AUTHENTIFIÉ posé : voile levé + plus aucun spinner résiduel + DevClock présent (le header reste
 * déterministe — on MASQUE le widget à la capture, sa boîte étant exclue de la comparaison). Le témoin de
 * DONNÉE propre à l'écran est vérifié par l'appelant.
 */
async function settleScreen(page: Page): Promise<void> {
  await settleVeil(page);
  await expect(page.locator(".animate-spin")).toHaveCount(0, { timeout: 30_000 });
  // DevClock présent : chaque `goto` recharge la page, le widget remonte après sa requête `dev/clock`.
  // On attend sa présence pour que le header ait toujours la même composition, puis on le masque.
  await expect(devClock(page)).toBeVisible({ timeout: 15_000 });
}

test("visual reference — écrans publics (clair)", async ({ page }) => {
  await forceTheme(page, "light");
  await freezeBrowser(page);
  await page.setViewportSize({ width: 1280, height: 900 });
  // Splash de connexion (absent au repos — on capture /login sans cliquer) masqué par sécurité.
  const shot = { ...SHOT, mask: [page.getByTestId("login-splash")] };

  // Login — écran d'entrée, capturé AU REPOS (pas de clic « Se connecter », donc pas de splash).
  await page.goto("/login");
  await expect(page.getByRole("button", { name: /se connecter/i })).toBeVisible();
  await expect(page).toHaveScreenshot("login.png", shot);

  // Register — l'écran des CHAMPS (étape 2, après le choix du sport) : dense en Input/Select partagés,
  // représentatif des hauteurs 36 px (PR 7/7).
  await page.goto("/register");
  await expect(page.getByRole("button", { name: /continuer/i })).toBeVisible();
  await page.getByRole("button", { name: /continuer/i }).click();
  await expect(page.getByRole("button", { name: /créer le compte/i })).toBeVisible();
  await expect(page).toHaveScreenshot("register-champs.png", shot);
});

test("visual reference — écrans authentifiés (clair)", async ({ page }) => {
  test.setTimeout(300_000); // le 1er run du club seedé peut conduire une génération CP-SAT réelle
  await forceTheme(page, "light");
  await freezeBrowser(page);
  await page.setViewportSize({ width: 1280, height: 900 });

  try {
    await loginSeededClub(page);
    await freezeServer(page); // APRÈS le login : la route exige le cookie JWT
    await ensureValidated(page); // débloque /planning et /matchs (socle validé)
    // Chaque capture authentifiée exclut le widget DevClock du diff (présence garantie par settleScreen).
    const authShot = { ...SHOT, mask: [devClock(page)] };

    // ── Planning (semaine) ───────────────────────────────────────────────────────────────────────
    // Témoin de donnée : une carte de séance RÉELLE (`WeekGrid` `data-slot-id`), posée par le plan.
    await page.goto("/planning");
    await settleScreen(page);
    await expect(page.locator("[data-slot-id]").first()).toBeVisible({ timeout: 30_000 });
    await expect(page).toHaveScreenshot("planning-semaine.png", authShot);

    // ── Matchs · Calendrier ──────────────────────────────────────────────────────────────────────
    // Le club seedé n'a AUCUNE rencontre (le seed pose des habitudes, pas de `Fixture`) : le Calendrier
    // rend sa coquille + l'état vide — déterministe, et représentatif du shell (en-tête, onglets,
    // filtres) retravaillé par la série. Témoin de donnée : l'état vide TERMINAL (après résolution de
    // la requête fixtures), pas la seule barre d'outils.
    await page.goto("/matchs");
    await landOnMatchesCalendar(page);
    await expect(page.getByText("Aucun match importé")).toBeVisible({ timeout: 30_000 });
    await settleScreen(page);
    await expect(page).toHaveScreenshot("matchs-calendrier.png", authShot);

    // ── Matchs · Semaine type ────────────────────────────────────────────────────────────────────
    // Gabarit (non daté). Témoin de donnée : la région n'est rendue QUE si ≥1 habitude est chargée
    // (`columns.length > 0`) — le seed en pose 32.
    await page.goto("/matchs/semaine-type");
    await settleScreen(page);
    await expect(page.getByRole("region", { name: "Grille de la semaine type" })).toBeVisible({ timeout: 30_000 });
    await expect(page).toHaveScreenshot("matchs-semaine-type.png", authShot);

    // ── Matchs · Contraintes (section Club ouverte) ──────────────────────────────────────────────
    // `?section=club` ouvre l'accordéon Club. La section rend un `FullPageSpinner` tant que ses données
    // chargent ; témoin de donnée : le sélecteur « Type » de la ligne d'ajout, propre à la section Club
    // chargée (les autres sections, fermées, ne rendent pas leurs champs).
    await page.goto("/matchs/contraintes?section=club");
    await settleScreen(page);
    await expect(page.getByLabel("Type", { exact: true })).toBeVisible({ timeout: 30_000 });
    await expect(page).toHaveScreenshot("matchs-contraintes-club.png", authShot);

    // ── Club ─────────────────────────────────────────────────────────────────────────────────────
    // Témoins de donnée : le `<h1>` (PageHeader), l'indice texte « Chargement des offres… » DISPARU, et
    // plus aucun spinner (la section « Demandes d'adhésion » en porte un le temps de sa requête).
    await page.goto("/club");
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText("Chargement des offres…")).toBeHidden({ timeout: 30_000 });
    await settleScreen(page);
    await expect(page).toHaveScreenshot("club.png", authShot);

    // ── Wizard · étape Coachs ────────────────────────────────────────────────────────────────────
    // Deep-link `?step=coaches` (club déjà généré → navigation ouverte). Témoin de donnée : l'état vide
    // « Aucun coach pour le moment. » a DISPARU (le seed a des coachs → la liste a remplacé l'indice).
    await page.goto("/wizard?step=coaches");
    await settleScreen(page);
    await expect(page.getByRole("heading", { name: /Étape 3\/6/ })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText("Aucun coach pour le moment.")).toBeHidden({ timeout: 30_000 });
    await expect(page).toHaveScreenshot("wizard-coachs.png", authShot);

    // ── Wizard · étape Contraintes ───────────────────────────────────────────────────────────────
    // Deep-link `?step=constraints`. Socle en vigueur → l'étape affiche son bandeau de verrouillage
    // (décision fondateur 2026-09-28) au-dessus du constructeur. La famille par défaut est « Horaires »
    // (`useState("TIME")`), peuplée par le seed (Groupe Adulte/Jeune/Senior…). Témoins : le titre d'étape
    // + l'en-tête de colonne « Cible » du tableau de contraintes (rendu dès qu'il y a ≥1 règle chargée ;
    // affiché « CIBLE » par `uppercase` CSS, mais le texte DOM est « Cible » — d'où le rôle columnheader).
    await page.goto("/wizard?step=constraints");
    await settleScreen(page);
    await expect(page.getByRole("heading", { name: /Étape 4\/6/ })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole("columnheader", { name: "Cible" })).toBeVisible({ timeout: 30_000 });
    await expect(page).toHaveScreenshot("wizard-contraintes.png", authShot);
  } finally {
    await releaseServerClock(page);
  }
});
