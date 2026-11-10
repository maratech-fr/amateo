import { render, screen, within } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { RichColor, RichDoc, RichMark } from "@/shared/lib/richDoc";

import { RichText } from "./rich-text";

/**
 * Promesse (Actualités PR B — A8, contenu stocké rendu chez TOUS les clubs) : **le corps riche d'une
 * note se rend en ÉLÉMENTS React — titre, listes à puces/numérotées imbriquées, gras, italique,
 * 5 couleurs par JETON de la peau, saut de ligne — et jamais une chaîne n'y devient du HTML** : une
 * balise saisie s'affiche littéralement, un nœud ou une marque inconnus sont ignorés sans lever, une
 * couleur hors palette (clé de prototype comprise) ne produit aucune classe.
 *
 * Jumeau serveur (angle nommé, domaine sensible) : `backend/tests/Unit/ReleaseNoteRichDocTest.php`
 * refuse ces formes au stockage ; ce test garde le RENDU si une forme interdite passait quand même
 * (note ancienne, régression serveur). Les jetons sont mesurés AA par `tests/e2e/a11y-contrast.spec.ts`
 * (`text-info`) et `tests/e2e/release-notes.spec.ts` (note aux 5 couleurs, deux thèmes).
 */

const text = (value: string, ...marks: RichMark[]) => (marks.length ? { type: "text" as const, text: value, marks } : { type: "text" as const, text: value });
const color = (name: RichColor): RichMark => ({ type: "noteColor", attrs: { name } });
const paragraph = (...content: ReturnType<typeof text>[]) => ({ type: "paragraph" as const, content });
const doc = (...content: RichDoc["content"]): RichDoc => ({ type: "doc", content });

const APP_CLASS: Record<RichColor, string> = {
  rouge: "text-destructive",
  orange: "text-warning",
  vert: "text-success",
  bleu: "text-info",
  gris: "text-muted-foreground",
};

const CONSOLE_CLASS: Record<RichColor, string> = {
  rouge: "text-console-danger",
  orange: "text-console-warning",
  vert: "text-console-success",
  bleu: "text-console-info",
  gris: "text-console-text-dim",
};

const COLORS = Object.keys(APP_CLASS) as RichColor[];

describe("RichText — structure", () => {
  it("rend le titre en <h3> par défaut et en <h4> avec headingLevel={4}", () => {
    const note = doc({ type: "heading", attrs: { level: 3 }, content: [text("Nouveautés de la rentrée")] });

    const { unmount } = render(<RichText doc={note} />);
    expect(screen.getByRole("heading", { level: 3, name: "Nouveautés de la rentrée" })).toBeInTheDocument();
    unmount();

    render(<RichText doc={note} headingLevel={4} />);
    expect(screen.getByRole("heading", { level: 4, name: "Nouveautés de la rentrée" })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { level: 3 })).not.toBeInTheDocument();
  });

  it("rend des listes à puces RÉELLES (list-disc) imbriquées, et une liste numérotée qui garde son départ", () => {
    const note = doc(
      {
        type: "bulletList",
        content: [
          {
            type: "listItem",
            content: [
              paragraph(text("Export PDF des matchs")),
              { type: "bulletList", content: [{ type: "listItem", content: [paragraph(text("Sous-puce"))] }] },
            ],
          },
        ],
      },
      { type: "orderedList", attrs: { start: 3 }, content: [{ type: "listItem", content: [paragraph(text("Troisième"))] }] },
    );
    const { container } = render(<RichText doc={note} />);

    const lists = screen.getAllByRole("list");
    const bullet = container.querySelector("ul") as HTMLElement;
    expect(bullet).not.toBeNull();
    expect(bullet.className).toContain("list-disc");
    const outerItem = within(bullet).getAllByRole("listitem")[0] as HTMLElement;
    expect(within(outerItem).getByText("Export PDF des matchs")).toBeInTheDocument();
    expect(within(outerItem).getByRole("list")).toBeInTheDocument();
    expect(within(within(outerItem).getByRole("list")).getByText("Sous-puce")).toBeInTheDocument();

    const ordered = container.querySelector("ol") as HTMLElement;
    expect(ordered).not.toBeNull();
    expect(ordered.className).toContain("list-decimal");
    expect(ordered.getAttribute("start")).toBe("3");
    expect(lists.length).toBe(3);
  });

  it("rend gras en <strong>, italique en <em>, et un saut de ligne en <br>", () => {
    const note = doc({
      type: "paragraph",
      content: [text("gras", { type: "bold" }), text(" et "), text("italique", { type: "italic" }), { type: "hardBreak" }, text("ligne 2")],
    });
    const { container } = render(<RichText doc={note} />);

    expect(container.querySelector("strong")?.textContent).toBe("gras");
    expect(container.querySelector("em")?.textContent).toBe("italique");
    const paragraphEl = container.querySelector("p") as HTMLElement;
    expect(paragraphEl.querySelector("br")).not.toBeNull();
    expect(paragraphEl.textContent).toBe("gras et italiqueligne 2");
  });
});

describe("RichText — couleurs par jeton de la peau", () => {
  it.each(COLORS)("couleur %s : jeton app par défaut, jeton console avec variant=\"console\"", (name) => {
    const note = doc(paragraph(text(`mot ${name}`, color(name))));

    const { unmount } = render(<RichText doc={note} />);
    const appSpan = screen.getByText(`mot ${name}`);
    expect(appSpan.className.split(/\s+/)).toContain(APP_CLASS[name]);
    unmount();

    render(<RichText doc={note} variant="console" />);
    const consoleSpan = screen.getByText(`mot ${name}`);
    expect(consoleSpan.className.split(/\s+/)).toContain(CONSOLE_CLASS[name]);
    // La peau console ne peint JAMAIS un jeton adaptatif de l'app (illisible sur fond sombre).
    expect(consoleSpan.className.split(/\s+/)).not.toContain(APP_CLASS[name]);
  });

  it("bleu gras = <strong> ET jeton text-info (marques cumulées)", () => {
    const { container } = render(<RichText doc={doc(paragraph(text("PDF", { type: "bold" }, color("bleu"))))} />);

    const strong = container.querySelector("strong") as HTMLElement;
    expect(strong).not.toBeNull();
    expect(strong.textContent).toBe("PDF");
    const colored = screen.getByText("PDF").closest(".text-info");
    expect(colored).not.toBeNull();
  });
});

describe("RichText — aucune chaîne ne devient du HTML (A8)", () => {
  it("une balise saisie s'affiche LITTÉRALEMENT : aucun <img>, aucun <script>", () => {
    const payload = "<img src=x onerror=alert(1)>";
    const { container } = render(<RichText doc={doc(paragraph(text(payload, { type: "bold" }, color("bleu"))), paragraph(text("<script>alert(1)</script>")))} />);

    expect(screen.getByText(payload)).toBeInTheDocument();
    expect(screen.getByText("<script>alert(1)</script>")).toBeInTheDocument();
    expect(container.querySelector("img")).toBeNull();
    expect(container.querySelector("script")).toBeNull();
  });

  it("un nœud inconnu (image) est ignoré sans lever, ses voisins restent rendus", () => {
    const forged = { type: "doc", content: [{ type: "image", attrs: { src: "x", onerror: "alert(1)" } }, paragraph(text("après l'image"))] } as unknown as RichDoc;
    const { container } = render(<RichText doc={forged} />);

    expect(screen.getByText("après l'image")).toBeInTheDocument();
    expect(container.querySelector("img")).toBeNull();
  });

  it("une marque inconnue (lien) est ignorée : le texte reste, aucun <a>", () => {
    const forged = doc(paragraph({ type: "text", text: "cliquez", marks: [{ type: "link", attrs: { href: "javascript:alert(1)" } }] } as unknown as ReturnType<typeof text>));
    const { container } = render(<RichText doc={forged} />);

    expect(screen.getByText("cliquez")).toBeInTheDocument();
    expect(container.querySelector("a")).toBeNull();
    expect(container.innerHTML).not.toContain("javascript:");
  });

  it.each(["__proto__", "constructor", "toString", "violet", "#f00"])("couleur hors palette « %s » : texte rendu, aucune classe parasite, aucune exception", (name) => {
    const forged = doc(paragraph(text("texte", { type: "noteColor", attrs: { name } } as unknown as RichMark)));
    const { container } = render(<RichText doc={forged} />);

    expect(screen.getByText("texte")).toBeInTheDocument();
    expect(container.innerHTML).not.toMatch(/function|native code|\[object|undefined|#f00/);
  });
});
