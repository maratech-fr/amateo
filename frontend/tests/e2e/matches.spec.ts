import { expect, test, type Locator } from "./fixtures";
import { ensureValidated, landOnMatchesCalendar, loginSeededClub } from "./support";

// ── Le login + la validation du socle du club seedé vivent dans support.ts (maison unique) : ce
//    fichier (et les ex-specs matches-consulter / matches-fbi-todo / matches-importer, fusionnés
//    ici) les importent au lieu d'en porter chacun une copie. Restent ci-dessous les petits
//    utilitaires de date/texte propres aux parcours matchs. ─────────────────────────────────────

/** Y-m-d LOCAL (jamais toISOString, qui bascule en UTC et peut changer le jour). */
function ymd(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

function addDays(ymdStr: string, n: number): string {
  const d = new Date(`${ymdStr}T00:00:00`);
  d.setDate(d.getDate() + n);
  return ymd(d);
}

/** Le prochain samedi (Y-m-d) à au moins `minAhead` jours d'aujourd'hui. */
function nextSaturdayAtLeast(minAhead: number): string {
  const d = new Date();
  d.setHours(0, 0, 0, 0);
  d.setDate(d.getDate() + minAhead);
  while (6 !== d.getDay()) {
    d.setDate(d.getDate() + 1); // 6 = samedi
  }
  return ymd(d);
}

/**
 * P4-360 — un SAMEDI FUTUR ~`weeksAhead` semaines après aujourd'hui (Y-m-d LOCAL), pour dater une
 * rencontre LOIN de « maintenant » SANS figer un mois en dur : une date absolue « 2027-03-xx »
 * devenait « passée » dès mars 2027 (case-jour non cliquable, semaine hors navigation). Un SAMEDI
 * est sa propre clé de week-end (`weekendKeyOf(samedi) === samedi`, cf. `weekendGrid.ts`), d'où les
 * assertions `semaine=<samedi>` et la navigation « Semaine suivante » (index-based sur les week-ends
 * qui portent une rencontre, `CalendarControls.tsx`). ⚠ La fenêtre doit tomber APRÈS le week-end
 * d'atterrissage ET AVANT la fin de saison du seed BCCL (`BcclSeeder.php`, saison 2026-2027) :
 * `weeksAhead` est choisi pour rester dans cette saison, comme l'ancienne cible de mars 2027.
 */
function futureSaturday(weeksAhead: number): string {
  const d = new Date();
  d.setHours(0, 0, 0, 0);
  d.setDate(d.getDate() + weeksAhead * 7);
  while (6 !== d.getDay()) {
    d.setDate(d.getDate() + 1); // 6 = samedi
  }
  return ymd(d);
}

/** Un nom d'équipe devient un motif littéral (« U11M1 (bis) » ne doit pas casser la RegExp). */
function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

/** Lit une collection API Platform quelle que soit sa forme (tableau nu, `member`, `hydra:member`). */
function membersOf(body: unknown): Record<string, unknown>[] {
  if (Array.isArray(body)) return body as Record<string, unknown>[];
  const obj = body as Record<string, unknown>;
  return (obj.member ?? obj["hydra:member"] ?? []) as Record<string, unknown>[];
}

/**
 * Amène la semaine affichée sur celle qui contient `target`, en cliquant `btn` (‹ ou ›) au plus
 * `max` fois. Un locator qui résout PLUSIEURS éléments ÉCHOUE en le disant (jamais avalé en `false`,
 * sinon la boucle traverse toutes les semaines sans « voir » sa cible — témoin muet).
 */
async function stepUntilVisible(btn: Locator, target: Locator, max: number): Promise<boolean> {
  const seen = async (): Promise<boolean> => {
    const n = await target.count();
    if (n > 1) {
      throw new Error(`stepUntilVisible: locator ambigu (${n} éléments) — scoper au conteneur (grille/table)`);
    }
    return 1 === n && (await target.first().isVisible());
  };
  if (await seen()) {
    return true;
  }
  for (let i = 0; i < max; i++) {
    if (!(await btn.isEnabled())) {
      break;
    }
    await btn.click();
    if (await seen()) {
      return true;
    }
  }
  return false;
}

/**
 * End-to-end of the placement loop (module matchs PR-3) under the real stack:
 * create a fixture manually → it lands in the "à placer" list → place it (venue +
 * kickoff) → it leaves the list. The conflict radar renders throughout.
 */
test("matches: create a fixture, place it, radar renders", async ({ page }) => {
  test.setTimeout(240_000); // onboarding runs a real CP-SAT generation
  await loginSeededClub(page);
  await ensureValidated(page);

  // A unique opponent so the assertions target THIS run's fixture (dev DB is not reset).
  const opponent = `E2E-${Date.now().toString(36).toUpperCase()}`;
  // P4-360 — fenêtre FUTURE calculée depuis aujourd'hui (plus de « 2027-03-xx » en dur). Le domicile
  // tombe un SAMEDI loin devant (placé sur la grille) ; les deux extérieurs sont des MERCREDIS (jour
  // sans habitude de match → AWAY_NO_FOOTPRINT) sur DEUX week-ends distincts : `homeSat + 4` et
  // `homeSat + 11` jours (weekendKeyOf = samedi suivant, puis le samedi d'après), comme les anciens
  // 06/10/17 mars. Structure jour-de-semaine/week-end IDENTIQUE à l'ancienne cible figée.
  const homeSat = futureSaturday(18);
  const awayWed1 = addDays(homeSat, 4);
  const awayWed2 = addDays(homeSat, 11);

  await page.getByRole("link", { name: "Matchs" }).click();
  await expect(page.getByRole("heading", { name: "Matchs" })).toBeVisible();
  // UXS-07 — la première entrée dans le module peut atterrir sur Conflits (conditionnel,
  // store vierge) : on attend la décision RENDUE puis on rejoint le Calendrier (maison unique).
  await landOnMatchesCalendar(page);

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
  await page.getByLabel("Date").fill(homeSat); // un samedi FUTUR (calculé depuis aujourd'hui)
  await page.getByLabel("Adversaire").fill(opponent);
  await page.getByRole("button", { name: "Créer" }).click();
  // La création est asynchrone : attendre la fermeture AVANT d'en rouvrir une (sinon
  // « Nouveau match » rouvrirait le dialogue DÉJÀ ouvert et on éditerait ses champs).
  await expect(page.getByRole("heading", { name: "Nouveau match" })).toBeHidden({ timeout: 15_000 });

  // ── Deux rencontres EXTÉRIEURES sans heure, un MERCREDI (jour 3) ──────────────
  //    Le seed ne pose d'habitudes de match que les jours 6/7 (BcclSeeder.php:1407-1424),
  //    donc AUCUNE équipe n'a d'habitude un mercredi : chaque extérieur sans heure émet
  //    un AWAY_NO_FOOTPRINT (gravité 7, MatchConflictDetector.php:590-609 ; filtré sur
  //    matchDate >= aujourd'hui — la fenêtre est future —, JAMAIS sur le statut : un extérieur né
  //    REVIEWED compte). Conflit DÉTERMINISTE qui peuple l'onglet Conflits sur une base
  //    FRAÎCHE (CI), là où le radar seul ne prouvait rien (le club n'a aucun conflit).
  //    DEUX week-ends distincts (awayWed1 et awayWed2, à +4 et +11 j du samedi domicile ;
  //    weekendKeyOf ramène chaque mercredi au samedi suivant) pour que le pivot Journée ait
  //    ≥2 entrées : avec UNE seule, `openKey` la force
  //    ouverte (ConflictsPage.tsx:156) et le clic EFFACE `?ouvert` — le deep-link goBack
  //    (:301) ne tiendrait plus. Un AWAY n'apparaît PAS dans « à placer » (UnplacedList :
  //    HOME uniquement) NI dans une case GYMNASE de la grille ; depuis le lot 3 PR-3a il
  //    apparaît dans la colonne « Extérieur » de la grille, écrit « à <adv> », jamais
  //    « vs <adv> » → l'assertion `vs ${opponent}` (:229) ne le ramasse pas. La colonne
  //    « Extérieur » est vérifiée plus bas (en-tête + bloc data-away « heure inconnue »).
  for (const [date, suffix] of [[awayWed1, "EXT"], [awayWed2, "EXT2"]] as const) {
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
    // porte des rencontres. Notre match est daté LOIN (homeSat, ~18 semaines) pour ne heurter
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
  // il est daté LOIN, futur). Tous les locators sont scopés au conteneur grille
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
  // M4 (retours BCCL 2026-10-27) : l'onglet s'ouvre filtré « Avec un match à domicile ». Nos
  // conflits sont des AWAY_NO_FOOTPRINT (matchs à l'extérieur) — on bascule sur « Tous » pour
  // les voir ; le défaut domicile lui-même est gardé par ConflictsPage.test.tsx/store.test.ts.
  await page.getByRole("button", { name: /^Tous \(\d+\)$/ }).click();

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
  // A8 — « Voir la semaine » navigue vers le Calendrier avec les masques DANS L'URL (le
  // conflit AWAY_NO_FOOTPRINT a un côté EXTÉRIEUR) : la query porte `exterieurs=1` d'emblée,
  // donc l'URL n'est plus le `/matchs` nu — et l'interrupteur « Extérieurs » arrive ALLUMÉ.
  await expect(page).toHaveURL(/\/matchs\?/);
  await expect(page).toHaveURL(/[?&]exterieurs=1/);
  // Le lien « Voir la semaine » VISE une rencontre (`match=<fixtureId>`) : le seed du Calendrier
  // la sélectionne, en fait la mise en évidence, PUIS retire le param (one-shot — jamais porté par
  // l'adresse). Témoin falsifiable : `match=` a disparu une fois la page seedée.
  await expect(page).not.toHaveURL(/[?&]match=/);
  await expect(page.getByRole("switch", { name: "Extérieurs" })).toHaveAttribute("aria-checked", "true");
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

  // ── C8 (amendement 2026-09-20) « adversaire multi-gymnases » : l'onglet Adversaires, groupé par
  //    club ─────────────────────────────────────────────────────────────────────────────────────
  //    On NE dépend d'aucune donnée FFBB live (réseau non fiable en CI) : le témoin RÉALISTE est que
  //    les deux extérieurs créés par CE run (`${opponent}-EXT`, `-EXT2`, saisis à la main → AUCUN
  //    code fédéral) apparaissent comme LIGNE CLUB (th scope=row), marquées « Aucun gymnase connu »
  //    et PORTENT l'action « Ajouter un gymnase » utilisable — décision fondateur 2026-09-21 : plus
  //    aucun bouton mort, un sans-code reçoit un gymnase via sa clé sentinelle locale (abefb489). Un
  //    écran qui ne les montrerait pas fait ÉCHOUER ces attentes en le disant.
  // L'ancien deep-link `configuration?section=adversaires` redirige vers l'onglet dédié.
  await page.goto("/matchs/configuration?section=adversaires");
  await expect(page).toHaveURL(/\/matchs\/adversaires/);
  await expect(page.getByRole("heading", { name: "Adversaires", level: 2 })).toBeVisible({ timeout: 15_000 });

  for (const suffix of ["EXT", "EXT2"] as const) {
    // Chaque orphelin (code fédéral absent) est une LIGNE CLUB : un `<th scope="row">` portant son
    // nom. Le nom accessible inclut le texte de la cellule (« … Aucun gymnase connu ») → RegExp avec
    // `\b` après le suffixe pour que « …-EXT » n'attrape pas « …-EXT2 » (mode strict).
    const rowheader = page.getByRole("rowheader", { name: new RegExp(`${opponent}-${suffix}\\b`) });
    await expect(
      rowheader,
      `l'extérieur ${opponent}-${suffix} devrait figurer comme adversaire (ligne club) — le test ne prouverait rien sinon`,
    ).toBeVisible({ timeout: 15_000 });
    // Sa LIGNE porte « Aucun gymnase connu » (aucun venue) ET offre bien l'action « Ajouter un
    // gymnase », utilisable (règle NOUVELLE : un sans-code s'apparie via sa clé sentinelle) —
    // scopé au <tr> pour ne pas résoudre à plusieurs éléments. Le bouton se replie en icône seule
    // sous @md mais garde son nom accessible (`sr-only`), donc le locator par nom tient.
    const row = rowheader.locator("xpath=ancestor::tr[1]");
    await expect(row.getByText("Aucun gymnase connu")).toBeVisible();
    const addVenue = row.getByRole("button", { name: /Ajouter un gymnase/ });
    await expect(addVenue).toHaveCount(1);
    await expect(addVenue).toBeEnabled();
  }
});

/**
 * PR-1 — la barre de filtres de la vue Semaine : passer « Par coach », ouvrir la
 * puce, chercher un coach, le cocher → la sélection s'active (chip « 1
 * sélectionné ») ET l'URL porte le deep-link (`?vue=coach&filtre=…`), preuve que
 * le filtre recadre la vue sur le périmètre du coach. Suppose que le club seedé
 * (BCCL) a un coach « Coach Tho » ; sinon, remplacer le terme de recherche.
 */
test("matches: filtre par coach recadre la vue et porte le deep-link", async ({ page }) => {
  test.setTimeout(240_000); // onboarding may run a real CP-SAT generation
  await loginSeededClub(page);
  await ensureValidated(page);

  await page.getByRole("link", { name: "Matchs" }).click();
  await expect(page.getByRole("heading", { name: "Matchs" })).toBeVisible();
  // UXS-07 — la première entrée dans le module peut atterrir sur Conflits (conditionnel,
  // store vierge) : on attend la décision RENDUE puis on rejoint le Calendrier (maison unique).
  await landOnMatchesCalendar(page);

  // Basculer l'axe du filtre sur « Par coach » (contrôle segmenté, aria-pressed).
  const parCoach = page.getByRole("button", { name: "Par coach" });
  await parCoach.click();
  await expect(parCoach).toHaveAttribute("aria-pressed", "true");

  // Ouvrir la puce de ressources et chercher le coach.
  await page.getByRole("button", { name: /Coachs :/ }).click();
  await page.getByPlaceholder("Rechercher…").fill("Coach Tho");
  await page.getByRole("button", { name: /Coach Tho/ }).first().click();

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
 * Gymnases adverses (2026-09-20) — REFLOW mobile du TABLEAU Adversaires (WCAG 1.4.10). Le tableau
 * par club adverse (`OpponentsPage`) est un `@container` : sous `@md`, les colonnes « Trajet » et
 * « Rencontres » DISPARAISSENT (`hidden @md:table-cell`) et le trajet redescend dans une ligne
 * empilée (`@md:hidden`), pour que le tableau ne déborde JAMAIS horizontalement de sa boîte à
 * largeur téléphone. Ce test verrouille CETTE promesse à 360 px :
 *   - témoin de rendu = la barre de recherche (peinte seulement s'il existe des adversaires) → le
 *     test CRÉE un extérieur pour garantir un tableau, sinon l'écran resterait un EmptyState ;
 *   - assertion = aucun débordement horizontal du CONTENEUR du tableau (le wrapper `overflow-x-auto`
 *     de `Table`), scrollWidth ≤ clientWidth, tol. 1 px ;
 *   - preuve directe du `@container` = l'en-tête de colonne « Trajet » est MASQUÉ à cette largeur.
 *
 * ⚠ On mesure le TABLEAU, PAS le document : au 20/09/2026 (Playwright, base réelle, lecture seule)
 * TOUT le produit débordait déjà à 360 px — accueil `scrollWidth` 558, /planning 501, /matchs ·
 * /club · /matchs/adversaires 370 (le coupant commun des 370 est le bouton horloge de l'en-tête
 * `AppLayout`, ~103 px). Cette dette de reflow GLOBALE est une ligne de roadmap ouverte (passe doc
 * du 20/09) ; la mesurer au niveau document ne prouverait PAS ce que ce test annonce (le
 * `@container` replie les colonnes du tableau).
 *
 * e2e écrit, PAS lancé (l'exécution des e2e reste au fondateur).
 */
test("gymnases adverses: le tableau Adversaires reflow sans défilement horizontal à 360 px", async ({ page }) => {
  test.setTimeout(240_000); // l'onboarding peut lancer une génération CP-SAT réelle
  await loginSeededClub(page);
  await ensureValidated(page);

  // e2e crée ce qu'il vérifie : un extérieur → ≥1 adversaire → le tableau (et sa searchbox) sont
  // rendus. Le club seedé CI n'a aucune rencontre garantie (cf. `.claude/rules/frontend.md`).
  const teamsRes = await page.request.get("/api/teams?itemsPerPage=100");
  expect(teamsRes.ok(), "GET /api/teams").toBeTruthy();
  const teamId = ((await teamsRes.json()).member?.[0]?.id ?? undefined) as string | undefined;
  expect(teamId, "le club seedé a au moins une équipe").toBeTruthy();
  const opponent = `REFLOW-${Date.now().toString(36).toUpperCase()}`;
  const created = await page.request.post("/api/fixtures", {
    data: { teamId, matchDate: futureSaturday(18), homeAway: "AWAY", opponentLabel: opponent, competitionId: null },
  });
  expect(created.ok(), "POST /api/fixtures").toBeTruthy();
  const fixtureId = (await created.json()).id as string;

  try {
    await page.setViewportSize({ width: 360, height: 740 });
    await page.goto("/matchs/adversaires");

    // Témoin : la searchbox n'est peinte que si des adversaires existent — pas de faux vert.
    await expect(page.getByRole("searchbox", { name: "Rechercher un club ou un gymnase" })).toBeVisible({ timeout: 15_000 });

    // Débordement horizontal du CONTENEUR du tableau (le wrapper `overflow-x-auto` de `Table`,
    // parent direct du <table>), jamais du document (dette globale, cf. docblock).
    const table = page.getByRole("table"); // un seul <table> sur l'écran Adversaires
    await expect(table).toBeVisible();
    const overflow = await table.evaluate((el) => {
      const box = el.parentElement; // le wrapper `overflow-x-auto` de la primitive Table
      return null === box ? 0 : box.scrollWidth - box.clientWidth;
    });
    expect(overflow, "le tableau Adversaires déborde horizontalement à 360 px (le @container ne replie pas ses colonnes)").toBeLessThanOrEqual(1);

    // Preuve directe du `@container` : l'en-tête « Trajet » (`@md:table-cell`) est masqué à 360 px.
    await expect(page.getByRole("columnheader", { name: "Trajet" })).toBeHidden();

    // P4-293 — le 4ᵉ segment « Trajet indisponible » existe, et le contrôle segmenté (flex-wrap)
    // ne déborde JAMAIS horizontalement à 360 px (il s'enroule, pas de défilement) : un 4ᵉ segment
    // ajouté sans soin déborderait sa boîte à largeur téléphone.
    const filterGroup = page.getByRole("group", { name: "Filtrer les adversaires" });
    await expect(filterGroup.getByRole("button", { name: /Trajet indisponible/ })).toBeVisible();
    const groupOverflow = await filterGroup.evaluate((el) => el.scrollWidth - el.clientWidth);
    expect(groupOverflow, "le contrôle segmenté des adversaires déborde horizontalement à 360 px (4ᵉ segment)").toBeLessThanOrEqual(1);
  } finally {
    // Base dev CI non remise à zéro : on nettoie NOTRE rencontre.
    await page.request.delete(`/api/fixtures/${fixtureId}`).catch(() => undefined);
  }
});

/**
 * PR 3b « Calendrier unique » — la barre « Semaine affichée » (3 compteurs), la modale
 * « FBI — à faire », le suivi P4-197 après « Placer », et le clic d'une ligne Mois
 * qui bascule en Semaine (URL `semaine=`, cellule focalisée). Le test CRÉE sa donnée (un
 * domicile daté loin) et se nettoie en `finally` (base dev non réinitialisée).
 */
test("matches PR 3b: compteurs, modale FBI, suivi P4-197, Mois→Semaine", async ({ page }) => {
  test.setTimeout(240_000);
  await loginSeededClub(page);
  await ensureValidated(page);

  const opponent = `E2E3B-${Date.now().toString(36).toUpperCase()}`;
  // Clé samedi de la semaine du match (weekendKeyOf ramène au samedi) — réutilisée pour
  // naviguer par l'URL (`semaine=`) dans le try ET dans le nettoyage `finally`.
  const matchDate = futureSaturday(19);
  await page.goto("/matchs");
  await expect(page.getByRole("heading", { name: "Matchs" })).toBeVisible();
  // UXS-07 — l'index peut renvoyer sur Conflits (atterrissage conditionnel, store vierge après
  // `goto`) : on attend la décision RENDUE puis on rejoint le Calendrier avant d'agir (maison unique).
  await landOnMatchesCalendar(page);
  // Lot A — le domicile créé est un AMICAL (competitionId null), masqué par défaut : on coche
  // « Amical » pour qu'il pèse dans les compteurs, la liste « À placer », la grille et le Mois.
  await page.getByRole("button", { name: "Amical", exact: true }).click();

  // Dette e2e : nettoyage par DELETE API en `finally` (plus de nettoyage « navigant » par la grille).
  let createdFixtureId: string | undefined;

  try {
    // ── Créer un domicile daté LOIN (samedi futur, calculé depuis aujourd'hui) ────
    await page.getByRole("button", { name: /Nouveau match/i }).click();
    await expect(page.getByRole("heading", { name: "Nouveau match" })).toBeVisible();
    await page.getByRole("dialog").getByRole("button", { name: /^Équipe/ }).click();
    await page.getByRole("listbox").getByRole("option").first().click();
    await page.getByLabel("Date").fill(matchDate);
    await page.getByLabel("Adversaire").fill(opponent);
    await page.getByRole("button", { name: "Créer" }).click();
    await expect(page.getByRole("heading", { name: "Nouveau match" })).toBeHidden({ timeout: 15_000 });
    // Capture l'id de la rencontre créée (par son adversaire unique) pour le DELETE du `finally`.
    createdFixtureId = membersOf(await (await page.request.get("/api/fixtures?itemsPerPage=200")).json())
      .find((f) => f.opponentLabel === opponent)?.id as string | undefined;

    // ── La barre « Semaine affichée » : trois compteurs (dont « conflits » = lien) ─
    const counters = page.getByRole("group", { name: "Semaine affichée" });
    await expect(counters.getByRole("button", { name: /à placer/ })).toBeVisible();
    await expect(counters.getByRole("link", { name: /conflits/ })).toHaveAttribute("href", "/matchs/conflits");
    await expect(page.getByRole("button", { name: /FBI à faire/ })).toHaveAttribute("aria-haspopup", "dialog");

    // ── Naviguer jusqu'à la semaine du domicile créé ─────────────────────────────
    // La liste « À placer » couvre TOUTES les semaines (P4-197) : on navigue sur la
    // semaine AFFICHÉE (clé samedi = matchDate), pas sur la liste — sinon `todo` est
    // visible dès la 1re semaine, la boucle n'avance jamais et l'URL ne porte pas `semaine=`.
    const todo = page.getByRole("button", { name: new RegExp(`vs ${opponent}`) });
    const nextWeek = page.getByRole("button", { name: "Semaine suivante" });
    // COURSE : attendre que le refetch de la liste soit ARRIVÉ avant de sauter — le match créé
    // apparaît dans « À placer » (qui couvre TOUTES les semaines, P4-197) ⇒ le cache est à jour.
    // Sans cette attente, « Semaine suivante » est encore `disabled` (une seule semaine connue),
    // la boucle ne saute jamais et le témoin lève à tort (rouge au 1er essai, vert au retry).
    await expect(todo).toBeVisible({ timeout: 15_000 });
    if (!page.url().includes(`semaine=${matchDate}`)) {
      await expect(nextWeek).toBeEnabled({ timeout: 15_000 });
    }
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
    const fbiBtn = page.getByRole("button", { name: /FBI à faire/ });
    await fbiBtn.click();
    await expect(page.getByRole("dialog", { name: "FBI — à faire" })).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(page.getByRole("dialog", { name: "FBI — à faire" })).toBeHidden();
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
    // Naviguer jusqu'au mois du match (futur) : la table le liste.
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
    // Nettoyage par l'API (dette e2e) : un DELETE ciblé sur l'id capturé, jamais une navigation de
    // grille qui dépend de l'état d'affichage. Base dev CI non réinitialisée → on retire NOTRE rencontre.
    if (undefined !== createdFixtureId) {
      await page.request.delete(`/api/fixtures/${createdFixtureId}`).catch(() => undefined);
    }
  }
});

/**
 * PR-2a — « Consulter » (lecture seule, temporalité Semaine), FUSIONNÉ ici (ex-`matches-consulter.spec.ts`).
 * Le test S'AUTO-PROVISIONNE : il crée ses rencontres via l'API et les nettoie en `finally`, donc il ne
 * suppose RIEN du seed. On éprouve les chips (type de compétition), la mémoire de session d'un filtre, la
 * Semaine type, le filtre par famille de conflit (collision de gymnase, bornée à la semaine), et les
 * temporalités Mois / Phase.
 */
test("consulter: chips, semaine type, et filtre par famille de conflit", async ({ page }) => {
  test.setTimeout(240_000); // l'onboarding peut lancer une vraie génération CP-SAT

  await loginSeededClub(page);
  await ensureValidated(page);

  // ── Auto-provisionnement (le club CI n'a AUCUNE rencontre) : 1ʳᵉ équipe + 1ᵉʳ gymnase, puis nos
  //    propres rencontres sur DEUX semaines FUTURES vides — nettoyées en `finally`. Le détecteur émet
  //    VENUE_OVERLAP dès qu'un gymnase + un coup d'envoi sont posés (statut UNPLACED compris).
  const teamsRes = await page.request.get("/api/teams?itemsPerPage=100");
  expect(teamsRes.ok(), "GET /api/teams").toBeTruthy();
  const teamId = ((await teamsRes.json()).member?.[0]?.id ?? undefined) as string | undefined;
  const venuesRes = await page.request.get("/api/venues?itemsPerPage=100");
  expect(venuesRes.ok(), "GET /api/venues").toBeTruthy();
  const venueId = ((await venuesRes.json()).member?.[0]?.id ?? undefined) as string | undefined;
  expect(teamId, "le club seedé a au moins une équipe").toBeTruthy();
  expect(venueId, "le club seedé a au moins un gymnase").toBeTruthy();

  const ts = Date.now();
  const cleanSat = nextSaturdayAtLeast(7);
  const overlapSat = addDays(cleanSat, 7);
  const cleanOpp = `E2E-CONSULTER-${ts}-CLEAN`;
  const overlapA = `E2E-CONSULTER-${ts}-A`;
  const overlapB = `E2E-CONSULTER-${ts}-B`;

  const createFixture = async (matchDate: string, opponentLabel: string): Promise<string> => {
    const res = await page.request.post("/api/fixtures", {
      data: { teamId, matchDate, homeAway: "HOME", opponentLabel, venueId, kickoffTime: "15:00", competitionId: null },
    });
    expect(res.ok(), `POST /api/fixtures ${opponentLabel}`).toBeTruthy();
    return (await res.json()).id as string;
  };

  const createdIds: string[] = [];
  try {
    createdIds.push(await createFixture(cleanSat, cleanOpp));
    createdIds.push(await createFixture(overlapSat, overlapA));
    createdIds.push(await createFixture(overlapSat, overlapB));

    // PR 3b — « Consulter » a FUSIONNÉ dans « Calendrier » : /matchs/consulter REDIRIGE vers /matchs.
    await page.goto("/matchs/consulter");
    await expect(page).toHaveURL(/\/matchs(\/conflits)?(\?|$)/);
    await landOnMatchesCalendar(page);
    await expect(page).toHaveURL(/\/matchs(\?|$)/);
    await expect(page.getByRole("navigation", { name: "Espaces matchs" }).getByRole("link", { name: "Calendrier" })).toHaveAttribute("aria-current", "page");

    // Chips type de compétition — STATIQUES (les 4 `KINDS`). Défauts lot A : « Amical » DÉCOCHÉ, les 3 autres cochés.
    await expect(page.getByRole("button", { name: "Amical", exact: true })).toHaveAttribute("aria-pressed", "false");
    for (const label of ["Championnat", "Coupe", "Brassage"]) {
      await expect(page.getByRole("button", { name: label, exact: true })).toHaveAttribute("aria-pressed", "true");
    }
    // Mémoire de session : un filtre allumé SURVIT à un aller-retour Conflits → Calendrier.
    const matchesNav = page.getByRole("navigation", { name: "Espaces matchs" });
    const exterieurs = page.getByRole("switch", { name: "Extérieurs" });
    await expect(exterieurs).toHaveAttribute("aria-checked", "false");
    await exterieurs.click();
    await expect(exterieurs).toHaveAttribute("aria-checked", "true");
    // ⚑ 15 s : l'interrupteur (aria-checked) reflète le store IMMÉDIATEMENT, mais l'écriture du
    // param dans l'URL est portée par `useCalendarUrlSync` (useCalendarUrlSync.ts:75-78), dont
    // la re-synchro est GARDÉE tant que teams/coaches/venues/fixtures ne sont pas chargés. Juste
    // après le montage du Calendrier, ces quatre lectures peuvent dépasser 5 s sous charge CI.
    await expect(page).toHaveURL(/[?&]exterieurs=1/, { timeout: 15_000 });
    await matchesNav.getByRole("link", { name: "Conflits" }).click();
    await expect(page).toHaveURL(/\/matchs\/conflits/);
    await matchesNav.getByRole("link", { name: "Calendrier" }).click();
    await expect(page).toHaveURL(/\/matchs(\?|$)/);
    await expect(exterieurs).toHaveAttribute("aria-checked", "true");
    // Même témoin réseau (15 s) : le Calendrier VIENT d'être re-monté par l'aller-retour
    // Conflits → Calendrier, donc ses quatre lectures re-chargent et la re-synchro de l'URL
    // (useCalendarUrlSync.ts:78) ne réécrit `exterieurs=1` qu'une fois les données prêtes —
    // c'est précisément ce point qui a rougi (run 38591293403 tentative 1, URL restée « /matchs »).
    await expect(page).toHaveURL(/[?&]exterieurs=1/, { timeout: 15_000 });
    await exterieurs.click();
    await expect(exterieurs).toHaveAttribute("aria-checked", "false");

    // Nos rencontres sont des AMICAUX : on coche « Amical » pour qu'elles pèsent (cases, puce, Mois).
    await page.getByRole("button", { name: "Amical", exact: true }).click();
    await expect(page.getByRole("button", { name: "Amical", exact: true })).toHaveAttribute("aria-pressed", "true");

    // Interrupteur « Semaine type » (role switch), MASQUÉ par défaut (lot A) ; on l'allume.
    const semaineType = page.getByRole("switch", { name: /Semaine type/ });
    await expect(semaineType).toHaveAttribute("aria-checked", "false");
    await semaineType.click();
    await expect(semaineType).toHaveAttribute("aria-checked", "true");
    await expect(page).toHaveURL(/[?&]type_semaine=1/);

    // Va sur la semaine de la collision (repérée par notre rencontre). On cherche › puis ‹.
    const nextWeek = page.getByRole("button", { name: "Semaine suivante" });
    const prevWeek = page.getByRole("button", { name: "Semaine précédente" });
    const grid = page.getByTestId("weekend-grid");
    const overlapCell = grid.getByText(overlapA, { exact: false });
    const found = (await stepUntilVisible(nextWeek, overlapCell, 12)) || (await stepUntilVisible(prevWeek, overlapCell, 24));
    expect(found, "la semaine de la collision créée est atteignable").toBeTruthy();
    await expect(overlapCell).toBeVisible();

    // Puce « Collision de gymnase » présente, compteur ≥ 1.
    const familiesGroup = page.getByRole("group", { name: "Familles de conflits" });
    const collisionChip = familiesGroup.getByRole("button", { name: /^Collision de gymnase/ });
    await expect(collisionChip).toBeVisible();
    await expect(collisionChip).toHaveAttribute("aria-pressed", "true");
    const chipCount = (await collisionChip.innerText()).match(/\d+/)?.[0] ?? "";
    expect(Number(chipCount), "compteur de collisions ≥ 1").toBeGreaterThanOrEqual(1);

    // Radar : le groupe « Collision de gymnase ». Décocher la puce le retire ; recocher le fait revenir.
    const radarGroup = page.getByRole("heading", { level: 3, name: "Collision de gymnase" });
    await expect(radarGroup).toBeVisible();
    await collisionChip.click();
    await expect(collisionChip).toHaveAttribute("aria-pressed", "false");
    await expect(collisionChip).toContainText(chipCount);
    await expect(radarGroup).toHaveCount(0);
    await expect(page).toHaveURL(/[?&]conflits=/);
    await collisionChip.click();
    await expect(collisionChip).toHaveAttribute("aria-pressed", "true");
    await expect(radarGroup).toBeVisible();

    // Bornage à la SEMAINE : sur la semaine de la rencontre ISOLÉE (W_clean), aucune collision.
    const cleanCell = grid.getByText(cleanOpp, { exact: false });
    expect(await stepUntilVisible(prevWeek, cleanCell, 24), "la semaine de la rencontre isolée est atteignable").toBeTruthy();
    await expect(cleanCell).toBeVisible();
    await expect(familiesGroup.getByRole("button", { name: /^Collision de gymnase/ })).toHaveCount(0);

    // ── Temporalité MOIS : les deux rencontres en collision y apparaissent, puce sur le mois. ──
    await page.getByRole("button", { name: "Mois", exact: true }).click();
    const monthTable = page.getByRole("table");
    await expect(monthTable).toBeVisible();
    const nextMonth = page.getByRole("button", { name: "Mois suivant" });
    const prevMonth = page.getByRole("button", { name: "Mois précédent" });
    const overlapMonthCell = page.getByText(overlapA, { exact: false });
    const foundMonth = (await stepUntilVisible(nextMonth, overlapMonthCell, 6)) || (await stepUntilVisible(prevMonth, overlapMonthCell, 12));
    expect(foundMonth, "le mois de la collision est atteignable en vue Mois").toBeTruthy();
    await expect(overlapMonthCell).toBeVisible();
    await expect(page.getByText(overlapB, { exact: false })).toBeVisible();
    await expect(page.getByRole("group", { name: "Familles de conflits" }).getByRole("button", { name: /^Collision de gymnase/ })).toBeVisible();

    // ── Temporalité PHASE : empty state « Aucune compétition appariée » OU le sélecteur de phase. ──
    await page.getByRole("button", { name: "Phase", exact: true }).click();
    const emptyPhase = page.getByText("Aucune compétition appariée");
    const phaseSelect = page.getByRole("combobox", { name: /Phase|compétition/i });
    await expect(emptyPhase.or(phaseSelect).first()).toBeVisible();
  } finally {
    for (const id of createdIds) {
      await page.request.delete(`/api/fixtures/${id}`).catch(() => undefined);
    }
  }
});

/**
 * La liste « FBI — à faire » du Calendrier, FUSIONNÉE ici (ex-`matches-fbi-todo.spec.ts`). Le test
 * S'AUTO-PROVISIONNE (crée SA rencontre — un domicile PLACÉ — via l'API, nettoyée en `finally`), ouvre
 * la liste, coche « saisi », éprouve le toast. Un geste qui n'exerce rien laisserait le toast absent.
 */
test("fbi — le compteur « FBI à faire » ouvre la liste ; cocher marque saisi dans FBI", async ({ page }) => {
  test.setTimeout(240_000);

  await loginSeededClub(page);

  const teamsRes = await page.request.get("/api/teams?itemsPerPage=100");
  expect(teamsRes.ok(), "GET /api/teams").toBeTruthy();
  const teamId = ((await teamsRes.json()).member?.[0]?.id ?? undefined) as string | undefined;
  const venuesRes = await page.request.get("/api/venues?itemsPerPage=100");
  expect(venuesRes.ok(), "GET /api/venues").toBeTruthy();
  const venueId = ((await venuesRes.json()).member?.[0]?.id ?? undefined) as string | undefined;
  expect(teamId, "le club seedé a au moins une équipe").toBeTruthy();
  expect(venueId, "le club seedé a au moins un gymnase").toBeTruthy();

  // NOTRE domicile PLACÉ (amical) compte dans « à saisir dans FBI » (domiciles PLACED).
  const opp = `E2E-FBI-${Date.now()}`;
  const created = await page.request.post("/api/fixtures", {
    data: { teamId, matchDate: nextSaturdayAtLeast(7), homeAway: "HOME", opponentLabel: opp, venueId, kickoffTime: "15:00", status: "PLACED", competitionId: null },
  });
  expect(created.ok(), "POST /api/fixtures").toBeTruthy();
  const id = (await created.json()).id as string;

  try {
    await page.goto("/matchs");
    await landOnMatchesCalendar(page);

    const counter = page.getByRole("button", { name: /FBI à faire/ });
    await expect(counter).toBeVisible({ timeout: 20_000 });
    await counter.click();

    const dialog = page.getByRole("dialog", { name: "FBI — à faire" });
    await expect(dialog).toBeVisible();
    const submitBtn = dialog.getByRole("button", { name: new RegExp(`Marquer saisi.*${opp}`) });
    await expect(submitBtn).toBeVisible();
    await submitBtn.click();

    await expect(page.getByText("Match marqué saisi dans FBI")).toBeVisible();
  } finally {
    await page.request.delete(`/api/fixtures/${id}`).catch(() => undefined);
  }
});

/**
 * L'onglet « Importer » — entrées de données (dépôt FBI, canal FFBB, engagements) et FILE de traitement
 * par équipe, FUSIONNÉ ici (ex-`matches-importer.spec.ts`). Auto-provisionné : présence de l'onglet,
 * absence de badge quand rien n'est à traiter, Configuration allégée (données FBI/FFBB migrées), et la
 * rencontre créée (= traitée) INVISIBLE avant l'interrupteur « Afficher les traitées », VISIBLE après.
 */
test("importer: onglet, badge absent, configuration allégée, file de traitement", async ({ page }) => {
  test.setTimeout(240_000);

  await loginSeededClub(page);
  await ensureValidated(page);

  // L'onglet Importer, sans badge si rien à traiter ; un badge EXACTEMENT égal au compte serveur sinon.
  const fixturesRes = await page.request.get("/api/fixtures?itemsPerPage=100");
  expect(fixturesRes.ok(), "GET /api/fixtures").toBeTruthy();
  const existing = ((await fixturesRes.json()).member ?? []) as { reviewState: string }[];
  const openBefore = existing.filter((f) => "NEW" === f.reviewState || "OUT_OF_SYNC" === f.reviewState).length;
  const tabLabel = 0 === openBefore ? "Importer" : `Importer · ${openBefore}`;

  await page.goto("/matchs");
  await landOnMatchesCalendar(page);
  const importerTab = page.getByRole("navigation", { name: "Espaces matchs" }).getByRole("link", { name: /^Importer/ });
  await expect(importerTab).toBeVisible();
  await expect(importerTab).toHaveText(tabLabel);
  await importerTab.click();
  await expect(page.getByRole("navigation", { name: "Espaces matchs" }).getByRole("link", { name: /^Importer/ })).toHaveAttribute("aria-current", "page");

  // Les entrées de données de match sont là.
  await expect(page.getByRole("button", { name: /Importer FBI/ })).toBeVisible();
  await expect(page.getByRole("button", { name: /Vérifier via le Canal FFBB/i })).toBeVisible();
  await expect(page.getByRole("button", { name: /Engagements FFBB/ })).toBeVisible();
  if (0 === openBefore) {
    await expect(page.getByText("Rien à traiter")).toBeVisible();
  } else {
    // M3 (BCCL 2026-10-27) : l'en-tête d'équipe de la file dit « N nouveautés à voir » (plus « à valider »).
    await expect(page.getByRole("button", { name: /à voir/ }).first()).toBeVisible();
  }

  // Configuration : « une section = un écran », gabarit parti en Semaine type, données FBI/FFBB migrées.
  await page.goto("/matchs/configuration");
  await expect(page.getByRole("button", { name: /Le gabarit idéal/ })).toHaveCount(0);
  await expect(page.getByRole("button", { name: /^Accès match/ })).toHaveAttribute("aria-expanded", "false");
  await expect(page.getByText(/Les créneaux que la mairie accorde/)).toHaveCount(0);
  await page.goto("/matchs/configuration?section=reglages");
  await expect(page.getByText(/Les créneaux que la mairie accorde/)).toBeVisible();
  await expect(page.getByRole("button", { name: /^Modifier les accès match de / }).first()).toBeVisible();
  await expect(page.getByText("Dépôt saisonnier FBI")).toHaveCount(0);
  await expect(page.getByRole("button", { name: "Engagements FFBB" })).toHaveCount(0);

  // Auto-provisionnement : une rencontre créée (= traitée par la saisie).
  const teamsRes = await page.request.get("/api/teams?itemsPerPage=100");
  expect(teamsRes.ok(), "GET /api/teams").toBeTruthy();
  const firstTeam = (await teamsRes.json()).member?.[0] as { id?: string; name?: string } | undefined;
  const teamId = firstTeam?.id;
  const teamName = firstTeam?.name ?? "";
  const venuesRes = await page.request.get("/api/venues?itemsPerPage=100");
  expect(venuesRes.ok(), "GET /api/venues").toBeTruthy();
  const venueId = ((await venuesRes.json()).member?.[0]?.id ?? undefined) as string | undefined;
  expect(teamId, "le club seedé a au moins une équipe").toBeTruthy();

  const opp = `E2E-IMPORTER-${Date.now()}`;
  const createRes = await page.request.post("/api/fixtures", {
    data: { teamId, matchDate: nextSaturdayAtLeast(7), homeAway: "HOME", opponentLabel: opp, venueId, kickoffTime: "15:00", competitionId: null },
  });
  expect(createRes.ok(), "POST /api/fixtures").toBeTruthy();
  const id = (await createRes.json()).id as string;

  try {
    await page.goto("/matchs/importer");
    const oppCell = page.getByText(opp, { exact: false });

    // TÉMOIN — la rencontre créée est TRAITÉE : masquée par défaut, badge inchangé.
    await expect(page.getByRole("navigation", { name: "Espaces matchs" }).getByRole("link", { name: /^Importer/ })).toHaveText(tabLabel);
    if (0 === openBefore) {
      await expect(page.getByText("Rien à traiter")).toBeVisible();
    }
    await expect(oppCell).toHaveCount(0);

    // Révéler les traitées → l'équipe apparaît ; on l'ouvre → la rencontre est là.
    await page.getByRole("checkbox", { name: "Afficher les traitées" }).check();
    const teamHeader = page.getByRole("button", { name: new RegExp(`^${escapeRegExp(teamName)} · `) });
    await expect(teamHeader).toBeVisible();
    if ("true" !== (await teamHeader.getAttribute("aria-expanded"))) {
      await teamHeader.click();
    }
    await expect(oppCell).toBeVisible();
  } finally {
    await page.request.delete(`/api/fixtures/${id}`).catch(() => undefined);
  }
});
