import { readFileSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * A11Y-33 (petites lignes) — UNE promesse : **les boutons-icônes de fermeture, de navigation et de
 * repli des sites nommés sont des cibles de 36 px** — la norme maison du bouton-icône de ligne,
 * `<Button variant="ghost" size="icon-sm">` (`size-9`, survol `hover:enabled:bg-muted`, icône 16 px
 * par `[&_svg]:size-4`, `button-variants.ts`) — et plus jamais un `<button>` brut `p-1` autour d'une
 * icône `size-4` (24 px).
 *
 * jsdom n'a aucun moteur de mise en page : une taille ne se MESURE pas en Vitest. Ce garde STATIQUE
 * (patron `textOpacityGuard` / `addButtonLabelGuard`) lit les sources et retrouve, pour chaque site,
 * la balise ouvrante qui porte son nom accessible ; elle doit être un `<Button` `size="icon-sm"`
 * `variant="ghost"`. Second volet : aucune balise `<button` brute à classe `p-1` ne subsiste dans ces
 * fichiers. Jumeau rendu (angle nommé) : `shared/components/ui/modal-signaler.test.tsx` vérifie
 * `size-9` sur le DOM des deux pictos de l'en-tête de `Modal`.
 *
 * PORTÉE délibérée (décision fondateur) : ces sept sites seulement ; `help-button.tsx` et les
 * `size-8` nommés localement restent hors périmètre. Un site qui change de nom accessible = mise à
 * jour MÉCANIQUE de `SITES`, jamais une suppression.
 */
const SRC_ROOT = join(import.meta.dirname, "..");

/** Chaque site : le fichier (relatif à `src/`) et le motif de son nom accessible dans la balise. */
const SITES: { file: string; label: RegExp; what: string }[] = [
  { file: "shared/components/ui/modal.tsx", label: /aria-label="Signaler"/, what: "drapeau « Signaler » de l'en-tête de modale" },
  { file: "shared/components/ui/modal.tsx", label: /aria-label="Fermer"/, what: "croix « Fermer » de l'en-tête de modale" },
  { file: "features/cockpit/MonthCalendar.tsx", label: /aria-label="Mois précédent"/, what: "« Mois précédent » de l'accueil" },
  { file: "features/cockpit/MonthCalendar.tsx", label: /aria-label="Mois suivant"/, what: "« Mois suivant » de l'accueil" },
  { file: "features/planning/SlotDetail.tsx", label: /aria-label=\{PLANNING_TEXTS\.closeLabel\}/, what: "« Fermer » du détail de séance" },
  { file: "features/matches/PlacementPanel.tsx", label: /aria-label=\{MATCHES_TEXTS\.panelClose\}/, what: "« Fermer » du panneau de placement" },
  { file: "features/planning/DiagnosticsPanel.tsx", label: /aria-label=\{PLANNING_TEXTS\.collapseDiagnosticsLabel\}/, what: "repli des diagnostics" },
  { file: "features/planning/LocksPanel.tsx", label: /aria-label="Réduire les verrous manuels"/, what: "repli des verrous manuels" },
];

/** Balises ouvrantes `<button …>` / `<Button …>`, en suivant la profondeur d'accolades (JSX multi-ligne). */
function openingTags(source: string): string[] {
  const tags: string[] = [];
  const re = /<[bB]utton\b/g;
  let m: RegExpExecArray | null;
  while (null !== (m = re.exec(source))) {
    let depth = 0;
    let i = m.index;
    for (; i < source.length; i++) {
      const c = source[i];
      if ("{" === c) {
        depth++;
      } else if ("}" === c) {
        depth--;
      } else if (">" === c && 0 === depth) {
        break;
      }
    }
    tags.push(source.slice(m.index, i + 1));
  }
  return tags;
}

/** Vrai si la balise est le bouton-icône de 36 px de la norme maison. */
function isIconSmGhostButton(tag: string): boolean {
  return tag.startsWith("<Button") && /size="icon-sm"/.test(tag) && /variant="ghost"/.test(tag);
}

/** Vrai si la balise est un `<button>` BRUT dont la classe porte le jeton `p-1` (cible de 24 px). */
function isRawPaddedButton(tag: string): boolean {
  if (!tag.startsWith("<button")) return false;
  const cls = /className="([^"]*)"/.exec(tag)?.[1] ?? "";
  return cls.split(/\s+/).includes("p-1");
}

function read(rel: string): string {
  return readFileSync(join(SRC_ROOT, rel), "utf8");
}

describe("A11Y-33 — boutons-icônes des sites nommés : 36 px (Button size=\"icon-sm\")", () => {
  it.each(SITES)("$what ($file) est un <Button variant=\"ghost\" size=\"icon-sm\">", ({ file, label }) => {
    const tags = openingTags(read(file)).filter((tag) => label.test(tag));
    expect(tags, `${file} : aucune balise ne porte ${label} — le site a-t-il changé de nom accessible ?`).toHaveLength(1);
    expect(isIconSmGhostButton(tags[0] as string), `${file} : ${tags[0]}`).toBe(true);
  });

  it("aucun <button> brut à `p-1` ne subsiste dans les fichiers des sites", () => {
    const offenders: string[] = [];
    for (const file of new Set(SITES.map((s) => s.file))) {
      for (const tag of openingTags(read(file))) {
        if (isRawPaddedButton(tag)) offenders.push(`${file} : ${tag.replace(/\s+/g, " ")}`);
      }
    }
    expect(offenders, "Bouton-icône de 24 px : passer à `<Button variant=\"ghost\" size=\"icon-sm\">` (A11Y-33).").toEqual([]);
  });

  it("le garde mord : un <button> p-1 est repéré, le <Button> icon-sm ghost passe (multi-ligne et `=>` compris)", () => {
    const raw = '<button type="button" aria-label="Fermer" className="rounded p-1 text-muted-foreground" onClick={() => close()}>';
    const conform = '<Button\n  type="button"\n  variant="ghost"\n  size="icon-sm"\n  onClick={() => close()}\n  aria-label="Fermer"\n>';
    expect(isRawPaddedButton(openingTags(raw)[0] as string)).toBe(true);
    expect(isIconSmGhostButton(openingTags(raw)[0] as string)).toBe(false);
    expect(isRawPaddedButton(openingTags(conform)[0] as string)).toBe(false);
    expect(isIconSmGhostButton(openingTags(conform)[0] as string)).toBe(true);
    // `p-1.5` ou `-m-1 p-1` d'un conteneur ne sont pas un bouton : seul le jeton exact `p-1` d'un <button> compte.
    expect(isRawPaddedButton('<button className="rounded p-1.5">')).toBe(false);
    // Un <Button> icône sans `variant="ghost"` (fond accent plein) n'est pas la norme du site.
    expect(isIconSmGhostButton('<Button size="icon-sm" aria-label="Fermer">')).toBe(false);
  });
});
