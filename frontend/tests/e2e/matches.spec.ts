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

  // PR 3b — l'écran est désormais le CALENDRIER unique : plus de rail, la liste
  // « à placer », le panneau, la grille, la bande extérieur ET le radar sont sur le
  // même établi de semaine. La barre « Semaine affichée » porte les trois compteurs.
  await expect(page.getByRole("group", { name: "Semaine affichée" })).toBeVisible();

  // Lot A — les rencontres créées plus bas sont des AMICAUX, et les extérieurs sont
  // masqués par défaut : on coche « Amical » ET on allume « Extérieurs » AVANT tout hop
  // de semaine (une semaine 100 % masquée sort des flèches de navigation).
  await page.getByRole("button", { name: "Amical", exact: true }).click();
  const exterieursSwitch = page.getByRole("switch", { name: "Extérieurs" });
  if ("false" === (await exterieursSwitch.getAttribute("aria-checked"))) {
    await exterieursSwitch.click();
  }
  await expect(exterieursSwitch).toHaveAttribute("aria-checked", "true");

  // Manual entry (« Nouveau match » est dans la barre d'actions, dispo partout).
  await page.getByRole("button", { name: /Nouveau match/i }).click();
  await expect(page.getByRole("heading", { name: "Nouveau match" })).toBeVisible();
  // « Équipe » est une listbox APG (P4-164) : on ouvre le trigger puis on choisit la 1re équipe.
  // Scopé au dialogue « Nouveau match » : depuis PR-1, la barre de filtres porte aussi une
  // puce « Équipes : … » qui matcherait /^Équipe/ hors de ce contexte.
  await page.getByRole("dialog").getByRole("button", { name: /^Équipe/ }).click();
  // Scoper AU listbox : depuis PR #897 son panneau est porté sous <body> (position: fixed), APRÈS
  // les <select> natifs du dialogue — `page.getByRole("option")` ramasserait sinon une <option>
  // native invisible (waiting for element to be visible → timeout).
  await page.getByRole("listbox").getByRole("option").first().click();
  await page.getByLabel("Date").fill("2027-03-06"); // a Saturday
  await page.getByLabel("Adversaire").fill(opponent);
  await page.getByRole("button", { name: "Créer" }).click();
  // La création est asynchrone : attendre la fermeture AVANT d'en rouvrir une (sinon
  // « Nouveau match » rouvrirait le dialogue DÉJÀ ouvert et on éditerait ses champs).
  await expect(page.getByRole("heading", { name: "Nouveau match" })).toBeHidden({ timeout: 15_000 });

  // ── Deux rencontres EXTÉRIEURES sans heure, un MERCREDI (jour 3) ──────────────
  //    Le seed ne pose d'habitudes de match que les jours 6/7 (BcclSeeder.php:1407-1424),
  //    donc AUCUNE équipe n'a d'habitude un mercredi : chaque extérieur sans heure émet
  //    un AWAY_NO_FOOTPRINT (gravité 7, MatchConflictDetector.php:590-609 ; filtré sur
  //    matchDate >= aujourd'hui — 2027 est futur —, JAMAIS sur le statut : un extérieur né
  //    REVIEWED compte). Conflit DÉTERMINISTE qui peuple l'onglet Conflits sur une base
  //    FRAÎCHE (CI), là où le radar seul ne prouvait rien (le club n'a aucun conflit).
  //    DEUX week-ends distincts (10→13-14 mars, weekendKeyOf ramène au samedi ; 17→20-21
  //    mars) pour que le pivot Journée ait ≥2 entrées : avec UNE seule, `openKey` la force
  //    ouverte (ConflictsPage.tsx:156) et le clic EFFACE `?ouvert` — le deep-link goBack
  //    (:301) ne tiendrait plus. Un AWAY n'apparaît PAS dans « à placer » (UnplacedList :
  //    HOME uniquement) NI dans une case GYMNASE de la grille ; depuis le lot 3 PR-3a il
  //    apparaît dans la colonne « Extérieur » de la grille, écrit « à <adv> », jamais
  //    « vs <adv> » → l'assertion `vs ${opponent}` (:229) ne le ramasse pas. La colonne
  //    « Extérieur » est vérifiée plus bas (en-tête + bloc data-away « heure inconnue »).
  for (const [date, suffix] of [["2027-03-10", "EXT"], ["2027-03-17", "EXT2"]] as const) {
    await page.getByRole("button", { name: /Nouveau match/i }).click();
    await expect(page.getByRole("heading", { name: "Nouveau match" })).toBeVisible();
    await page.getByRole("dialog").getByRole("button", { name: /^Équipe/ }).click();
    await page.getByRole("listbox").getByRole("option").first().click();
    await page.getByLabel("Domicile ou extérieur").selectOption("AWAY");
    await page.getByLabel("Date").fill(date); // un mercredi — aucune habitude ce jour-là
    await page.getByLabel("Adversaire").fill(`${opponent}-${suffix}`);
    await page.getByRole("button", { name: "Créer" }).click();
    await expect(page.getByRole("heading", { name: "Nouveau match" })).toBeHidden({ timeout: 15_000 });
  }

  // PR 3b — la liste « à placer », le panneau et la grille sont TOUJOURS visibles
  // sur le Calendrier (plus de vue à ouvrir).

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
  // Le picker de gymnase est le Listbox partagé (P4-164 PR-2) : ouvrir, sauter le placeholder
  // « Gymnase… » (option 0), choisir le premier gymnase réel (option 1).
  await page.locator('button[aria-haspopup="listbox"]').first().click();
  // Scopé AU listbox (même piège que ci-dessus : panneau porté sous <body>). L'option 0 est le
  // placeholder « Gymnase… » (une vraie option du listbox, value ""), donc .nth(1) = 1er gymnase réel.
  await page.getByRole("listbox").getByRole("option").nth(1).click();
  await page.getByLabel("Heure de coup d'envoi").fill("15:00");
  // exact: the page also carries the "Placer automatiquement" solver button (PR D).
  const place = page.getByRole("button", { name: "Placer", exact: true });

  if (await place.isEnabled()) {
    await place.click();
    await expect(page.getByRole("button", { name: new RegExp(`vs ${opponent}`) })).toHaveCount(0, { timeout: 15_000 });

    // ── Manual loop (P1-4 PR E1): the placed match is a clickable grid cell. ──
    // ⚠ La grille ne montre qu'UNE semaine : celle du premier week-end À VENIR qui
    // porte des rencontres. Notre match est daté LOIN (6 mars 2027) pour ne heurter
    // aucune donnée réelle — sur une base qui porte de vraies rencontres (le bac à
    // sable), la grille atterrit donc des mois AVANT lui. On avance jusqu'à SA
    // semaine (bornes : seules les semaines qui portent des rencontres sont listées).
    const cell = page.getByRole("button", { name: new RegExp(`\\d\\d:\\d\\d · ${opponent}`) });
    const nextWeek = page.getByRole("button", { name: "Semaine suivante" });
    for (let hops = 0; hops < 40 && !(await cell.isVisible()) && (await nextWeek.isEnabled()); hops += 1) {
      await nextWeek.click();
      await page.waitForTimeout(150);
    }
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

  // ── lot 3 PR-3a — les EXTÉRIEURS dans la grille (colonne « Extérieur ») ───────
  // Les deux -EXT/-EXT2 créés plus haut (mercredis SANS habitude → heure inconnue)
  // apparaissent dans la colonne « Extérieur » de la grille, PAS dans une case
  // gymnase. On hop jusqu'à la semaine qui porte le premier extérieur (base réelle :
  // il est daté LOIN, mars 2027). Tous les locators sont scopés au conteneur grille
  // (`data-testid="weekend-grid"`) pour ne rien ramasser dans la bande AwayList sœur.
  const gridRoot = page.getByTestId("weekend-grid");
  const awayExtBlock = gridRoot.getByRole("button", { name: new RegExp(`à ${opponent}-EXT\\b`) });
  const nextWeekAway = page.getByRole("button", { name: "Semaine suivante" });
  for (let hops = 0; hops < 60 && !(await awayExtBlock.isVisible()) && (await nextWeekAway.isEnabled()); hops += 1) {
    await nextWeekAway.click();
    await page.waitForTimeout(120);
  }
  await expect(
    awayExtBlock,
    "colonne « Extérieur » : le bloc -EXT est introuvable sur les semaines à venir (l'extérieur n'entre pas dans la grille)",
  ).toBeVisible({ timeout: 15_000 });
  // En-tête « Extérieur » présent dans le conteneur grille.
  await expect(gridRoot.getByText("Extérieur")).toBeVisible();
  // Le bloc porte data-away ET dit « heure inconnue » (gouttière « h ? » — mercredi sans habitude).
  await expect(awayExtBlock).toHaveAttribute("data-away", "true");
  await expect(awayExtBlock).toHaveAccessibleName(/heure inconnue/);
  // Témoin : l'AWAY n'est JAMAIS une case gymnase (« vs <adv> ») — il dit « à <adv> » ;
  // l'ancien « NI sur la grille » est donc faux, mais `vs ${opponent}` reste à 0.
  await expect(gridRoot.getByRole("button", { name: new RegExp(`vs ${opponent}-EXT`) })).toHaveCount(0);
  // Témoin de vacuité : la colonne « Extérieur » n'existe QUE le jour qui porte un
  // extérieur (le mercredi) — un seul en-tête « Extérieur » dans cette semaine.
  await expect(gridRoot.getByText("Extérieur")).toHaveCount(1);

  // ── Onglet « Conflits » (PR A) : /matchs/conflits. On ÉTEND ce scénario plutôt
  //    que d'ouvrir un 2e test : il réutilise la saison DÉJÀ générée+validée ici
  //    (un test séparé re-paierait l'onboarding de 240 s et, faute d'autre source
  //    de conflit, risquerait un écran vide légitimement RED). Le témoin ci-dessous
  //    fait ÉCHOUER un écran vide EN LE DISANT (règle « un e2e vert sans rien
  //    éprouver est un faux vert », cf. modal-reachability.spec.ts).
  //
  // ⚠ Le lien de nav « Conflits » (role=link, scopé au <nav> « Espaces matchs »)
  //    est distinct du bouton du rail « Conflits (n) » de Placer (role=button).
  const matchesNav = page.getByRole("navigation", { name: "Espaces matchs" });
  await matchesNav.getByRole("link", { name: "Conflits" }).click();

  // Témoin : au moins une entrée d'accordéon « <libellé> · <n> » (n ≥ 1 par
  // construction — une entrée à 0 conflit n'existe pas). Un écran vide rend
  // l'EmptyState « Aucun conflit sur la saison » et AUCUN accordéon : ce
  // `toBeVisible` tombe alors, en nommant ce que le vide signifie.
  const anyEntry = page.getByRole("button", { name: /·\s*\d+$/ }).first();
  await expect(
    anyEntry,
    "onglet Conflits vide : aucune entrée d'accordéon — le club n'a aucun conflit, le test ne prouverait rien",
  ).toBeVisible({ timeout: 15_000 });
  const anyEntryName = (await anyEntry.textContent()) ?? "";
  const anyEntryCount = Number(anyEntryName.match(/·\s*(\d+)\s*$/)?.[1] ?? "0");
  expect(anyEntryCount, `entrée « ${anyEntryName.trim()} » à 0 conflit — témoin vide`).toBeGreaterThan(0);

  // Témoin PLUS précis : au pivot Coach, nos AWAY_NO_FOOTPRINT (sans coachId,
  // conflictPivot.ts:104-105) tombent dans la sentinelle « Autres conflits ». Gravité 7
  // = groupe REPLIÉ « Angles morts » (diagnostic.ts:28,57 ; ConflictLine.tsx:184-208 —
  // libellé DISTINCT de la chip famille « Extérieur sans heure », conflictLabels.ts:25).
  // ⚠ « Angles morts » ne se rend QUE dans une entrée OUVERTE : on ouvre d'abord l'entrée
  // « Autres conflits · n » (forcée ouverte si elle est seule — ConflictsPage.tsx:156 —,
  // sinon fermée sur une base qui porte d'autres conflits). On la déplie si besoin, puis
  // on LIT la phrase AWAY_NO_FOOTPRINT (ConflictLine.tsx:84-86) : preuve que le conflit
  // attendu est bien là, pas juste « une entrée quelconque ».
  const autresEntry = page.getByRole("button", { name: /^Autres conflits ·/ });
  if ("false" === (await autresEntry.getAttribute("aria-expanded"))) {
    await autresEntry.click();
  }
  await expect(autresEntry).toHaveAttribute("aria-expanded", "true");
  await page.getByRole("button", { name: /Angles morts/ }).first().click();
  await expect(page.getByText(/invisible du radar, déclarez une habitude/).first()).toBeVisible();

  // ── P4-207 : résolution d'un conflit depuis l'onglet ─────────────────────────
  // Le gestionnaire (login = owner, il génère/valide) voit « Traiter ». On MESURE
  // avant/après (la base sandbox peut porter d'autres conflits) : jamais une valeur
  // absolue. Un conflit ANNOTÉ reste listé mais quitte les COMPTEURS.
  const navBadge = page.getByRole("navigation", { name: "Espaces matchs" }).getByRole("link", { name: /^Conflits/ });
  const countOf = async (loc: import("@playwright/test").Locator): Promise<number> => Number(((await loc.textContent()) ?? "").match(/·\s*(\d+)/)?.[1] ?? "0");
  const openBefore = await countOf(navBadge);
  const autresBefore = await countOf(autresEntry);
  expect(autresBefore, "l'entrée « Autres conflits » devrait porter ≥ 2 conflits à traiter (nos deux extérieurs)").toBeGreaterThanOrEqual(2);

  // 1re ligne « Angles morts » : « Traiter » → menu APG → « Dérogation demandée ».
  await page.getByRole("button", { name: "Traiter le conflit" }).first().click();
  await page.getByRole("menuitem", { name: "Dérogation demandée" }).click();

  // Témoins : la chip paraît, la ligne reste listée, les compteurs baissent de 1.
  const derogChip = page.getByRole("button", { name: /Statut de traitement : Dérogation demandée/ });
  await expect(derogChip.first()).toBeVisible({ timeout: 15_000 });
  await expect(page.getByText(/invisible du radar, déclarez une habitude/).first()).toBeVisible();
  await expect(page.getByRole("button", { name: new RegExp(`^Autres conflits ·\\s*${autresBefore - 1}\\b`) })).toBeVisible({ timeout: 15_000 });
  await expect(navBadge).toHaveText(new RegExp(`Conflits ·\\s*${openBefore - 1}\\b`), { timeout: 15_000 });

  // Décocher la puce « Dérogation demandée » du groupe Traitement cache la ligne annotée ;
  // la re-cocher la ramène (le groupe n'existe QUE si des conflits sont traités : on vient
  // d'annoter, donc `hasTreated` est vrai). La puce de FILTRE porte « Dérogation demandée <n> »
  // (icône aria-hidden), distincte de la pastille « Statut de traitement : Dérogation demandée ».
  const treatment = page.getByRole("group", { name: "Traitement" });
  await expect(treatment).toBeVisible();
  const derogFilter = treatment.getByRole("button", { name: /^Dérogation demandée/ });
  await expect(derogFilter).toHaveAttribute("aria-pressed", "true");
  await derogFilter.click();
  await expect(derogFilter).toHaveAttribute("aria-pressed", "false");
  await expect(derogChip).toHaveCount(0);
  await expect(page).toHaveURL(/[?&]traitement=/);
  await derogFilter.click();
  await expect(derogFilter).toHaveAttribute("aria-pressed", "true");
  await expect(derogChip.first()).toBeVisible();
  await expect(page).not.toHaveURL(/[?&]traitement=/);

  // « Remettre à traiter » (note vide ⇒ DELETE direct) restaure l'état : compteur remonté.
  await derogChip.first().click();
  await page.getByRole("menuitem", { name: "Remettre à traiter" }).click();
  await expect(derogChip).toHaveCount(0, { timeout: 15_000 });
  await expect(page.getByRole("button", { name: new RegExp(`^Autres conflits ·\\s*${autresBefore}\\b`) })).toBeVisible({ timeout: 15_000 });

  // Pivot par défaut = Coach (store + URL sans param `pivot`).
  const pivotGroup = page.getByRole("group", { name: "Regrouper par" });
  await expect(pivotGroup.getByRole("button", { name: "Coach" })).toHaveAttribute("aria-pressed", "true");

  // Bascule sur « Journée » : l'URL porte pivot=journee ET une entrée week-end
  // « <dates> · n » (libellé weekendShortLabel, qui COMMENCE par un jour → chiffre,
  // ce qui l'écarte des sentinelles « Extérieur »/« Sans date ») existe.
  await pivotGroup.getByRole("button", { name: "Journée" }).click();
  await expect(page).toHaveURL(/[?&]pivot=journee/);
  await expect(pivotGroup.getByRole("button", { name: "Journée" })).toHaveAttribute("aria-pressed", "true");
  const weekendEntry = page.getByRole("button", { name: /^\d.*·\s*\d+$/ }).first();
  await expect(
    weekendEntry,
    "pivot Journée sans aucune entrée week-end datée (que des sentinelles) — le club n'a aucun conflit daté",
  ).toBeVisible({ timeout: 15_000 });

  // Déplie l'entrée : une ligne de conflit paraît, portant « Voir la semaine »
  // (rendu seulement pour un conflit DATÉ — un week-end n'en contient que).
  await weekendEntry.click();
  await expect(weekendEntry).toHaveAttribute("aria-expanded", "true");
  // Le conflit AWAY_NO_FOOTPRINT est gravité 7 → REPLIÉ dans « Angles morts » : « Voir la
  // semaine » y reste caché tant qu'on ne déplie pas le groupe (ConflictLine.tsx:180-210).
  // Seule l'entrée OUVERTE rend ses enfants, donc un unique bouton « Angles morts » ici.
  await page.getByRole("button", { name: /Angles morts/ }).first().click();
  const voir = page.getByRole("button", { name: "Voir la semaine" }).first();
  await expect(voir).toBeVisible();

  // « Voir la semaine » → Calendrier (/matchs). Le libellé de semaine affiché
  // (weekLabel « Semaine du <lundi> au <dimanche> ») DOIT porter le week-end du
  // conflit : le dimanche (borne haute de weekLabel) est le samedi+1, présent dans
  // l'étiquette courte du bouton (weekendShortLabel) — même samedi des deux côtés,
  // donc le token « <jour> <mois>. » du dimanche est contenu dans le titre du bouton.
  const voirTitle = (await voir.getAttribute("title")) ?? ""; // « Voir la semaine du <court> dans le Calendrier »
  await voir.click();
  await expect(page).toHaveURL(/\/matchs$/);
  // A8 — le conflit AWAY_NO_FOOTPRINT a un côté EXTÉRIEUR : « Voir la semaine » a levé
  // l'interrupteur Extérieurs avant de naviguer (sinon la semaine visée serait masquée).
  await expect(page).toHaveURL(/[?&]exterieurs=1/);
  const weekSpan = page.getByText(/^Semaine du .+ au .+$/);
  await expect(weekSpan).toBeVisible({ timeout: 15_000 });
  const weekText = (await weekSpan.textContent()) ?? "";
  const sunday = weekText.split(" au ")[1]?.trim() ?? "";
  expect(sunday, `libellé de semaine inattendu : « ${weekText} »`).not.toBe("");
  expect(voirTitle, `« ${voirTitle} » ne porte pas le dimanche « ${sunday} » de « ${weekText} » — mauvaise semaine`).toContain(sunday);

  // Retour navigateur : pivot Journée ET entrée ouverte (?ouvert) retrouvés.
  await page.goBack();
  await expect(page).toHaveURL(/\/matchs\/conflits/);
  await expect(page).toHaveURL(/[?&]pivot=journee/);
  await expect(page).toHaveURL(/[?&]ouvert=/);
  await expect(page.getByRole("group", { name: "Regrouper par" }).getByRole("button", { name: "Journée" })).toHaveAttribute("aria-pressed", "true");
  await expect(page.getByRole("button", { name: /^\d.*·\s*\d+$/ }).first()).toHaveAttribute("aria-expanded", "true");
  // L'entrée est ré-ouverte (deep-link), mais le retour REMONTE la page : l'état de
  // dépliage du groupe gravité 7 est réinitialisé (ConflictLine.tsx:173) — on redéplie
  // « Angles morts » avant d'attendre « Voir la semaine ».
  await page.getByRole("button", { name: /Angles morts/ }).first().click();
  await expect(page.getByRole("button", { name: "Voir la semaine" }).first()).toBeVisible();

  // ── PR-3 « adversaire multi-gymnases » : l'écran de trajet adverse, groupé par club ─────
  //    On NE dépend d'aucune donnée FFBB live (réseau non fiable en CI) : le témoin RÉALISTE
  //    est que les deux extérieurs créés par CE run (`${opponent}-EXT`, `-EXT2`, saisis à la
  //    main → AUCUN code fédéral résolu) apparaissent dans la liste repliée « N adversaires sans
  //    code fédéral » (PR 2a — OpponentTravelCard sort les orphelins à part), et que le résumé
  //    d'en-tête parle d'« équipes adverses ». Un écran qui ne les montrerait pas fait ÉCHOUER
  //    ces attentes en le disant.
  await page.goto("/matchs/configuration?section=adversaires");
  // Le résumé d'en-tête de la section porte le nouveau libellé « … équipes adverses ».
  await expect(page.getByRole("button", { name: /Adversaires à localiser · .*équipes adverses/ })).toBeVisible({ timeout: 15_000 });

  // PR 2a : les adversaires sans code fédéral vivent dans une disclosure repliée par défaut.
  const orphansToggle = page.getByRole("button", { name: /\d+ adversaires? sans code fédéral$/ });
  await expect(orphansToggle, "témoin: les extérieurs sans code fédéral doivent former la liste repliée").toBeVisible({ timeout: 15_000 });
  await orphansToggle.click();

  for (const suffix of ["EXT", "EXT2"] as const) {
    // `exact: true` : le nom Playwright est une SOUS-CHAÎNE insensible à la casse par défaut, donc
    // « …-EXT » attraperait aussi « …-EXT2 » (strict mode violation). Chaque orphelin déplié est
    // <li><h4>…</h4><p>code fédéral non résolu</p></li> (OpponentTravelCard, PR 2a).
    const heading = page.getByRole("heading", { name: `${opponent}-${suffix}`, exact: true, level: 4 });
    await expect(
      heading,
      `l'extérieur ${opponent}-${suffix} devrait figurer dans la liste « sans code fédéral » — le test ne prouverait rien sinon`,
    ).toBeVisible({ timeout: 15_000 });
    // Sa ligne (le <li> de CET en-tête exact) porte le sous-libellé « code fédéral non résolu » ET
    // n'offre PAS de « Localiser » — scopé au <li> pour ne pas résoudre à plusieurs éléments.
    const row = heading.locator("xpath=ancestor::li[1]");
    await expect(row.getByText("code fédéral non résolu")).toBeVisible();
    await expect(row.getByRole("button", { name: /Localiser/ })).toHaveCount(0);
  }
});

/**
 * PR-1 — la barre de filtres de la vue Semaine : passer « Par coach », ouvrir la
 * puce, chercher un coach, le cocher → la sélection s'active (chip « 1
 * sélectionné ») ET l'URL porte le deep-link (`?vue=coach&filtre=…`), preuve que
 * le filtre recadre la vue sur le périmètre du coach. Suppose que le club seedé
 * (BCCL) a un coach « Thomas » ; sinon, remplacer le terme de recherche.
 */
test("matches: filtre par coach recadre la vue et porte le deep-link", async ({ page }) => {
  test.setTimeout(240_000); // onboarding may run a real CP-SAT generation
  await login(page);
  await ensureValidated(page);

  await page.getByRole("link", { name: "Matchs" }).click();
  await expect(page.getByRole("heading", { name: "Matchs" })).toBeVisible();

  // Basculer l'axe du filtre sur « Par coach » (contrôle segmenté, aria-pressed).
  const parCoach = page.getByRole("button", { name: "Par coach" });
  await parCoach.click();
  await expect(parCoach).toHaveAttribute("aria-pressed", "true");

  // Ouvrir la puce de ressources et chercher le coach.
  await page.getByRole("button", { name: /Coachs :/ }).click();
  await page.getByPlaceholder("Rechercher…").fill("Thomas");
  await page.getByRole("button", { name: /Thomas/ }).first().click();

  // La sélection est active (chip « 1 sélectionné ») et l'URL porte le deep-link.
  await expect(page.getByRole("button", { name: /Coachs : 1 sélectionné/ })).toBeVisible({ timeout: 15_000 });
  await expect(page).toHaveURL(/[?&]vue=coach/);
  await expect(page).toHaveURL(/[?&]filtre=/);

  // Fermer la puce AVANT de poursuivre : son fond de fermeture est un <button>
  // invisible plein écran (z-50) qui intercepte tous les clics tant qu'elle est
  // ouverte. Échap la ferme désormais (P4-184) et rend le focus au déclencheur.
  await page.keyboard.press("Escape");

  // PR 3b — le Calendrier rend la barre « Semaine affichée » et l'établi recadrés
  // sur le coach (plus de vue à ouvrir).
  await expect(page.getByRole("group", { name: "Semaine affichée" })).toBeVisible();
});

/**
 * PR 2a « Configuration & navigation » — la barre d'espaces réordonnée + défilable, la Semaine
 * type sœur (redirection des anciens deep-links), et la section « Accès match » un gymnase à la
 * fois. Écrit sous la stack réelle (socle validé par `ensureValidated`).
 */
test("matches PR 2a: nav ordonnée, défilable à 400 px, Semaine type, Accès match, recherche adversaires", async ({ page }) => {
  test.setTimeout(240_000); // l'onboarding lance une vraie génération CP-SAT
  await login(page);
  await ensureValidated(page);

  await page.goto("/matchs");
  const nav = page.getByRole("navigation", { name: "Espaces matchs" });
  const links = nav.getByRole("link");
  // PR 3b — CINQ onglets : Conflits · Calendrier · Importer · Configuration · Semaine type
  // (« Consulter » et « Semaine » ont fusionné dans « Calendrier »).
  await expect(links).toHaveCount(5);
  await expect(links.nth(0)).toContainText("Conflits");
  await expect(links.nth(1)).toHaveText("Calendrier");
  await expect(links.nth(2)).toContainText("Importer");
  await expect(links.nth(3)).toHaveText("Configuration");
  await expect(links.nth(4)).toHaveText("Semaine type");

  // À 400 px la nav déborde : l'onglet actif (« Calendrier », l'index /matchs) est ramené en vue.
  await page.setViewportSize({ width: 400, height: 800 });
  await page.goto("/matchs");
  const active = nav.locator('[aria-current="page"]');
  await expect(active).toHaveText("Calendrier");
  await expect(active).toBeInViewport();
  await page.setViewportSize({ width: 1280, height: 900 });

  // Anciens deep-links du gabarit/créneaux → la Semaine type.
  await page.goto("/matchs/configuration?section=gabarit");
  await expect(page).toHaveURL(/\/matchs\/semaine-type$/);
  await expect(page.getByRole("heading", { name: "Semaine type", level: 2 })).toBeVisible();

  // ── Accès match : ajouter un créneau à un gymnase SANS accès, un gymnase à la fois ──
  await page.goto("/matchs/configuration?section=reglages");
  const header = page.getByRole("button", { name: /^Accès match/ });
  await expect(header).toBeVisible();
  // ⚠ `accessSummary` (lib/configSummaries.ts) rend `null` tant que les fenêtres ne sont pas
  //    chargées → l'en-tête vaut « Accès match » SANS compte. Lire `before` à cet instant donnait
  //    0 (faux) et faisait échouer `before + 1`. On attend donc que le résumé soit là : « N
  //    gymnase(s) » ou « aucun gymnase avec accès match ».
  await expect(header).toHaveText(/·\s*(\d+\s*gymnases?|aucun gymnase)/);
  const readCount = async (): Promise<number> => {
    const text = (await header.innerText()).trim();
    if (/·\s*aucun gymnase/.test(text)) {
      return 0;
    }
    const m = text.match(/·\s*(\d+)\s*gymnase/);
    if (null === m) {
      throw new Error(`en-tête « Accès match » sans compte (résumé non chargé) : « ${text} »`);
    }
    return Number(m[1]);
  };
  const before = await readCount();

  // Témoin de vacuité : le scénario n'a de sens que s'il existe un gymnase sans accès.
  const disclosure = page.getByRole("button", { name: /\d+ gymnases? sans accès match$/ });
  await expect(disclosure, "témoin: le seed doit avoir au moins un gymnase sans accès match").toBeVisible();
  await disclosure.click();

  // ⚠ Scoper à la LISTE DÉPLIÉE de la disclosure (frère `<ul>` du bouton, `ConfigurationPage.tsx`
  //    L174-181) : un `.first()` sur toute la page attraperait le premier gymnase de la liste
  //    principale (qui a DÉJÀ des accès), et l'ajout d'une 2ᵉ plage rendrait « sam. …, 14:00–22:00 »
  //    (introuvable). On prend donc un gymnase SANS accès, garanti d'avoir une seule fenêtre après.
  const withoutList = disclosure.locator("xpath=following-sibling::ul[1]");
  const firstEdit = withoutList.getByRole("button", { name: /^Modifier les accès match de / }).first();
  const editLabel = (await firstEdit.getAttribute("aria-label")) ?? "";
  const venueName = editLabel.replace("Modifier les accès match de ", "");
  const modifierAfter = page.getByRole("button", { name: `Modifier les accès match de ${venueName}` });

  // Ajout → vérifs → nettoyage : le `finally` retire la fenêtre AJOUTÉE même si une vérif échoue,
  // pour ne pas laisser de créneau parasite dans la base dev CI (non réinitialisée entre runs).
  try {
    await firstEdit.click();
    // Le nom accessible de la modale vient de son `label` (« Accès match ») ; le gymnase est dans le titre (h2).
    const dialog = page.getByRole("dialog", { name: "Accès match" });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole("heading", { name: `Accès match · ${venueName}` })).toBeVisible();
    await dialog.getByLabel("Jour de la fenêtre match").selectOption("6");
    await dialog.getByLabel("Début de la fenêtre match").fill("14:00");
    await dialog.getByLabel("Fin de la fenêtre match").fill("22:00");
    await dialog.getByRole("button", { name: "Ajouter la fenêtre match" }).click();
    await expect(dialog.getByText(/Samedi 14:00/)).toBeVisible();
    // Deux « Fermer » (la croix d'en-tête + le pied) : on ferme par le bouton du pied.
    await dialog.locator("footer").getByRole("button", { name: "Fermer" }).click();

    // Le gymnase a rejoint la liste principale : SA ligne (le <li> de son nom exact) porte la plage
    // formatée « sam. 14:00–22:00 » (une seule fenêtre → pas de virgule) ; le focus revient à son
    // « Modifier » ; et l'en-tête compte un gymnase de plus.
    const venueRow = page.getByText(venueName, { exact: true }).locator("xpath=ancestor::li[1]");
    await expect(venueRow).toContainText("sam. 14:00–22:00");
    await expect(modifierAfter).toBeFocused();
    await expect.poll(readCount).toBe(before + 1);
  } finally {
    // Nettoyage robuste : rouvre le gymnase et supprime la fenêtre Samedi 14:00 SI elle existe.
    if ("" !== venueName && (await modifierAfter.count()) > 0) {
      await modifierAfter.first().click();
      const cleanupDialog = page.getByRole("dialog", { name: "Accès match" });
      await expect(cleanupDialog).toBeVisible();
      const del = cleanupDialog.getByRole("button", { name: "Supprimer la fenêtre Samedi 14:00" });
      if ((await del.count()) > 0) {
        await del.first().click();
        await expect(cleanupDialog.getByText(/Samedi 14:00/)).toHaveCount(0);
      }
      await cleanupDialog.locator("footer").getByRole("button", { name: "Fermer" }).click();
    }
  }
  // Après nettoyage, l'en-tête est revenu à son compte initial.
  await expect.poll(readCount).toBe(before);

  // ── Recherche adversaires : « xyz » n'a aucun résultat, Escape vide la requête ──
  await page.goto("/matchs/configuration?section=adversaires");
  const search = page.getByRole("searchbox", { name: "Rechercher un club ou une équipe" });
  await expect(search).toBeVisible();
  await search.fill("xyznonexistant");
  await expect(page.getByText(/Aucun adversaire pour/)).toBeVisible();
  await search.press("Escape");
  await expect(search).toHaveValue("");
});

/**
 * PR 3b « Calendrier unique » — la barre « Semaine affichée » (3 compteurs), la modale
 * « À recopier dans FBI », le suivi P4-197 après « Placer », et le clic d'une ligne Mois
 * qui bascule en Semaine (URL `semaine=`, cellule focalisée). Le test CRÉE sa donnée (un
 * domicile daté loin) et se nettoie en `finally` (base dev non réinitialisée).
 */
test("matches PR 3b: compteurs, modale FBI, suivi P4-197, Mois→Semaine", async ({ page }) => {
  test.setTimeout(240_000);
  await login(page);
  await ensureValidated(page);

  const opponent = `E2E3B-${Date.now().toString(36).toUpperCase()}`;
  // Clé samedi de la semaine du match (weekendKeyOf ramène au samedi) — réutilisée pour
  // naviguer par l'URL (`semaine=`) dans le try ET dans le nettoyage `finally`.
  const matchDate = "2027-03-13";
  await page.goto("/matchs");
  await expect(page.getByRole("heading", { name: "Matchs" })).toBeVisible();
  // Lot A — le domicile créé est un AMICAL (competitionId null), masqué par défaut : on coche
  // « Amical » pour qu'il pèse dans les compteurs, la liste « À placer », la grille et le Mois.
  await page.getByRole("button", { name: "Amical", exact: true }).click();

  try {
    // ── Créer un domicile daté LOIN (samedi 2027-03-13) ──────────────────────────
    await page.getByRole("button", { name: /Nouveau match/i }).click();
    await expect(page.getByRole("heading", { name: "Nouveau match" })).toBeVisible();
    await page.getByRole("dialog").getByRole("button", { name: /^Équipe/ }).click();
    await page.getByRole("listbox").getByRole("option").first().click();
    await page.getByLabel("Date").fill(matchDate);
    await page.getByLabel("Adversaire").fill(opponent);
    await page.getByRole("button", { name: "Créer" }).click();
    await expect(page.getByRole("heading", { name: "Nouveau match" })).toBeHidden({ timeout: 15_000 });

    // ── La barre « Semaine affichée » : trois compteurs (dont « conflits » = lien) ─
    const counters = page.getByRole("group", { name: "Semaine affichée" });
    await expect(counters.getByRole("button", { name: /à placer/ })).toBeVisible();
    await expect(counters.getByRole("link", { name: /conflits/ })).toHaveAttribute("href", "/matchs/conflits");
    await expect(counters.getByRole("button", { name: /à saisir dans FBI/ })).toHaveAttribute("aria-haspopup", "dialog");

    // ── Naviguer jusqu'à la semaine du domicile créé ─────────────────────────────
    // La liste « À placer » couvre TOUTES les semaines (P4-197) : on navigue sur la
    // semaine AFFICHÉE (clé samedi = matchDate), pas sur la liste — sinon `todo` est
    // visible dès la 1re semaine, la boucle n'avance jamais et l'URL ne porte pas `semaine=`.
    const todo = page.getByRole("button", { name: new RegExp(`vs ${opponent}`) });
    const nextWeek = page.getByRole("button", { name: "Semaine suivante" });
    for (let hops = 0; hops < 60 && !page.url().includes(`semaine=${matchDate}`) && (await nextWeek.isEnabled()); hops += 1) {
      await nextWeek.click();
      await page.waitForTimeout(120);
    }
    // Témoin qui PARLE si la navigation n'a jamais atteint la semaine du match (la base CI
    // porte d'autres rencontres, la semaine du match DOIT être atteignable) — pas de repli silencieux.
    if (!page.url().includes(`semaine=${matchDate}`)) {
      throw new Error(
        `PR 3b: la semaine ${matchDate} n'a jamais été atteinte après 60 sauts `
        + `(URL courante : ${page.url()}). « Semaine suivante » n'a pas déposé la clé.`,
      );
    }
    // L'URL porte la semaine EXACTE du match (plus précis que \d{4}-…).
    await expect(page).toHaveURL(new RegExp(`[?&]semaine=${matchDate}`));
    await expect(todo).toBeVisible({ timeout: 15_000 });

    // ── « à placer » ramène le focus sur le <h2> « À placer » ─────────────────────
    await counters.getByRole("button", { name: /à placer/ }).click();
    await expect(page.getByRole("heading", { name: "À placer" })).toBeFocused();

    // ── « FBI » ouvre la modale ; Échap la ferme et rend le focus au compteur ─────
    const fbiBtn = counters.getByRole("button", { name: /à saisir dans FBI/ });
    await fbiBtn.click();
    await expect(page.getByRole("dialog", { name: "À recopier dans FBI" })).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(page.getByRole("dialog", { name: "À recopier dans FBI" })).toBeHidden();
    await expect(fbiBtn).toBeFocused();

    // ── Suivi P4-197 : « Placer » recadre sur le match et le focalise ─────────────
    await todo.click();
    await page.locator('button[aria-haspopup="listbox"]').first().click();
    await page.getByRole("listbox").getByRole("option").nth(1).click();
    await page.getByLabel("Heure de coup d'envoi").fill("15:00");
    const place = page.getByRole("button", { name: "Placer", exact: true });
    if (await place.isEnabled()) {
      await place.click();
      // La cellule placée est focalisée (repli : le <h2> « À placer »).
      const cell = page.getByRole("button", { name: new RegExp(`\\d\\d:\\d\\d · ${opponent}`) });
      await expect(cell).toBeVisible({ timeout: 15_000 });
    }

    // ── Mois → clic ligne → bascule Semaine (URL semaine=, cellule focalisée) ──────
    await page.getByRole("button", { name: "Mois", exact: true }).click();
    // Naviguer jusqu'au mois du match (mars 2027) : la table le liste.
    const row = page.getByRole("button", { name: new RegExp(`Ouvrir .*${opponent}.* dans la semaine`) });
    const nextMonth = page.getByRole("button", { name: "Mois suivant" });
    for (let hops = 0; hops < 24 && !(await row.isVisible()) && (await nextMonth.isEnabled()); hops += 1) {
      await nextMonth.click();
      await page.waitForTimeout(120);
    }
    await expect(row).toBeVisible({ timeout: 15_000 });
    await row.click();
    // Bascule en Semaine + URL semaine=.
    await expect(page.getByRole("button", { name: "Semaine", exact: true })).toHaveAttribute("aria-pressed", "true");
    await expect(page).toHaveURL(/[?&]semaine=\d{4}-\d{2}-\d{2}/);
  } finally {
    // Nettoyage : supprimer le match créé (grille si placé) — best-effort.
    // Même correctif P4-197 : on navigue sur la semaine AFFICHÉE (clé matchDate), pas sur
    // la liste « À placer » (qui montre le match dès la 1re semaine → boucle figée).
    // Lot A — l'URL force types + extérieurs pour que l'amical créé soit visible au nettoyage.
    await page.goto("/matchs?type=amical,championnat,coupe,brassage&exterieurs=1");
    const cell = page.getByRole("button", { name: new RegExp(`\\d\\d:\\d\\d · ${opponent}`) });
    const nextWeek = page.getByRole("button", { name: "Semaine suivante" });
    for (let hops = 0; hops < 60 && !page.url().includes(`semaine=${matchDate}`) && (await nextWeek.isEnabled()); hops += 1) {
      await nextWeek.click();
      await page.waitForTimeout(120);
    }
    if (await cell.isVisible().catch(() => false)) {
      await cell.click();
      const del = page.getByRole("button", { name: "Supprimer" });
      if ((await del.count()) > 0) {
        await del.first().click();
        const confirm = page.getByRole("button", { name: "Supprimer", exact: true }).last();
        await confirm.click().catch(() => {});
      }
    }
  }
});
