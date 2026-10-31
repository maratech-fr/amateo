import { expect, test, type Page } from "./fixtures";

import { registerAndVerify, uniqueAra } from "./support";
import { buildTeamsXlsx } from "./support-xlsx";

const XLSX_MIME = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";

/** Une séance placée, normalisée pour les contrôles de cohérence du planning rendu. */
interface PlacedSlot {
  teamId: string;
  venueId: string;
  coachId: string | null;
  dayOfWeek: number;
  startMin: number;
  endMin: number;
  sharedTrainingBlockId: string | null;
}

/** Minutes depuis minuit du premier « HH:MM » trouvé (startTime servi en ISO ou « HH:MM:SS »). */
function minutesOf(value: unknown): number {
  const m = String(value).match(/(\d{2}):(\d{2})/);
  return null === m ? 0 : Number(m[1]) * 60 + Number(m[2]);
}

function overlaps(a: PlacedSlot, b: PlacedSlot): boolean {
  return a.dayOfWeek === b.dayOfWeek && a.startMin < b.endMin && b.startMin < a.endMin;
}

/** Lit la collection API Platform quelle que soit sa forme (tableau nu, `member`, `hydra:member`). */
function membersOf(body: unknown): Record<string, unknown>[] {
  if (Array.isArray(body)) return body as Record<string, unknown>[];
  const obj = body as Record<string, unknown>;
  return ((obj.member ?? obj["hydra:member"] ?? []) as Record<string, unknown>[]);
}

async function getJson(page: Page, path: string): Promise<unknown> {
  const resp = await page.request.get(path);
  expect(resp.ok(), `GET ${path} (HTTP ${resp.status()})`).toBeTruthy();
  return resp.json();
}

/**
 * Arme l'attente du refetch GET `/api/venues` déclenché par `useCreateVenue`
 * (`invalidateEverywhere(queryClient, "venues")`, queries.ts:202). À APPELER juste AVANT
 * le clic « Ajouter un gymnase », puis à `await` juste après : le témoin texte du sélecteur
 * ne peut afficher le gymnase créé qu'une fois CE GET revenu, et son propre `timeout` ne doit
 * donc partir que de là — pas du clic (sous charge CI le GET traîne au-delà d'une petite marge,
 * ce qui faisait flaker le témoin direct, P4-343).
 *
 * Ne matche QUE le refetch navigateur : méthode GET (le POST de création partage le pathname),
 * pathname exact `/api/venues` (le `?page=` de `collectionAll` est ignoré), statut 200. Les
 * lectures ultérieures via `page.request` (`/api/venues?itemsPerPage=50`) ne lèvent pas
 * d'événement de page, donc ne peuvent pas être prises pour ce refetch.
 */
function awaitVenuesRefetch(page: Page) {
  return page
    .waitForResponse(
      (r) => {
        try {
          return "GET" === r.request().method() && "/api/venues" === new URL(r.url()).pathname && 200 === r.status();
        } catch {
          return false;
        }
      },
      { timeout: 30_000 },
    )
    .catch(() => null);
}

/**
 * **LE parcours de bout en bout (audit P0.2, FRT-05), sur un club RÉALISTE de 10 équipes.**
 *
 * « Travailler avec une équipe n'a aucun sens — un petit club c'est 10-12 équipes » (fondateur) :
 * la version 1-équipe ne prouvait presque rien. Ici un club NEUF importe ses 10 équipes depuis un
 * export FBI (geste réel — ce spec absorbe `teams-import.spec.ts`, retiré : une équipe créée à la
 * main, le doublon détecté et décoché, les neuf nouvelles importées), équipe 2 gymnases avec de
 * quoi placer 20 séances, crée un coach lié à DEUX équipes, lance une VRAIE génération (CP-SAT,
 * palier 60 s), voit le planning posé, le valide, le consulte, le rouvre, puis atterrit sur le
 * cockpit. En fin de course, on relit le planning RENDU et on prouve sa cohérence : chaque équipe
 * est placée, jamais deux équipes sur la même case d'un gymnase, un coach jamais à deux endroits à
 * la fois. Ces trois vérités sont des invariants dont le MOTEUR est la source (doublon VOULU sur un
 * point sensible — pipeline de génération : voir `engine/tests/` invariants + `MatchConflictDetectorTest`
 * côté géométrie) ; ici elles gardent le bout « pipeline → planning servi → rendu » pour un décor réaliste.
 */
test("parcours complet : 10 équipes FBI → 2 gymnases → génération → planning cohérent → validé → cockpit", async ({ page }) => {
  test.setTimeout(300_000); // import FBI + vraie génération d'un club de 10 équipes (palier 60 s) + validation.

  // --- Register a fresh club + verify by email → onboarding wizard.
  const ara = uniqueAra("E2EF");
  const clubName = "E2E Journey Club";
  await registerAndVerify(page, { email: `journey-${ara}@e2e.fr`, ara, firstName: "Flo", lastName: "Journey", clubName });
  await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });

  // --- Step 1 · équipes : UNE créée à la main (le futur doublon), puis NEUF importées depuis FBI.
  // ⚑ 20 s ici (pas le sélecteur) : ce point a flaké sur 4 PR à cause du voile de WhatsNewModal
  // (POST /release-notes/seen sans exemption veil) — corrigé à la source ; si ça re-flake, lire le trace.
  await page.getByLabel("Nom de l'équipe").fill("SM1");
  await page.getByLabel("Catégorie").selectOption({ label: "Senior" });
  await page.getByRole("button", { name: "Ajouter l'équipe" }).click();
  await expect(page.locator('input[value="SM1"]')).toBeVisible({ timeout: 20_000 });

  // Le fichier FBI : SM1 (doublon) + neuf nouvelles, toutes au code club du run (= l'ARA).
  const organisme = `${ara} - CLUB E2E`;
  const newTeams = [
    { nom: "SF1", categorie: "Senior", numero: "2" },
    { nom: "U18M1", categorie: "U18", numero: "3" },
    { nom: "U18F1", categorie: "U18", numero: "4" },
    { nom: "U15M1", categorie: "U15", numero: "5" },
    { nom: "U15F1", categorie: "U15", numero: "6" },
    { nom: "U13M1", categorie: "U13", numero: "7" },
    { nom: "U13F1", categorie: "U13", numero: "8" },
    { nom: "U11M1", categorie: "U11", numero: "9" },
    { nom: "U11F1", categorie: "U11", numero: "10" },
  ];
  const buffer = buildTeamsXlsx([
    { nom: "SM1", categorie: "Senior", numero: "1", organisme },
    ...newTeams.map((t) => ({ ...t, organisme })),
  ]);
  await page.getByRole("button", { name: "Importer depuis FBI" }).click();
  const importDialog = page.getByRole("dialog", { name: "Importer vos équipes (export FBI)" });
  await expect(importDialog).toBeVisible();
  await importDialog.getByLabel("Fichier FBI (.xlsx)").setInputFiles({ name: "equipes.xlsx", mimeType: XLSX_MIME, buffer });
  // Le doublon SM1 est marqué « déjà présente » et décoché ; les neuf nouvelles sont cochées.
  const dupe = importDialog.getByRole("checkbox", { name: /^SM1/ });
  await expect(dupe).toBeVisible({ timeout: 15_000 });
  await expect(dupe).toHaveAccessibleName(/déjà présente/);
  await expect(dupe).not.toBeChecked();
  await expect(importDialog.getByRole("checkbox", { name: /^U11M1/ })).toBeChecked();
  await importDialog.getByRole("button", { name: /^Importer \d+ équipe/ }).click();
  await expect(importDialog.getByText(/équipes? créées?/)).toBeVisible({ timeout: 15_000 });
  await importDialog.getByRole("button", { name: "Fermer" }).last().click();
  // Les dix équipes sont en liste, SM1 jamais dupliquée.
  await expect(page.locator('input[value="U11M1"]')).toBeVisible({ timeout: 20_000 });
  await expect(page.locator('input[value="SM1"]')).toHaveCount(1);
  await page.getByRole("button", { name: "Suivant" }).click();

  // --- Step 2 · deux gymnases. On PROUVE le geste (ajout + refus « après minuit » + un créneau) dans
  //     le navigateur, puis on garnit la capacité par l'API (créneaux non chevauchants) : 10 équipes ×
  //     2 séances = 20 placements, il faut de la marge. Le geste reste réel, le volume reste robuste.
  await expect(page.getByRole("heading", { name: /Étape 2\/6/ })).toBeVisible();
  await page.getByLabel("Nom du gymnase").fill("Gymnase A");
  // Armé AVANT le clic : le sélecteur n'affiche le gymnase créé qu'APRÈS le refetch de
  // `/api/venues` (invalidé dans `useCreateVenue`, queries.ts:202) — `VenuesStep.tsx:430`
  // fait `setSelectedId(created.id)`, mais `selected` (VenuesStep.tsx:317) le résout contre
  // la liste `venues` ENCORE en cache tant que le GET n'est pas revenu. On attend donc ce GET,
  // puis seulement le témoin texte — dont les 15 s partent de la liste à jour, pas du clic.
  const venuesRefetchA = awaitVenuesRefetch(page);
  await page.getByRole("button", { name: "Ajouter un gymnase" }).click();
  expect(
    await venuesRefetchA,
    "Gymnase A : le refetch GET /api/venues n'est jamais revenu après « Ajouter un gymnase » — la liste des gymnases ne peut pas s'actualiser.",
  ).not.toBeNull();
  await expect(page.getByLabel("Gymnase", { exact: true })).toContainText("Gymnase A", { timeout: 15_000 });
  await expect(page.getByText(/cliquez la grille pour ajouter un créneau/i)).toBeVisible();
  // Refus « finirait après minuit » (150 min à 22:45) — la grille reste intacte, aucun créneau posé.
  await page.getByLabel("Durée à poser").selectOption("150");
  await page.getByRole("button", { name: "Lun 22:45", exact: true }).click();
  await expect(page.getByText(/finirait après minuit/i)).toBeVisible();
  await page.getByLabel("Durée à poser").selectOption("90");
  // Un vrai créneau posé à la grille (Lun 18:00) — le geste prouvé.
  await page.getByRole("button", { name: "Lun 18:00", exact: true }).click();

  // Second gymnase + un créneau à la grille (Mar 18:00 sur B).
  // L'accordéon d'ajout se referme après un ajout réussi : on le rouvre pour le gymnase suivant.
  await page.getByRole("button", { name: "Ajouter des gymnases" }).click();
  await page.getByLabel("Nom du gymnase").fill("Gymnase B");
  // Même attente de refetch qu'au Gymnase A. ⚠ Ici le sélecteur n'est PAS vide en attendant :
  // le repli `?? venues[0]` (VenuesStep.tsx:317) le laisse sur le gymnase PRÉCÉDENT (« Gymnase A »)
  // jusqu'à ce que le refetch ramène B — on ATTEND donc le GET puis que B soit bien le courant
  // AVANT de poser le créneau à la grille (sinon « Mar 18:00 » tomberait sur A).
  const venuesRefetchB = awaitVenuesRefetch(page);
  await page.getByRole("button", { name: "Ajouter un gymnase" }).click();
  expect(
    await venuesRefetchB,
    "Gymnase B : le refetch GET /api/venues n'est jamais revenu après « Ajouter un gymnase » — la liste des gymnases ne peut pas s'actualiser.",
  ).not.toBeNull();
  await expect(page.getByLabel("Gymnase", { exact: true })).toContainText("Gymnase B", { timeout: 15_000 });
  await page.getByRole("button", { name: "Mar 18:00", exact: true }).click();

  // Capacité par l'API : les deux gymnases du club, puis une grille lun→ven × {18:00, 19:30, 21:00}
  // (90 min, dos à dos, JAMAIS chevauchants), en SAUTANT les deux cases déjà posées à la grille.
  const venues = membersOf(await getJson(page, "/api/venues?itemsPerPage=50"));
  const venueByName = new Map(venues.map((v) => [v.name as string, v.id as string]));
  const venueAId = venueByName.get("Gymnase A");
  const venueBId = venueByName.get("Gymnase B");
  expect(venueAId && venueBId, "les deux gymnases doivent exister côté serveur").toBeTruthy();
  const times = ["18:00", "19:30", "21:00"];
  for (const [venueId, skipDayAt18] of [[venueAId, 1] as const, [venueBId, 2] as const]) {
    for (let day = 1; day <= 5; day += 1) {
      for (const startTime of times) {
        if ("18:00" === startTime && day === skipDayAt18) continue; // posé à la grille, pas de doublon.
        const r = await page.request.post("/api/venue_training_slots", {
          headers: { "Content-Type": "application/json" },
          data: { venueId, dayOfWeek: day, startTime, durationMinutes: 90, capacity: 1 },
        });
        expect(r.ok(), `créneau API ${String(venueId)} j${day} ${startTime} (HTTP ${r.status()})`).toBeTruthy();
      }
    }
  }
  await page.getByRole("button", { name: "Suivant" }).click();

  // --- Step 3 · coach : créé dans le navigateur (geste), PUIS lié à DEUX équipes par l'API (le
  //     décor « un coach sur deux équipes » qui donne son sens au contrôle « jamais à deux endroits »).
  await expect(page.getByRole("heading", { name: /Étape 3\/6/ })).toBeVisible();
  await page.getByLabel("Prénom").fill("Coa");
  await page.getByLabel("Nom", { exact: true }).fill("Ch");
  await page.getByRole("button", { name: "Ajouter le coach" }).click();
  await expect(page.getByRole("button", { name: "Terminer l'édition" })).toBeVisible({ timeout: 15_000 });
  const coaches = membersOf(await getJson(page, "/api/coaches?itemsPerPage=5"));
  const coachId = coaches[0]?.id as string | undefined;
  const teams = membersOf(await getJson(page, "/api/teams?itemsPerPage=50"));
  expect(coachId, "le coach créé doit exister").toBeTruthy();
  expect(teams.length, "les dix équipes doivent exister").toBeGreaterThanOrEqual(10);
  for (const teamId of [teams[0].id as string, teams[1].id as string]) {
    const r = await page.request.post("/api/team_coaches", {
      headers: { "Content-Type": "application/json" },
      data: { teamId, coachId, role: "MAIN" },
    });
    expect(r.ok(), `liaison coach→équipe ${teamId} (HTTP ${r.status()})`).toBeTruthy();
  }
  await page.getByRole("button", { name: "Suivant" }).click();

  // --- Step 4 · contraintes (aucune — on passe).
  await expect(page.getByRole("heading", { name: /Étape 4\/6/ })).toBeVisible();
  await page.getByRole("button", { name: "Suivant" }).click();

  // --- Step 5 · récap → génération.
  await expect(page.getByRole("heading", { name: /Étape 5\/6/ })).toBeVisible();
  await page.getByRole("button", { name: "Continuer vers la génération" }).click();

  // --- Step 6 · vraie génération + planning posé.
  await expect(page.getByRole("heading", { name: /Étape 6\/6/ })).toBeVisible();

  // P4-168 — TÉMOIN Mercure volet (a) : armé AVANT le clic, il prouve qu'un flux SSE s'est OUVERT
  // (réponse `/.well-known/mercure`, `text/event-stream`, 200) — pas que le hub est joignable. Sans
  // lui, un hub mort passerait par le repli polling en silence (D1 : hub muet = ÉCHEC, jamais un skip).
  const mercureStream = page
    .waitForResponse(
      (r) =>
        /\/\.well-known\/mercure/.test(r.url())
        && 200 === r.status()
        && (r.headers()["content-type"] ?? "").includes("text/event-stream"),
      { timeout: 15_000 },
    )
    .catch(() => null);
  await page.getByRole("button", { name: "Lancer la génération" }).click();
  expect(
    await mercureStream,
    "Mercure : aucun flux SSE ouvert après le lancement — hub absent ou bloqué (D1 : échec, pas skip)",
  ).not.toBeNull();

  // Le planning embarqué remplace le lanceur dès qu'un schedule est COMPLETED (SM1 apparaît).
  await expect(page.getByText("SM1").first()).toBeVisible({ timeout: 180_000 });

  // P4-168 — TÉMOIN Mercure volet (b) : le compteur d'événements SSE (`data-schedule-stream-events`,
  // ScheduleStreamWitness) prouve la livraison PAR LE FLUX, pas par le repli polling. `events === 0`
  // ⇒ hub muet (D2). Le flux est déjà relâché ici (fin de génération) ; le compteur, monotone, survit.
  const witness = page.locator("[data-schedule-stream]").first();
  await expect(witness).toHaveCount(1);
  const streamState = await witness.getAttribute("data-schedule-stream");
  const eventsReceived = Number(await witness.getAttribute("data-schedule-stream-events"));
  expect(
    eventsReceived,
    `Planning livré SANS événement Mercure : le repli polling a masqué un hub muet (D2). État du flux : ${streamState}, événements SSE : ${eventsReceived}.`,
  ).toBeGreaterThanOrEqual(1);

  // --- Valider dans le wizard embarqué (Valider = la SORTIE, symétrie Valider ↔ Rouvrir).
  await page.getByRole("button", { name: "Valider" }).click();
  const dialog = page.getByRole("dialog", { name: "Valider le planning" });
  await expect(dialog).toBeVisible();
  await dialog.getByRole("button", { name: "Valider", exact: true }).click();

  // --- Le succès ATTERRIT sur /planning (version en vigueur). L'URL est l'assertion falsifiable ;
  //     « Rouvrir visible » seul serait un FAUX VERT (présent dans les deux écrans).
  await expect(page).toHaveURL(/\/planning/, { timeout: 15_000 });
  await expect(page.getByText("SM1").first()).toBeVisible();
  await expect(page.getByText("Terminé").first()).toBeVisible();
  // Le sélecteur de version est ABSENT sur /planning (geste de travail, embarqué seulement) — c'est
  // ce qui prouve qu'on a QUITTÉ l'atelier, pas juste qu'un bouton a changé.
  await expect(page.getByRole("combobox", { name: /version du planning/i })).toHaveCount(0);
  const reopen = page.getByRole("button", { name: "Rouvrir" });
  await expect(reopen).toBeVisible();

  // --- Contrôle de COHÉRENCE du planning EN VIGUEUR, lu via l'API de la version pointée (c'est ce
  //     que le navigateur rend) : chaque équipe placée, aucune collision de gymnase, aucun coach à
  //     deux endroits. Invariants dont le MOTEUR est la source (doublon VOULU, point sensible
  //     « pipeline de génération » — voir engine/tests invariants + `MatchConflictDetectorTest`). On
  //     le lit AVANT « Rouvrir », qui dépointe le plan (`planning/queries.ts` : Rouvrir → chosenScheduleId null).
  const me = (await getJson(page, "/api/me")) as { seasonPlan?: { chosenScheduleId?: string | null } };
  const scheduleId = me.seasonPlan?.chosenScheduleId ?? null;
  expect(scheduleId, "le plan de saison validé doit pointer une version").toBeTruthy();
  const slots: PlacedSlot[] = membersOf(await getJson(page, `/api/schedule_slot_templates?scheduleId=${String(scheduleId)}`))
    .filter((r) => "string" === typeof r.teamId)
    .map((r) => {
      const startMin = minutesOf(r.startTime);
      return {
        teamId: r.teamId as string,
        venueId: r.venueId as string,
        coachId: (r.coachId ?? null) as string | null,
        dayOfWeek: r.dayOfWeek as number,
        startMin,
        endMin: startMin + (r.durationMinutes as number),
        sharedTrainingBlockId: (r.sharedTrainingBlockId ?? null) as string | null,
      };
    });
  expect(slots.length, "la génération doit avoir posé des séances").toBeGreaterThan(0);

  // (1) chaque équipe placée.
  const placedTeamIds = new Set(slots.map((s) => s.teamId));
  for (const t of teams) {
    expect(placedTeamIds.has(t.id as string), `l'équipe ${String(t.name ?? t.id)} doit avoir au moins une séance placée`).toBeTruthy();
  }
  // (2) jamais deux équipes DIFFÉRENTES qui se chevauchent dans le MÊME gymnase (hors bloc mutualisé commun).
  for (let i = 0; i < slots.length; i += 1) {
    for (let j = i + 1; j < slots.length; j += 1) {
      const a = slots[i];
      const b = slots[j];
      if (a.venueId !== b.venueId || a.teamId === b.teamId) continue;
      const sameSharedBlock = null !== a.sharedTrainingBlockId && a.sharedTrainingBlockId === b.sharedTrainingBlockId;
      if (sameSharedBlock) continue;
      expect(overlaps(a, b), `collision de gymnase : ${a.teamId} et ${b.teamId} se chevauchent dans le même gymnase`).toBeFalsy();
    }
  }
  // (3) un coach jamais à deux endroits à la fois (le coach lié à deux équipes donne son sens au contrôle).
  for (let i = 0; i < slots.length; i += 1) {
    for (let j = i + 1; j < slots.length; j += 1) {
      const a = slots[i];
      const b = slots[j];
      if (null === a.coachId || a.coachId !== b.coachId || a.teamId === b.teamId) continue;
      expect(overlaps(a, b), `coach à deux endroits : ${a.coachId} sur ${a.teamId} et ${b.teamId} en même temps`).toBeFalsy();
    }
  }

  // --- Rouvrir boucle le cycle : retour à l'étape génération du wizard, planning éditable à nouveau.
  await reopen.click();
  await expect(page).toHaveURL(/\/wizard/, { timeout: 15_000 });
  await expect(page.getByRole("heading", { name: /Étape 6\/6/ })).toBeVisible();
  await expect(page.getByText("SM1").first()).toBeVisible({ timeout: 15_000 });
  await expect(page.getByRole("button", { name: "Valider" })).toBeVisible({ timeout: 15_000 });

  // --- L'accueil ouvre sur le cockpit temporel (calendrier au mois) : « Mois suivant » est son repère.
  await page.goto("/");
  await expect(page.getByRole("button", { name: "Mois suivant" })).toBeVisible({ timeout: 15_000 });
});
