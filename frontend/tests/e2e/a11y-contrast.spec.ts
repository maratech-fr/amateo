import { expect, test } from "./fixtures";

import { expectNoContrastViolations, forceTheme, registerAndVerify, settleVeil, uniqueAra } from "./support";

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
      for (const token of ["text-warning", "text-success", "text-accent", "text-foreground"]) {
        const fg = of(token, "color");
        out[`${token} on background`] = ratio(fg, bg);
        out[`${token} on card`] = ratio(fg, card);
      }
      probe.remove();
      return out;
    });

    for (const [pair, ratio] of Object.entries(ratios)) {
      expect(ratio, `${pair} (${mode}) = ${ratio.toFixed(2)}:1, needs ≥ 4.5 for normal text`).toBeGreaterThanOrEqual(4.5);
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
      return {
        "bg-diff on card": ratio(diff, card),
        "bg-diff on background": ratio(diff, bg),
        "text-diff-foreground on bg-diff": ratio(diffFg, diff),
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
