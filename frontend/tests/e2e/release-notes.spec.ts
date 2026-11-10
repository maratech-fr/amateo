import type { APIRequest, APIRequestContext, Page } from "@playwright/test";

import { expect, test } from "./fixtures";
import { expectNoContrastViolations, forceTheme, isoLocal, loginSeededClub } from "./support";
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

/** Le jeton CSRF de la session SA figée : `GET /api/admin/auth/me` le relit (ou l'émet) — `AdminAuthController::me`. */
async function adminCsrf(request: APIRequestContext): Promise<string> {
  const me = await request.get("/api/admin/auth/me");
  expect(me.ok(), `GET /api/admin/auth/me (${me.status()})`).toBeTruthy();
  const token = (await me.json()).csrfToken as unknown;
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

      // Filigrane et publication sont stockés À LA SECONDE (`TIMESTAMP(0)`) et la modale compare
      // `publishedAt > seenUpTo` strictement : une note publiée dans la même seconde que le filigrane
      // ne s'ouvrirait pas. On attend donc que l'horloge (la même que celle du serveur, conteneurs
      // sur un seul hôte) ait passé la seconde du filigrane avant de publier.
      const journal = await member.request.get("/api/release-notes");
      expect(journal.ok(), "GET /api/release-notes (relecture du filigrane)").toBeTruthy();
      const seenUpTo = Date.parse((await journal.json()).seenUpTo as string);
      expect(Number.isNaN(seenUpTo), "le filigrane du membre est posé").toBe(false);
      await expect.poll(() => Date.now(), { message: "l'horloge a passé la seconde du filigrane", timeout: 5_000 }).toBeGreaterThanOrEqual(seenUpTo + 1_000);

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

  /**
   * Promesse (PR B — A8, XSS stockée, + AA des 5 couleurs) : **une note riche publiée par la SA arrive
   * chez le membre mise en forme (titre, puce, numéros, gras, 5 couleurs) dans la modale ET sur
   * `/nouveautes`, et une balise HTML saisie comme texte s'y affiche LITTÉRALEMENT** — aucun `<img>`
   * créé, aucune alerte déclenchée ; les 5 couleurs passent l'AA sur la page, en clair puis en sombre.
   * Ce que jsdom ne voit pas : le navigateur réel (exécution d'un `onerror`, couleurs calculées).
   * Rendu unitaire : `rich-text.test.tsx` ; refus serveur des formes interdites : `ReleaseNoteRichDocTest`.
   */
  test("A8 — une note riche s'affiche mise en forme, la balise saisie reste du texte, 5 couleurs AA (clair, sombre)", async ({ page, browser, baseURL }) => {
    test.setTimeout(180_000);

    const payload = "<img src=x onerror=alert(1)>";
    const colorWords = { rouge: "Rouge AA", orange: "Orange AA", vert: "Vert AA", bleu: "Bleu AA", gris: "Gris AA" } as const;
    const text = (value: string, marks?: unknown[]) => (marks ? { type: "text", text: value, marks } : { type: "text", text: value });
    const item = (...content: unknown[]) => ({ type: "listItem", content });
    const bodyRich = {
      type: "doc",
      content: [
        { type: "heading", attrs: { level: 3 }, content: [text("Titre riche e2e")] },
        { type: "bulletList", content: [item({ type: "paragraph", content: [text(payload, [{ type: "bold" }, { type: "noteColor", attrs: { name: "bleu" } }])] })] },
        { type: "orderedList", attrs: { start: 3 }, content: [item({ type: "paragraph", content: [text("Troisième étape")] })] },
        {
          type: "paragraph",
          content: Object.entries(colorWords).flatMap(([name, word], index) => [
            ...(index > 0 ? [text(" ")] : []),
            text(word, [{ type: "noteColor", attrs: { name } }]),
          ]),
        },
      ],
    };

    const lightContext = await browser.newContext({ baseURL });
    const member = await lightContext.newPage();
    const alerts: string[] = [];
    member.on("dialog", (dialog) => {
      alerts.push(dialog.message());
      void dialog.dismiss();
    });
    let noteId: string | null = null;
    const csrf = await adminCsrf(page.request);
    let darkContext: Awaited<ReturnType<typeof browser.newContext>> | null = null;

    try {
      await forceTheme(member, "light");
      await loginSeededClub(member);
      expect((await member.request.post("/api/release-notes/seen")).status(), "POST /api/release-notes/seen (filigrane)").toBe(204);
      const journal = await member.request.get("/api/release-notes");
      const seenUpTo = Date.parse((await journal.json()).seenUpTo as string);
      expect(Number.isNaN(seenUpTo), "le filigrane du membre est posé").toBe(false);
      // Même raison que le parcours ci-dessus : filigrane et publication à la SECONDE, comparaison stricte.
      await expect.poll(() => Date.now(), { message: "l'horloge a passé la seconde du filigrane", timeout: 5_000 }).toBeGreaterThanOrEqual(seenUpTo + 1_000);

      // La SA publie la note riche par l'API (le corps riche seul : le texte brut est dérivé serveur).
      const title = `${TITLE_PREFIX}A8 ${Date.now().toString(36)}`;
      const created = await page.request.post("/api/admin/release-notes", {
        headers: { "X-CSRF-Token": csrf },
        data: { title, bodyRich, noteDate: isoLocal(new Date()), version: "1.3.5" },
      });
      expect(created.status(), `POST /api/admin/release-notes (${await created.text()})`).toBe(201);
      noteId = (await created.json()).id as string;
      const published = await page.request.post(`/api/admin/release-notes/${noteId}/publish`, { headers: { "X-CSRF-Token": csrf } });
      expect(published.ok(), `POST /publish (${published.status()})`).toBeTruthy();

      // (1) La modale : titre de note h3, titre du corps h4, la balise en TEXTE dans un <strong> d'une puce.
      await reloadAndAwaitJournal(member);
      const dialog = member.getByRole("dialog", { name: "Quoi de neuf" });
      await expect(dialog).toBeVisible({ timeout: 15_000 });
      await expect(dialog.getByRole("heading", { level: 3, name: title })).toBeVisible();
      await expect(dialog.getByRole("heading", { level: 4, name: "Titre riche e2e" })).toBeVisible();
      await expect(dialog.locator("li strong").filter({ hasText: payload })).toBeVisible();
      await expect(dialog.locator('img[src="x"]')).toHaveCount(0);
      await dialog.getByRole("button", { name: "Fermer" }).click();
      await expect(dialog).toBeHidden();

      // (2) `/nouveautes`, thème clair : structure, littéral, aucun <img>, couleurs = jetons, AA.
      await member.goto("/nouveautes");
      const titleHeading = member.getByRole("heading", { level: 2, name: title });
      await expect(titleHeading).toBeVisible({ timeout: 15_000 });
      const card = member.getByRole("listitem").filter({ has: titleHeading });
      await expect(card.getByRole("heading", { level: 3, name: "Titre riche e2e" })).toBeVisible();
      await expect(card.locator("ul li strong").filter({ hasText: payload })).toBeVisible();
      await expect(card.locator('ol[start="3"] li')).toHaveText("Troisième étape");
      await expect(member.locator('img[src="x"]')).toHaveCount(0);

      // Témoin : chaque couleur est PEINTE par un jeton (une couleur ignorée hériterait du texte et
      // passerait l'AA par construction) — et les cinq sont distinctes.
      const painted = await card.evaluate((li: HTMLElement, words: string[]) => {
        return words.map((word) => {
          const el = Array.from(li.querySelectorAll("span, strong, em")).find((node) => node.textContent === word) as HTMLElement | undefined;
          const host = el?.closest("p");
          const color = el ? getComputedStyle(el).color : null;
          return { word, color, inherits: !el || !host || color === getComputedStyle(host).color };
        });
      }, Object.values(colorWords));
      expect(painted.filter((entry) => entry.inherits), "chaque mot coloré porte sa propre couleur").toEqual([]);
      expect(new Set(painted.map((entry) => entry.color)).size, "5 couleurs distinctes").toBe(5);
      await expectNoContrastViolations(member, "/nouveautes · note riche 5 couleurs (light)");

      // (3) Même page en thème sombre (contexte neuf : le thème se pose AVANT le démarrage de l'app).
      darkContext = await browser.newContext({ baseURL });
      const dark = await darkContext.newPage();
      dark.on("dialog", (d) => {
        alerts.push(d.message());
        void d.dismiss();
      });
      await forceTheme(dark, "dark");
      await loginSeededClub(dark);
      await dark.goto("/nouveautes");
      await expect(dark.getByRole("heading", { level: 2, name: title })).toBeVisible({ timeout: 15_000 });
      await expectNoContrastViolations(dark, "/nouveautes · note riche 5 couleurs (dark)");

      expect(alerts, "aucune alerte JavaScript : la balise saisie n'a pas été interprétée").toEqual([]);
    } finally {
      if (null !== noteId) {
        await deleteNote(page.request, csrf, noteId);
      }
      await darkContext?.close();
      await lightContext.close();
    }
  });
});
