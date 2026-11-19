import type { Browser, Request, Response } from "@playwright/test";

import { expect, test, type Page } from "./fixtures";
import { COCKPIT_TEXTS } from "../../src/features/cockpit/texts";
import { PLANNING_TEXTS } from "../../src/features/planning/texts";
import { SESSION_TEXTS } from "../../src/shared/session/texts";
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
 * génération ») ; club qui FERME (dernier gestionnaire parti → bandeau daté, dont le SEUL bouton est
 * « Demander à devenir gestionnaire », PR 3).
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

/**
 * PR6 (occupations) — décor du menu Exporter vu par le Membre, posé par le GESTIONNAIRE et par l'API
 * (geste réel) : une occupation « École » de SAISON (la couche que `/planning` affiche, le socle
 * pointé — `useVersionLanding.ts:70`), sur une heure libre de la grille de saison d'un gymnase du club
 * (un chevauchement serait refusé 422). Effacée en `finally`.
 */
async function createSeasonOccupancy(manager: Page): Promise<string> {
  const members = <T>(body: unknown): T[] => (Array.isArray(body) ? body : (((body as { member?: T[] }).member ?? []) as T[]));
  const venues = members<{ id: string; name: string }>(await (await manager.request.get("/api/venues")).json()).filter((v) => !v.name.startsWith("Gymnase coordonnées"));
  const venue = venues[0];
  expect(venue, "décor : le club seedé porte au moins un gymnase").toBeDefined();
  if (undefined === venue) {
    throw new Error("décor non tenu : aucun gymnase");
  }
  // Les créneaux de SAISON de ce gymnase (sans `schedulePlanId`) : on cherche une heure LIBRE d'une heure.
  const busy: { day: number; start: number; end: number }[] = [];
  for (let pageNumber = 1; pageNumber <= 10; pageNumber += 1) {
    const res = await manager.request.get(`/api/venue_training_slots?venueId=${venue.id}&page=${pageNumber}`);
    const batch = members<{ dayOfWeek: number; startTime: string; durationMinutes: number }>(await res.json());
    for (const s of batch) {
      const [hh, mm] = (s.startTime.match(/(\d{2}):(\d{2})/)?.slice(1) ?? ["0", "0"]).map(Number);
      const start = hh * 60 + mm;
      busy.push({ day: s.dayOfWeek, start, end: start + s.durationMinutes });
    }
    if (batch.length < 30) break;
  }
  let chosen: { day: number; start: number } | null = null;
  for (let day = 1; day <= 7 && null === chosen; day += 1) {
    for (let start = 9 * 60; start <= 21 * 60 && null === chosen; start += 15) {
      if (!busy.some((b) => b.day === day && start < b.end && b.start < start + 60)) {
        chosen = { day, start };
      }
    }
  }
  if (null === chosen) {
    throw new Error("décor non tenu : aucune heure libre dans la grille de saison du gymnase");
  }
  const startTime = `${String(Math.floor(chosen.start / 60)).padStart(2, "0")}:${String(chosen.start % 60).padStart(2, "0")}`;
  const occupancy = await manager.request.post("/api/venue_occupancies", {
    headers: { "Content-Type": "application/json" },
    data: { venueId: venue.id, dayOfWeek: chosen.day, startTime, durationMinutes: 60, label: "École" },
  });
  expect(occupancy.status(), `pose de l'occupation de saison (HTTP ${occupancy.status()})`).toBe(201);
  return ((await occupancy.json()) as { id: string }).id;
}

test.describe("Membre — lecture seule de bout en bout", () => {
  test("club établi : chaque écran se lit, rien ne s'écrit, rien ne sort", async ({ page, browser }) => {
    test.setTimeout(240_000);
    const manager = await newPage(browser);
    const email = `membre-${uniqueAra("m").toLowerCase()}@test.fr`;
    let locatedVenueId = "";
    let occupancyId = "";
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
      occupancyId = await createSeasonOccupancy(manager);

      await becomeMember(page, manager, ara, email);
      const watch = watchMember(page);

      await visit(page, watch, "/", false);
      // PR6 (occupations) — le Membre exporte ce qu'il lit : il ouvre le planning de saison depuis
      // l'accueil (« Ouvrir », `SeasonPlanBanner.tsx`, sans garde de rôle), puis le menu Exporter
      // (`PlanningPage.tsx` `rightSlot`, sans garde de rôle) lui offre « Afficher les occupations »
      // dès que la couche affichée porte une occupation (`ExportMenu.tsx`, `offersOccupancies`).
      await page.getByRole("button", { name: COCKPIT_TEXTS.open, exact: true }).click();
      await expect(page, "« Ouvrir » mène le Membre au planning").toHaveURL(/\/planning/);
      await settleVeil(page);
      await page.getByRole("button", { name: PLANNING_TEXTS.exportButton }).click();
      await expect(page.getByRole("checkbox", { name: PLANNING_TEXTS.exportShowOccupancies }), "/planning : le Membre ne voit pas « Afficher les occupations » dans Exporter").toBeVisible();
      expect(watch.violations, `/planning (Exporter) : le Membre a déclenché ${watch.violations.join(" · ")}`).toEqual([]);
      await visit(page, watch, "/planning", false);
      for (const sub of ["consulter", "semaine-type", "conflits"]) {
        await visit(page, watch, `/matchs/${sub}`, false);
      }
      for (const sub of ["importer", "configuration", "contraintes", "adversaires", "reconciliation"]) {
        await visit(page, watch, `/matchs/${sub}`, true);
      }
      for (const step of ["teams", "venues", "coaches", "constraints", "recap", "generate"]) {
        await visit(page, watch, `/wizard?step=${step}`, true);
        if ("teams" === step) {
          // PR 1a — l'onglet « Séances par jour » de la modale Liens est un écran d'écriture : le Membre
          // l'ouvre (lecture), son champ est en lecture seule, et l'ouvrir n'écrit rien.
          await settleVeil(page);
          await page.getByRole("button", { name: /^Liens de / }).first().click();
          const dialog = page.getByRole("dialog");
          await dialog.getByRole("tab", { name: "Séances par jour" }).click();
          await expect(dialog.getByLabel("Séances max par jour"), "témoin : le champ du plafond est rendu, en lecture seule").toBeDisabled();
          expect(watch.violations, `onglet Séances par jour : le Membre a déclenché ${watch.violations.join(" · ")}`).toEqual([]);
          await page.keyboard.press("Escape");
        }
        if ("venues" === step) {
          // Le Membre CONSULTE le gymnase localisé sans adresse (choisir un gymnase est une lecture) :
          // sa carte s'affiche repliée « Localisé », sans retrouver l'adresse par un appel sortant.
          await settleVeil(page);
          await page.locator("#venue-picker").click();
          await page.getByRole("option", { name: /Gymnase coordonnées/ }).click();
          await expect(page.getByText(/Gymnase coordonnées/).first(), "témoin : la carte du gymnase localisé est ouverte").toBeVisible();
          await expect(page.getByRole("link", { name: "Voir sur la carte" }).first(), "témoin : la vue repliée « Localisé » est rendue").toBeVisible();
          expect(watch.violations, `gymnase localisé sans adresse : le Membre a déclenché ${watch.violations.join(" · ")}`).toEqual([]);
          // PR6 (occupations) — le choix [Créneau | Occupation] et le champ « Motif » sont des gestes
          // de gestionnaire : un Membre ne les voit pas sur les Gymnases de saison. Étape atteinte
          // par l'adresse `/wizard?step=venues` que l'app lit (`WizardLayout.tsx`, deep link), pas
          // par un état injecté. Jumeaux : `VenuesStep.occupancy.test.tsx` (« Membre : ni barre de
          // pose… ») et le 403 serveur (`MemberWriteSweepTest`, `/api/venue_occupancies`).
          await expect(page.getByRole("button", { name: /^Occupation$/ }), "Gymnases de saison : le sélecteur Créneau | Occupation est offert au Membre").toHaveCount(0);
          await expect(page.getByRole("textbox", { name: /Motif/ }), "Gymnases de saison : le champ « Motif » est offert au Membre").toHaveCount(0);
        }
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
      if ("" !== occupancyId) {
        await manager.request.delete(`/api/venue_occupancies/${occupancyId}`).catch(() => undefined);
      }
      await manager.context().close();
    }
  });

  test("club sans planning, puis club qui ferme : lecture libre, bandeau daté dont le seul geste est la demande", async ({ page, browser }) => {
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
      // PR 3 : le bandeau porte UN geste, et un seul — demander à devenir gestionnaire (la reprise
      // part à l'adresse officielle). Aucun autre bouton dans le bandeau, aucun bouton d'écriture
      // ailleurs sur l'écran : le Membre n'obtient rien de plus qu'une demande.
      const bannerButtons = closing.getByRole("button");
      await expect(bannerButtons).toHaveCount(1);
      await expect(bannerButtons.first()).toHaveAccessibleName(SESSION_TEXTS.requestManagerRole);
      // Relevé SWITCH : UN seul geste à l'écran — la carte « Mon rôle » de /club ne le répète pas.
      await expect(page.getByRole("button", { name: SESSION_TEXTS.requestManagerRole }), "/club (club qui ferme) : le geste de demande est répété").toHaveCount(1);
      await expect(page.getByRole("button", { name: WRITE_BUTTON }), "/club (club qui ferme) : un bouton d'écriture est offert au Membre").toHaveCount(0);
      expect(watch.violations, `le Membre a déclenché ${watch.violations.join(" · ")}`).toEqual([]);
    } finally {
      await eraseAccount(page);
      if (!managerErased) {
        await eraseAccount(manager);
      }
      await manager.context().close();
    }
  });
});
