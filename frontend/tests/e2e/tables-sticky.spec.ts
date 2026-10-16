import { expect, type Locator, test } from "./fixtures";

import { ensureValidated, loginSeededClub, settleVeil } from "./support";

/**
 * Lot 5 PR E (P4-284) — FILET D'ISO-RENDU des deux tableaux à en-tête FIGÉ.
 *
 * ⚠ CE N'EST PAS UN TEST ROUGE. Il est VERT sur le code ACTUEL (le `<table>` fait main, avant
 * migration) et DOIT le rester APRÈS la migration vers la primitive partagée `shared/components/ui/
 * table.tsx` étendue (`wrapperClassName`). C'est la CONDITION EXPRESSE du fondateur (2026-10-16) :
 * « seulement si tu arrives à obtenir le même rendu avant et après, donc l'en-tête qui reste figé
 * sur les 2 autres tableaux ». Si ce spec rougit APRÈS la migration, la migration a cassé le
 * figement : on corrige la migration, pas ce spec.
 *
 * ── POURQUOI E2E (et pas Vitest) ──────────────────────────────────────────────────────────────
 * `position: sticky`, `overflow` et `boundingBox` ne sont calculés que par un vrai moteur de mise en
 * page — jsdom rend tout à 0 (`.claude/rules/frontend.md`, charte `docs/testing/tests-par-parcours.md`
 * § Guide E2E). Seul un navigateur peut prouver qu'un en-tête reste collé pendant qu'on fait défiler
 * son conteneur. Le contrat de la PROP (`wrapperClassName`), lui, vit au plus bas coût en Vitest
 * (`src/shared/components/ui/table.test.tsx`) — ici on prouve l'EFFET visuel, pas le câblage.
 *
 * ── CE QUE CHAQUE MESURE GARDE (migration-risk) ────────────────────────────────────────────────
 *  1. l'en-tête de colonne porte `position: sticky` collé en haut (`top: 0px`) — perdu si la
 *     migration laisse tomber la classe `sticky` du `<th>` ;
 *  2. le conteneur reste défilant (`overflow-y: auto`) — perdu si la primitive impose son
 *     `overflow-x-auto` figé au lieu du défilement borné de l'écran → l'en-tête n'aurait plus
 *     d'ancêtre défilant et le `sticky` serait inerte ;
 *  3. (Par club, qui a assez d'équipes pour déborder) l'en-tête NE BOUGE PAS quand on fait défiler —
 *     avec un TÉMOIN POSITIF : le conteneur a réellement défilé (`scrollTop > 0`). Sans ce témoin,
 *     un tableau trop court « passerait » sans rien prouver (faux vert, cf. `modal-reachability`).
 *
 * La matrice de trajet d'un petit club (3 gymnases) ne déborde pas : on y prouve (1) et (2) par le
 * style calculé — le mécanisme `sticky` + conteneur défilant — ce qui suffit à garder la migration
 * (« si le décor le permet » : ici il ne permet pas le défilement, donc pas de mesure (3)).
 */

/** Lit le style calculé de figement d'un en-tête (navigateur réel — jsdom rendrait tout vide). */
async function stickiness(th: Locator): Promise<{ position: string; top: string; left: string }> {
  return th.evaluate((el) => {
    const s = getComputedStyle(el);
    return { position: s.position, top: s.top, left: s.left };
  });
}

test("vue « Par club » du planning : l'en-tête de colonne reste figé au défilement vertical", async ({ page }) => {
  test.setTimeout(300_000); // le 1er run du club seedé peut conduire une génération CP-SAT réelle
  await page.setViewportSize({ width: 1280, height: 720 }); // hauteur bornée → le corps du tableau déborde

  await loginSeededClub(page);
  await ensureValidated(page); // un plan EN VIGUEUR → /planning a des créneaux → la vue club a des lignes

  await page.goto("/planning");
  await settleVeil(page);
  // Bascule vers la 5ᵉ vue (matrice équipes × jours) — SegmentedControl « Vue du planning ».
  await page.getByRole("button", { name: "Par club" }).click();
  await settleVeil(page);

  const equipeHeader = page.getByRole("columnheader", { name: "Équipe" });
  await expect(equipeHeader).toBeVisible({ timeout: 30_000 });

  // (1) l'en-tête « Équipe » est collé en haut ET à gauche (coin figé).
  const sticky = await stickiness(equipeHeader);
  expect(sticky.position, "l'en-tête « Équipe » doit être position:sticky").toBe("sticky");
  expect(sticky.top).toBe("0px");
  expect(sticky.left).toBe("0px");

  // (2) + (3) : conteneur défilant trouvé, il défile réellement (témoin positif), l'en-tête ne bouge pas.
  const probe = await equipeHeader.evaluate((th: HTMLElement) => {
    // Remonte jusqu'au premier ancêtre VERTICALEMENT défilant (indépendant des classes : robuste à
    // la migration — on cherche l'EFFET, pas `overflow-auto` dans le className).
    let el: HTMLElement | null = th.parentElement;
    let scroller: HTMLElement | null = null;
    while (null !== el) {
      const s = getComputedStyle(el);
      const scrollableY = "auto" === s.overflowY || "scroll" === s.overflowY;
      if (scrollableY && el.scrollHeight > el.clientHeight + 2) {
        scroller = el;
        break;
      }
      el = el.parentElement;
    }
    if (null === scroller) return { hasScroller: false, overflowY: "", scrolled: 0, topBefore: 0, topAfter: 0 };
    const overflowY = getComputedStyle(scroller).overflowY;
    const topBefore = th.getBoundingClientRect().top;
    scroller.scrollTop = 400;
    const scrolled = scroller.scrollTop;
    const topAfter = th.getBoundingClientRect().top;
    return { hasScroller: true, overflowY, scrolled, topBefore, topAfter };
  });

  expect(
    probe.hasScroller,
    "le corps de la vue « Par club » doit DÉFILER (assez d'équipes pour déborder la hauteur bornée) — " +
      "sinon le figement de l'en-tête n'est pas mis à l'épreuve (témoin positif, jamais un faux vert).",
  ).toBe(true);
  expect(["auto", "scroll"]).toContain(probe.overflowY); // (2) conteneur défilant conservé
  expect(probe.scrolled, "le conteneur a réellement défilé (témoin positif du scroll)").toBeGreaterThan(0);
  expect(
    Math.abs(probe.topAfter - probe.topBefore),
    "l'en-tête de colonne « Équipe » reste figé en haut pendant le défilement du corps",
  ).toBeLessThanOrEqual(2);
});

test("matrice des trajets (wizard · gymnases) : en-têtes figés (coin + 1ʳᵉ colonne)", async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1280, height: 720 });

  await loginSeededClub(page);

  // La matrice n'affiche son tableau que si AU MOINS un temps de trajet existe (sinon écran de
  // consentement « Calculer les trajets » → worker IGN, flaky). Le scénario CRÉE la donnée qu'il
  // vérifie (charte § E2E) : un seul couple suffit à sortir du consentement. Nettoyé en `finally`.
  const venuesRes = await page.request.get("/api/venues?itemsPerPage=100");
  const venues = ((await venuesRes.json()).member ?? []) as { id: string; name: string }[];
  expect(
    venues.length,
    "le club seedé doit avoir ≥ 2 gymnases pour que la matrice ait des lignes/colonnes (sinon rien à figer)",
  ).toBeGreaterThanOrEqual(2);

  let travelId: string | null = null;
  try {
    const created = await page.request.post("/api/venue_travel_times", {
      data: { venueAId: venues[0].id, venueBId: venues[1].id, drivingMinutes: 12 },
    });
    expect(created.ok(), `la création d'un temps de trajet doit réussir (HTTP ${created.status()})`).toBe(true);
    travelId = ((await created.json()) as { id: string }).id;

    await page.goto("/wizard?step=venues");
    await settleVeil(page);
    await page.getByRole("button", { name: "Trajets entre gymnases" }).click();

    const dialog = page.getByRole("dialog", { name: "Trajets entre gymnases" });
    await expect(dialog).toBeVisible({ timeout: 15_000 });

    // Coin figé : l'en-tête « Depuis \ vers » est collé en haut ET à gauche.
    const corner = dialog.getByRole("columnheader", { name: /Depuis/ });
    await expect(corner).toBeVisible({ timeout: 15_000 });
    const cornerSticky = await stickiness(corner);
    expect(cornerSticky.position, "le coin « Depuis \\ vers » doit être position:sticky").toBe("sticky");
    expect(cornerSticky.top).toBe("0px");
    expect(cornerSticky.left).toBe("0px");

    // 1ʳᵉ colonne figée : l'en-tête de LIGNE (nom du gymnase) reste collé à gauche au défilement horizontal.
    const rowHeader = dialog.locator('th[scope="row"]').first();
    await expect(rowHeader).toBeVisible();
    const rowSticky = await stickiness(rowHeader);
    expect(rowSticky.position, "l'en-tête de ligne (gymnase) doit être position:sticky").toBe("sticky");
    expect(rowSticky.left).toBe("0px");

    // Le conteneur de la matrice reste défilant (`overflow` auto) — ancêtre défilant du `sticky`.
    const overflowY = await corner.evaluate((el) => {
      const table = el.closest("table");
      const wrapper = table?.parentElement ?? null;
      return null === wrapper ? "" : getComputedStyle(wrapper).overflowY;
    });
    expect(["auto", "scroll"]).toContain(overflowY);
  } finally {
    if (null !== travelId) {
      await page.request.delete(`/api/venue_travel_times/${travelId}`).catch(() => undefined);
    }
  }
});
