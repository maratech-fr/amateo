import { expect, test } from "./fixtures";
import { settleVeil } from "./support";

/**
 * **Un incident dans la vie d'un club — et l'overlay qui le couvre reste borné à SON plan.**
 *
 * P4-122, dans les mots du fondateur : « être capable de faire […] un incident qui est couvert
 * par le planning d'overlay ». C'est le témoin qui manquait — **celui qui aurait rougi sur le bug
 * du 2026-08-19** : le repli silencieux vers le plan de SAISON quand on regarde une période.
 *
 * ⚑ **Pourquoi le club SEEDÉ, et l'incident QUI EXISTE DÉJÀ, VALIDÉ** (mesuré, pas supposé) :
 * le seed porte exactement la matière du parcours — deux plannings de **reprise** (17-23 et 24-30
 * août, une version COMPLETED chacun) et l'**incident Matéo** : une fermeture du gymnase (31/08 au
 * 16/10) posée DIRECTEMENT SUR LA RACINE et **VALIDÉE** (son plan pointe une version COMPLETED qui
 * transcrit le planning d'overlay réel). Depuis l'arbitrage fondateur 2026-09-02, l'incident n'est
 * plus « à faire » : plus de segment « sem. du … · à faire » à générer, plus de puce à cliquer. On
 * ne le RÉGÉNÈRE donc pas — on LIT son overlay déjà en vigueur, et l'on prouve qu'il ne déborde pas
 * sur le socle.
 *
 * **Le parcours (4 gestes, aucune écriture) :** (0) le socle est là, terminé (`journey.spec` prouve
 * qu'on sait le CONSTRUIRE de zéro ; ici il est le décor dont on vérifie qu'il ne bouge pas) →
 * (1) au cockpit, la carte de l'incident au radar est une fermeture VALIDÉE — son action est « Voir
 * le planning » (`RadarPanel.viewOverlay`), jamais un wizard de génération → (2) elle mène à
 * `/planning`, l'écran de l'overlay, qui porte le badge « Période » que le socle n'a jamais →
 * (3) le socle est toujours terminé, intact → (4) « Tous les plannings » liste le socle, l'overlay
 * (par sa fenêtre) et les deux reprises : trois plannings distincts qui coexistent.
 *
 * ⚠ **Le bornage, PROUVÉ sans le wizard** (le cœur, le bug du 2026-08-19). L'écran EMBARQUÉ du
 * wizard est le seul à porter le sélecteur de versions (`PlanningToolbar`, `embedded`) — et il
 * n'est PLUS sur le chemin d'un overlay DÉJÀ VALIDÉ : « Ajuster » y ouvre le picker de semaines
 * (fragile), « Rouvrir » est destructif. On prouve donc le bornage sur `/planning` AUTONOME de
 * l'overlay : il affiche le badge « Période » (`isSeasonPlanType` faux — JAMAIS rendu pour le
 * socle) et n'expose AUCUN sélecteur de versions (geste embarqué), donc le socle ne peut pas y
 * faire fuir sa version « en vigueur ». Un repli silencieux vers la SAISON aurait, lui, affiché
 * l'écran du socle — sans ce badge. C'est l'assertion de bornage la plus forte atteignable de
 * façon déterministe et NON destructive sur ce nouveau modèle.
 *
 * Le socle et la génération « from scratch », eux, restent couverts par `journey.spec.ts`, qui
 * les fait sur un club neuf, de l'inscription jusqu'à la réouverture.
 *
 * ⚠ **La base e2e n'est JAMAIS réinitialisée** : ce spec est IDEMPOTENT — il LIT un état déjà
 * validé (l'incident VALIDÉ, les reprises COMPLETED) et n'écrit RIEN. En CI comme en local, le
 * même verdict. C'est la leçon de `matches.spec.ts`.
 */

const EMAIL = "mara.mb@bccl.fr";
const PASSWORD = "maraboubccl";

/**
 * L'incident du seed : la fermeture du gymnase Matéo (31 août → 16 octobre 2026), plan créé SUR LA
 * RACINE et VALIDÉ (une version COMPLETED transcrite) — c'est LUI que ce parcours mène jusqu'à son
 * overlay. On le désigne par un fragment de son titre, stable d'un seed à l'autre.
 */
const INCIDENT = "Matéo";

async function login(page: import("./fixtures").Page): Promise<void> {
  await page.goto("/login");
  await page.getByLabel("Email").fill(EMAIL);
  await page.getByLabel("Mot de passe", { exact: true }).fill(PASSWORD);
  await page.getByRole("button", { name: "Se connecter" }).click();
  await expect(page.getByRole("button", { name: "Saison de travail" })).toBeVisible({ timeout: 20_000 });
}

/**
 * Le cockpit, une fois RÉELLEMENT prêt à recevoir un clic.
 *
 * ⚠ `goto("/")` rend la main dès le premier octet : le bandeau du haut arrive avant les cartes du
 * radar, et le voile d'action peut encore couvrir l'écran. Décider « Reprendre ou Adapter ? »
 * entre les deux lit un écran incomplet — le clic frappe un élément qui disparaît, et rien ne
 * navigue.
 */
async function openCockpit(page: import("./fixtures").Page): Promise<void> {
  await page.goto("/");
  await expect(page.getByRole("button", { name: /Tous les plannings/ })).toBeVisible({ timeout: 30_000 });
  await settleVeil(page);
}

/** Les versions offertes par l'écran EMBARQUÉ = la lignée du plan affiché. Vide = rien de généré. */
async function versionsOf(page: import("./fixtures").Page): Promise<string[]> {
  const selector = page.getByRole("combobox", { name: /version du planning/i });

  return (await selector.count()) === 0 ? [] : selector.locator("option").allTextContents();
}

/** Le socle : écran de la version EN VIGUEUR, sans sélecteur (choisir une version est un geste embarqué). */
async function expectSocleIntact(page: import("./fixtures").Page, label: string): Promise<void> {
  await page.goto("/planning");
  await expect(page.getByText("Terminé").first(), `${label} : le socle doit rester terminé`).toBeVisible({ timeout: 30_000 });
  await expect(page.getByRole("combobox", { name: /version du planning/i }), `${label} : /planning est l'écran de la version en vigueur — aucun sélecteur`).toHaveCount(0);
}

test("un incident VALIDÉ ouvre un overlay borné à SON plan, sans toucher au socle", async ({ page }) => {
  // Le spec ne GÉNÈRE plus rien (l'overlay est déjà validé) : il LIT et navigue. Le budget large
  // couvre un runner chargé + les allers-retours cockpit/planning, pas une attente de solveur.
  test.setTimeout(120_000);

  await login(page);

  // --- 0 · Le socle est là, terminé. (`journey.spec` prouve qu'on sait le CONSTRUIRE de zéro ;
  //         ici il est le décor dont on vérifie qu'il ne bouge pas.)
  await expectSocleIntact(page, "avant l'incident");

  // --- 1 · LES PLANNINGS DE REPRISE et l'INCIDENT existent déjà. On ouvre le cockpit ; la
  //         cohabitation des trois plannings sera vérifiée en fin de parcours.
  await openCockpit(page);

  // --- 2 · L'INCIDENT au radar est une fermeture DÉJÀ VALIDÉE. On vise l'ouvreur DE LA CARTE qui
  //         porte son motif, jamais une POSITION dans la liste : `RadarCard` (`RadarPanel.tsx`)
  //         rend son titre dans un `<p>` à l'intérieur d'un conteneur bordé — le filtrer par son
  //         texte désigne LA carte, quel que soit l'ordre d'affichage.
  // ⚠ Borner au REPÈRE d'abord : « Matéo » apparaît aussi dans chaque case du calendrier du mois
  // (« 31 Août … Matéo indisponible (incident) »), donc un filtre sur toute la page attrape le
  // calendrier et jamais la carte. Le radar est un `<aside>` — repère `complementary`.
  const radar = page.getByRole("complementary").filter({ hasText: "À traiter" });
  const card = radar.locator("div.rounded-md.border").filter({ hasText: INCIDENT });
  await expect(card.first(), "l'incident du seed doit avoir sa carte au radar").toBeVisible({ timeout: 30_000 });

  // La carte d'une fermeture VALIDÉE (`ClosureRadarItem`, `hasOverlay`) porte « Voir le planning »
  // (et « Ajuster ») — PAS de puce « sem. du … · à faire » : celles-ci ne naissent que d'une mère
  // DÉCOUPÉE en semaines, cas disparu de ce seed. On CONSULTE l'overlay ; aucune génération n'est
  // lancée (idempotence). `viewOverlay` sélectionne le plan de l'overlay et navigue vers /planning.
  await card.first().getByRole("button", { name: /Voir le planning/ }).first().click();
  await expect(page, "« Voir le planning » doit mener à l'écran /planning de l'overlay").toHaveURL(/\/planning/, { timeout: 30_000 });
  await settleVeil(page);

  // --- 3 · LE TÉMOIN DU BORNAGE — le cœur de ce parcours.
  //
  // Le bug du 2026-08-19 faisait retomber l'écran de période sur le plan de SAISON en silence. Le
  // sélecteur de versions ne vit QUE dans l'atelier embarqué du wizard (`PlanningToolbar`,
  // `embedded`), hors du chemin d'un overlay DÉJÀ VALIDÉ (« Ajuster » ouvre le picker de semaines,
  // « Rouvrir » est destructif). On prouve donc le bornage sur /planning AUTONOME de l'overlay :
  //
  // T1 — l'écran porte le badge « Période » (`PlanningToolbar` : `!isSeasonPlanType(selected)`),
  //      que l'écran du socle n'a JAMAIS. Un repli silencieux vers la saison aurait affiché le
  //      socle, sans ce badge — c'est LA falsification directe du bug fondateur.
  await expect(
    page.getByText("Période", { exact: true }).first(),
    "/planning de l'overlay doit se dire « Période » — un repli sur le socle ne porterait pas ce badge",
  ).toBeVisible({ timeout: 30_000 });

  // T2 — /planning autonome n'expose AUCUN sélecteur de versions (c'est un geste embarqué) : le
  //      socle ne peut donc pas y faire fuir sa version « en vigueur ». La lignée de l'overlay ne
  //      se consulte que dans l'atelier embarqué, hors chemin ici (cf. le docblock).
  expect((await versionsOf(page)).length, "/planning autonome ne montre aucun sélecteur de versions").toBe(0);

  // --- 4 · LE SOCLE N'A PAS BOUGÉ pendant qu'on consultait l'overlay.
  await expectSocleIntact(page, "après l'overlay");

  // --- 5 · LES TROIS PLANNINGS COEXISTENT, chacun avec son état.
  await openCockpit(page);
  await page.getByRole("button", { name: /Tous les plannings/ }).first().click();
  const plannings = page.getByRole("dialog");
  await expect(plannings).toBeVisible({ timeout: 15_000 });
  await expect(plannings, "le socle doit rester listé et VALIDÉ").toContainText("Validé");
  // ⚠ On identifie l'overlay par sa FENÊTRE, pas par son nom — choix d'IDEMPOTENCE. Depuis la
  // décision fondateur 2026-08-23, un plan de période naît nommé du TITRE de son entrée ; mais la
  // base e2e peut encore porter des plans nés sous l'ANCIEN gabarit serveur (« Ajustement
  // gymnase — … »), au nom distinct du titre — s'appuyer sur la fenêtre traverse les deux ères
  // sans jamais mentir.
  await expect(plannings, "l'overlay de l'incident doit être listé à côté du socle").toContainText("31-08-2026 → 16-10-2026");
  // Les DEUX reprises du seed sont là aussi : trois plannings distincts coexistent, chacun avec
  // sa lignée — c'est la « réalité d'un club » que ce parcours doit attester (P4-122).
  await expect(plannings, "les plannings de reprise doivent cohabiter avec le socle et l'overlay").toContainText("Reprise");
});
