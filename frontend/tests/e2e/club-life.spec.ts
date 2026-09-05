import { expect, test } from "./fixtures";
import { settleVeil } from "./support";

/**
 * **Un incident dans la vie d'un club — l'overlay qui le couvre reste borné à SON plan.**
 *
 * P4-122, dans les mots du fondateur : « être capable de faire […] un incident qui est couvert
 * par le planning d'overlay ». C'est le témoin qui manquait — **celui qui aurait rougi sur le bug
 * du 2026-08-19** : le repli silencieux vers le plan de SAISON quand on regarde une période.
 *
 * ⚑ **Pourquoi le club SEEDÉ, et l'incident QUI EXISTE DÉJÀ, VALIDÉ** : le seed porte exactement
 * la matière du parcours — deux plannings de **reprise** (17-23 et 24-30 août) et l'**incident
 * Matéo** : une fermeture du gymnase (31/08 au 16/10). Depuis le découpage début·milieu·fin
 * (fondateur 2026-09-05), l'incident ne porte PLUS de plan sur sa racine : il est découpé en deux
 * enfants-segments — un **milieu** (31/08 → 11/10, les six semaines pleines) et une **fin** (semaine
 * du 12/10), chacun un plan VALIDÉ transcrivant l'overlay réel. Les deux bouts étant COUVERTS,
 * l'incident quitte le radar (une carte de couverture ne vit que tant qu'il reste une semaine à
 * traiter) : on le retrouve dans **« Tous les plannings »**, par la fenêtre de chaque segment.
 *
 * **Le parcours (lecture seule, aucune génération) :** (0) le socle est là, terminé (`journey.spec`
 * prouve qu'on sait le CONSTRUIRE de zéro ; ici il est le décor dont on vérifie qu'il ne bouge pas)
 * → (1) « Tous les plannings » liste le socle validé, les DEUX segments de l'incident (par leur
 * fenêtre) et les deux reprises : ils coexistent → (2) **Consulter** le plan du MILIEU mène à
 * `/planning`, l'écran de l'overlay, qui porte le badge « Période » que le socle n'a jamais et
 * n'expose AUCUN sélecteur de versions → (3) le socle est toujours terminé, intact.
 *
 * ⚠ **Le bornage** (le cœur, le bug du 2026-08-19) est prouvé sur `/planning` AUTONOME de l'overlay
 * du milieu : badge « Période » (`isSeasonPlanType` faux — JAMAIS rendu pour le socle) et aucun
 * sélecteur de versions embarqué. Un repli silencieux vers la SAISON aurait affiché l'écran du
 * socle, sans ce badge.
 *
 * ⚠ **La base e2e n'est JAMAIS réinitialisée par ce spec** : il LIT un état déjà validé et n'écrit
 * RIEN — même verdict en CI comme en local. C'est la leçon de `matches.spec.ts`.
 */

const EMAIL = "mara.mb@bccl.fr";
const PASSWORD = "maraboubccl";

/** Les fenêtres des deux segments de l'incident, telles que « Tous les plannings » les affiche. */
const MILIEU_WINDOW = "31-08-2026 → 11-10-2026";
const FIN_WINDOW = "12-10-2026 → 18-10-2026";

async function login(page: import("./fixtures").Page): Promise<void> {
  await page.goto("/login");
  await page.getByLabel("Email").fill(EMAIL);
  await page.getByLabel("Mot de passe", { exact: true }).fill(PASSWORD);
  await page.getByRole("button", { name: "Se connecter" }).click();
  await expect(page.getByRole("button", { name: "Saison de travail" })).toBeVisible({ timeout: 20_000 });
}

async function openCockpit(page: import("./fixtures").Page): Promise<void> {
  await page.goto("/");
  await expect(page.getByRole("button", { name: /Tous les plannings/ })).toBeVisible({ timeout: 30_000 });
  await settleVeil(page);
}

/** Les versions offertes par l'écran EMBARQUÉ = la lignée du plan affiché. Vide = geste embarqué absent. */
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

test("un incident VALIDÉ (découpé en milieu + fin) : chaque overlay reste borné à SON plan, le socle intact", async ({ page }) => {
  // Le spec ne GÉNÈRE plus rien (les overlays sont déjà validés) : il LIT et navigue. Le budget
  // large couvre un runner chargé + les allers-retours cockpit/planning, pas une attente de solveur.
  test.setTimeout(120_000);

  await login(page);

  // --- 0 · Le socle est là, terminé (décor dont on vérifie qu'il ne bouge pas).
  await expectSocleIntact(page, "avant l'incident");

  // --- 1 · L'incident est DÉCOUPÉ (fondateur 2026-09-05) : plus de plan sur la racine, mais deux
  //         segments VALIDÉS (milieu + fin). Couverts, ils quittent le radar — on les retrouve dans
  //         « Tous les plannings », par leur fenêtre. Les trois plannings (socle, incident, reprises)
  //         coexistent, chacun avec son état.
  await openCockpit(page);
  await page.getByRole("button", { name: /Tous les plannings/ }).first().click();
  const plannings = page.getByRole("dialog");
  await expect(plannings).toBeVisible({ timeout: 15_000 });
  await expect(plannings, "le socle doit rester listé et VALIDÉ").toContainText("Validé");
  // On identifie les segments par leur FENÊTRE (choix d'idempotence : un plan de période naît nommé
  // du titre de son entrée, mais la base peut porter d'anciens gabarits — la fenêtre traverse les ères).
  await expect(plannings, "le MILIEU de l'incident est listé par sa fenêtre").toContainText(MILIEU_WINDOW);
  await expect(plannings, "la FIN de l'incident est listée par sa fenêtre").toContainText(FIN_WINDOW);
  await expect(plannings, "les plannings de reprise cohabitent avec le socle et l'incident").toContainText("Reprise");

  // --- 2 · LE TÉMOIN DU BORNAGE — Consulter le plan du MILIEU mène à /planning « Période ».
  //
  // Le bug du 2026-08-19 faisait retomber l'écran de période sur le plan de SAISON en silence. On
  // ouvre l'overlay du milieu par « Consulter » (plan pointé → /planning en lecture seule), puis :
  const milieuRow = plannings.locator("li").filter({ hasText: MILIEU_WINDOW });
  await expect(milieuRow, "la ligne du milieu doit être unique dans la liste").toHaveCount(1);
  await milieuRow.getByRole("button", { name: /^Consulter/ }).click();
  await expect(page, "« Consulter » doit mener à l'écran /planning de l'overlay du milieu").toHaveURL(/\/planning/, { timeout: 30_000 });
  await settleVeil(page);

  // T1 — l'écran porte le badge « Période » (`PlanningToolbar` : `!isSeasonPlanType(selected)`), que
  //      l'écran du socle n'a JAMAIS. Un repli silencieux vers la saison aurait affiché le socle,
  //      sans ce badge — LA falsification directe du bug fondateur.
  await expect(
    page.getByText("Période", { exact: true }).first(),
    "/planning de l'overlay doit se dire « Période » — un repli sur le socle ne porterait pas ce badge",
  ).toBeVisible({ timeout: 30_000 });

  // T2 — /planning autonome n'expose AUCUN sélecteur de versions (geste embarqué) : le socle ne peut
  //      donc pas y faire fuir sa version « en vigueur ».
  expect((await versionsOf(page)).length, "/planning autonome ne montre aucun sélecteur de versions").toBe(0);

  // --- 3 · LE SOCLE N'A PAS BOUGÉ pendant qu'on consultait l'overlay.
  await expectSocleIntact(page, "après l'overlay");
});
