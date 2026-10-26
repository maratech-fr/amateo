import type { Locator } from "@playwright/test";

import { expect, test } from "./fixtures";

import { ensureValidated, loginSeededClub, registerAndVerify, uniqueAra } from "./support";

/**
 * P4-271 — les lignes compactes tiennent sur UNE ligne à la largeur bureau (1280 px). jsdom
 * ne calcule aucune mise en page (`boundingBox` y vaut 0), donc le reflow d'une ligne ne se
 * mesure qu'en Playwright. Trois régressions gardées :
 *  - l'étape Coachs : équipe / rôle / Lier partagent le MÊME offsetTop (le `wrapperClassName`
 *    de la régression 2026-09-06 : un TeamSelect pleine largeur poussait « Lier » à la ligne
 *    suivante) ;
 *  - une contrainte au repos tient sur une seule ligne (résumé + ✎ + 🗑, jamais un formulaire
 *    déplié en permanence) ;
 *  - la ligne d'ajout d'une interdiction de gymnase (section Équipes) : équipe + gymnase +
 *    « Interdire » sur la même ligne (PR 3/7 « uniformité des sélecteurs », largeur en
 *    `wrapperClassName` — une largeur restée en `className` n'aurait pas tenu la boîte flex) ;
 *  - la ligne d'ajout d'une règle de club (section Club) : disposition en DEUX rangées (décision
 *    fondateur 2026-10-01 « aérer ») — rangée 1 jours + Type, rangée 2 Pas avant + Pas après +
 *    Ajouter. Tous les contrôles à 36 px (= `h-9`, décision B) ; les trois de la rangée 2 sur la
 *    MÊME ligne (jamais un bouton d'action orphelin sous la ligne) ; Type au-dessus ; 0 débordement
 *    horizontal. Le groupe multi-jours (`DayMultiPicker`, boutons `min-h-6`) est hors mesure.
 */
test.use({ viewport: { width: 1280, height: 900 } });

/** Le haut de la boîte englobante d'un locator (-1 si absente). */
async function topOf(locator: Locator): Promise<number> {
  const box = await locator.boundingBox();
  return box?.y ?? -1;
}

test("étape Coachs : équipe, rôle et « Lier » sur la même ligne (1280 px)", async ({ page }) => {
  test.setTimeout(120_000);

  const ara = uniqueAra("E2EL");
  await registerAndVerify(page, { email: `layout-${ara}@e2e.fr`, ara, firstName: "Lay", lastName: "Out", clubName: "E2E Layout Club" });
  await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });

  // Étape 1 — une équipe (le TeamSelect « Lier » a besoin d'au moins une équipe).
  await page.getByLabel("Nom de l'équipe").fill("SM1");
  await page.getByLabel("Catégorie").selectOption({ label: "Senior" });
  await page.getByRole("button", { name: "Ajouter l'équipe" }).click();
  await expect(page.locator('input[value="SM1"]')).toBeVisible({ timeout: 20_000 });
  await page.getByRole("button", { name: "Suivant" }).click();

  // Étape 2 — un gymnase + un créneau, puis on avance.
  await expect(page.getByRole("heading", { name: /Étape 2\/6/ })).toBeVisible();
  await page.getByLabel("Nom du gymnase").fill("Gymnase E2E");
  await page.getByRole("button", { name: "Ajouter un gymnase" }).click();
  await expect(page.getByLabel("Gymnase", { exact: true })).toContainText("Gymnase E2E");
  await page.getByRole("button", { name: "Lun 18:00", exact: true }).click();
  await page.getByRole("button", { name: "Suivant" }).click();

  // Étape 3 — un coach : la carte créée s'ouvre DÉJÀ en édition, révélant la ligne « Lier »
  // sans clic (le témoin de création est donc le bouton « Terminer l'édition »).
  await expect(page.getByRole("heading", { name: /Étape 3\/6/ })).toBeVisible();
  await page.getByLabel("Prénom").fill("Coa");
  await page.getByLabel("Nom", { exact: true }).fill("Ch");
  await page.getByRole("button", { name: "Ajouter le coach" }).click();
  await expect(page.getByRole("button", { name: "Terminer l'édition" })).toBeVisible({ timeout: 15_000 });

  // On SCOPE à la ligne « Lier » : le rail du wizard porte aussi un bouton d'étape « Équipes »
  // (regex `/^Équipe/`), d'où deux correspondances en portée globale. Les trois contrôles sont
  // enfants directs du même `div.mt-2.flex` que « Lier » (CoachesStep.tsx:245-255).
  const lier = page.getByRole("button", { name: "Lier" });
  await expect(lier).toBeVisible();
  const row = lier.locator("xpath=..");
  const team = row.getByRole("button", { name: /^Équipe/ });
  const role = row.getByRole("combobox", { name: "Rôle" });
  await expect(team).toBeVisible();

  const [teamTop, roleTop, lierTop] = await Promise.all([topOf(team), topOf(role), topOf(lier)]);
  // items-center : les trois contrôles partagent la même ligne → même offsetTop (tolérance 4 px).
  expect(Math.abs(teamTop - roleTop)).toBeLessThanOrEqual(4);
  expect(Math.abs(teamTop - lierTop)).toBeLessThanOrEqual(4);
});

test("contraintes : une règle au repos tient sur une seule ligne (1280 px)", async ({ page }) => {
  test.setTimeout(240_000);

  await loginSeededClub(page);
  await ensureValidated(page);

  await page.goto("/matchs/contraintes?section=club");
  // On crée une règle « pas après 21h » (samedi coché par défaut) via la ligne d'ajout.
  await page.getByLabel("Pas après (heure de fin)").fill("21:00");
  await page.getByRole("button", { name: "Ajouter" }).click();

  // La règle apparaît au repos, compacte : un résumé + « Modifier » + « Supprimer ».
  const modifier = page.getByRole("button", { name: "Modifier" }).first();
  await expect(modifier).toBeVisible({ timeout: 15_000 });

  // Le résumé (span) et le bouton « Modifier » partagent la même ligne → même offsetTop.
  const summaryTop = await page.locator("span", { hasText: /Obligatoire/ }).first().boundingBox().then((b) => b?.y ?? -1);
  const modifierTop = await modifier.boundingBox().then((b) => b?.y ?? -1);
  expect(summaryTop).toBeGreaterThan(0);
  expect(Math.abs(summaryTop - modifierTop)).toBeLessThanOrEqual(6);

  // Nettoyage : on lève la règle qu'on vient de créer (la base de DEV n'est pas réinitialisée).
  await page.getByRole("button", { name: "Supprimer" }).first().click();
  await page.getByRole("dialog").getByRole("button", { name: "Supprimer", exact: true }).click();
});

test("contraintes club : la ligne d'ajout d'une règle est sur DEUX rangées, contrôles à 36 px (1280 px)", async ({ page }) => {
  test.setTimeout(240_000);

  await loginSeededClub(page);
  await ensureValidated(page);

  // La ligne d'AJOUT (AddClubRuleRow) est toujours là, même sans règle existante — rien à créer,
  // donc rien à nettoyer. Disposition en DEUX rangées (décision fondateur 2026-10-01) : rangée 1 =
  // jours + Type ; rangée 2 = Pas avant + Pas après + Ajouter. Le bouton « Ajouter » ancre la
  // rangée 2 (son parent direct), dont le parent est le bloc RuleFields (flex-col) qui porte Type.
  await page.goto("/matchs/contraintes?section=club");

  const ajouter = page.getByRole("button", { name: "Ajouter" });
  await expect(ajouter).toBeVisible({ timeout: 15_000 });
  const row2 = ajouter.locator("xpath=..");
  const block = row2.locator("xpath=..");

  const typeSelect = block.getByRole("combobox", { name: "Type" });
  const avant = row2.getByLabel("Pas avant (heure de début)");
  const apres = row2.getByLabel("Pas après (heure de fin)");
  await expect(typeSelect).toBeVisible();
  await expect(avant).toBeVisible();
  await expect(apres).toBeVisible();

  const [typeBox, avantBox, apresBox, ajouterBox] = await Promise.all([typeSelect, avant, apres, ajouter].map((c) => c.boundingBox()));
  const height = (b: Awaited<ReturnType<Locator["boundingBox"]>>): number => b?.height ?? -1;
  const topY = (b: Awaited<ReturnType<Locator["boundingBox"]>>): number => b?.y ?? -1;

  // Décision fondateur B : tout contrôle fait 36 px (= h-9).
  for (const b of [typeBox, avantBox, apresBox, ajouterBox]) {
    expect(height(b)).toBeGreaterThanOrEqual(35);
    expect(height(b)).toBeLessThanOrEqual(37);
  }

  // Rangée 2 : Pas avant, Pas après et « Ajouter » sur la MÊME ligne (même offsetTop, ±4) — jamais
  // un bouton orphelin tombé seul sous la ligne.
  const row2Tops = [topY(avantBox), topY(apresBox), topY(ajouterBox)];
  expect(Math.max(...row2Tops) - Math.min(...row2Tops)).toBeLessThanOrEqual(4);

  // Structure en DEUX rangées voulue : « Type » (rangée 1) est AU-DESSUS de la rangée 2.
  expect(topY(typeBox)).toBeLessThan(Math.min(...row2Tops) - 4);

  // Aucun débordement horizontal, ni sur la rangée 2 ni sur le bloc (à 1280 px).
  expect(await row2.evaluate((el) => el.scrollWidth - el.clientWidth)).toBeLessThanOrEqual(1);
  expect(await block.evaluate((el) => el.scrollWidth - el.clientWidth)).toBeLessThanOrEqual(1);
});

test("contraintes équipes : équipe, gymnase et « Interdire » sur la même ligne (1280 px)", async ({ page }) => {
  test.setTimeout(240_000);

  await loginSeededClub(page);
  await ensureValidated(page);

  // Section Équipes : la ligne d'ajout d'une interdiction de gymnase par équipe. Depuis la
  // PR 3/7 « uniformité des sélecteurs », équipe et gymnase passent par TeamSelect/VenueSelect
  // (triggers `role="button"`, largeur portée par `wrapperClassName="min-w-32"`) : on garde que
  // les deux sélecteurs riches et le bouton « Interdire » tiennent bien sur UNE ligne — une
  // largeur restée en `className` serait allée au bouton interne, jamais à la boîte que la ligne
  // flex mesure, et aurait pu faire déborder « Interdire » (le piège que le lint garde en amont).
  await page.goto("/matchs/contraintes?section=equipes");

  const interdire = page.getByRole("button", { name: "Interdire" });
  await expect(interdire).toBeVisible({ timeout: 15_000 });

  // La ligne d'ajout (div.flex) est le parent direct du bouton « Interdire » ; les deux sélecteurs
  // riches en sont descendants (enveloppés dans leur `wrapperClassName`).
  const row = interdire.locator("xpath=..");
  const team = row.getByRole("button", { name: /Équipe/ });
  const venue = row.getByRole("button", { name: /Gymnase interdit/ });
  await expect(team).toBeVisible();
  await expect(venue).toBeVisible();

  const [teamTop, venueTop, interdireTop] = await Promise.all([topOf(team), topOf(venue), topOf(interdire)]);
  // items-center : les trois contrôles partagent la même ligne → même offsetTop (tolérance 4 px).
  expect(teamTop).toBeGreaterThan(0);
  expect(Math.abs(teamTop - venueTop)).toBeLessThanOrEqual(4);
  expect(Math.abs(teamTop - interdireTop)).toBeLessThanOrEqual(4);
});

test("builder wizard (contraintes) : la ligne d'ajout est sur DEUX rangées, contrôles à 36 px (1280 px)", async ({ page }) => {
  test.setTimeout(240_000);

  await loginSeededClub(page);
  await ensureValidated(page);

  // Le builder de contraintes du WIZARD (étape 4/6, famille « Horaires » par défaut) partage le
  // risque de `matches/ConstraintsPage` que P4-283 vient de reprendre : un unique conteneur
  // flex-wrap où le bouton `ml-auto` tombe en orphelin dès que la ligne déborde à 1280 px. La
  // reprise impose DEUX rangées (patron RuleFields/CoachFields) : rangée 1 = la sélection
  // (« Cible »…), rangée 2 = les valeurs (heures) + « Ajouter la contrainte » à droite. Le
  // jumeau jsdom (`steps/ConstraintsStep.test.tsx`) prouve la STRUCTURE DOM ; ici on MESURE ce
  // que seul le navigateur voit (36 px, deux rangées, 0 débordement).
  await page.goto("/wizard?step=constraints");
  await expect(page.getByRole("heading", { name: /Étape 4\/6/ })).toBeVisible({ timeout: 30_000 });

  // Le bouton « Ajouter la contrainte » ancre la rangée 2 (son parent direct) ; le parent de
  // celle-ci est le bloc à deux rangées, qui porte « Cible » dans sa rangée 1.
  const ajouter = page.getByRole("button", { name: "Ajouter la contrainte" });
  await expect(ajouter).toBeVisible({ timeout: 30_000 });
  const row2 = ajouter.locator("xpath=..");
  const block = row2.locator("xpath=..");

  const cible = block.getByRole("combobox", { name: "Cible" });
  const avant = row2.getByLabel("Pas avant", { exact: true });
  const apres = row2.getByLabel("Pas après", { exact: true });
  await expect(cible).toBeVisible();
  await expect(avant).toBeVisible();
  await expect(apres).toBeVisible();

  const [cibleBox, avantBox, apresBox, ajouterBox] = await Promise.all([cible, avant, apres, ajouter].map((c) => c.boundingBox()));
  const height = (b: Awaited<ReturnType<Locator["boundingBox"]>>): number => b?.height ?? -1;
  const topY = (b: Awaited<ReturnType<Locator["boundingBox"]>>): number => b?.y ?? -1;

  // Décision fondateur B : tout contrôle fait 36 px (= h-9).
  for (const b of [cibleBox, avantBox, apresBox, ajouterBox]) {
    expect(height(b)).toBeGreaterThanOrEqual(35);
    expect(height(b)).toBeLessThanOrEqual(37);
  }

  // Rangée 2 : Pas avant, Pas après et « Ajouter la contrainte » sur la MÊME ligne (±4) — jamais
  // un bouton d'action orphelin tombé seul sous la ligne.
  const row2Tops = [topY(avantBox), topY(apresBox), topY(ajouterBox)];
  expect(Math.max(...row2Tops) - Math.min(...row2Tops)).toBeLessThanOrEqual(4);

  // Structure en DEUX rangées voulue : « Cible » (rangée 1) est AU-DESSUS de la rangée 2.
  expect(topY(cibleBox)).toBeGreaterThan(0);
  expect(topY(cibleBox)).toBeLessThan(Math.min(...row2Tops) - 4);

  // Aucun débordement horizontal, ni sur la rangée 2 ni sur le bloc (à 1280 px).
  expect(await row2.evaluate((el) => el.scrollWidth - el.clientWidth)).toBeLessThanOrEqual(1);
  expect(await block.evaluate((el) => el.scrollWidth - el.clientWidth)).toBeLessThanOrEqual(1);
});

/**
 * D10 — la ligne d'ajout d'un gymnase (étape 2 du wizard) ne déborde plus à l'étroit.
 *
 * ⚠ MÉCANISME RÉEL (vérifié par mesure, 2026-10-26) : le `Button` porte DÉJÀ `whitespace-nowrap`
 * (`button.tsx:20`) ET `size="sm"` lui fixe une hauteur `h-9` (36 px). Le bouton ne peut donc NI
 * passer sur plusieurs lignes (texte `nowrap`) NI grandir en hauteur (hauteur fixe) : « hauteur bbox »
 * n'attrape RIEN ici. La régression est un DÉBORDEMENT HORIZONTAL de la rangée `flex items-end gap-2`
 * (`VenuesStep.tsx:519`, SANS `flex-wrap`) : le libellé long « Ajouter un gymnase » rend le bouton
 * incompressible à ~188 px (sous `white-space:nowrap`, son `min-width:auto` = la largeur PLEINE du
 * texte), et à largeur réduite la rangée (CP 80 px + bouton 188 px + marges + champ nom) dépasse le
 * formulaire. Le correctif — libellé visible court « Ajouter » (~97 px) + complément sr-only +
 * `shrink-0` — fait rentrer la rangée. On MESURE donc le débordement horizontal, pas une hauteur.
 *
 * Largeur de reproduction : 320 px (bien en deçà du garde-fou 360 px — patron de reflow sr-only
 * d'`OpponentsPage`) pour une marge franche de part et d'autre (rouge avant, vert après). jsdom ne
 * calcule aucune mise en page : ce débordement ne se voit qu'en Playwright. Le jumeau jsdom
 * (`VenuesStep.addButtonLabel.test.tsx`) prouve, lui, le libellé court + sr-only + nom accessible.
 */
test("étape Gymnases : la ligne « Ajouter » ne déborde pas à 320 px (reflow du libellé)", async ({ page }) => {
  test.setTimeout(120_000);

  const ara = uniqueAra("E2EV");
  await registerAndVerify(page, { email: `venueadd-${ara}@e2e.fr`, ara, firstName: "Ven", lastName: "Add", clubName: "E2E Venue Add" });
  await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });

  // Étape 1 — une équipe (sinon « Suivant » ne mène pas à l'étape 2), puis on avance.
  await page.getByLabel("Nom de l'équipe").fill("SM1");
  await page.getByLabel("Catégorie").selectOption({ label: "Senior" });
  await page.getByRole("button", { name: "Ajouter l'équipe" }).click();
  await expect(page.locator('input[value="SM1"]')).toBeVisible({ timeout: 20_000 });
  await page.getByRole("button", { name: "Suivant" }).click();

  // Étape 2 — SANS gymnase, l'accordéon « Ajouter des gymnases » est ouvert par défaut : la ligne
  // d'ajout (et son bouton) est rendue directement, sans clic.
  await expect(page.getByRole("heading", { name: /Étape 2\/6/ })).toBeVisible();

  // On RÉTRÉCIT à une largeur qui crée la pression : à 1280 px la ligne tient trivialement.
  await page.setViewportSize({ width: 320, height: 800 });

  const addButton = page.getByRole("button", { name: "Ajouter un gymnase" });
  await expect(addButton).toBeVisible({ timeout: 15_000 });
  // Témoin : le champ de nom (flex-1) est bien présent sur la ligne — sinon on ne mesurerait pas
  // le vrai reflow, et le test serait vert par vacuité.
  await expect(page.getByRole("textbox", { name: "Nom du gymnase" })).toBeVisible();

  // La rangée d'ajout tient dans son formulaire SANS débordement horizontal à 320 px.
  const form = addButton.locator("xpath=ancestor::form");
  const overflow = await form.evaluate((el) => el.scrollWidth - el.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);

  // Second angle : le bouton ne dépasse pas le bord droit du CONTENU du formulaire (padding/bordure
  // retirés) — le débordement du libellé long poussait son bord droit au-delà.
  const buttonFits = await addButton.evaluate((btn) => {
    const f = btn.closest("form");
    if (null === f) return false;
    const cs = getComputedStyle(f);
    const rightInset = (parseFloat(cs.paddingRight) || 0) + (parseFloat(cs.borderRightWidth) || 0);
    return btn.getBoundingClientRect().right <= f.getBoundingClientRect().right - rightInset + 1;
  });
  expect(buttonFits).toBe(true);
});
