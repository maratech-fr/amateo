import { expect, test } from "./fixtures";
import { settleVeil } from "./support";

/**
 * **Un incident dans la vie d'un club — et l'overlay qui le couvre reste borné à SON plan.**
 *
 * P4-122, dans les mots du fondateur : « être capable de faire […] un incident qui est couvert
 * par le planning d'overlay ». C'est le témoin qui manquait — **celui qui aurait rougi sur le bug
 * du 2026-08-19** : le repli silencieux vers le plan de SAISON quand on génère une période. Le
 * gestionnaire croyait adapter sa période, et générait dans le socle.
 *
 * ⚑ **Pourquoi le club SEEDÉ, et pourquoi l'incident QUI EXISTE DÉJÀ** (mesuré, pas supposé) :
 * le seed porte exactement la matière du parcours — deux plannings de **reprise** (17-23 et 24-30
 * août, une version COMPLETED chacun) et l'**incident Matéo** (fermeture du 31/08 au 16/10, plan
 * créé DIRECTEMENT SUR LA RACINE et **VALIDÉ** — il pointe une version COMPLETED transcrivant le
 * planning d'overlay réel). C'est le préalable que P5-13 a livré aux fixtures pour ce test.
 *
 * ⚠ **DEPUIS L'ARBITRAGE FONDATEUR 2026-09-02, l'incident n'est plus « à faire » mais DÉJÀ VALIDÉ**
 * et sans segment intermédiaire : la carte du radar est une fermeture VALIDÉE (`activeByEntry` ⇒
 * `visibleClosures`), donc son action mène à VOIR l'overlay, pas à un chip « sem. du … · à faire »
 * (ceux-ci ne naissent que d'une mère DÉCOUPÉE en semaines — cas de l'ancien segment, disparu). Le
 * cœur du parcours (bornage de l'overlay au socle) reste vrai, mais l'ENCHAÎNEMENT de gestes ci-
 * dessous a été écrit pour un incident À GÉNÉRER : il doit être revu sur un run réel avant de gater.
 *
 * Le socle et la génération « from scratch », eux, restent couverts par `journey.spec.ts`, qui
 * les fait sur un club neuf, de l'inscription jusqu'à la réouverture.
 *
 * ⚠ **La base e2e n'est JAMAIS réinitialisée** : ce spec est IDEMPOTENT. L'indisponibilité n'est
 * déclarée que si son motif n'est pas déjà à l'écran ; une période déjà dotée d'un plan offre
 * « Reprendre » là où une période vierge offre « Adapter » ; une version déjà générée n'est pas
 * régénérée. En CI le seed est vierge, en local il porte l'état du run précédent — et les deux
 * doivent rendre le même verdict. C'est la leçon de `matches.spec.ts`.
 */

const EMAIL = "mara.mb@bccl.fr";
const PASSWORD = "maraboubccl";

/**
 * L'incident du seed : la fermeture du gymnase Matéo (31 août → 16 octobre 2026), plan créé SUR LA
 * RACINE et VALIDÉ (une version COMPLETED transcrite) — c'est LUI que ce parcours mène jusqu'à son
 * overlay. On le désigne par un fragment de son titre, stable d'un seed à l'autre.
 */
const INCIDENT = "Matéo";

async function login(page: import("./fixtures").Page): Promise<void> {
  await page.goto("/login");
  await page.getByLabel("Email").fill(EMAIL);
  await page.getByLabel("Mot de passe", { exact: true }).fill(PASSWORD);
  await page.getByRole("button", { name: "Se connecter" }).click();
  await expect(page.getByRole("button", { name: "Saison de travail" })).toBeVisible({ timeout: 20_000 });
}

/**
 * Le cockpit, une fois RÉELLEMENT prêt à recevoir un clic.
 *
 * ⚠ `goto("/")` rend la main dès le premier octet : le bandeau du haut arrive avant les cartes du
 * radar, et le voile d'action peut encore couvrir l'écran. Décider « Reprendre ou Adapter ? »
 * entre les deux lit un écran incomplet — le clic frappe un élément qui disparaît, et rien ne
 * navigue.
 */
async function openCockpit(page: import("./fixtures").Page): Promise<void> {
  await page.goto("/");
  await expect(page.getByRole("button", { name: /Tous les plannings/ })).toBeVisible({ timeout: 30_000 });
  await settleVeil(page);
}

/** Les versions offertes par l'écran EMBARQUÉ = la lignée du plan affiché. Vide = rien de généré. */
async function versionsOf(page: import("./fixtures").Page): Promise<string[]> {
  const selector = page.getByRole("combobox", { name: /version du planning/i });

  return (await selector.count()) === 0 ? [] : selector.locator("option").allTextContents();
}

/**
 * Le titre de la période, LU une fois résolu.
 *
 * ⚠ Le bandeau rend `Mode période — {title ?? "…"}` : lu trop tôt, il donne l'ELLIPSE comme
 * titre, et toutes les assertions suivantes portent alors sur une chaîne qui n'existe nulle part.
 */
async function readPeriodTitle(page: import("./fixtures").Page): Promise<string> {
  let title = "";
  await expect
    .poll(
      async () => {
        const text = (await page.getByText(/^Mode période — /).first().textContent()) ?? "";
        title = text.replace(/^Mode période — /, "").trim();

        return title;
      },
      { timeout: 30_000 },
    )
    .not.toBe("…");

  return title;
}

/** Le socle : écran de la version EN VIGUEUR, sans sélecteur (choisir une version est un geste embarqué). */
async function expectSocleIntact(page: import("./fixtures").Page, label: string): Promise<void> {
  await page.goto("/planning");
  await expect(page.getByText("Terminé").first(), `${label} : le socle doit rester terminé`).toBeVisible({ timeout: 30_000 });
  await expect(page.getByRole("combobox", { name: /version du planning/i }), `${label} : /planning est l'écran de la version en vigueur — aucun sélecteur`).toHaveCount(0);
}

// ⚠ **PARQUÉ (`fixme`) — il ne tourne pas encore, et voici EXACTEMENT où il s'arrête.**
//
// Le parcours mène l'incident jusqu'à l'ouverture de sa période, puis échoue au moment de
// désigner la BONNE carte du radar : `opener.last()` clique une carte dont la fenêtre est déjà
// planifiée, et le serveur refuse en 409 `window_already_planned`. Reproductible sur un seed
// FRAÎCHEMENT rechargé, donc déterministe — ce n'est pas un aléa de données.
//
// Le geste qui manque est de viser l'ouvreur **de la carte qui porte le motif de l'incident**,
// au lieu d'une position dans la liste. Il faut pour cela connaître le conteneur de carte du
// radar (`RadarPanel`), ce qui se lit dans le code plutôt que par sondage — c'est la première
// chose à faire en reprenant.
//
// ⚑ Il est commité MALGRÉ cela parce qu'il porte des faits MESURÉS qui ne sont écrits nulle part
// ailleurs, et que la prochaine session ne doit pas repayer : les deux seules portes vers un plan
// de période, l'horizon du cockpit qui rend un incident lointain invisible, la garde de
// chevauchement, l'ellipse du bandeau. `fixme` le laisse visible sans rougir la CI.
test("un incident déclaré ouvre un overlay borné à SON plan, sans toucher au socle", async ({ page }) => {
  test.setTimeout(420_000);

  await login(page);

  // --- 0 · Le socle est là, validé. (`journey.spec` prouve qu'on sait le CONSTRUIRE de zéro ;
  //         ici il est le décor dont on vérifie qu'il ne bouge pas.)
  await expectSocleIntact(page, "avant l'incident");

  // --- 1 · LES PLANNINGS DE REPRISE existent déjà, chacun avec SA version terminée. C'est la
  //         cohabitation qu'on vérifiera en fin de parcours : trois plannings distincts.
  await openCockpit(page);

  // --- 2 · L'INCIDENT s'ouvre par la porte du radar. On vise l'ouvreur DE LA CARTE qui le porte,
  //         jamais une POSITION dans la liste : le premier jet cliquait `opener.last()` en
  //         supposant que le radar range par date, et tombait sur une carte déjà planifiée →
  //         409, reproductible sur seed frais. `RadarCard` (`RadarPanel.tsx`) rend son titre dans
  //         un `<p>` à l'intérieur d'un conteneur bordé : le filtrer par son texte désigne LA
  //         carte, quel que soit l'ordre d'affichage.
  // ⚠ Borner au REPÈRE d'abord : « Matéo » apparaît aussi dans chaque case du calendrier du mois
  // (« 31 Août … Matéo indisponible (incident) »), donc un filtre sur toute la page attrape le
  // calendrier et jamais la carte. Le radar est un `<aside>` — repère `complementary`.
  const radar = page.getByRole("complementary").filter({ hasText: "À traiter" });
  const card = radar.locator("div.rounded-md.border").filter({ hasText: INCIDENT });
  await expect(card.first(), "l'incident du seed doit avoir sa carte au radar").toBeVisible({ timeout: 30_000 });

  // ⚠ DEUX faits d'écran qu'on ne devine pas, et qui ont coûté deux runs de sept minutes chacun —
  // c'est la raison pour laquelle la consigne était de LIRE `RadarPanel` d'abord :
  //  1. la carte d'une fermeture est REPLIÉE (son action vit dans `children`, monté au dépli) ;
  //  2. son ouvreur n'est PAS un bouton « Adapter » mais une PUCE DE COUVERTURE, une par semaine
  //     ou groupe de semaines, libellée « sem. du … · à faire » (`RadarPanel.tsx` — la puce
  //     appelle `adapt(child.id)` quand la semaine n'a pas encore de version, `viewOverlay`
  //     sinon). Les semaines déjà gouvernées, elles, ne sont pas cliquables du tout.
  const unfold = card.first().getByRole("button", { name: /^Déplier / });
  if (await unfold.isVisible().catch(() => false)) {
    await unfold.click();
  }
  // ⚠ IDEMPOTENCE — la base e2e n'est jamais réinitialisée, donc la puce de NOTRE semaine change
  // d'état d'un run à l'autre : « à faire » au premier passage (elle appelle `adapt` → wizard),
  // « ✅ » ensuite (elle appelle `viewOverlay` → `/planning`). On la vise donc par sa FENÊTRE, pas
  // par son état, et on accepte les deux destinations. Mordu au deuxième run : le premier avait
  // généré l'overlay, et le spec ne se reconnaissait plus.
  await card.first().getByRole("button", { name: /^sem\. du 7 sept/ }).first().click();

  // Une période de PLUSIEURS semaines demande d'abord lesquelles ajuster. On prend le bloc entier
  // — c'est le geste du gestionnaire qui subit une fermeture de trois semaines.
  const weeks = page.getByRole("dialog", { name: "Choisir les semaines" });
  if (await weeks.isVisible().catch(() => false)) {
    const wholeBlock = weeks.getByRole("button", { name: /^(Adapter toute la période|Continuer d'un bloc)/ });
    await expect(wholeBlock, "le chemin « d'un bloc » doit être OFFERT : la fenêtre de l'incident n'est ni sous vacances ni déjà planifiée").toBeEnabled({ timeout: 15_000 });
    await wholeBlock.click();
  }
  await expect(page, "la puce doit mener à l'écran de la période — atelier (1er passage) ou planning (overlay déjà là)").toHaveURL(/\/(wizard|planning)/, { timeout: 30_000 });
  const inWizard = /\/wizard/.test(page.url());

  // --- 3 · Générer l'overlay, s'il ne l'est pas déjà (idempotence). L'atelier n'existe qu'au
  //         premier passage : une fois l'overlay né, la puce mène droit à l'écran du planning.
  let periodTitle = "l'overlay de la période";
  if (inWizard) {
    periodTitle = await readPeriodTitle(page);
    await page.getByRole("button", { name: "Génération", exact: false }).first().click();
    await expect(page.getByRole("heading", { name: /Étape 6\/6/ })).toBeVisible({ timeout: 30_000 });
    if ((await versionsOf(page)).length === 0) {
      await page.getByRole("button", { name: "Générer le planning de période" }).click();
      // Mesuré à 4 s sur ce club ; la marge couvre un runner chargé, pas une attente à l'aveugle.
      await expect(page.getByRole("combobox", { name: /version du planning/i }), `${periodTitle} : la génération n'a produit aucune version`).toBeVisible({ timeout: 180_000 });
    }
  }

  // --- 4 · LES TÉMOINS DU BORNAGE — le cœur de ce parcours.
  //
  // Le bug du 2026-08-19 faisait retomber l'écran de période sur le plan de SAISON en silence.
  // `PlanningToolbar` porte d'ailleurs le commentaire « bug fondateur 2026-08-19 » à l'endroit
  // où il borne la liste des versions.

  // T1 — l'écran NOMME son plan. Un wizard resté en mode saison n'affiche pas ce bandeau.
  if (inWizard) {
    await expect(page.getByText(`Mode période — ${periodTitle}`), "l'atelier doit se dire en mode période sur SA période").toBeVisible();
  }

  // T2 — le sélecteur ne montre QUE la lignée de ce plan. Le socle du club seedé est VALIDÉ :
  // s'il fuitait ici, son libellé « en vigueur » apparaîtrait dans la liste.
  const versions = await versionsOf(page);
  expect(versions.length, "au moins une version d'overlay attendue").toBeGreaterThan(0);
  for (const version of versions) {
    expect(version, `« ${version} » n'appartient pas à la lignée de cette période — le socle a fui dans le sélecteur`).not.toMatch(/en vigueur/i);
    expect(version, `« ${version} » ne ressemble pas à une version de période`).toMatch(/^V\d+/);
  }

  // T3 — le socle n'a pas bougé pendant qu'on adaptait.
  await expectSocleIntact(page, "après l'overlay");

  // T4 — les deux plannings coexistent, chacun avec son état.
  await openCockpit(page);
  await page.getByRole("button", { name: /Tous les plannings/ }).first().click();
  const plannings = page.getByRole("dialog");
  await expect(plannings).toBeVisible({ timeout: 15_000 });
  await expect(plannings, "le socle doit rester listé et VALIDÉ").toContainText("Validé");
  // ⚠ On identifie l'overlay par sa FENÊTRE, pas par son nom — choix d'IDEMPOTENCE. Depuis la
  // décision fondateur 2026-08-23, un plan de période naît nommé du TITRE de son entrée : le
  // bandeau du wizard et cette liste affichent donc le même nom pour un plan né sous cette règle.
  // Mais la base e2e peut encore porter des plans nés sous l'ANCIEN gabarit serveur (« Ajustement
  // gymnase — … »), au nom distinct du titre — s'appuyer sur la fenêtre traverse les deux ères
  // sans jamais mentir.
  await expect(plannings, "l'overlay de l'incident doit être listé à côté du socle").toContainText("31-08-2026 → 16-10-2026");
  // Les DEUX reprises du seed sont là aussi : trois plannings distincts coexistent, chacun avec
  // sa lignée — c'est la « réalité d'un club » que ce parcours doit attester (P4-122).
  await expect(plannings, "les plannings de reprise doivent cohabiter avec le socle et l'overlay").toContainText("Reprise");
});
