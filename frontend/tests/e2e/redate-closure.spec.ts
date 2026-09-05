import AxeBuilder from "@axe-core/playwright";

import { expect, test } from "./fixtures";
import { forceTheme, settleVeil, watchFailedApiCalls } from "./support";

/**
 * Contraste (WCAG 1.4.3) SCOPÉ à la région d'aperçu (`[aria-live]`) — le contenu que P4-174 ajoute :
 * liste d'effets + `WarningPanel` des effets destructifs. On ne scanne PAS toute la page : derrière
 * la modale, le calendrier laisse ses jours débordants (grisés `text-muted-foreground/50`) sous le
 * voile sombre — un faux positif pré-existant, étranger à ce geste ; et le bouton `bg-destructive`
 * partagé (blanc sur rouge, ~4.0) est une limite de JETON commune à toute l'app, hors de ce lot.
 */
async function expectNoPreviewContrastViolations(page: import("./fixtures").Page, label: string): Promise<void> {
  const results = await new AxeBuilder({ page }).include('[aria-live="polite"]').withRules(["color-contrast"]).analyze();
  const offenders = results.violations.flatMap((v) => v.nodes.map((n) => `  ${n.target.join(" ")} — ${(n.failureSummary ?? "").split("\n").join(" ")}\n    HTML: ${n.html}`));
  expect(offenders, `${label}: colour-contrast (WCAG 1.4.3) violations:\n${offenders.join("\n")}`).toEqual([]);
}

/**
 * **Re-dater une fermeture depuis le cockpit (D3 v1) — et le planning se dit « à régénérer ».**
 *
 * Le pendant ÉCRIVANT de `club-life.spec.ts`. Il exerce le seul chemin UI du `PUT` re-datage :
 * cockpit → jour de la fermeture → « Modifier les dates » → nouvelle fenêtre → le serveur déplace
 * le plan et l'annonce (`stalenessMessage` du toast : « planning à régénérer »).
 *
 * ⚑ **SA PROPRE fermeture, pas l'incident du seed** : depuis le découpage début·milieu·fin
 * (fondateur 2026-09-05), l'incident Matéo est DÉCOUPÉ en deux enfants — sa racine porte des
 * semaines-enfants, elle n'est donc plus re-datable (fenêtre gelée). Ce trajet crée donc SA PROPRE
 * fermeture d'UN SEUL bout (lun 19/10 → ven 23/10, une seule semaine entamée = un seul segment,
 * re-datable), la re-date vers un AUTRE bout d'un seul segment (jeu 22/10 : toujours la même
 * semaine calendaire → un seul segment, la règle de re-datage l'accepte), puis la SUPPRIME en
 * `finally` — le trajet est IDEMPOTENT quelle que soit l'ordre des fichiers et ne laisse aucun
 * résidu dans la base e2e (amateo_dev).
 *
 * ⚠ Re-dater vers un vendredi (semaine entamée + semaines pleines = plusieurs segments) serait
 * REFUSÉ (422) par la règle début·milieu·fin — d'où le choix de jeu 22/10, qui reste dans la
 * semaine du 19/10 (un seul segment).
 */

const EMAIL = "mara.mb@bccl.fr";
const PASSWORD = "maraboubccl";
const TITLE = "Fermeture e2e à re-dater";
// Un seul bout : lundi → vendredi (une semaine entamée = un segment), bien après l'incident du seed
// (fin 18/10) et dans la saison. Re-datage vers jeudi = toujours la même semaine (un segment).
const START = "2026-10-19";
const ORIGINAL_END = "2026-10-23";
const REDATED_END = "2026-10-22";

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

/** Crée la fermeture (entrée période) + son plan « d'un bloc » via l'API — la matière du re-datage. */
async function createClosureWithPlan(page: import("./fixtures").Page): Promise<string> {
  const created = await page.request.post("/api/calendar_entries", {
    headers: { "Content-Type": "application/json" },
    data: { kind: "period", periodType: "closure", title: TITLE, startDate: START, endDate: ORIGINAL_END },
  });
  expect(created.ok(), `création de la fermeture (${created.status()})`).toBeTruthy();
  const entryId = (await created.json()).id as string;

  const plan = await page.request.post("/api/schedule_plans", {
    headers: { "Content-Type": "application/json" },
    data: { calendarEntryId: entryId },
  });
  expect(plan.ok(), `adaptation « d'un bloc » (${plan.status()})`).toBeTruthy();

  return entryId;
}

/** Amène le calendrier sur octobre 2026 (la fenêtre de la fermeture) — il s'ouvre sur le mois courant. */
async function goToOctober(page: import("./fixtures").Page): Promise<void> {
  for (let i = 0; i < 6; i += 1) {
    if (await page.getByText("Octobre 2026", { exact: true }).isVisible().catch(() => false)) {
      return;
    }
    await page.getByRole("button", { name: "Mois suivant" }).click();
  }
  await expect(page.getByText("Octobre 2026", { exact: true })).toBeVisible({ timeout: 10_000 });
}

/** Ouvre le dialogue du jour sur une case COUVERTE par la fermeture (son titre est dans l'aria-label). */
async function openClosureDay(page: import("./fixtures").Page): Promise<void> {
  await goToOctober(page);
  const dayCell = page.getByRole("button", { name: new RegExp(`${TITLE}.*(?<!passé \\(non modifiable\\))$`) }).filter({ hasNotText: "passé" });
  await expect(dayCell.first(), "une case-jour couverte par la fermeture doit être cliquable").toBeVisible({ timeout: 30_000 });
  await dayCell.first().click();
  await expect(page.getByRole("dialog")).toBeVisible({ timeout: 15_000 });
}

/** Re-date depuis le dialogue déjà ouvert : change « Jusqu'au » et enregistre. */
async function redateTo(page: import("./fixtures").Page, newEnd: string): Promise<void> {
  await page.getByRole("button", { name: new RegExp(`Modifier les dates de.*${TITLE}`, "i") }).click();
  const endInput = page.getByLabel("Jusqu'au");
  await expect(endInput).toBeVisible({ timeout: 10_000 });
  await endInput.fill(newEnd);
  await page.getByRole("button", { name: "Enregistrer" }).click();
}

test("re-dater une fermeture d'un bloc depuis le cockpit marque son planning « à régénérer »", async ({ page }) => {
  test.setTimeout(120_000);
  const failed = watchFailedApiCalls(page);

  await login(page);
  let entryId = "";
  try {
    entryId = await createClosureWithPlan(page);

    await openCockpit(page);
    await openClosureDay(page);

    // Le témoin de VÉRITÉ = le toast de succès : il ne s'affiche que si le PUT a réellement re-daté
    // (200) — et il porte « planning à régénérer ». Re-datage vers jeudi 22/10 : toujours la même
    // semaine (un seul segment) → la règle début·milieu·fin l'accepte.
    await redateTo(page, REDATED_END);
    await expect(
      page.getByText(/Fermeture re-datée du .+ — planning à régénérer/),
      `le PUT de re-datage doit réussir et l'annoncer${failed.length ? ` — échecs API: ${failed.join(", ")}` : ""}`,
    ).toBeVisible({ timeout: 30_000 });

    // P4-173 (témoin) — de retour au cockpit, le signal « à régénérer » est désormais VISIBLE : re-dater
    // a touché une donnée du club, la version pointée du plan de saison devient périmée. Le cockpit le
    // DIT (il était muet avant P4-173 — seul /planning le savait). La pastille porte la cause en clair.
    await expect(page.getByText(/À régénérer/).first()).toBeVisible({ timeout: 30_000 });

    // Reflow (WCAG 1.4.10) — à 375 px, la pastille ENVELOPPE (whitespace-normal + flex-wrap) : son
    // bord droit ne dépasse JAMAIS la fenêtre, sur le cockpit ET dans la modale « Tous les plannings »
    // (où elle jouxte l'état). Mesuré au vrai moteur (jsdom n'a pas de mise en page). On borne la
    // pastille elle-même, pas la page entière (le cockpit dense scrolle par ailleurs ses grilles).
    const pillOverflow = () =>
      page.evaluate(() => {
        const pills = [...document.querySelectorAll("span")].filter((s) => /^À régénérer/.test(s.textContent ?? ""));
        if (0 === pills.length) {
          return -1; // aucune pastille peinte → rien à mesurer (ne fait pas échouer)
        }
        return Math.max(...pills.map((p) => Math.ceil(p.getBoundingClientRect().right) - window.innerWidth));
      });
    await page.setViewportSize({ width: 375, height: 800 });
    await expect.poll(pillOverflow).toBeLessThanOrEqual(0);
    await page.getByRole("button", { name: /Tous les plannings/ }).click();
    await expect(page.getByRole("dialog")).toBeVisible({ timeout: 15_000 });
    await expect.poll(pillOverflow).toBeLessThanOrEqual(0);
  } finally {
    // IDEMPOTENCE : on retire la fermeture créée (cascade son plan) — aucun résidu dans la base e2e.
    if ("" !== entryId) {
      await page.request.delete(`/api/calendar_entries/${entryId}`);
    }
  }
});

/**
 * **D3 v2 (P4-174) — re-dater une indisponibilité DÉCOUPÉE : aperçu des effets → confirmation.**
 *
 * Le pendant écrivant du geste v2. Une mère de fermeture DÉCOUPÉE en semaines (≥ 1 enfant, sans
 * plan-bloc) n'est pas re-datable « d'un bloc » : le serveur sert `redateNeedsPreview` et le front
 * exige un APERÇU (« Voir les effets ») avant de « Confirmer » (règle fondateur 2026-09-05 : on
 * annonce avant de détruire — le front AFFICHE les phrases servies, il n'en recalcule aucune).
 *
 * Le trajet crée SA PROPRE mère JETABLE (mère + 3 semaines-enfants) via l'API, dans une fenêtre de
 * novembre 2026 SANS vacances scolaires (après la Toussaint, avant Noël) et bien au-delà des
 * périodes du seed, la re-date en la RACCOURCISSANT d'une semaine (la dernière semaine tombe → au
 * moins un effet destructif), et supprime tout en `finally` — idempotent, aucun résidu dans
 * amateo_dev.
 */
const SPLIT_TITLE = "Indispo découpée e2e";
// Novembre 2026, hors vacances : semaines calendaires lun→dim des 9, 16 et 23 novembre. La mère va
// du mercredi de la semaine 1 (11/11) au samedi de la semaine 3 (28/11) — trois segments début/milieu/fin.
const SPLIT_MOTHER_START = "2026-11-11";
const SPLIT_MOTHER_END = "2026-11-28";
const SPLIT_SHORT_END = "2026-11-21"; // raccourci au samedi de la semaine 2 → la semaine 3 tombe
const SPLIT_WEEKS: [string, string][] = [
  ["2026-11-09", "2026-11-15"],
  ["2026-11-16", "2026-11-22"],
  ["2026-11-23", "2026-11-29"],
];

/** Crée la mère découpée + ses 3 semaines-enfants (sans plan-bloc) → le serveur la dit `redateNeedsPreview`. */
async function createSplitClosure(page: import("./fixtures").Page): Promise<string[]> {
  const ids: string[] = [];
  const mother = await page.request.post("/api/calendar_entries", {
    headers: { "Content-Type": "application/json" },
    data: { kind: "period", periodType: "closure", title: SPLIT_TITLE, startDate: SPLIT_MOTHER_START, endDate: SPLIT_MOTHER_END },
  });
  expect(mother.ok(), `création de la mère découpée (${mother.status()})`).toBeTruthy();
  const motherId = (await mother.json()).id as string;
  ids.push(motherId);

  for (const [start, end] of SPLIT_WEEKS) {
    const child = await page.request.post("/api/calendar_entries", {
      headers: { "Content-Type": "application/json" },
      data: { kind: "period", periodType: "closure", title: "Segment e2e", startDate: start, endDate: end, parentEntryId: motherId },
    });
    expect(child.ok(), `création d'une semaine-enfant (${child.status()})`).toBeTruthy();
    ids.push((await child.json()).id as string);
  }

  // Le serveur reconnaît la mère comme découpée (aperçu requis) — sinon le geste testé n'existe pas.
  const check = await page.request.get(`/api/calendar_entries/${motherId}`);
  expect((await check.json()).redateNeedsPreview, "la mère doit être servie redateNeedsPreview=true").toBeTruthy();
  return ids;
}

/** Amène le calendrier sur un mois donné (il s'ouvre sur le mois courant). */
async function goToMonth(page: import("./fixtures").Page, label: string): Promise<void> {
  for (let i = 0; i < 12; i += 1) {
    if (await page.getByText(label, { exact: true }).isVisible().catch(() => false)) {
      return;
    }
    await page.getByRole("button", { name: "Mois suivant" }).click();
  }
  await expect(page.getByText(label, { exact: true })).toBeVisible({ timeout: 10_000 });
}

/** Ouvre le dialogue d'un jour couvert par la mère, entre en mode re-datage et charge l'aperçu. */
async function openSplitPreview(page: import("./fixtures").Page): Promise<void> {
  await goToMonth(page, "Novembre 2026");
  // Un jour de MILIEU de fenêtre, ordinaire (ni jour férié comme le 11, ni bord) — couvert par la
  // mère ET une semaine-enfant. Le 18 novembre 2026 est un mercredi banal de la semaine 2.
  const dayCell = page.getByRole("button", { name: new RegExp(`18 Novembre.*${SPLIT_TITLE}`) });
  await expect(dayCell, "la case du 18/11 couverte par la mère doit être cliquable").toBeVisible({ timeout: 30_000 });
  await dayCell.click();
  await expect(page.getByRole("dialog")).toBeVisible({ timeout: 15_000 });

  await page.getByRole("button", { name: new RegExp(`Modifier les dates de.*${SPLIT_TITLE}`, "i") }).click();
  const endInput = page.getByLabel("Jusqu'au");
  await expect(endInput).toBeVisible({ timeout: 10_000 });
  await endInput.fill(SPLIT_SHORT_END);
  await page.getByRole("button", { name: "Voir les effets" }).click();
  // L'aperçu chargé : au moins un effet listé, et le bouton d'action bascule sur « Confirmer ».
  await expect(page.getByRole("listitem").first()).toBeVisible({ timeout: 20_000 });
  await expect(page.getByRole("button", { name: "Confirmer" })).toBeVisible({ timeout: 10_000 });
}

test("re-dater une indisponibilité découpée : aperçu des effets puis confirmation marque le planning « à régénérer »", async ({ page }) => {
  test.setTimeout(150_000);
  const failed = watchFailedApiCalls(page);

  await login(page);
  let ids: string[] = [];
  try {
    ids = await createSplitClosure(page);

    await openCockpit(page);
    await openSplitPreview(page);

    // Le contraste de l'aperçu (liste + bouton d'action) une fois RENDU à l'utilisateur.
    await expectNoPreviewContrastViolations(page, "cockpit · aperçu de re-datage (thème clair)");

    // Confirmer : la semaine 3 tombe → effet destructif → toast « plans de période ajustés ».
    await page.getByRole("button", { name: "Confirmer" }).click();
    await expect(
      page.getByText(/plans de période ajustés, planning à régénérer/),
      `la confirmation doit réussir et l'annoncer${failed.length ? ` — échecs API: ${failed.join(", ")}` : ""}`,
    ).toBeVisible({ timeout: 30_000 });

    // De retour au cockpit, le signal « à régénérer » est visible (le re-datage a touché une donnée du club).
    await expect(page.getByText(/À régénérer/).first()).toBeVisible({ timeout: 30_000 });
  } finally {
    // Idempotence : on retire la mère (cascade son plan/ses enfants) ET chaque enfant, dans l'ordre inverse.
    for (const id of ids.reverse()) {
      await page.request.delete(`/api/calendar_entries/${id}`);
    }
  }
});

test("aperçu de re-datage découpé : le contraste tient en thème sombre", async ({ page }) => {
  test.setTimeout(150_000);
  await forceTheme(page, "dark");

  await login(page);
  let ids: string[] = [];
  try {
    ids = await createSplitClosure(page);
    await openCockpit(page);
    await openSplitPreview(page);
    // La liste d'effets (dont le WarningPanel des effets destructifs) reste AA en sombre.
    await expectNoPreviewContrastViolations(page, "cockpit · aperçu de re-datage (thème sombre)");
  } finally {
    for (const id of ids.reverse()) {
      await page.request.delete(`/api/calendar_entries/${id}`);
    }
  }
});
