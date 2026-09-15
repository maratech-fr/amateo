import { createHmac } from "node:crypto";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

import { expect, type Page } from "@playwright/test";

/**
 * Le `storageState` où le projet `setup` (`superadmin.setup.ts`) fige LA session superadmin du
 * run, et que le projet `superadmin` réutilise (voir `playwright.config.ts`). Chemin ABSOLU,
 * source unique partagée par la config et le setup — pour qu'ils ne dérivent jamais l'un de
 * l'autre. Le dossier `.auth/` est ignoré par git (`tests/e2e/.gitignore`).
 */
export const SUPERADMIN_STORAGE_STATE = join(dirname(fileURLToPath(import.meta.url)), ".auth", "superadmin.json");

/**
 * Socle e2e de la console superadmin — le premier parcours qui franchit `/admin`.
 *
 * ⚠ **Aucune surface de production n'est ajoutée pour ça, et c'est délibéré.** La voie
 * évidente aurait été une route `/api/dev/*` délivrant une session admin, sur le patron des
 * dev-routes existantes (`DevClockController` : `%kernel.debug%` + 404 en prod). Refusée :
 * un simulateur d'horloge qui fuiterait en prod est un incident ; une porte qui délivre une
 * session SUPERADMIN qui fuiterait est la compromission complète de la surface cross-tenant
 * (bypass RLS, tous les clubs). Le TOTP est calculé ICI, dans le test, à partir d'un secret
 * semé côté hôte — le backend ne connaît aucun chemin de contournement.
 */

/** Décodage base32 (RFC 4648) — le format dans lequel la commande imprime le secret. */
function base32Decode(input: string): Buffer {
  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  const clean = input.replace(/=+$/, "").toUpperCase();
  let bits = 0;
  let value = 0;
  const out: number[] = [];
  for (const char of clean) {
    const index = alphabet.indexOf(char);
    if (index < 0) {
      throw new Error(`Secret TOTP invalide : caractère « ${char} » hors alphabet base32.`);
    }
    value = (value << 5) | index;
    bits += 5;
    if (bits >= 8) {
      bits -= 8;
      out.push((value >>> bits) & 0xff);
    }
  }
  return Buffer.from(out);
}

/**
 * Le code TOTP courant (RFC 6238).
 *
 * Paramètres pris du provisioning URI qu'imprime `app:superadmin:create` :
 * `algorithm=SHA1&digits=6&period=30`. Les recopier ici plutôt que de les deviner — un
 * décalage silencieux donnerait un 401 impossible à diagnostiquer depuis le test.
 */
export function totpCode(secret: string, at: number = Date.now()): string {
  const counter = Math.floor(at / 1000 / 30);
  const buffer = Buffer.alloc(8);
  buffer.writeBigUInt64BE(BigInt(counter));
  const mac = createHmac("sha1", base32Decode(secret)).update(buffer).digest();
  const offset = mac[mac.length - 1]! & 0x0f;
  const binary = ((mac[offset]! & 0x7f) << 24) | (mac[offset + 1]! << 16) | (mac[offset + 2]! << 8) | mac[offset + 3]!;
  return String(binary % 1_000_000).padStart(6, "0");
}

export interface SuperAdminCredentials {
  email: string;
  password: string;
  secret: string;
}

/**
 * Les identifiants semés par la cible `make e2e` (côté hôte), ou `null` si le préflight n'a
 * pas tourné.
 *
 * ⚠ On rend `null` plutôt que de jeter : un développeur qui lance `npx playwright test`
 * directement, sans le préflight, doit voir un SKIP explicite — pas un échec cryptique qui
 * ressemble à une régression.
 */
export function superAdminFromEnv(): SuperAdminCredentials | null {
  const email = process.env.E2E_SUPERADMIN_EMAIL;
  const password = process.env.E2E_SUPERADMIN_PASSWORD;
  const secret = process.env.E2E_SUPERADMIN_TOTP_SECRET;
  if (!email || !password || !secret) {
    return null;
  }
  return { email, password, secret };
}

/**
 * Ouvre une session superadmin par les VRAIS écrans (mot de passe puis TOTP), pas par l'API :
 * le parcours fait partie de ce qu'on veut voir tenir.
 */
export async function loginAsSuperAdmin(page: Page, credentials: SuperAdminCredentials): Promise<void> {
  await page.goto("/admin");

  // Par ID et non par libellé : « Mot de passe » attrape aussi le bouton d'affichage du
  // `PasswordInput` (deux éléments, strict mode violation).
  await page.locator("#admin-email").fill(credentials.email);
  await page.locator("#admin-password").fill(credentials.password);
  await page.getByRole("button", { name: "Continuer" }).click();

  const code = page.locator("#admin-totp");
  await expect(code, "l'écran TOTP doit suivre le mot de passe (MFA obligatoire, SA0)").toBeVisible({ timeout: 15_000 });

  // ⚠ Le code est calculé au MOMENT de la saisie, pas au début du test : une fenêtre TOTP
  // dure 30 s et le parcours qui précède peut la traverser.
  await code.fill(totpCode(credentials.secret));
  await page.getByRole("button", { name: "Ouvrir la console" }).click();

  await expect(page.getByRole("heading", { name: /parc|vue d'ensemble|clubs/i }).first(), "la console doit s'ouvrir après le TOTP").toBeVisible({ timeout: 20_000 });
}
