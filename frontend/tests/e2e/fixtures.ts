import { test as base } from "@playwright/test";

import { watchFailedApiCalls } from "./support";

/**
 * **Le `test` de ce dépôt — celui qui dit POURQUOI quand un parcours casse.**
 *
 * ⚑ Écrit après l'avoir payé deux fois dans la même journée (2026-08-21, PR #684 puis #687) :
 * `journey.spec.ts` est tombé en CI sur `expect(page.locator('input[value="SM1"]')).toBeVisible()`
 * — « element(s) not found », trois tentatives, puis une relance complète VERTE. Ce message dit
 * ce qui MANQUE à l'écran ; il ne dit pas si le serveur a refusé la création, s'il a répondu 422,
 * ou si c'est la liste qui ne s'est pas rafraîchie. Deux enquêtes pour rien.
 *
 * Cette fixture enregistre donc les appels d'API en échec et les **attache au rapport quand, et
 * seulement quand, le test tombe**.
 *
 * ⚠ **Ce n'est PAS une assertion, et c'est délibéré.** Des 4xx LÉGITIMES existent dans ces
 * parcours : gardes fail-closed, refus de rôle, 404 anti-énumération des pages à token. En faire
 * un échec transformerait un comportement voulu en faux rouge. On collecte, on n'accuse pas.
 *
 * ⚑ **Pourquoi une fixture et pas un hook de config ni un helper appelé partout** : `globalSetup`
 * n'a pas accès à `page` (il tourne avant tout navigateur), et un helper à appeler dans chaque
 * spec serait recopié neuf fois — donc oublié au dixième. Une fixture `auto` s'applique sans que
 * personne ait à y penser : il suffit d'importer `test` d'ici plutôt que de `@playwright/test`.
 *
 * ⚠ Limite déclarée : la fixture n'observe que la `page` par défaut du test. Aucun spec n'ouvre
 * aujourd'hui une seconde page ; le jour où l'un le fera, il devra appeler `watchFailedApiCalls`
 * lui-même sur la sienne.
 */
export const test = base.extend<{ apiFailureWatch: void }>({
  apiFailureWatch: [
    async ({ page }, use, testInfo) => {
      const failures = watchFailedApiCalls(page);

      await use();

      if (testInfo.status !== testInfo.expectedStatus && failures.length > 0) {
        await testInfo.attach("appels-api-en-echec", { body: failures.join("\n"), contentType: "text/plain" });
      }
    },
    { auto: true },
  ],
});

export { expect } from "@playwright/test";
// Les specs importaient aussi leurs TYPES d'ici : on les fait suivre, pour qu'un seul import suffise.
export type { ConsoleMessage, Locator, Page } from "@playwright/test";
