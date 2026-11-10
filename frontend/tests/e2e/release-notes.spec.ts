import type { APIRequest, APIRequestContext, Page } from "@playwright/test";

import { expect, test } from "./fixtures";
import { isoLocal, loginSeededClub } from "./support";
import { SUPERADMIN_STORAGE_STATE, superAdminFromEnv } from "./support-admin";

/**
 * Journal de nouveautés, de la console au membre — le seul parcours qui relie les deux surfaces.
 *
 * Promesses (ce que jsdom ne peut pas voir, ou qui traverse deux sessions réelles) :
 *  1. **une note publiée par la SA s'ouvre UNE fois chez le membre, annoncée « jj/mm/aaaa - v1.3.5 »,
 *     et la croix suffit à la marquer lue** : au rechargement, plus de modale (horodatage serveur) ;
 *  2. **sur `/nouveautes`, le corps d'une note occupe sa carte** : `getComputedStyle(p).maxWidth`
 *     vaut `none` (la borne `max-w-prose` de `FichePage` n'est pas émise sur cette page — l'ABSENCE
 *     de la classe est gardée par `ReleaseNotesPage.test.tsx` / `fiche-page.test.tsx`, l'EFFET ici).
 *
 * Les règles unitaires (une seule note, compteur, toutes les sorties, ordre en-tête → titre → corps)
 * vivent dans `WhatsNewModal.test.tsx` / `ReleaseNotesPage.test.tsx` ; la normalisation de la
 * version dans `backend/tests/Integration/Api/ReleaseNotesTest.php`. Ce spec garde ses LITTÉRAUX
 * (témoin indépendant des clés de textes).
 *
 * ⚠ `release_note` est une table GLOBALE : une note e2e oubliée s'ouvrirait chez TOUS les specs club
 * (la modale a déjà fait flaker `journey.spec.ts`). D'où le balayage par PRÉFIXE de titre en
 * `beforeAll` ET `afterAll`, plus la suppression de la note du run en `finally` — chaque suppression
 * vérifiée (204, ou 404 déjà partie).
 *
 * Projet `superadmin` (session SA figée, `retries: 0`) ; le membre vit dans un contexte NEUF.
 */

const TITLE_PREFIX = "[e2e nouveautés] ";

const credentials = superAdminFromEnv();

async function adminCsrf(request: APIRequestContext): Promise<string> {
  const session = await request.get("/api/admin/session");
  expect(session.ok(), `GET /api/admin/session (${session.status()})`).toBeTruthy();
  const token = (await session.json()).csrfToken as unknown;
  expect(typeof token, "la session admin porte un jeton CSRF").toBe("string");
  return token as string;
}

async function deleteNote(request: APIRequestContext, csrf: string, id: string): Promise<void> {
  const res = await request.delete(`/api/admin/release-notes/${id}`, { headers: { "X-CSRF-Token": csrf } });
  expect([204, 404], `suppression de la note e2e ${id} (${res.status()})`).toContain(res.status());
}

/** Balayage IDEMPOTENT : toute note dont le titre porte le préfixe e2e (résidu d'un run tué compris). */
async function sweepE2eNotes(request: APIRequestContext): Promise<void> {
  const csrf = await adminCsrf(request);
  const list = await request.get("/api/admin/release-notes");
  expect(list.ok(), `GET /api/admin/release-notes (${list.status()})`).toBeTruthy();
  const items = ((await list.json()).items ?? []) as { id: string; title: string }[];
  for (const note of items.filter((item) => item.title.startsWith(TITLE_PREFIX))) {
    await deleteNote(request, csrf, note.id);
  }
}

async function withAdminRequest(playwright: { request: APIRequest }, baseURL: string | undefined, run: (request: APIRequestContext) => Promise<void>): Promise<void> {
  const request = await playwright.request.newContext({ baseURL, storageState: SUPERADMIN_STORAGE_STATE });
  try {
    await run(request);
  } finally {
    await request.dispose();
  }
}

/** Recharge et attend la lecture du journal : l'absence de modale ne se conclut qu'APRÈS la réponse. */
async function reloadAndAwaitJournal(member: Page): Promise<void> {
  const journal = member.waitForResponse((res) => new URL(res.url()).pathname === "/api/release-notes" && res.request().method() === "GET");
  await member.reload();
  expect((await journal).ok(), "GET /api/release-notes après rechargement").toBeTruthy();
}

test.describe("journal de nouveautés — de la console au membre", () => {
  test.skip(null === credentials, "préflight superadmin absent — lancez `make -C frontend e2e` (il sème le compte et exporte son secret TOTP).");

  test.beforeAll(async ({ playwright }, testInfo) => {
    await withAdminRequest(playwright, testInfo.project.use.baseURL, sweepE2eNotes);
  });

  test.afterAll(async ({ playwright }, testInfo) => {
    await withAdminRequest(playwright, testInfo.project.use.baseURL, sweepE2eNotes);
  });

  test("une note publiée s'ouvre une fois, « date - version », la croix la marque lue ; la page la montre pleine carte", async ({ page, browser, baseURL }) => {
    test.setTimeout(120_000);

    // (1) Le MEMBRE, dans un contexte neuf (la session SA ne doit pas voyager) : il se connecte, et
    // son filigrane de lecture est posé MAINTENANT — seule une note publiée après s'ouvrira.
    const memberContext = await browser.newContext({ baseURL });
    const member = await memberContext.newPage();
    let noteId: string | null = null;
    const csrf = await adminCsrf(page.request);

    try {
      await loginSeededClub(member);
      const seen = await member.request.post("/api/release-notes/seen");
      expect(seen.status(), "POST /api/release-notes/seen (filigrane du membre)").toBe(204);

      // (2) La SA publie une note versionnée par l'API, depuis sa session figée.
      const today = new Date();
      const title = `${TITLE_PREFIX}${Date.now().toString(36)}`;
      const body = "Le corps de la note e2e, assez long pour s'étaler sur la largeur de la carte quand rien ne le borne à la longueur de ligne lisible.";
      const created = await page.request.post("/api/admin/release-notes", {
        headers: { "X-CSRF-Token": csrf },
        data: { title, body, noteDate: isoLocal(today), version: "1.3.5" },
      });
      expect(created.status(), `POST /api/admin/release-notes (${await created.text()})`).toBe(201);
      noteId = (await created.json()).id as string;
      const published = await page.request.post(`/api/admin/release-notes/${noteId}/publish`, { headers: { "X-CSRF-Token": csrf } });
      expect(published.ok(), `POST /publish (${published.status()})`).toBeTruthy();

      // (3) Le membre recharge : UNE modale, annoncée « jj/mm/aaaa - v1.3.5 », titre de la note.
      await reloadAndAwaitJournal(member);
      const dialog = member.getByRole("dialog", { name: "Quoi de neuf" });
      await expect(dialog).toBeVisible({ timeout: 15_000 });
      await expect(member.getByRole("dialog")).toHaveCount(1);
      const [y, m, d] = isoLocal(today).split("-");
      await expect(dialog.getByText(`${d}/${m}/${y} - v1.3.5`, { exact: true })).toBeVisible();
      await expect(dialog.getByRole("heading", { level: 3, name: title })).toBeVisible();

      // La CROIX seule : la lecture part au serveur, la modale se ferme…
      const seenOnClose = member.waitForResponse((res) => new URL(res.url()).pathname === "/api/release-notes/seen" && res.request().method() === "POST");
      await dialog.getByRole("button", { name: "Fermer" }).click();
      expect((await seenOnClose).status(), "la croix enregistre la lecture (POST /seen)").toBe(204);
      await expect(dialog).toBeHidden();

      // …et ne revient pas au rechargement.
      await reloadAndAwaitJournal(member);
      await expect(member.getByRole("dialog", { name: "Quoi de neuf" })).toHaveCount(0);

      // (4) `/nouveautes` : en-tête → titre h2 → corps, et le corps n'est PAS borné à 65 caractères.
      await member.goto("/nouveautes");
      const titleHeading = member.getByRole("heading", { level: 2, name: title });
      await expect(titleHeading).toBeVisible({ timeout: 15_000 });
      const card = member.getByRole("listitem").filter({ has: titleHeading });
      const order = await card.evaluate((li: HTMLElement, expectedHeading: string) => {
        const nodes = Array.from(li.querySelectorAll("p, h2"));
        return nodes.map((node) => (node.tagName === "H2" ? "titre" : node.textContent?.trim() === expectedHeading ? "en-tête" : "corps"));
      }, `${d}/${m}/${y} - v1.3.5`);
      expect(order.slice(0, 3), "ordre de lecture de la carte").toEqual(["en-tête", "titre", "corps"]);

      const maxWidths = await card.evaluate((li: HTMLElement) => Array.from(li.querySelectorAll("p")).map((p) => getComputedStyle(p).maxWidth));
      expect(maxWidths.length, "la carte porte des paragraphes").toBeGreaterThan(0);
      expect(maxWidths, "aucun paragraphe de la carte n'est borné (max-w-prose non émis sur /nouveautes)").toEqual(maxWidths.map(() => "none"));
    } finally {
      // (5) Nettoyage : la note du run (même si une assertion a cassé), puis le contexte membre.
      if (null !== noteId) {
        await deleteNote(page.request, csrf, noteId);
      }
      await memberContext.close();
    }
  });
});
