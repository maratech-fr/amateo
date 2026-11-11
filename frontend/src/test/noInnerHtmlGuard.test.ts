import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";

import { describe, expect, it } from "vitest";

/**
 * Promesse (Actualités PR B — A8, XSS stockée : une note est rendue chez TOUS les clubs) : **aucune
 * source de l'application n'injecte une chaîne comme du HTML dans le DOM de l'app, et l'éditeur
 * TipTap reste confiné à son dossier** — le rendu du corps riche passe par des éléments React
 * (`shared/components/ui/rich-text.tsx`), jamais par une chaîne.
 *
 * Garde STATIQUE (patron `textOpacityGuard`) sur `src/**` (`.ts`/`.tsx`, hors `*.test.*` et hors
 * `src/test/`), lignes de commentaire ignorées. Rougit sur :
 *   - `dangerouslySetInnerHTML`, `.innerHTML =`, `.outerHTML =`, `insertAdjacentHTML(` ;
 *   - `getHTML(` / `generateHTML(` (sérialisation HTML de TipTap) et `setContent(` avec une chaîne ;
 *   - `srcDoc` HORS d'une iframe `sandbox=""` sur la même ligne (la seule forme qui isole le HTML
 *     dans un document opaque, sans script — aperçu d'e-mail : `MailboxPage`, `SollicitationTab`) ;
 *   - tout import `@tiptap/*` hors de `features/admin/releaseNoteEditor/**`.
 * Aucune exemption nominative. Chaque motif est falsifié par un témoin positif ET un témoin négatif
 * (bloc « le détecteur voit… » ci-dessous) : un motif qui ne voit plus rien rougit.
 */
const SRC_ROOT = join(import.meta.dirname, "..");
const EDITOR_DIR = "features/admin/releaseNoteEditor/";
const COMMENT_LINE = /^\s*(?:\/\/|\*|\/\*|\{\/\*|\*\/)/;

const HTML_SINKS: { name: string; pattern: RegExp }[] = [
  { name: "dangerouslySetInnerHTML", pattern: /\bdangerouslySetInnerHTML\b/ },
  { name: ".innerHTML =", pattern: /\.(?:inner|outer)HTML\s*(?:\+)?=(?!=)/ },
  { name: "insertAdjacentHTML(", pattern: /\binsertAdjacentHTML\s*\(/ },
  { name: "getHTML(", pattern: /\bgetHTML\s*\(/ },
  { name: "generateHTML(", pattern: /\bgenerateHTML\s*\(/ },
  { name: "setContent(chaîne)", pattern: /\bsetContent\s*\(\s*["'`]/ },
];

const SRC_DOC = /\bsrcDoc\b/i;
const SANDBOXED_EMPTY = /\bsandbox=(?:""|\{""\}|'')/;
const TIPTAP_IMPORT = /(?:\bfrom\s+|\bimport\s*\(\s*|\brequire\s*\(\s*|^\s*import\s+)["']@tiptap\//;

/** Violations d'UNE ligne de `rel` (chemin relatif à `src/`). */
function lineViolations(rel: string, line: string): string[] {
  if (COMMENT_LINE.test(line)) return [];
  const out = HTML_SINKS.filter((sink) => sink.pattern.test(line)).map((sink) => sink.name);
  if (SRC_DOC.test(line) && !SANDBOXED_EMPTY.test(line)) out.push("srcDoc sans sandbox=\"\"");
  if (TIPTAP_IMPORT.test(line) && !rel.startsWith(EDITOR_DIR)) out.push("import @tiptap hors de " + EDITOR_DIR);
  return out;
}

function sourceFiles(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) {
      if (full === join(SRC_ROOT, "test")) continue;
      out.push(...sourceFiles(full));
    } else if (/\.(?:ts|tsx)$/.test(entry.name) && !/\.test\.(?:ts|tsx)$/.test(entry.name) && !entry.name.endsWith(".d.ts")) {
      out.push(full);
    }
  }
  return out;
}

describe("A8 — aucune chaîne injectée comme HTML, TipTap confiné", () => {
  it("le balayage voit bien les sources de l'app (témoin de portée)", () => {
    const rels = sourceFiles(SRC_ROOT).map((file) => file.slice(SRC_ROOT.length + 1));
    expect(rels.length).toBeGreaterThan(200);
    expect(rels).toContain("shared/components/ui/rich-text.tsx");
    expect(rels).toContain("features/mailbox/MailboxPage.tsx");
    expect(rels.some((rel) => rel.endsWith(".test.tsx"))).toBe(false);
  });

  it("aucune source ne porte un puits HTML, un srcDoc non isolé, ni un import @tiptap hors de l'éditeur", () => {
    const offenders: string[] = [];
    for (const file of sourceFiles(SRC_ROOT)) {
      const rel = file.slice(SRC_ROOT.length + 1);
      readFileSync(file, "utf8")
        .split("\n")
        .forEach((line, index) => {
          for (const violation of lineViolations(rel, line)) offenders.push(`src/${rel}:${index + 1} → ${violation} : ${line.trim().slice(0, 100)}`);
        });
    }
    expect(
      offenders,
      "Rendre un contenu en ÉLÉMENTS React (patron `RichText`), jamais une chaîne HTML ; un aperçu HTML isolé passe par <iframe sandbox=\"\" srcDoc> ; TipTap ne s'importe que dans features/admin/releaseNoteEditor/.",
    ).toEqual([]);
  });
});

describe("A8 — le détecteur voit chaque motif (témoins positifs) et pas leurs voisins (témoins négatifs)", () => {
  const OUTSIDE = "features/release-notes/WhatsNewModal.tsx";

  it.each([
    ["dangerouslySetInnerHTML", '<div dangerouslySetInnerHTML={{ __html: note.body }} />'],
    [".innerHTML =", "el.innerHTML = note.body;"],
    [".innerHTML +=", "el.innerHTML += note.body;"],
    [".outerHTML =", "el.outerHTML = html;"],
    ["insertAdjacentHTML(", 'el.insertAdjacentHTML("beforeend", html);'],
    ["getHTML(", "const html = editor.getHTML();"],
    ["generateHTML(", "const html = generateHTML(doc, extensions);"],
    ["setContent(\"…\")", 'editor.commands.setContent("<p>x</p>");'],
    ["setContent(`…`)", "editor.commands.setContent(`<p>${x}</p>`);"],
    ["srcDoc sans sandbox", "<iframe srcDoc={html} />"],
    ["srcDoc sandbox permissif", '<iframe sandbox="allow-scripts" srcDoc={html} />'],
    ["import @tiptap hors dossier", 'import { Editor } from "@tiptap/core";'],
    ["import type @tiptap hors dossier", 'import type { JSONContent } from "@tiptap/core";'],
    ["import dynamique @tiptap", 'const m = await import("@tiptap/react");'],
  ])("rougit sur %s", (_name, line) => {
    expect(lineViolations(OUTSIDE, line)).not.toEqual([]);
  });

  it.each([
    ["comparaison innerHTML", "if (el.innerHTML === before) return;"],
    ["commentaire citant le motif", " * JAMAIS injecté via `dangerouslySetInnerHTML` sur du contenu non assaini"],
    ["iframe isolée", '<iframe title="Corps" sandbox="" srcDoc={message.bodyHtml} />'],
    ["setContent avec un document JSON", "editor.commands.setContent(doc);"],
    ["méthode homonyme", "const el = getHTMLElement(id);"],
  ])("ne rougit pas sur %s", (_name, line) => {
    expect(lineViolations(OUTSIDE, line)).toEqual([]);
  });

  it("un import @tiptap DANS le dossier de l'éditeur est permis", () => {
    expect(lineViolations(`${EDITOR_DIR}ReleaseNoteEditor.tsx`, 'import { useEditor } from "@tiptap/react";')).toEqual([]);
  });
});
