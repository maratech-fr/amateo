import { expect, test } from "./fixtures";

import { superAdminFromEnv } from "./support-admin";

/**
 * La liste des clubs de la console superadmin, sur la vraie stack : la colonne « Dernière
 * activité » se lit en mots (« Aujourd'hui », « Hier », « Il y a N jours », « Jamais ») avec son
 * sens dans le caption, la recherche garde le terme saisi et dit le vide, une ligne se déplie sur
 * ses membres. Le CALCUL (jour Europe/Paris, paire 22:30/23:30 UTC) est prouvé au plus bas
 * (`AdminDashboardPage.test.tsx`, `lib/relativeDays.test.ts`) ; ici, le parcours réel — API servie
 * en RFC 3339, session superadmin, rendu navigateur.
 *
 * Projet `superadmin` (session figée par `superadmin.setup.ts`, zéro login ici). Littéraux
 * volontaires : `tests/e2e/` est le témoin indépendant des clés de textes.
 */
const credentials = superAdminFromEnv();

const RELATIVE_LABEL = /^(Aujourd'hui|Hier|Il y a \d+ jours|Jamais)$/;

test.describe("console superadmin : la liste des clubs", () => {
  test.skip(null === credentials, "préflight superadmin absent — lancez `make -C frontend e2e` (il sème le compte et exporte son secret TOTP).");

  test("dernière activité lisible, recherche conservée, ligne dépliée sur ses membres", async ({ page }) => {
    test.setTimeout(90_000);
    await page.goto("/admin?tab=clubs");

    const table = page.getByRole("table", { name: /Liste des comptes clubs/ });
    await expect(table, "la table des clubs doit être rendue").toBeVisible({ timeout: 20_000 });
    await expect(table.locator("caption")).toContainText("dernière activité : ouverture de l'app par un utilisateur du club ou génération lancée, au jour près");
    await expect(table.getByRole("columnheader", { name: "Dernière activité" })).toBeVisible();

    // Témoin positif : sans club, chaque assertion de ligne serait vraie du vide.
    const rows = table.locator("tbody tr").filter({ has: page.getByRole("button", { name: "Actions" }) });
    await expect(rows.first(), "la console doit lister au moins un club (décor de la stack)").toBeVisible();

    // Chaque cellule « Dernière activité » (2ᵉ colonne) dit un libellé relatif — jamais une date brute.
    const count = await rows.count();
    for (let i = 0; i < count; i++) {
      const firstLine = rows.nth(i).locator("td").nth(1).locator("p, time").first();
      await expect(firstLine).toHaveText(RELATIVE_LABEL);
    }

    // Recherche sans résultat : le vide est dit, le terme reste dans le champ.
    const search = page.getByRole("searchbox", { name: "Rechercher un club" });
    await search.fill("zzz-aucun");
    await page.getByRole("button", { name: "Rechercher" }).click();
    await expect(page.getByText("Aucun club ne correspond à « zzz-aucun ».")).toBeVisible();
    await expect(search).toHaveValue("zzz-aucun");

    // Retour à la liste, puis une ligne dépliée montre ses membres.
    await search.fill("");
    await page.getByRole("button", { name: "Rechercher" }).click();
    await expect(rows.first()).toBeVisible();
    // Le bouton change de nom et d'état au clic : on le vise par un nom qui couvre les deux états
    // (« Afficher… » / « Masquer le détail de … »), jamais par `expanded: false` qui ne matche plus après.
    const toggle = rows.first().getByRole("button", { name: /^(Afficher|Masquer) le détail de / });
    await expect(toggle).toHaveAttribute("aria-expanded", "false");
    await expect(toggle).toHaveAccessibleName(/^Afficher le détail de /);
    await toggle.click();
    await expect(toggle).toHaveAttribute("aria-expanded", "true");
    await expect(toggle).toHaveAccessibleName(/^Masquer le détail de /);
    await expect(table.getByText("Membres", { exact: true }).first()).toBeVisible();
  });
});
