import { mkdirSync, writeFileSync } from "node:fs";
import { dirname } from "node:path";

import { test as setup } from "./fixtures";

import { loginAsSuperAdmin, superAdminFromEnv, SUPERADMIN_STORAGE_STATE } from "./support-admin";

/**
 * Projet `setup` Playwright — ouvre UNE seule session superadmin par run et la fige dans un
 * `storageState`, que le projet `superadmin` réutilise (voir `playwright.config.ts`).
 *
 * ⚠ **POURQUOI, et ce que ça règle.** Le firewall `admin_auth` plafonne à 5 tentatives / 15 min
 * PAR IP (`backend/config/packages/rate_limiter.yaml`). Chaque spec superadmin qui se reloguait,
 * MULTIPLIÉ par `retries: 2` en CI, franchissait ce plafond : le 429 se déguisait alors en « la
 * console ne s'ouvre pas après le TOTP » (`support-admin.ts` l.101, run rouge du 2026-09-15). Une
 * session unique, figée ici et réutilisée, ramène le run à UN login — bien sous le quota.
 *
 * ⚠ **Sans préflight** (un `npx playwright test` direct, sans `make -C frontend e2e` qui sème le
 * compte), `superAdminFromEnv()` rend `null`. On écrit alors un `storageState` VIDE — le projet
 * `superadmin` peut malgré tout créer son contexte, et ses specs se SKIPPENT via leur propre garde
 * `test.skip(null === credentials, …)`. AUCUN login n'est tenté, aucun jeton `admin_auth` consommé.
 */
setup("session superadmin unique (storageState)", async ({ page }) => {
  mkdirSync(dirname(SUPERADMIN_STORAGE_STATE), { recursive: true });

  const credentials = superAdminFromEnv();
  if (null === credentials) {
    // Le fichier DOIT exister avant que le projet `superadmin` crée son contexte : un état vide
    // est valide et n'ouvre aucune session.
    writeFileSync(SUPERADMIN_STORAGE_STATE, JSON.stringify({ cookies: [], origins: [] }));
    setup.skip(true, "préflight superadmin absent — état vide écrit, les specs superadmin se skipperont.");
    return;
  }

  await loginAsSuperAdmin(page, credentials);
  // La session stateful `/api/admin/**` vit dans un cookie httpOnly ; `storageState` le capture
  // (lecture CDP, httpOnly compris) et le rejoue tel quel dans le projet superadmin.
  await page.context().storageState({ path: SUPERADMIN_STORAGE_STATE });
});
