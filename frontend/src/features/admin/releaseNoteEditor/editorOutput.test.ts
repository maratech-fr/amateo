import { readFileSync } from "node:fs";
import { join } from "node:path";

import { Editor, type JSONContent } from "@tiptap/core";
import { afterEach, describe, expect, it } from "vitest";

import { releaseNoteExtensions } from "./editorExtensions";
import { EMOJI_CATALOG } from "./emojiCatalog";

/**
 * Promesse (Actualités PR B, M1 « un seul dessin ») : **ce que l'éditeur de la console PRODUIT reste
 * dans la liste blanche du serveur, et un collage garde ses titres et ses listes mais jamais un lien,
 * un style ou une couleur externe.** On pilote l'éditeur RÉEL (mêmes extensions que
 * `ReleaseNoteEditor`, `@tiptap/core` en jsdom) par des gestes scriptés et on asserte `getJSON()` :
 *
 *  1. ÉGAL au fichier `fixtures/<nom>.json` commis — ces fichiers sont rejoués côté serveur par
 *     `backend/tests/CrossStack/ReleaseNoteRichDocEditorFixturesTest.php` (jumeau, angle nommé) ;
 *  2. et, INDÉPENDAMMENT du fichier, la propriété qui fait la promesse (titres ramenés au niveau 3,
 *     aucune marque `link`/`noteColor` née d'un collage, profondeur, `start`…).
 *
 * L'ordre des marques n'est pas une promesse (il suit le rang du schéma) : il est normalisé avant
 * comparaison. Une fixture qui diffère de la sortie réelle s'affiche en entier dans l'échec ; la
 * régénérer se fait EN COPIANT cette sortie, jamais en élargissant le validateur sans relecture.
 * Limites déclarées (plan B2) : `insertContent(html)` passe par le même analyseur `parseHTML` que le
 * collage mais pas par `transformPastedHTML` ; les règles de saisie (`- `, `1. `) ne se déclenchent
 * pas par `insertContent` et ne sont pas couvertes ici.
 */

const FIXTURES = join(import.meta.dirname, "fixtures");

const NODE_TYPES = new Set(["doc", "paragraph", "heading", "bulletList", "orderedList", "listItem", "text", "hardBreak"]);
const MARK_TYPES = new Set(["bold", "italic", "noteColor"]);

let editor: Editor | null = null;

function newEditor(): Editor {
  editor = new Editor({ element: document.createElement("div"), extensions: releaseNoteExtensions() });
  return editor;
}

afterEach(() => {
  editor?.destroy();
  editor = null;
});

function readFixture(name: string): JSONContent {
  return JSON.parse(readFileSync(join(FIXTURES, `${name}.json`), "utf8")) as JSONContent;
}

/** Copie avec les marques triées par type (l'ordre suit le rang du schéma, il n'est pas promis). */
function normalized(node: JSONContent): JSONContent {
  const out: JSONContent = { ...node };
  if (node.marks) out.marks = [...node.marks].sort((a, b) => a.type.localeCompare(b.type));
  if (node.content) out.content = node.content.map(normalized);
  return out;
}

function expectEqualsFixture(actual: JSONContent, name: string): void {
  expect(normalized(actual), `sortie réelle de l'éditeur pour « ${name} » :\n${JSON.stringify(actual, null, 2)}`).toEqual(normalized(readFixture(name)));
}

function walk(node: JSONContent, visit: (node: JSONContent, listDepth: number) => void, listDepth = 0): void {
  visit(node, listDepth);
  const nested = node.type === "bulletList" || node.type === "orderedList" ? listDepth + 1 : listDepth;
  for (const child of node.content ?? []) walk(child, visit, nested);
}

function maxListDepth(node: JSONContent): number {
  let max = 0;
  walk(node, (n, depth) => {
    if (n.type === "bulletList" || n.type === "orderedList") max = Math.max(max, depth + 1);
  });
  return max;
}

function nodesOfType(node: JSONContent, type: string): JSONContent[] {
  const found: JSONContent[] = [];
  walk(node, (n) => {
    if (n.type === type) found.push(n);
  });
  return found;
}

function markTypes(node: JSONContent): string[] {
  const found: string[] = [];
  walk(node, (n) => found.push(...(n.marks ?? []).map((mark) => mark.type)));
  return found;
}

/** Côté front de M1 : aucun type de nœud ni de marque hors de la liste blanche serveur. */
function expectWithinWhitelist(node: JSONContent): void {
  walk(node, (n) => {
    expect(NODE_TYPES.has(n.type ?? ""), `nœud hors liste blanche : ${n.type}`).toBe(true);
    for (const mark of n.marks ?? []) expect(MARK_TYPES.has(mark.type), `marque hors liste blanche : ${mark.type}`).toBe(true);
    if (n.type === "heading") expect(n.attrs).toEqual({ level: 3 });
    if (n.type === "orderedList") expectOrderedListAttrsWithinWhitelist(n);
  });
}

/**
 * Attributs d'une liste numérotée acceptés par le serveur (`ReleaseNoteRichDoc`, liste FERMÉE) :
 * `type` absent ou null — jamais « a », « I »… — et `start` entier dans [1, 9999].
 */
function expectOrderedListAttrsWithinWhitelist(list: JSONContent): void {
  const attrs = (list.attrs ?? {}) as { start?: unknown; type?: unknown };
  expect(Object.keys(attrs).filter((key) => key !== "start" && key !== "type"), "attributs de liste numérotée hors liste blanche").toEqual([]);
  expect(attrs.type ?? null, "type de numérotation : toujours null (a/i/I refusés par le serveur)").toBeNull();
  if (attrs.start !== undefined) {
    expect(Number.isInteger(attrs.start), `start entier (reçu ${String(attrs.start)})`).toBe(true);
    expect(attrs.start as number, "start ≥ 1").toBeGreaterThanOrEqual(1);
    expect(attrs.start as number, "start ≤ 9999").toBeLessThanOrEqual(9999);
  }
}

/**
 * Frappe réelle : chaque caractère passe par `handleTextInput` (là où vivent les règles de saisie
 * comme « 1. » → liste numérotée), sinon il est inséré tel quel.
 */
function typeText(e: Editor, value: string): void {
  for (const char of value) {
    const { from, to } = e.state.selection;
    const handled = e.view.someProp("handleTextInput", (handler) => handler(e.view, from, to, char, () => e.state.tr.insertText(char, from, to)));
    if (!handled) e.view.dispatch(e.state.tr.insertText(char, from, to));
  }
}

describe("éditeur de notes — sortie réelle ⊆ liste blanche serveur (fixtures croisées)", () => {
  it("frappe : deux paragraphes", () => {
    const e = newEditor();
    e.commands.insertContent("Bonjour le club");
    e.commands.keyboardShortcut("Enter");
    e.commands.insertContent("Deuxième ligne");

    const out = e.getJSON();
    expectWithinWhitelist(out);
    expect(nodesOfType(out, "paragraph")).toHaveLength(2);
    expectEqualsFixture(out, "frappe");
  });

  it("marques : gras, italique et chacune des 5 couleurs (cumulables)", () => {
    const e = newEditor();
    e.commands.insertContent("gras italique rouge orange vert bleu gris");
    const apply = (from: number, to: number, run: (chain: ReturnType<Editor["chain"]>) => ReturnType<Editor["chain"]>) => run(e.chain().setTextSelection({ from, to })).run();
    apply(1, 5, (c) => c.setMark("bold"));
    apply(6, 14, (c) => c.setMark("italic"));
    apply(15, 20, (c) => c.setMark("noteColor", { name: "rouge" }));
    apply(21, 27, (c) => c.setMark("noteColor", { name: "orange" }));
    apply(28, 32, (c) => c.setMark("noteColor", { name: "vert" }));
    apply(33, 37, (c) => c.setMark("bold").setMark("noteColor", { name: "bleu" }));
    apply(38, 42, (c) => c.setMark("italic").setMark("noteColor", { name: "gris" }));

    const out = e.getJSON();
    expectWithinWhitelist(out);
    const colors = nodesOfType(out, "text").flatMap((t) => (t.marks ?? []).filter((m) => m.type === "noteColor").map((m) => m.attrs?.name as string));
    expect(colors.sort()).toEqual(["bleu", "gris", "orange", "rouge", "vert"]);
    expectEqualsFixture(out, "marques-5-couleurs");
  });

  it("Maj+Entrée dans du gras : un saut de ligne, la suite reste en gras", () => {
    const e = newEditor();
    e.commands.insertContent("avant");
    e.chain().setTextSelection({ from: 1, to: 6 }).setMark("bold").setTextSelection(6).run();
    e.commands.keyboardShortcut("Shift-Enter");
    e.commands.insertContent("après");

    const out = e.getJSON();
    expectWithinWhitelist(out);
    const paragraphContent = out.content?.[0]?.content ?? [];
    expect(paragraphContent.map((n) => n.type)).toEqual(["text", "hardBreak", "text"]);
    expect(paragraphContent[2]?.marks?.map((m) => m.type)).toEqual(["bold"]);
    expectEqualsFixture(out, "saut-dans-gras");
  });

  it("titre, puces sur 2 niveaux, liste numérotée, emoji dans une puce", () => {
    const e = newEditor();
    e.chain().insertContent("Nouveautés").toggleHeading({ level: 3 }).run();
    e.commands.keyboardShortcut("Enter");
    e.chain().insertContent("Export PDF").toggleBulletList().run();
    e.commands.keyboardShortcut("Enter");
    e.chain().insertContent("Détail ").sinkListItem("listItem").run();
    e.commands.insertContent("\u{1F3C0}");
    e.commands.keyboardShortcut("Enter");
    e.commands.liftListItem("listItem");
    e.commands.liftListItem("listItem");
    e.chain().insertContent("Première étape").toggleOrderedList().run();

    const out = e.getJSON();
    expectWithinWhitelist(out);
    expect(out.content?.[0]).toMatchObject({ type: "heading", attrs: { level: 3 } });
    expect(maxListDepth(out)).toBe(2);
    expect(nodesOfType(out, "orderedList")).toHaveLength(1);
    expect(nodesOfType(out, "listItem").some((item) => JSON.stringify(item).includes("\u{1F3C0}"))).toBe(true);
    expectEqualsFixture(out, "titre-listes-emoji");
  });

  it("collage web : titres h1/h2 → titre unique, listes gardées sur 3 niveaux, start conservé, lien → texte, style/souligné perdus", () => {
    const e = newEditor();
    e.commands.insertContent('<h1>T</h1><h2>S</h2><ul><li>a<ul><li>b<ol><li>c</li></ol></li></ul></li></ul><ol start="3"><li><a href="x">lien</a></li></ol><p style="color:red"><u>u</u> <b>g</b></p>');

    const out = e.getJSON();
    expectWithinWhitelist(out);
    const headings = nodesOfType(out, "heading");
    expect(headings.map((h) => h.attrs)).toEqual([{ level: 3 }, { level: 3 }]);
    expect(maxListDepth(out)).toBe(3);
    expect(nodesOfType(out, "orderedList").map((list) => list.attrs?.start)).toContain(3);
    expect(markTypes(out)).not.toContain("link");
    expect(markTypes(out)).not.toContain("noteColor");
    expect(markTypes(out)).not.toContain("underline");
    expect(nodesOfType(out, "text").map((t) => t.text).join("|")).toContain("lien");
    expectEqualsFixture(out, "collage-web");
  });

  it("collage Word Windows (mso-list) : des paragraphes « · texte », pas une liste (limitation déclarée)", () => {
    const e = newEditor();
    e.commands.insertContent(
      '<p class="MsoListParagraph" style="mso-list:l0 level1 lfo1"><span style="mso-list:Ignore">·</span>Premier point</p>' +
        '<p class="MsoListParagraph" style="mso-list:l0 level1 lfo1"><span style="mso-list:Ignore">·</span>Second point</p>',
    );

    const out = e.getJSON();
    expectWithinWhitelist(out);
    expect(nodesOfType(out, "bulletList")).toHaveLength(0);
    expect(nodesOfType(out, "paragraph").map((p) => p.content?.map((t) => t.text).join(""))).toEqual(["·Premier point", "·Second point"]);
    expectEqualsFixture(out, "collage-word-windows");
  });

  it("catalogue emoji : tous les caractères du catalogue passent l'éditeur (fixture régénérée = commise)", () => {
    const chars = EMOJI_CATALOG.flatMap((theme) => theme.items.map((item) => item.char)).join("");
    expect(chars.length, "catalogue non vide").toBeGreaterThan(0);
    const e = newEditor();
    e.commands.insertContent(chars);

    const out = e.getJSON();
    expectWithinWhitelist(out);
    // La fixture DOIT être le catalogue courant : ajouter un emoji sans la régénérer rougit ici.
    expect(readFixture("emoji-catalogue"), "fixture emoji-catalogue.json = paragraphe portant tous les `char` du catalogue").toEqual({
      type: "doc",
      content: [{ type: "paragraph", content: [{ type: "text", text: chars }] }],
    });
    expectEqualsFixture(out, "emoji-catalogue");
  });

  it("collage de 4 niveaux : l'éditeur l'accepte (fixture NÉGATIVE, refusée par le serveur avec son message)", () => {
    const e = newEditor();
    e.commands.insertContent("<ul><li>1<ul><li>2<ul><li>3<ul><li>4</li></ul></li></ul></li></ul></li></ul>");

    const out = e.getJSON();
    expect(maxListDepth(out)).toBe(4);
    expectEqualsFixture(out, "collage-profondeur-4");
  });
});

/**
 * Promesse (décision 2026-11-10, trou relevé à l'implémentation) : **la liste blanche serveur reste
 * FERMÉE, c'est l'éditeur qui s'y conforme** — une liste numérotée collée en lettres ou en chiffres
 * romains (`<ol type="a">`), ou un départ hors bornes (collé ou tapé « 0. », « 12000. »), ne produit
 * JAMAIS un `type` non nul ni un `start` hors [1, 9999] : sinon la note enregistrée prendrait un 400
 * « Le contenu mis en forme est invalide. » sans que la superadmin ait rien fait d'interdit.
 * Jumeau serveur (refus de ces mêmes formes) : `backend/tests/Unit/ReleaseNoteRichDocTest.php`
 * (« liste numérotée type "a" », « start 0 », « start 10000 »).
 */
describe("éditeur de notes — liste numérotée ramenée à la liste blanche (type, start)", () => {
  it.each([
    ["lettres (type=\"a\")", '<ol type="a"><li>un</li><li>deux</li></ol>'],
    ["romains (type=\"I\")", '<ol type="I" start="4"><li>quatre</li></ol>'],
    ["romains minuscules (type=\"i\")", '<ol type="i"><li>un</li></ol>'],
    ["départ 0 collé", '<ol start="0"><li>zéro</li></ol>'],
    ["départ négatif collé", '<ol start="-3"><li>moins</li></ol>'],
    ["départ 10000 collé", '<ol start="10000"><li>dix mille</li></ol>'],
  ])("collage %s : la liste reste numérotée, attributs dans la liste blanche", (_name, html) => {
    const e = newEditor();
    e.commands.insertContent(html);

    const out = e.getJSON();
    expect(nodesOfType(out, "orderedList"), "la liste collée reste une liste numérotée").toHaveLength(1);
    expectWithinWhitelist(out);
  });

  it.each([
    ["« 0. »", "0. zéro"],
    ["« 12000. »", "12000. trop loin"],
  ])("frappe %s en début de ligne : liste numérotée dont le départ reste dans [1, 9999]", (_name, typed) => {
    const e = newEditor();
    typeText(e, typed);

    const out = e.getJSON();
    // Soit la règle de saisie ne crée pas de liste, soit elle en crée une BORNÉE — jamais un start refusé.
    expectWithinWhitelist(out);
  });

  it("frappe « 3. » : la règle de saisie reste active et garde son départ (témoin du helper de frappe)", () => {
    const e = newEditor();
    typeText(e, "3. trois");

    const out = e.getJSON();
    const lists = nodesOfType(out, "orderedList");
    expect(lists, "« 3. » crée une liste numérotée").toHaveLength(1);
    expect(lists[0]?.attrs?.start).toBe(3);
    expectWithinWhitelist(out);
  });
});
