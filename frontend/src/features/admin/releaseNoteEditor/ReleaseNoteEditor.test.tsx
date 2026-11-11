import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { Editor, type JSONContent } from "@tiptap/core";
import { afterEach, describe, expect, it, vi } from "vitest";

import type { RichDoc } from "@/shared/lib/richDoc";

import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";
import { releaseNoteExtensions } from "./editorExtensions";
import { EMOJI_CATALOG } from "./emojiCatalog";
import { ReleaseNoteEditor } from "./ReleaseNoteEditor";

/**
 * Promesses (Actualités PR B, B2/B4/B6) :
 *  1. **le schéma de l'éditeur interdit ce que le serveur refuserait** : Tab au 3ᵉ niveau de liste ne
 *     crée pas un 4ᵉ niveau, un titre ne se pose pas dans une puce (éditeur headless, mêmes extensions) ;
 *  2. **la barre « Mise en forme » est un vrai outil clavier/lecteur d'écran** : `role="toolbar"` nommé,
 *     boutons à bascule `aria-pressed`, groupe « Couleur du texte » de 6 boutons, et chaque geste rend
 *     par `onChange` un document de la liste blanche ;
 *  3. **un emoji choisi s'insère À LA POSITION DU CURSEUR** (« a|b » → « a🏀b »).
 *
 * Le DOM ProseMirror est piloté en jsdom par la sélection DOM + `selectionchange` ; si un geste de
 * barre ne s'y pilote pas, le repli sur `editor.commands` est prévu par le plan et doit être DÉCLARÉ.
 * Le panneau d'emoji seul : `EmojiPicker.test.tsx` ; la sortie complète ⊆ liste blanche :
 * `editorOutput.test.ts`.
 */

const NODE_TYPES = new Set(["doc", "paragraph", "heading", "bulletList", "orderedList", "listItem", "text", "hardBreak"]);
const MARK_TYPES = new Set(["bold", "italic", "noteColor"]);

const AB: RichDoc = { type: "doc", content: [{ type: "paragraph", content: [{ type: "text", text: "ab" }] }] };

function walk(node: JSONContent, visit: (node: JSONContent) => void): void {
  visit(node);
  for (const child of node.content ?? []) walk(child, visit);
}

function expectWithinWhitelist(doc: JSONContent): void {
  walk(doc, (n) => {
    expect(NODE_TYPES.has(n.type ?? ""), `nœud hors liste blanche : ${n.type}`).toBe(true);
    for (const mark of n.marks ?? []) expect(MARK_TYPES.has(mark.type), `marque hors liste blanche : ${mark.type}`).toBe(true);
  });
}

function lastDoc(onChange: ReturnType<typeof vi.fn>): RichDoc {
  const calls = onChange.mock.calls;
  expect(calls.length, "onChange a été appelé").toBeGreaterThan(0);
  return calls[calls.length - 1]?.[0] as RichDoc;
}

describe("schéma de l'éditeur (headless) — ce que le serveur refuserait ne se fabrique pas", () => {
  let editor: Editor | null = null;
  afterEach(() => {
    editor?.destroy();
    editor = null;
  });

  function threeLevels(): Editor {
    editor = new Editor({
      element: document.createElement("div"),
      extensions: releaseNoteExtensions(),
      content: {
        type: "doc",
        content: [
          {
            type: "bulletList",
            content: [
              {
                type: "listItem",
                content: [
                  { type: "paragraph", content: [{ type: "text", text: "1" }] },
                  {
                    type: "bulletList",
                    content: [
                      {
                        type: "listItem",
                        content: [
                          { type: "paragraph", content: [{ type: "text", text: "2" }] },
                          {
                            type: "bulletList",
                            content: [
                              { type: "listItem", content: [{ type: "paragraph", content: [{ type: "text", text: "3a" }] }] },
                              { type: "listItem", content: [{ type: "paragraph", content: [{ type: "text", text: "3b" }] }] },
                            ],
                          },
                        ],
                      },
                    ],
                  },
                ],
              },
            ],
          },
        ],
      },
    });
    return editor;
  }

  /** Position (dans le texte) de la première occurrence de `needle`. */
  function posOf(e: Editor, needle: string): number {
    let found = -1;
    e.state.doc.descendants((node, pos) => {
      if (found < 0 && node.isText && node.text?.includes(needle)) found = pos + (node.text?.indexOf(needle) ?? 0);
    });
    expect(found, `texte « ${needle} » introuvable`).toBeGreaterThanOrEqual(0);
    return found;
  }

  it("Tab sur une puce du 3ᵉ niveau : refusé, document inchangé", () => {
    const e = threeLevels();
    e.commands.setTextSelection(posOf(e, "3b") + 1);
    const before = e.getJSON();

    e.commands.keyboardShortcut("Tab");

    expect(e.getJSON()).toEqual(before);
  });

  it("Tab sur une puce du 2ᵉ niveau reste permis (le refus ne vise que le 3ᵉ)", () => {
    editor = new Editor({
      element: document.createElement("div"),
      extensions: releaseNoteExtensions(),
      content: {
        type: "doc",
        content: [
          {
            type: "bulletList",
            content: [
              { type: "listItem", content: [{ type: "paragraph", content: [{ type: "text", text: "1a" }] }] },
              { type: "listItem", content: [{ type: "paragraph", content: [{ type: "text", text: "1b" }] }] },
            ],
          },
        ],
      },
    });
    const e = editor;
    e.commands.setTextSelection(posOf(e, "1b") + 1);
    const before = JSON.stringify(e.getJSON());

    e.commands.keyboardShortcut("Tab");

    expect(JSON.stringify(e.getJSON())).not.toBe(before);
  });

  it("« Titre » dans une puce : refusé, document inchangé (un titre n'entre jamais dans un listItem)", () => {
    const e = threeLevels();
    e.commands.setTextSelection(posOf(e, "2") + 1);
    const before = e.getJSON();

    e.commands.toggleHeading({ level: 3 });

    expect(e.getJSON()).toEqual(before);
  });
});

/**
 * Pose la sélection DOM dans le contenteditable puis la signale à ProseMirror (qui lit la sélection
 * du document à `selectionchange` tant que l'éditeur a le focus).
 */
function selectInEditor(root: HTMLElement, from: number, to = from): void {
  // Le premier NŒUD TEXTE du paragraphe : une marque l'enveloppe (<span>, <strong>), et sur
  // l'élément les offsets compteraient des enfants, pas des caractères.
  const paragraphEl = root.querySelector("p");
  const textNode = paragraphEl ? document.createTreeWalker(paragraphEl, NodeFilter.SHOW_TEXT).nextNode() : null;
  expect(textNode, "un nœud texte dans le premier paragraphe").toBeTruthy();
  root.focus();
  const selection = document.getSelection() as Selection;
  const range = document.createRange();
  range.setStart(textNode as Node, from);
  range.setEnd(textNode as Node, to);
  selection.removeAllRanges();
  selection.addRange(range);
  act(() => {
    fireEvent(document, new Event("selectionchange"));
  });
}

function editable(container: HTMLElement): HTMLElement {
  const root = container.querySelector('[contenteditable="true"]') as HTMLElement | null;
  expect(root, "zone d'édition contenteditable").not.toBeNull();
  return root as HTMLElement;
}

describe("ReleaseNoteEditor — barre « Mise en forme »", () => {
  it("expose une barre nommée, des boutons à bascule aria-pressed et le groupe de 6 couleurs", () => {
    const { container } = render(<ReleaseNoteEditor id="release-note-body" value={null} onChange={vi.fn()} />);

    const toolbar = screen.getByRole("toolbar", { name: ADMIN_SECTIONS_TEXTS.releaseNotesToolbarLabel });
    for (const name of [
      ADMIN_SECTIONS_TEXTS.releaseNotesToolBold,
      ADMIN_SECTIONS_TEXTS.releaseNotesToolItalic,
      ADMIN_SECTIONS_TEXTS.releaseNotesToolHeading,
      ADMIN_SECTIONS_TEXTS.releaseNotesToolBulletList,
      ADMIN_SECTIONS_TEXTS.releaseNotesToolOrderedList,
    ]) {
      expect(within(toolbar).getByRole("button", { name })).toHaveAttribute("aria-pressed", "false");
    }

    const colors = within(toolbar).getByRole("group", { name: ADMIN_SECTIONS_TEXTS.releaseNotesColorLabel });
    const colorButtons = within(colors).getAllByRole("button");
    expect(colorButtons.map((button) => button.textContent?.trim() || button.getAttribute("aria-label"))).toEqual([
      ADMIN_SECTIONS_TEXTS.releaseNotesColorDefault,
      ADMIN_SECTIONS_TEXTS.releaseNotesColorRouge,
      ADMIN_SECTIONS_TEXTS.releaseNotesColorOrange,
      ADMIN_SECTIONS_TEXTS.releaseNotesColorVert,
      ADMIN_SECTIONS_TEXTS.releaseNotesColorBleu,
      ADMIN_SECTIONS_TEXTS.releaseNotesColorGris,
    ]);
    expect(colorButtons.every((button) => button.hasAttribute("aria-pressed"))).toBe(true);
    // Sans couleur posée, c'est « Couleur par défaut » qui est enfoncé.
    expect(within(colors).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.releaseNotesColorDefault })).toHaveAttribute("aria-pressed", "true");

    expect(within(toolbar).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.releaseNotesEmoji })).toHaveAttribute("aria-haspopup", "dialog");
    expect(within(toolbar).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.releaseNotesUndo })).toBeInTheDocument();
    expect(within(toolbar).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.releaseNotesRedo })).toBeInTheDocument();
    expect(editable(container).id).toBe("release-note-body");
  });

  it("Gras puis Bleu sur une sélection : onChange rend gras + bleu, la barre reflète l'état", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    const { container } = render(<ReleaseNoteEditor id="release-note-body" value={AB} onChange={onChange} />);
    const toolbar = screen.getByRole("toolbar", { name: ADMIN_SECTIONS_TEXTS.releaseNotesToolbarLabel });

    selectInEditor(editable(container), 0, 2);
    await user.click(within(toolbar).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.releaseNotesToolBold }));
    await user.click(within(toolbar).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.releaseNotesColorBleu }));

    await waitFor(() => {
      const doc = lastDoc(onChange);
      expectWithinWhitelist(doc as JSONContent);
      const marks = (doc.content[0] as JSONContent).content?.[0]?.marks ?? [];
      expect(marks.map((m) => m.type).sort()).toEqual(["bold", "noteColor"]);
      expect(marks.find((m) => m.type === "noteColor")?.attrs).toEqual({ name: "bleu" });
    });
    expect(within(toolbar).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.releaseNotesToolBold })).toHaveAttribute("aria-pressed", "true");
    expect(within(toolbar).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.releaseNotesColorBleu })).toHaveAttribute("aria-pressed", "true");
  });

  it("« Couleur par défaut » retire la couleur ; « Annuler » défait le dernier geste", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    const red: RichDoc = { type: "doc", content: [{ type: "paragraph", content: [{ type: "text", text: "ab", marks: [{ type: "noteColor", attrs: { name: "rouge" } }] }] }] };
    const { container } = render(<ReleaseNoteEditor id="release-note-body" value={red} onChange={onChange} />);
    const toolbar = screen.getByRole("toolbar", { name: ADMIN_SECTIONS_TEXTS.releaseNotesToolbarLabel });

    selectInEditor(editable(container), 0, 2);
    await user.click(within(toolbar).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.releaseNotesColorDefault }));
    await waitFor(() => expect((lastDoc(onChange).content[0] as JSONContent).content?.[0]?.marks ?? []).toEqual([]));

    await user.click(within(toolbar).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.releaseNotesUndo }));
    await waitFor(() => expect((lastDoc(onChange).content[0] as JSONContent).content?.[0]?.marks).toEqual([{ type: "noteColor", attrs: { name: "rouge" } }]));
  });

  it("« Liste à puces » transforme le paragraphe en puce ; un second clic rend un paragraphe", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    const { container } = render(<ReleaseNoteEditor id="release-note-body" value={AB} onChange={onChange} />);
    const toolbar = screen.getByRole("toolbar", { name: ADMIN_SECTIONS_TEXTS.releaseNotesToolbarLabel });
    const bullet = within(toolbar).getByRole("button", { name: ADMIN_SECTIONS_TEXTS.releaseNotesToolBulletList });

    selectInEditor(editable(container), 1);
    await user.click(bullet);
    await waitFor(() => expect(lastDoc(onChange).content[0]?.type).toBe("bulletList"));
    expect(bullet).toHaveAttribute("aria-pressed", "true");

    await user.click(bullet);
    await waitFor(() => expect(lastDoc(onChange).content[0]?.type).toBe("paragraph"));
  });
});

describe("ReleaseNoteEditor — emoji inséré au curseur", () => {
  it("curseur au milieu de « ab », 🏀 choisi dans le panneau → « a🏀b », focus rendu à l'éditeur", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    const basket = EMOJI_CATALOG.flatMap((theme) => theme.items).find((item) => item.char === "\u{1F3C0}");
    expect(basket, "le catalogue contient le ballon de basket").toBeDefined();
    const { container } = render(<ReleaseNoteEditor id="release-note-body" value={AB} onChange={onChange} />);
    const root = editable(container);

    selectInEditor(root, 1);
    await user.click(screen.getByRole("button", { name: ADMIN_SECTIONS_TEXTS.releaseNotesEmoji }));
    const dialog = screen.getByRole("dialog", { name: ADMIN_SECTIONS_TEXTS.releaseNotesEmojiDialog });
    await user.click(within(dialog).getByRole("button", { name: basket?.label }));

    await waitFor(() => expect(lastDoc(onChange)).toEqual({ type: "doc", content: [{ type: "paragraph", content: [{ type: "text", text: "a\u{1F3C0}b" }] }] }));
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(root.contains(document.activeElement) || root === document.activeElement).toBe(true);
  });
});
