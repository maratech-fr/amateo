import { expect, type Locator, type Page, test } from "./fixtures";

import { ensureValidated, expectNoA11yViolations, expectNoContrastViolations, forceTheme, landOnMatchesCalendar, loginSeededClub, registerAndVerify, settleVeil, uniqueAra } from "./support";

/**
 * WCAG 2.2 AA colour-contrast (1.4.3) on the real rendered app — the axis jsdom
 * cannot see. Runs axe-core (color-contrast rule) inside Chromium on the public
 * screens in BOTH themes, and walks a fresh club through the data-entry wizard so
 * the dense screens (availability grid, constraints) are checked too.
 */
const MODES = ["dark", "light"] as const;

for (const mode of MODES) {
  test(`contrast — public screens (${mode})`, async ({ page }) => {
    await forceTheme(page, mode);

    await page.goto("/login");
    await expect(page.getByRole("button", { name: /se connecter/i })).toBeVisible();
    await expectNoContrastViolations(page, `login (${mode})`);

    // Register = 2 écrans (sport puis champs) — les deux sont publics, on vérifie les deux.
    await page.goto("/register");
    await expect(page.getByRole("button", { name: /continuer/i })).toBeVisible();
    await expectNoContrastViolations(page, `register · sport (${mode})`);
    await page.getByRole("button", { name: /continuer/i }).click();
    await expect(page.getByRole("button", { name: /créer le compte/i })).toBeVisible();
    await expectNoContrastViolations(page, `register · champs (${mode})`);
  });
}

for (const mode of MODES) {
  test(`contrast — wizard data entry (${mode})`, async ({ page }) => {
    test.setTimeout(120_000);
    await forceTheme(page, mode);

    const ara = uniqueAra("A11Y");
    await registerAndVerify(page, { email: `a11y-${ara}@e2e.fr`, ara, firstName: "A11y", lastName: "Contrast", clubName: "A11y Club" });
    await expect(page.getByRole("heading", { name: /Étape 1\/6/ })).toBeVisible({ timeout: 15_000 });

    await expectNoContrastViolations(page, `wizard · équipes (${mode})`);

    // Add a team + advance to the gym availability grid (dense small text).
    // ⚠ La catégorie n'a plus de valeur par défaut (revue #347) : la pré-sélection valait
    // `categories[0]`, que le catalogue réordonné a transformé en « Vétéran » pour tous les
    // clubs. Le choix est donc explicite — et il PERSISTE d'un ajout à l'autre, si bien
    // qu'il ne coûte qu'une fois par changement de catégorie.
    await page.getByLabel("Nom de l'équipe").fill("SM1");
    await page.getByLabel("Catégorie").selectOption({ label: "Senior" });
    await page.getByRole("button", { name: "Ajouter l'équipe" }).click();
    await page.getByRole("button", { name: "Suivant" }).click();
    await expect(page.getByRole("heading", { name: /Étape 2\/6/ })).toBeVisible();
    // ⚠ Scanner un écran SETTLED, pas en pleine transition d'étape. Le clic « Suivant » est une
    // transition (lot C) : le temps qu'elle se pose, la surbrillance de l'étape courante dans le
    // rail n'a pas encore sa couleur finale (bref `text-muted-foreground` sur `bg-muted` ≈ 3.93).
    // Avant que le voile ne diffère son blocage à 250 ms, l'`inert` couvrait ce sous-arbre et axe
    // le SAUTAIT — un faux vert qui ne vérifiait rien de cet écran. On attend donc le settle : axe
    // valide alors la vraie couleur (`text-foreground`, AA). cf. `settleVeil`.
    await settleVeil(page);
    await expectNoContrastViolations(page, `wizard · gymnases (${mode})`);
  });
}

/**
 * A11Y-22/23/24 — les écrans AUTHENTIFIÉS denses (`/matchs`, `/matchs/semaine-type`, `/planning`,
 * `/club`) portent les grilles, onglets et régions que ce lot a corrigés. axe sur les écrans PUBLICS
 * ne les peint jamais : on connecte le club seedé, on amène le plan à « validé » (matchs débloqués),
 * et on lance un scan axe COMPLET (`wcag2a`/`wcag2aa`/`wcag21aa`) sur chaque écran, DANS LES DEUX
 * THÈMES. C'est ce scan structurel qui garde A11Y-23 (`aria-valid-attr-value` : un `aria-controls`
 * vers un id absent) et A11Y-24 (`scrollable-region-focusable`), en plus du contraste (color-contrast
 * est tagué wcag2aa). Chaque scan exige un TÉMOIN (une grille / région / carte RENDUE) — un scan sur
 * écran vide ne prouve rien — et attend la levée du voile.
 *
 * ⚠ Un e2e crée ce qu'il vérifie (mémoire dépôt, 3 PR rougies). Le club seedé CI a des habitudes de
 * semaine type et un planning (via `ensureValidated`) mais AUCUNE rencontre (`app:bccl:seed` pose des
 * `TeamMatchHabit`/`MatchSlotRotation`, jamais de `Fixture`). `/matchs` rend donc un EmptyState
 * « Aucun match importé » (`CalendarPage.tsx:528`), pas la grille : on lui POSTe un amical (patron
 * `matches-consulter.spec.ts`), nettoyé en `finally`. Les trois autres écrans ont une donnée GARANTIE
 * par le seed / l'onboarding (voir chaque témoin). L'audit du 18/09 a exécuté ce scan sur la base BCCL
 * réelle (291 rencontres) sans violation, hormis `/matchs/semaine-type` (les deux défauts corrigés).
 * Onboarding idempotent : seul le 1ᵉʳ thème déclenche une génération (CP-SAT réelle).
 */
function ymd(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}
/** Le prochain samedi (Y-m-d) à au moins `minAhead` jours d'aujourd'hui — une semaine future VIERGE. */
function nextSaturdayAtLeast(minAhead: number): string {
  const d = new Date();
  d.setHours(0, 0, 0, 0);
  d.setDate(d.getDate() + minAhead);
  while (6 !== d.getDay()) {
    d.setDate(d.getDate() + 1); // 6 = samedi
  }
  return ymd(d);
}
/** Amène la semaine affichée sur celle qui contient `target`, en cliquant `btn` (‹ ou ›) au plus
 * `max` fois. Un locator qui résout PLUSIEURS éléments ÉCHOUE en le disant (jamais avalé en `false`). */
async function stepUntilVisible(btn: Locator, target: Locator, max: number): Promise<boolean> {
  const seen = async (): Promise<boolean> => {
    const n = await target.count();
    if (n > 1) {
      throw new Error(`stepUntilVisible: locator ambigu (${n} éléments) — scoper au conteneur (grille)`);
    }
    return 1 === n && (await target.first().isVisible());
  };
  if (await seen()) {
    return true;
  }
  for (let i = 0; i < max; i++) {
    if (!(await btn.isEnabled())) {
      break;
    }
    await btn.click();
    if (await seen()) {
      return true;
    }
  }
  return false;
}

// Témoin PAR ÉCRAN : un locator qui prouve un CONTENU rendu (pas seulement le gabarit). Le témoin
// GLOBAL précédent (`[data-testid="weekend-grid"], [role="region"], [class*="shadow-sm"]`) était trop
// étroit — la grille /planning (`WeekGrid`) ne porte NI carte `shadow-sm` NI `role="region"` NI
// testid, si bien qu'axe scannait une page pourtant PEINTE (heading + boutons de créneaux) et le
// témoin la déclarait « vide ». Chaque écran désigne donc sa propre preuve de contenu, GARANTIE en CI.
const AUTH_SCREENS: { path: string; label: string; witness: (page: Page) => Locator }[] = [
  // `/matchs/semaine-type` : la région nommée de `TypicalWeekendGrid.tsx:92`, rendue dès qu'il existe
  // ≥1 habitude (`columns.length > 0`). Le seed pose 32 `TeamMatchHabit` (`BcclSeeder.php:1407-1424`).
  { path: "/matchs/semaine-type", label: "matchs · semaine type", witness: (page) => page.getByRole("region", { name: "Grille de la semaine type" }) },
  // `/planning` : une carte de session RÉELLE (`WeekGrid` `data-slot-id`, WeekGrid.tsx:429/471), posée
  // par le planning que `ensureValidated` génère+valide. Le bouton de verrou n'existe PAS sur un plan
  // validé (lecture seule → cadenas passif, `onToggleLock` undefined, WeekGrid.tsx:188-191) : on vise
  // un créneau PLACÉ, présent dans la vue par défaut « Par gymnase ».
  { path: "/planning", label: "planning · grille", witness: (page) => page.locator("[data-slot-id]") },
  // `/club` : le titre de page `<h1>Gestion du club</h1>` (`ClubPage.tsx:644`), toujours rendu — contenu
  // de la page, pas du gabarit (l'`AppLayout` ne porte aucun h1).
  { path: "/club", label: "club · fiche", witness: (page) => page.getByRole("heading", { level: 1 }) },
];

for (const mode of MODES) {
  test(`contrast — authenticated screens (${mode})`, async ({ page }) => {
    test.setTimeout(300_000); // le 1er thème conduit une génération CP-SAT réelle
    await forceTheme(page, mode);
    await loginSeededClub(page);
    await ensureValidated(page);

    // ── /matchs : provisionner une rencontre (le club CI n'en a AUCUNE) ─────────────────────────
    // Les cookies du contexte suivent `page.request` (JWT httpOnly) : on prend la 1ʳᵉ équipe + le 1ᵉʳ
    // gymnase, on POSTe un amical HOME PLACÉ (venue + coup d'envoi → il tombe dans une case de grille).
    const teamsRes = await page.request.get("/api/teams?itemsPerPage=100");
    expect(teamsRes.ok(), "GET /api/teams").toBeTruthy();
    const teamId = ((await teamsRes.json()).member?.[0]?.id ?? undefined) as string | undefined;
    const venuesRes = await page.request.get("/api/venues?itemsPerPage=100");
    expect(venuesRes.ok(), "GET /api/venues").toBeTruthy();
    const venueId = ((await venuesRes.json()).member?.[0]?.id ?? undefined) as string | undefined;
    expect(teamId, "le club seedé a au moins une équipe").toBeTruthy();
    expect(venueId, "le club seedé a au moins un gymnase").toBeTruthy();

    const opponent = `A11Y-${mode.toUpperCase()}-${Date.now().toString(36).toUpperCase()}`;
    const created = await page.request.post("/api/fixtures", {
      data: { teamId, matchDate: nextSaturdayAtLeast(7), homeAway: "HOME", opponentLabel: opponent, venueId, kickoffTime: "15:00", competitionId: null },
    });
    expect(created.ok(), "POST /api/fixtures").toBeTruthy();
    const fixtureId = (await created.json()).id as string;
    try {
      await page.goto("/matchs");
      // UXS-07 — `goto` recharge la page (store vierge) : l'atterrissage conditionnel peut renvoyer
      // sur Conflits si le club porte des conflits. On attend que la décision soit RENDUE puis on
      // rejoint le Calendrier avant de scanner (maison unique, cf. `landOnMatchesCalendar`).
      await landOnMatchesCalendar(page);
      // « Amical » est DÉCOCHÉ par défaut (#916) — notre amical (`competitionId: null`) serait invisible,
      // la grille resterait un EmptyState. On le coche AVANT de chercher la semaine (une semaine 100 %
      // masquée n'entre pas dans le sélecteur : `weekends` dérive des fixtures VISIBLES).
      const amical = page.getByRole("button", { name: "Amical", exact: true });
      if ("true" !== (await amical.getAttribute("aria-pressed"))) {
        await amical.click();
      }
      await expect(amical).toHaveAttribute("aria-pressed", "true");
      // Rejoindre la semaine de NOTRE rencontre (indépendant de l'horloge serveur). Le libellé est
      // scopé à la grille (`weekend-grid`) : sans conflit, il n'est peint qu'une fois, dans la case.
      const grid = page.getByTestId("weekend-grid");
      const cell = grid.getByText(opponent, { exact: false });
      const nextWeek = page.getByRole("button", { name: "Semaine suivante" });
      const prevWeek = page.getByRole("button", { name: "Semaine précédente" });
      const found = (await stepUntilVisible(nextWeek, cell, 12)) || (await stepUntilVisible(prevWeek, cell, 24));
      expect(found, `matchs · calendrier (${mode}) : la semaine de la rencontre créée (${opponent}) est atteignable — grille jamais rendue`).toBeTruthy();
      await expect(cell).toBeVisible();
      await settleVeil(page);
      // ⚠ On NE bouge PAS le pointeur : le dernier geste (clic « Amical » puis, en CI, 0 saut de
      // semaine car la rencontre créée tombe sur la semaine résolue) le laisse sur la puce PRESSÉE
      // (`variant="default"` = `bg-accent`). Le scan en état `:hover` est VOULU — c'est lui qui a
      // pris le défaut #d74945/4,26 (l'ancien `hover:opacity-90` compositait l'accent vers le blanc) ;
      // le jeton `--accent-hover` change la teinte au survol sans casser le contraste, ce scan le garde.
      await expectNoA11yViolations(page, `matchs · calendrier (${mode})`);
    } finally {
      // Base dev CI non remise à zéro : on nettoie NOTRE rencontre, sans supposer l'état.
      await page.request.delete(`/api/fixtures/${fixtureId}`).catch(() => undefined);
    }

    // ── Les trois écrans à donnée GARANTIE par le seed / l'onboarding ────────────────────────────
    for (const screen of AUTH_SCREENS) {
      await page.goto(screen.path);
      await settleVeil(page);
      await expect(screen.witness(page).first(), `${screen.label} (${mode}) : aucun témoin (carte/grille/région) rendu — un scan sur écran vide ne prouve rien`).toBeVisible({ timeout: 20_000 });
      await expectNoA11yViolations(page, `${screen.label} (${mode})`);
    }
  });
}

/**
 * A11Y-06: the semantic status tokens (`--warning`, `--success`) are used as
 * normal-size text (`text-sm`/`text-xs` in DiagnosticsPanel, ConflictRadar,
 * RecapStep…), where axe on the public screens never renders them. axe only
 * checks text that is actually painted, so a token that fails in a state we don't
 * navigate to would slip through. Measure the token pairs DIRECTLY, in both
 * themes, against BOTH surfaces a status label can sit on (background + card):
 * WCAG 1.4.3 requires 4.5:1 for normal text. Light `--warning`/`--success` were
 * ~3:1 and were darkened to clear it — this locks that in.
 */
for (const mode of MODES) {
  test(`contrast — semantic status tokens as normal text (${mode})`, async ({ page }) => {
    await forceTheme(page, mode);
    await page.goto("/login");

    const ratios = await page.evaluate(() => {
      const cv = document.createElement("canvas");
      cv.width = cv.height = 1;
      const ctx = cv.getContext("2d")!;
      const probe = document.createElement("div");
      document.body.appendChild(probe);
      const toRgb = (color: string): [number, number, number] => {
        ctx.clearRect(0, 0, 1, 1);
        ctx.fillStyle = color;
        ctx.fillRect(0, 0, 1, 1);
        const d = ctx.getImageData(0, 0, 1, 1).data;
        return [d[0], d[1], d[2]];
      };
      const of = (cls: string, prop: "color" | "backgroundColor"): [number, number, number] => {
        probe.className = cls;
        return toRgb(getComputedStyle(probe)[prop]);
      };
      const lum = ([r, g, b]: [number, number, number]): number => {
        const c = [r, g, b].map((v) => {
          const x = v / 255;
          return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
      };
      const ratio = (a: [number, number, number], b: [number, number, number]): number => {
        const [l1, l2] = [lum(a), lum(b)].sort((x, y) => y - x);
        return (l1 + 0.05) / (l2 + 0.05);
      };
      const bg = of("bg-background", "backgroundColor");
      const card = of("bg-card", "backgroundColor");
      const out: Record<string, number> = {};
      // `text-accent` rejoint la liste avec P4-43 : le filtre de ressources actif écrit son
      // libellé en accent. La mesure a valu son prix — le fond teinté qu'on lui destinait
      // (`bg-accent/10`) tombait à 4.18:1 en clair, et `bg-muted` au survol à 4.37:1. Sur
      // le fond nu il passe (4.77:1). Ce jeton est désormais du TEXTE : il se garde ici.
      // `text-foreground` (lot C PR-2) : le texte du panneau du VOILE BLOQUANT — panneau `bg-card`,
      // bouton d'abandon `bg-background`. Le voile n'apparaît que le temps d'une mutation, donc axe
      // ne l'échantillonne JAMAIS sur un écran : on verrouille sa paire ici, dans les deux thèmes.
      // `text-muted-foreground` (P4-179) : les jours HORS-MOIS du calendrier (`MonthCalendar`) le
      // portent maintenant en PLEIN — l'ancien `/50` tombait à 2,1:1 / 2,5:1 (opacité sur du texte,
      // sous AA, y compris derrière le voile d'une modale). Le calendrier n'est pas visité par axe
      // sur les écrans publics → on verrouille sa paire ici. (Règle : jamais d'opacité sur du texte.)
      for (const token of ["text-warning", "text-success", "text-accent", "text-foreground", "text-muted-foreground"]) {
        const fg = of(token, "color");
        out[`${token} on background`] = ratio(fg, bg);
        out[`${token} on card`] = ratio(fg, card);
      }
      // P4-173 — le TEXTE de la pastille « à régénérer » (StatusPill warning) : `text-foreground`
      // sur `bg-warning/10`. Le fond est SEMI-TRANSPARENT (α 0.1) : sa vraie couleur est la
      // composition sur `bg-background`. Le cockpit n'est pas visité par axe ici → on mesure la
      // paire, dans les deux thèmes. `text-warning` y tombait à 4,30:1 en clair (< AA) : le texte
      // est donc `text-foreground` (repli de la bannière /planning) ; l'icône, elle, reste
      // `text-warning` et se mesure au seuil graphique 1.4.11 (≥ 3:1) dans le test dédié plus bas.
      const composite = (over: string, under: [number, number, number]): [number, number, number] => {
        ctx.clearRect(0, 0, 1, 1);
        ctx.fillStyle = `rgb(${under[0]}, ${under[1]}, ${under[2]})`;
        ctx.fillRect(0, 0, 1, 1);
        probe.className = over;
        ctx.fillStyle = getComputedStyle(probe).backgroundColor;
        ctx.fillRect(0, 0, 1, 1);
        const d = ctx.getImageData(0, 0, 1, 1).data;
        return [d[0], d[1], d[2]];
      };
      out["text-foreground on bg-warning/10"] = ratio(of("text-foreground", "color"), composite("bg-warning/10", bg));
      // Option 1 (jeton `--accent-hover`) — le SURVOL d'un bouton accent (`variant="default"`) : le
      // texte du repos (`--accent-foreground`) sur la teinte de survol (`--accent-hover`, opaque,
      // dérivée par mode). Remplace `hover:opacity-90` qui compositait `bg-accent` vers la surface
      // (blanc/accent : 4,85 → 4,26 en clair). Sur /login (défaut, pas de club) on mesure la paire
      // par défaut d'`index.css` ; le survol club est gardé par `color.test.ts` + le scan /matchs.
      out["text-accent-foreground on bg-accent-hover"] = ratio(of("text-accent-foreground", "color"), of("bg-accent-hover", "backgroundColor"));
      // A11Y-22 — la même pastille warning peut être posée sur une CARD (ConflictRadar dans une
      // Card, WeekendGrid « À confirmer ») : le fond `bg-warning/10` se composite alors sur `bg-card`.
      out["text-foreground on bg-warning/10 (over card)"] = ratio(of("text-foreground", "color"), composite("bg-warning/10", card));
      // P4-177 — le TEXTE de la pastille `accent` (StatusPill : « Gain » d'un compromis, source
      // MANUEL) : `text-foreground` sur `bg-accent/10` (fond α 0.1 → composité sur `bg-background`).
      // `text-accent` y tombe sous AA (cf. AGENTS.md gotcha #11) : le texte est donc `text-foreground`
      // comme la variante warning ; l'icône reste `text-accent` (seuil graphique, test dédié plus bas).
      // Les surfaces porteuses (compromis /planning, matrice de trajets) ne sont pas visitées par axe
      // ici → on verrouille la paire dans les deux thèmes.
      out["text-foreground on bg-accent/10"] = ratio(of("text-foreground", "color"), composite("bg-accent/10", bg));
      // P4-164 — le LIBELLÉ d'une option de listbox (`text-foreground`) sur la surbrillance
      // active/survol (`bg-muted`, opaque) : la ligne active d'un sélecteur d'équipe n'est pas
      // toujours peinte quand axe scanne (liste fermée), on verrouille la paire ici, deux thèmes.
      out["text-foreground on bg-muted"] = ratio(of("text-foreground", "color"), of("bg-muted", "backgroundColor"));
      // P4-179 — le TEXTE du bouton partagé `destructive` : `text-destructive-foreground` sur le fond
      // plein `bg-destructive`. Le blanc en dur y tombait à 4,02:1 en SOMBRE (mesuré) : on a introduit
      // le jeton `--destructive-foreground` (blanc en clair, texte sombre en sombre) et remonté
      // `--destructive` sombre à L 0,66. Aucun écran public ne peint un bouton destructive → paire
      // verrouillée ici, deux thèmes.
      out["text-destructive-foreground on bg-destructive"] = ratio(of("text-destructive-foreground", "color"), of("bg-destructive", "backgroundColor"));
      // P4-180 — le TEXTE d'une case de la grille de réservation (`ReservationGrid`) : compteur `N/cap`,
      // « libre », libellé de groupe. Le fond de case est un `color-mix(in oklch, <couleur> 30%, card)` :
      // `text-accent`/`text-muted-foreground` y tombaient sous AA (3,2–4,1 selon thème, mesuré) ; le texte
      // est donc `text-foreground`. On mesure sur la couleur PAR DÉFAUT (accent) ET sur la couleur de
      // gymnase la plus CLAIRE de la palette (`#FFD21E`, pire cas pour du texte clair en sombre). Aucun
      // écran public ne peint cette grille → paires verrouillées ici, deux thèmes.
      const cellFill = (mix: string): [number, number, number] => {
        probe.className = "";
        probe.style.backgroundColor = mix;
        const rgb = toRgb(getComputedStyle(probe).backgroundColor);
        probe.style.backgroundColor = "";
        return rgb;
      };
      const fg = of("text-foreground", "color");
      out["text-foreground on réservation cell (accent tint)"] = ratio(fg, cellFill("color-mix(in oklch, var(--accent) 30%, var(--card))"));
      out["text-foreground on réservation cell (bright venue tint)"] = ratio(fg, cellFill("color-mix(in oklch, #FFD21E 30%, var(--card))"));
      // A11Y-22 — la case RÉELLE de la grille week-end (`WeekendGrid`) porte son texte `text-foreground`
      // sur `tint(venueColor)` = `<hex>22` (α 0x22/255 ≈ 0,13), composité sur `bg-card`. On mesure la
      // PIRE teinte de `VENUE_PALETTE` pour du texte foncé : le jaune `#FFD21E` (le plus clair). La
      // grille PLANNING (`WeekGrid`) partage le MÊME helper `tint()` et la même sous-ligne
      // `text-foreground` (coach, corrigé de `text-muted-foreground` qui tombait à 4,24-4,33 en sombre) :
      // cette paire les garde donc toutes les deux, dans les deux thèmes.
      const compositeColor = (color: string, under: [number, number, number]): [number, number, number] => {
        ctx.clearRect(0, 0, 1, 1);
        ctx.fillStyle = `rgb(${under[0]}, ${under[1]}, ${under[2]})`;
        ctx.fillRect(0, 0, 1, 1);
        ctx.fillStyle = color;
        ctx.fillRect(0, 0, 1, 1);
        const d = ctx.getImageData(0, 0, 1, 1).data;
        return [d[0], d[1], d[2]];
      };
      out["text-foreground on WeekendGrid cell (bright venue tint)"] = ratio(fg, compositeColor("#FFD21E22", card));
      // A11Y-22 — `text-muted-foreground` PLEIN (l'opacité sur du texte a disparu) sur les cases vides /
      // fermées des grilles planning (`WeekGrid`) : sur `bg-muted` opaque, et sur `bg-muted/40` sur card.
      out["text-muted-foreground on bg-muted"] = ratio(of("text-muted-foreground", "color"), of("bg-muted", "backgroundColor"));
      out["text-muted-foreground on bg-muted/40 (over card)"] = ratio(of("text-muted-foreground", "color"), composite("bg-muted/40", card));
      // A11Y — les CARTES de conflit de l'onglet Conflits (`ConflictLine`, `TONE_CLASSES`) portent leur
      // texte (`text-foreground`) et leur phrase (`text-muted-foreground`) sur des teintes SEMI-TRANSPARENTES
      // selon la gravité : `bg-destructive/5` (rouge), `bg-warning/5` (ambre), `bg-muted/30` (neutre),
      // compositées sur `bg-background` (liste de l'onglet) OU `bg-card` (ConflictRadar/WeekendGrid).
      // L'onglet Conflits n'est PAS visité par axe (il faudrait y provisionner une vraie collision) → on
      // verrouille ses paires composites ici, dans les deux thèmes. cf. TONE_CLASSES (ConflictLine.tsx).
      const conflictTints: [string, string][] = [
        ["destructive/5", "bg-destructive/5"],
        ["warning/5", "bg-warning/5"],
        ["muted/30", "bg-muted/30"],
      ];
      const conflictMutedFg = of("text-muted-foreground", "color");
      for (const [tintName, tintClass] of conflictTints) {
        const overBg = composite(tintClass, bg);
        const overCard = composite(tintClass, card);
        out[`text-foreground on bg-${tintName} (conflict card over background)`] = ratio(fg, overBg);
        out[`text-foreground on bg-${tintName} (conflict card over card)`] = ratio(fg, overCard);
        out[`text-muted-foreground on bg-${tintName} (conflict card over background)`] = ratio(conflictMutedFg, overBg);
        out[`text-muted-foreground on bg-${tintName} (conflict card over card)`] = ratio(conflictMutedFg, overCard);
      }
      // P4-181 — `text-destructive` en TEXTE vit sur des teintes `bg-destructive/10|15` (badge de
      // contrainte `PeriodStructure`, badge `ReconciliationPanel`, jour sélectionné `CoachWishForm`,
      // « F » férié `MonthCalendar` en /15, alerte `SlotReservationModal`), sur card ET sur background.
      // Mesuré INFORMATIF lors de P4-179/180 : 3,98:1 en clair sur /10 ; le sombre passait sur
      // background (4,87) mais PAS sur card (4,42 en /10, 4,16 en /15). Les deux jetons `--destructive`
      // sont recalés (clair L 0,50, sombre L 0,72) et les six paires deviennent des GATES DURS.
      const td = of("text-destructive", "color");
      out["text-destructive on background"] = ratio(td, bg);
      out["text-destructive on card"] = ratio(td, card);
      out["text-destructive on bg-destructive/10 (over background)"] = ratio(td, composite("bg-destructive/10", bg));
      out["text-destructive on bg-destructive/10 (over card)"] = ratio(td, composite("bg-destructive/10", card));
      out["text-destructive on bg-destructive/15 (over background)"] = ratio(td, composite("bg-destructive/15", bg));
      out["text-destructive on bg-destructive/15 (over card)"] = ratio(td, composite("bg-destructive/15", card));
      probe.remove();
      return { out };
    });

    for (const [pair, r] of Object.entries(ratios.out)) {
      expect(r, `${pair} (${mode}) = ${r.toFixed(2)}:1, needs ≥ 4.5 for normal text`).toBeGreaterThanOrEqual(4.5);
    }
  });
}

/**
 * P2-44 PR-4 — le symbole ⇄ d'ÉCART au socle (`bg-diff`, sur la carte de la grille) est un
 * élément GRAPHIQUE non-textuel : WCAG 1.4.11 exige 3:1 contre les surfaces adjacentes (la carte
 * et le fond), pas 4.5:1. Comme le token n'est peint que sur l'écran de génération d'une fermeture
 * (que ce spec public ne visite pas), on mesure ses paires DIRECTEMENT, dans les deux thèmes :
 * la pastille contre `bg-card`/`bg-background`, et son foreground SUR la pastille. Un token qui
 * dériverait sous 3:1 rougirait ici — on ajuste alors sa valeur oklch (jamais le seuil).
 */
for (const mode of MODES) {
  test(`contrast — diff marker (non-text, WCAG 1.4.11) tokens (${mode})`, async ({ page }) => {
    await forceTheme(page, mode);
    await page.goto("/login");

    const ratios = await page.evaluate(() => {
      const cv = document.createElement("canvas");
      cv.width = cv.height = 1;
      const ctx = cv.getContext("2d")!;
      const probe = document.createElement("div");
      document.body.appendChild(probe);
      const toRgb = (color: string): [number, number, number] => {
        ctx.clearRect(0, 0, 1, 1);
        ctx.fillStyle = color;
        ctx.fillRect(0, 0, 1, 1);
        const d = ctx.getImageData(0, 0, 1, 1).data;
        return [d[0], d[1], d[2]];
      };
      const of = (cls: string, prop: "color" | "backgroundColor"): [number, number, number] => {
        probe.className = cls;
        return toRgb(getComputedStyle(probe)[prop]);
      };
      const lum = ([r, g, b]: [number, number, number]): number => {
        const c = [r, g, b].map((v) => {
          const x = v / 255;
          return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
      };
      const ratio = (a: [number, number, number], b: [number, number, number]): number => {
        const [l1, l2] = [lum(a), lum(b)].sort((x, y) => y - x);
        return (l1 + 0.05) / (l2 + 0.05);
      };
      const bg = of("bg-background", "backgroundColor");
      const card = of("bg-card", "backgroundColor");
      const diff = of("bg-diff", "backgroundColor");
      const diffFg = of("text-diff-foreground", "color");
      // P4-173 — l'ICÔNE `text-warning` de la pastille « à régénérer », sur `bg-warning/10`
      // (composité sur bg) : élément graphique, seuil 1.4.11 (≥ 3:1), pas 4,5:1 (le TEXTE de la
      // pastille est `text-foreground`, mesuré dans le test AA plus haut).
      const composite = (over: string, under: [number, number, number]): [number, number, number] => {
        ctx.clearRect(0, 0, 1, 1);
        ctx.fillStyle = `rgb(${under[0]}, ${under[1]}, ${under[2]})`;
        ctx.fillRect(0, 0, 1, 1);
        probe.className = over;
        ctx.fillStyle = getComputedStyle(probe).backgroundColor;
        ctx.fillRect(0, 0, 1, 1);
        const d = ctx.getImageData(0, 0, 1, 1).data;
        return [d[0], d[1], d[2]];
      };
      return {
        "bg-diff on card": ratio(diff, card),
        "bg-diff on background": ratio(diff, bg),
        "text-diff-foreground on bg-diff": ratio(diffFg, diff),
        "text-warning icon on bg-warning/10": ratio(of("text-warning", "color"), composite("bg-warning/10", bg)),
        // P4-177 — l'ICÔNE `text-accent` de la pastille `accent` (StatusPill, source MANUEL), sur
        // `bg-accent/10` (composité sur bg) : élément graphique, seuil 1.4.11 (≥ 3:1). Le TEXTE de
        // cette variante est `text-foreground`, mesuré au seuil AA dans le test plus haut.
        "text-accent icon on bg-accent/10": ratio(of("text-accent", "color"), composite("bg-accent/10", bg)),
        // P4-164 — l'ICÔNE d'alerte (`text-warning`) d'une option de listbox DÉSACTIVÉE, sur la
        // surbrillance active (`bg-muted`, opaque) : élément graphique, seuil 1.4.11 (≥ 3:1).
        "text-warning icon on bg-muted": ratio(of("text-warning", "color"), of("bg-muted", "backgroundColor")),
      };
    });

    for (const [pair, ratio] of Object.entries(ratios)) {
      expect(ratio, `${pair} (${mode}) = ${ratio.toFixed(2)}:1, needs ≥ 3 for a non-text graphic`).toBeGreaterThanOrEqual(3);
    }
  });
}

/**
 * Keyboard reachability + visible focus on the public forms (WCAG 2.1.1 / 2.4.7):
 * tabbing from the top reaches the email + password fields and the NAMED submit
 * control, and each focused control gains a FOCUS-INDUCED ring — an outline, or a
 * box-shadow that DIFFERS from the control's resting shadow. Comparing against the
 * resting style is deliberate: an input carries a permanent `shadow-sm`, so a
 * "boxShadow !== none" check would pass even if the real focus ring were removed.
 */
test("keyboard — login form is reachable with a focus-induced ring", async ({ page }) => {
  await page.goto("/login");
  await expect(page.getByRole("button", { name: /se connecter/i })).toBeVisible();

  // Snapshot each focusable's RESTING outline/shadow (nothing focused yet), keyed
  // by a data-idx we stamp on it, so the walk can prove the ring appeared on focus.
  const resting: { idx: number; outlineW: number; shadow: string }[] = await page.evaluate(() => {
    const els = Array.from(document.querySelectorAll<HTMLElement>("input, button, a[href], select, textarea, [tabindex]"));
    return els.map((el, i) => {
      el.dataset.kbIdx = String(i);
      const s = getComputedStyle(el);
      return { idx: i, outlineW: parseFloat(s.outlineWidth) || 0, shadow: s.boxShadow };
    });
  });

  const reached: { key: string; name: string }[] = [];
  for (let i = 0; i < 12; i++) {
    await page.keyboard.press("Tab");
    const info = await page.evaluate(() => {
      const el = document.activeElement as HTMLElement | null;
      if (!el || el === document.body) return null;
      const s = getComputedStyle(el);
      return {
        idx: el.dataset.kbIdx ?? null,
        tag: el.tagName.toLowerCase(),
        type: (el as HTMLInputElement).type ?? "",
        name: el.getAttribute("aria-label") ?? el.textContent?.trim() ?? "",
        outlineShown: s.outlineStyle !== "none" && (parseFloat(s.outlineWidth) || 0) > 0,
        shadow: s.boxShadow,
      };
    });
    if (!info) continue;
    const rest = info.idx === null ? undefined : resting.find((r) => String(r.idx) === info.idx);
    const shadowChanged = rest ? info.shadow !== rest.shadow : info.shadow !== "none";
    expect(info.outlineShown || shadowChanged, `focused ${info.tag} "${info.name}" gained no focus-induced ring (outline/shadow unchanged from resting)`).toBe(true);
    reached.push({ key: `${info.tag}:${info.type}`, name: info.name });
  }

  expect(reached.some((r) => r.key === "input:email" || r.key === "input:text")).toBe(true);
  expect(reached.some((r) => r.key === "input:password")).toBe(true);
  // The primary action specifically — not merely "some button" — must be reachable.
  expect(reached.some((r) => r.key.startsWith("button") && /se connecter/i.test(r.name)), "submit button 'Se connecter' was never reached by Tab").toBe(true);
});
