import { expect, test } from "./fixtures";

type Page = import("@playwright/test").Page;

/**
 * P4-107 (3ᵉ tranche) — **les largeurs, mesurées là où elles existent.**
 *
 * Les tests unitaires (`fiche-page.test.tsx`, `modal-size.test.tsx`) épinglent des CLASSES :
 * jsdom n'a aucun moteur de mise en page, `getBoundingClientRect` y vaut 0. Ils ne peuvent
 * donc pas voir le défaut le plus bête de ce lot — **un token `--container-fiche` absent
 * d'`index.css`** : `max-w-fiche` serait alors une classe qui n'engendre AUCUN CSS, la page
 * partirait pleine largeur, et tous les tests unitaires resteraient verts.
 *
 * D'où ce fichier, et sa taille : deux mesures, pas un inventaire. La calibration se fait sur
 * **1920×1080**, l'écran de référence du fondateur — celui sur lequel « la marge était plus
 * grande que l'utile ».
 *
 * ⚠ **Le témoin du paragraphe.** La borne de lisibilité ne se prouve que si le paragraphe est
 * mesuré ÉTROIT pendant que son cadre est mesuré LARGE : les deux ensemble, sinon on ne
 * distingue pas « la borne agit » de « il n'y avait pas la place ». Et le test échoue en le
 * disant s'il ne trouve aucun paragraphe à mesurer — un scénario vide qui passe en silence est
 * le faux vert que ce dépôt a déjà payé une fois (`modal-reachability.spec.ts`). Le détail de
 * ce que ce témoin a appris en rougissant est écrit à l'endroit de l'assertion.
 */

// Club de dev seedé (BasketballInit) — même porte que `matches.spec.ts`.
const EMAIL = "mara.mb@bccl.fr";
const PASSWORD = "maraboubccl";

/** 52rem : le token `--container-fiche`. La valeur est écrite ICI en dur, exprès — si elle
 *  change dans `index.css`, ce test doit rougir et obliger à venir dire que c'était voulu. */
const FICHE_WIDTH = 832;

async function login(page: Page): Promise<void> {
  await page.goto("/login");
  await page.getByLabel("Email").fill(EMAIL);
  await page.getByLabel("Mot de passe", { exact: true }).fill(PASSWORD);
  await page.getByRole("button", { name: "Se connecter" }).click();
  await expect(page.getByRole("button", { name: "Saison de travail" })).toBeVisible({ timeout: 15_000 });
}

test.describe("largeurs sur 1920×1080", () => {
  test.use({ viewport: { width: 1920, height: 1080 } });

  test("les pages fiche mesurent la largeur du cadre partagé, et leurs textes restent lisibles dedans", async ({ page }) => {
    test.setTimeout(90_000);
    await login(page);

    for (const path of ["/profile", "/club"]) {
      await page.goto(path);
      // Le cadre : le premier élément de la page principale qui porte la classe du token.
      const fiche = page.locator("main .max-w-fiche").first();
      await expect(fiche, `${path} : la page doit être cadrée par FichePage`).toBeVisible({ timeout: 20_000 });

      const box = await fiche.boundingBox();
      expect(box, `${path} : cadre non mesurable`).not.toBeNull();
      // Tolérance d'1 px (sous-pixel navigateur). Une largeur de ~1856 px signalerait un
      // token `--container-fiche` absent : la classe existe, le CSS non.
      expect(
        Math.round(box!.width),
        `${path} : le cadre mesure ${Math.round(box!.width)} px au lieu de ${FICHE_WIDTH}. Si c'est la largeur de la fenêtre, le token --container-fiche manque à index.css et \`max-w-fiche\` n'engendre aucun CSS.`,
      ).toBeGreaterThanOrEqual(FICHE_WIDTH - 1);
      expect(Math.round(box!.width), `${path} : le cadre dépasse la largeur fiche`).toBeLessThanOrEqual(FICHE_WIDTH + 1);
    }

    // La borne de lisibilité, sur la page qui porte le plus de texte d'aide (Club).
    //
    // ⚠ **Ce que ce témoin a appris, et pourquoi il a changé de forme.** Sa première version
    // exigeait un paragraphe de plus de 120 caractères, en croyant qu'un texte long était
    // nécessaire pour que la mesure prouve quelque chose. Elle a ROUGI : les textes d'aide de
    // /club plafonnent à ~113 caractères, et les accordéons fermés n'en rendent aucun. Or la
    // prémisse était fausse — un `<p>` est un bloc : sa largeur vaut min(conteneur, max-width),
    // **quelle que soit la longueur du texte**. Un texte court aurait donc mesuré 832 px sans la
    // borne, exactement comme un texte long.
    //
    // La propriété qui compte est donc celle-ci : **le paragraphe est BORNÉ pendant que son
    // cadre, lui, est large** — s'ils mesuraient pareil, la borne ne s'appliquerait pas. D'où
    // les deux assertions, plus le témoin « il faut au moins un paragraphe à mesurer ».
    const paragraph = await page.evaluate(() => {
      const candidate = Array.from(document.querySelectorAll<HTMLElement>("main .max-w-fiche p")).find(
        (p) => (p.textContent ?? "").trim().length > 40,
      );
      return candidate ? { width: candidate.getBoundingClientRect().width, text: (candidate.textContent ?? "").slice(0, 60) } : null;
    });

    expect(
      paragraph,
      "aucun paragraphe d'au moins 40 caractères sur /club : le scénario ne met RIEN à l'épreuve — sans texte à mesurer, la borne de lisibilité n'est pas testée",
    ).not.toBeNull();
    expect(
      Math.round(paragraph!.width),
      `le texte d'aide « ${paragraph!.text}… » occupe ${Math.round(paragraph!.width)} px alors que son cadre en fait ${FICHE_WIDTH} : la borne \`[&_p]:max-w-prose\` ne s'applique pas. Élargir la fiche sans borner ses paragraphes échange un défaut contre un autre.`,
    ).toBeLessThan(FICHE_WIDTH - 200);
    // Deuxième sens : une borne qui écraserait le texte serait un autre défaut.
    expect(Math.round(paragraph!.width), "le paragraphe est anormalement étroit — la borne ne doit pas écraser le texte").toBeGreaterThan(300);
  });

  test("étape Équipes : aucun sélecteur ne coupe la valeur qu'il affiche", async ({ page }) => {
    test.setTimeout(90_000);
    await login(page);

    // ⚠ **Le seul test qui puisse voir ce défaut.** jsdom n'a aucun moteur de mise en page :
    // le test unitaire ne garde que la PARITÉ des largeurs entre les trois rendus de colonnes
    // (`teamColumns.ts`). Qu'une valeur SÉLECTIONNÉE tienne dans son champ — « Homme » rendu
    // « Homn », le défaut constaté par le fondateur à 1920 — ne se mesure qu'ici.
    await page.goto("/wizard");
    await page.getByRole("button", { name: "Équipes" }).first().click();
    const selects = page.locator("select[aria-label='Genre'], select[aria-label='Niveau de jeu'], select[aria-label='Catégorie']");
    await expect(selects.first()).toBeVisible({ timeout: 30_000 });

    // ⚠ **Cette mesure dépend des POLICES de la machine qui l'exécute, et la CI fait foi.**
    // Mesuré le 2026-08-21 : « — catégorie — » fait **92,6 px** en local et **103 px** en CI —
    // même chaîne, même pile `system-ui`, polices installées différentes. Conséquence à
    // connaître avant de dimensionner une colonne : un vert local ne prouve rien si la marge
    // est au rasoir. C'est ainsi que `w-36` est passé ici et a rougi là-bas — et c'était la CI
    // qui avait raison, le placeholder ÉTAIT coupé.
    //
    // ⚠ **Le témoin, et ce qu'il a coûté de ne pas l'avoir.** La première version de ce test
    // mesurait les sélecteurs du FORMULAIRE d'ajout, dont la valeur sélectionnée est « — » :
    // 14 px, qui tiennent partout. Le test passait, et il passait ENCORE en remettant la
    // largeur fautive (`w-20`) — il ne gardait rien. On exige donc d'avoir mesuré de VRAIES
    // valeurs, celles des lignes de la liste.
    await expect(page.getByRole("button", { name: "Supprimer" }).first()).toBeVisible({ timeout: 30_000 });
    const count = await selects.count();
    let measured = 0;

    for (let i = 0; i < count; i++) {
      const verdict = await selects.nth(i).evaluate((el: HTMLSelectElement) => {
        // Largeur du TEXTE de l'option sélectionnée, mesurée à la police réelle du champ.
        const style = window.getComputedStyle(el);
        const probe = document.createElement("span");
        probe.style.cssText = `position:absolute;visibility:hidden;white-space:nowrap;font:${style.font}`;
        probe.textContent = el.options[el.selectedIndex]?.text ?? "";
        document.body.append(probe);
        const textWidth = probe.getBoundingClientRect().width;
        probe.remove();
        // Place réellement offerte au texte : largeur du champ moins ses rembourrages (le
        // droit contient le chevron).
        const usable = el.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);

        return { label: el.getAttribute("aria-label"), text: probe.textContent, textWidth, usable };
      });

      // ⚠ On ASSERTE sur tout ce qui est AFFICHÉ, placeholder compris : un « — catégorie — »
      // coupé est le même défaut qu'un « Homme » coupé, et c'est exactement ce que la CI a
      // trouvé là où le club de dev ne le montrait pas. Seul le COMPTE du témoin ignore les
      // « — » nus : eux ne prouvent rien, ils tiennent partout.
      const isPlaceholder = "" === (verdict.text ?? "").trim() || (verdict.text ?? "").trim().startsWith("—");
      if (!isPlaceholder) {
        measured += 1;
      }
      expect(
        Math.ceil(verdict.textWidth),
        `« ${verdict.text} » (${verdict.label}) demande ${Math.ceil(verdict.textWidth)} px et n'en reçoit que ${Math.floor(verdict.usable)} : la valeur est COUPÉE à l'écran, exactement le défaut « Homn » pour « Homme »`,
      ).toBeLessThanOrEqual(Math.floor(verdict.usable));
    }

    expect(
      measured,
      "aucune valeur RÉELLE mesurée (que des « — » du formulaire d'ajout) : le scénario ne met rien à l'épreuve — attendre les lignes de la liste",
    ).toBeGreaterThan(2);
  });
});

/**
 * P4-261 — à 360 px, le NOM du club de l'en-tête (`AppLayout`) ne se tronque plus à « B… » :
 * il se MASQUE proprement sous `sm` (`hidden sm:inline` sur le span), tandis que le lien
 * d'accueil garde son nom accessible (porté par son `aria-label`, à toutes les largeurs).
 *
 * Décision fondateur : desktop-first, mobile en V2 — la nav de droite ne se rétracte PAS. La
 * dette de reflow GLOBALE à 360 px (le produit déborde encore, DevClock de dev compris) reste
 * une ligne de roadmap ; ce test ne mesure donc PAS le non-débordement de l'en-tête (cf. le
 * docblock du test 360 px de `matches.spec.ts`), il verrouille le comportement DÉCIDÉ : le mot
 * se masque/réapparaît proprement, l'accessibilité ne bouge pas.
 *
 * ⚠ Le nom du club vient d'un TÉMOIN que le scénario établit lui-même (`GET /api/me`), jamais
 * d'un littéral « BCCL » : le seed CI peut changer de nom sans casser le test, et le span est
 * lié à ce témoin (`toHaveText`) avant qu'on ne juge sa visibilité.
 *
 * e2e écrit, PAS lancé (garde sandbox ; l'exécution des e2e reste au fondateur, la CI fait foi).
 */
test("en-tête à 360 px : le nom du club se masque sous sm, le lien d'accueil garde son nom accessible", async ({ page }) => {
  test.setTimeout(90_000);
  await login(page);

  // Témoin établi par le scénario : le nom que l'en-tête affiche EST celui du club de /api/me.
  const meRes = await page.request.get("/api/me");
  expect(meRes.ok(), "GET /api/me").toBeTruthy();
  const clubName = ((await meRes.json()).club?.name ?? "") as string;
  expect(clubName, "le club seedé a un nom — sans lui le scénario ne met RIEN à l'épreuve").toBeTruthy();

  // /club est sous AppLayout et joignable quel que soit l'état d'onboarding.
  await page.goto("/club");
  const header = page.locator("header");
  // Le span du nom : unique enfant direct <span> du lien d'accueil (a[href='/']).
  const nameSpan = header.locator("a[href='/'] > span");
  // On lie le span au témoin AVANT de juger sa visibilité (toHaveText lit le textContent,
  // masqué ou non) : on prouve que c'est bien l'élément du nom du club.
  await expect(nameSpan).toHaveText(clubName);

  // ── 360 px (téléphone) : le lien d'accueil reste, avec son nom accessible ; le MOT est masqué ──
  await page.setViewportSize({ width: 360, height: 740 });
  const homeLink = header.getByRole("link", { name: clubName });
  await expect(homeLink).toBeVisible();
  await expect(homeLink).toHaveAccessibleName(clubName);
  await expect(nameSpan, "à 360 px le nom du club doit être MASQUÉ (hidden sm:inline), pas tronqué à « B… »").toBeHidden();

  // ── 1280 px (bureau) : le mot réapparaît (sm:inline) ; le nom accessible n'a jamais bougé ──
  await page.setViewportSize({ width: 1280, height: 900 });
  await expect(nameSpan, "à 1280 px le nom du club doit être visible").toBeVisible();
  await expect(homeLink).toHaveAccessibleName(clubName);
});
