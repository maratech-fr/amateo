import type { Browser, Request, Response } from "@playwright/test";

import { expect, test, type Page } from "./fixtures";
import { loginSeededClub, registerAndVerify, settleVeil, uniqueAra, watchFailedApiCalls } from "./support";

/**
 * PROMESSE (chantier « Demandes d'adhésion et rôle Membre », PR 2) : **un Membre lit tout, ne
 * modifie rien** — et le navigateur ne le trahit jamais. Sur chaque écran de l'app, un Membre :
 *  - ne déclenche AUCUNE écriture (aucune requête non-GET vers `/api/*` au montage, hors
 *    `matches/module-visit` et `release-notes/seen`, qui sont des marqueurs personnels) ;
 *  - ne déclenche AUCUN appel sortant (`/api/ffbb/*`, `/api/geocode*`, `/api/venues/geo-check`,
 *    `…/email-preview`) ;
 *  - ne reçoit AUCUN 403 (l'écran ne tente pas un geste réservé) ;
 *  - ne voit AUCUN lien personnel de doléance (`/doleances/` absent du DOM) ;
 *  - ne voit aucun bouton d'écriture, et lit la bannière « Consultation : réservé aux
 *    gestionnaires » là où l'écran est une saisie.
 * Variantes : club SANS planning (`/wizard` en lecture, navigation libre, pas de toast « première
 * génération ») ; club qui FERME (dernier gestionnaire parti → bandeau daté, sans bouton).
 *
 * Seul un navigateur voit ces trois choses ENSEMBLE (requêtes réellement émises par l'écran monté,
 * DOM rendu, bannières) — jsdom les prouve écran par écran (Vitest), le serveur les refuse en 403
 * (`MemberWriteSweepTest`, `MemberReadsAreSideEffectFreeTest`). Ce spec est le filet de bout en bout.
 *
 * Décor : le club seedé (planning validé, matchs) pour le parcours nominal ; un club NEUF pour les
 * variantes. Le Membre naît par le geste réel : inscription sur le code FFBB du club (adhésion en
 * attente) puis approbation par le gestionnaire `POST /api/memberships/{id}/approve {"role":"member"}`.
 * Nettoyage en `finally` : chaque compte créé s'efface (`DELETE /api/me`).
 *
 * ⚠ Le hub des doléances n'est pas parcouru : le club seedé ne garantit pas une collecte ouverte.
 * Sa promesse (états et adresses visibles, aucun lien) est prouvée par `SollicitationTab.test.tsx`
 * (Membre) et, côté serveur, par `MemberReadsAreSideEffectFreeTest` (jeton absent).
 */

const PASSWORD = "Password123!";
const READ_ONLY_NOTICE = "Consultation : réservé aux gestionnaires";
/**
 * Les requêtes non-GET permises au Membre en naviguant : ses marqueurs personnels, et le contrôle
 * des contraintes avant génération (une LECTURE pure en POST, ouverte au Membre — classée `open:`
 * dans `MemberWriteSweepTest`, prouvée sans effet par `MemberReadsAreSideEffectFreeTest`).
 */
const ALLOWED_WRITES = [/^\/api\/matches\/module-visit$/, /^\/api\/release-notes\/seen$/, /^\/api\/constraints\/validate$/];
const OUTBOUND = [/^\/api\/ffbb\//, /^\/api\/geocode/, /^\/api\/venues\/geo-check/, /\/email-preview$/];
const WRITE_BUTTON = /^(Générer|Lancer la génération|Enregistrer|Ajouter|Supprimer|Valider|Rouvrir|Importer|Approuver|Copier le lien|Appliquer|Tout appliquer|Relancer)/;

interface Watch {
  violations: string[];
}

/** Arme la surveillance réseau du Membre : écritures, sortants, 403 — collectés, assertés à chaque écran. */
function watchMember(page: Page): Watch {
  const watch: Watch = { violations: [] };
  page.on("request", (request: Request) => {
    const url = new URL(request.url());
    if (!url.pathname.startsWith("/api/")) return;
    if (OUTBOUND.some((re) => re.test(url.pathname))) {
      watch.violations.push(`appel sortant ${request.method()} ${url.pathname}`);
    }
    if ("GET" !== request.method() && "HEAD" !== request.method() && !ALLOWED_WRITES.some((re) => re.test(url.pathname))) {
      watch.violations.push(`écriture ${request.method()} ${url.pathname}`);
    }
  });
  page.on("response", (response: Response) => {
    const url = new URL(response.url());
    if (url.pathname.startsWith("/api/") && 403 === response.status()) {
      watch.violations.push(`403 ${response.request().method()} ${url.pathname}`);
    }
  });
  return watch;
}

/**
 * Ouvre un écran comme le Membre et vérifie les invariants communs ; `banner` dit si la bannière y est
 * attendue ; `writeButtons: false` pour un écran PERSONNEL (`/profile`) où « Enregistrer » et
 * « Supprimer définitivement mon compte » sont légitimes (`PATCH`/`DELETE /api/me` ouverts au Membre).
 */
async function visit(page: Page, watch: Watch, path: string, banner: boolean, writeButtons = true): Promise<void> {
  await page.goto(path);
  await settleVeil(page);
  // Témoin positif : l'écran est RENDU (un titre de page), sinon tout ce qui suit serait vrai sur du vide.
  await expect(page.getByRole("heading").first(), `${path} : aucun titre rendu`).toBeVisible({ timeout: 20_000 });

  const notice = page.getByText(READ_ONLY_NOTICE);
  if (banner) {
    await expect(notice.first(), `${path} : bannière de consultation attendue`).toBeVisible();
  } else {
    await expect(notice, `${path} : pas de bannière de consultation ici`).toHaveCount(0);
  }
  if (writeButtons) {
    await expect(page.getByRole("button", { name: WRITE_BUTTON }), `${path} : un bouton d'écriture est offert au Membre`).toHaveCount(0);
  }
  expect(await page.content(), `${path} : un lien personnel de doléance est dans le DOM`).not.toContain("/doleances/");
  expect(watch.violations, `${path} : le Membre a déclenché ${watch.violations.join(" · ")}`).toEqual([]);
}

/** Le gestionnaire (page dédiée) approuve l'adhésion en attente de `email` comme Membre. */
async function approveAsMember(manager: Page, email: string): Promise<void> {
  let pendingId = "";
  await expect
    .poll(async () => {
      const res = await manager.request.get("/api/memberships/pending");
      if (!res.ok()) return "";
      const members = ((await res.json()) as { members?: { id: string; email: string }[] }).members ?? [];
      pendingId = members.find((m) => m.email === email)?.id ?? "";
      return pendingId;
    }, { timeout: 15_000 })
    .not.toBe("");
  const approved = await manager.request.post(`/api/memberships/${pendingId}/approve`, { data: { role: "member" } });
  expect(approved.status(), "le gestionnaire approuve l'adhésion en Membre").toBe(200);
}

/** Inscrit un compte sur le code FFBB `ara` (adhésion en attente), puis le fait approuver Membre. */
async function becomeMember(page: Page, manager: Page, ara: string, email: string): Promise<void> {
  await registerAndVerify(page, { email, ara, password: PASSWORD, firstName: "Paul", lastName: "Membre" });
  await approveAsMember(manager, email);
  await page.goto("/");
  const me = await page.request.get("/api/me");
  const body = (await me.json()) as { role?: string; membershipStatus?: string };
  expect(body.membershipStatus, "le Membre est actif").toBe("active");
  expect(body.role, "le rôle servi est « member »").toBe("member");
}

async function eraseAccount(page: Page): Promise<void> {
  await page.request.delete("/api/me", { data: { password: PASSWORD } }).catch(() => undefined);
}

async function newPage(browser: Browser): Promise<Page> {
  const context = await browser.newContext();
  const page = await context.newPage();
  // La fixture n'observe que la page par défaut : la page du gestionnaire s'arme elle-même.
  watchFailedApiCalls(page);
  return page;
}

test.describe("Membre — lecture seule de bout en bout", () => {
  test("club établi : chaque écran se lit, rien ne s'écrit, rien ne sort", async ({ page, browser }) => {
    test.setTimeout(240_000);
    const manager = await newPage(browser);
    const email = `membre-${uniqueAra("m").toLowerCase()}@test.fr`;
    let locatedVenueId = "";
    try {
      await loginSeededClub(manager);
      const meRes = await manager.request.get("/api/me");
      const ara = ((await meRes.json()) as { club?: { ffbbClubCode?: string | null } }).club?.ffbbClubCode ?? "";
      expect(ara, "le club seedé porte un code FFBB").not.toBe("");
      // Un gymnase LOCALISÉ SANS adresse (coordonnées seules) : l'écran Gymnases retrouverait son
      // adresse par `GET /api/geocode/reverse` — un appel sortant qu'un Membre ne doit jamais
      // déclencher. Créé ici par le gestionnaire (le seed n'en garantit pas), effacé en `finally`.
      const venue = await manager.request.post("/api/venues", {
        data: { name: `Gymnase coordonnées ${uniqueAra("g")}`, source: "manual", latitude: "45.76799", longitude: "4.88853" },
      });
      expect(venue.ok(), "le gestionnaire crée un gymnase localisé sans adresse").toBe(true);
      locatedVenueId = ((await venue.json()) as { id: string }).id;

      await becomeMember(page, manager, ara, email);
      const watch = watchMember(page);

      await visit(page, watch, "/", false);
      await visit(page, watch, "/planning", false);
      for (const sub of ["consulter", "semaine-type", "conflits"]) {
        await visit(page, watch, `/matchs/${sub}`, false);
      }
      for (const sub of ["importer", "configuration", "contraintes", "adversaires", "reconciliation"]) {
        await visit(page, watch, `/matchs/${sub}`, true);
      }
      for (const step of ["teams", "venues", "coaches", "constraints", "recap", "generate"]) {
        await visit(page, watch, `/wizard?step=${step}`, true);
      }
      await visit(page, watch, "/club", true);
      // `/club` : ni Demandes d'adhésion, ni Invitations, ni Membres.
      await expect(page.getByRole("button", { name: /Demandes d'adhésion/ })).toHaveCount(0);
      await expect(page.getByRole("button", { name: /^Invitations$/ })).toHaveCount(0);
      await expect(page.getByRole("button", { name: /^Membres$/ })).toHaveCount(0);
      // `/profile` : écran PERSONNEL — ses boutons d'écriture sont ceux du Membre lui-même.
      await visit(page, watch, "/profile", false, false);
      await visit(page, watch, "/nouveautes", false);
    } finally {
      await eraseAccount(page);
      if ("" !== locatedVenueId) {
        await manager.request.delete(`/api/venues/${locatedVenueId}`).catch(() => undefined);
      }
      await manager.context().close();
    }
  });

  test("club sans planning, puis club qui ferme : lecture libre, bandeau daté sans bouton", async ({ page, browser }) => {
    test.setTimeout(240_000);
    const manager = await newPage(browser);
    const ara = uniqueAra("MBR");
    const managerEmail = `gest-${ara.toLowerCase()}@test.fr`;
    const memberEmail = `membre-${ara.toLowerCase()}@test.fr`;
    let managerErased = false;
    try {
      // Un club NEUF (jamais généré) : son gestionnaire naît par l'inscription réelle.
      await registerAndVerify(manager, { email: managerEmail, ara, password: PASSWORD, clubName: `Club ${ara}` });
      await becomeMember(page, manager, ara, memberEmail);
      const watch = watchMember(page);

      // Sans planning : l'accueil renvoie à l'assistant, en LECTURE, sans le toast « première génération ».
      await page.goto("/");
      await expect(page).toHaveURL(/\/wizard/);
      await settleVeil(page);
      await expect(page.getByText(READ_ONLY_NOTICE).first()).toBeVisible();
      await expect(page.getByText("Lancez votre première génération d'abord.")).toHaveCount(0);
      // Navigation libre : les étapes suivantes ne sont pas verrouillées pour un Membre.
      await expect(page.getByRole("button", { name: /Coachs/ }).first()).toBeEnabled();
      expect(watch.violations, `le Membre a déclenché ${watch.violations.join(" · ")}`).toEqual([]);

      // Le dernier gestionnaire s'efface : le club ferme.
      const erased = await manager.request.delete("/api/me", { data: { password: PASSWORD } });
      expect(erased.ok(), "le dernier gestionnaire efface son compte").toBe(true);
      managerErased = true;

      await page.goto("/club");
      await settleVeil(page);
      const closing = page.getByRole("status").filter({ hasText: /n'a plus de gestionnaire/ });
      await expect(closing).toBeVisible();
      await expect(closing).toContainText(/\d{2}\/\d{2}\/\d{4}/);
      await expect(closing.getByRole("button")).toHaveCount(0);
    } finally {
      await eraseAccount(page);
      if (!managerErased) {
        await eraseAccount(manager);
      }
      await manager.context().close();
    }
  });
});
