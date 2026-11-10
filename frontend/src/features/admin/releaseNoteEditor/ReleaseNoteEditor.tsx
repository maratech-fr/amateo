import { type Editor, EditorContent, useEditor, useEditorState } from "@tiptap/react";
import { type ReactNode, useEffect, useRef } from "react";

import { cn } from "@/shared/lib/utils";
import { RICH_COLOR_CLASS, type RichColor, type RichDoc } from "@/shared/lib/richDoc";

import { ADMIN_SECTIONS_TEXTS } from "../texts/sections";
import { releaseNoteExtensions } from "./editorExtensions";
import { EmojiPicker } from "./EmojiPicker";

const COLORS: ReadonlyArray<{ name: RichColor; label: string }> = [
  { name: "rouge", label: ADMIN_SECTIONS_TEXTS.releaseNotesColorRouge },
  { name: "orange", label: ADMIN_SECTIONS_TEXTS.releaseNotesColorOrange },
  { name: "vert", label: ADMIN_SECTIONS_TEXTS.releaseNotesColorVert },
  { name: "bleu", label: ADMIN_SECTIONS_TEXTS.releaseNotesColorBleu },
  { name: "gris", label: ADMIN_SECTIONS_TEXTS.releaseNotesColorGris },
];

const TOOL_CLASS =
  "h-9 rounded px-2 text-sm text-console-text hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-console-accent/40 aria-pressed:bg-console-accent/20 aria-pressed:text-white disabled:opacity-50";

/** Bouton-pastille de couleur : carré de 36 px, pastille centrée. */
const SWATCH_TOOL_CLASS = "inline-flex w-9 items-center justify-center px-0";

/**
 * L'éditeur riche des notes du journal (console superadmin) : barre « Mise en forme » (gras,
 * italique, titre, listes, 5 couleurs + défaut, emoji, annuler/rétablir) au-dessus d'une zone
 * TipTap dont le schéma (`releaseNoteExtensions`) ne produit que la liste blanche serveur.
 * `onChange` reçoit le document ProseMirror à chaque modification ; `value` n'est lu qu'au
 * montage (remonter le composant pour repartir d'un document vide). La zone éditable porte `id`
 * et se nomme par l'élément `${id}-label` (le `<label>` du formulaire).
 *
 * Les raccourcis d'édition natifs restent (Ctrl+B/I, Ctrl+Alt+3, Ctrl+Maj+8/7, `- `, `1. `, `### `) :
 * le stockage est du JSON, jamais du Markdown.
 */
export function ReleaseNoteEditor({ value, onChange, id }: { value: RichDoc | null; onChange: (doc: RichDoc) => void; id: string }) {
  // Le rappel le plus récent, lu à chaque mise à jour (l'éditeur est créé une seule fois).
  const onChangeRef = useRef(onChange);
  useEffect(() => {
    onChangeRef.current = onChange;
  }, [onChange]);

  const editor = useEditor({
    extensions: releaseNoteExtensions(),
    content: value ?? undefined,
    editorProps: {
      attributes: {
        id,
        role: "textbox",
        "aria-multiline": "true",
        "aria-labelledby": `${id}-label`,
        class:
          "min-h-28 w-full rounded-b-md px-3 py-2 text-sm text-white outline-none focus-visible:ring-2 focus-visible:ring-console-accent/20 [&_h3]:text-base [&_h3]:font-semibold [&_ol]:list-decimal [&_ol]:pl-6 [&_ul]:list-disc [&_ul]:pl-5",
      },
    },
    onUpdate: ({ editor: current }) => onChangeRef.current(current.getJSON() as RichDoc),
  });

  return (
    <div className="rounded-md border border-white/15 bg-white/[0.04] focus-within:border-console-accent/70">
      <Toolbar editor={editor} />
      <EditorContent editor={editor} />
    </div>
  );
}

function Toolbar({ editor }: { editor: Editor }) {
  const state = useEditorState({
    editor,
    selector: ({ editor: current }) => ({
      bold: current.isActive("bold"),
      italic: current.isActive("italic"),
      heading: current.isActive("heading"),
      bulletList: current.isActive("bulletList"),
      orderedList: current.isActive("orderedList"),
      color: (current.getAttributes("noteColor").name ?? null) as RichColor | null,
      canUndo: current.can().undo(),
      canRedo: current.can().redo(),
    }),
  });

  return (
    <div role="toolbar" aria-label={ADMIN_SECTIONS_TEXTS.releaseNotesToolbarLabel} className="flex flex-wrap items-center gap-1 border-b border-white/10 p-1">
      <Tool pressed={state.bold} onClick={() => editor.chain().focus().toggleBold().run()} className="font-bold">
        {ADMIN_SECTIONS_TEXTS.releaseNotesToolBold}
      </Tool>
      <Tool pressed={state.italic} onClick={() => editor.chain().focus().toggleItalic().run()} className="italic">
        {ADMIN_SECTIONS_TEXTS.releaseNotesToolItalic}
      </Tool>
      <Tool pressed={state.heading} onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}>
        {ADMIN_SECTIONS_TEXTS.releaseNotesToolHeading}
      </Tool>
      <Tool pressed={state.bulletList} onClick={() => editor.chain().focus().toggleBulletList().run()}>
        {ADMIN_SECTIONS_TEXTS.releaseNotesToolBulletList}
      </Tool>
      <Tool pressed={state.orderedList} onClick={() => editor.chain().focus().toggleOrderedList().run()}>
        {ADMIN_SECTIONS_TEXTS.releaseNotesToolOrderedList}
      </Tool>

      <div role="group" aria-label={ADMIN_SECTIONS_TEXTS.releaseNotesColorLabel} className="flex items-center gap-0.5 border-x border-white/10 px-1">
        <Tool pressed={null === state.color} onClick={() => editor.chain().focus().unsetMark("noteColor").run()} className={SWATCH_TOOL_CLASS}>
          <span aria-hidden="true" className="inline-block size-4 rounded-full border border-console-text-dim" />
          <span className="sr-only">{ADMIN_SECTIONS_TEXTS.releaseNotesColorDefault}</span>
        </Tool>
        {COLORS.map((color) => (
          <Tool key={color.name} pressed={color.name === state.color} onClick={() => editor.chain().focus().setMark("noteColor", { name: color.name }).run()} className={SWATCH_TOOL_CLASS}>
            <span aria-hidden="true" className={cn("inline-block size-4 rounded-full bg-current", RICH_COLOR_CLASS.console.get(color.name))} />
            <span className="sr-only">{color.label}</span>
          </Tool>
        ))}
      </div>

      <EmojiPicker
        onPick={(char) => {
          // `focus()` restaure la dernière sélection (insertion au curseur) mais rend le focus DOM
          // à la frame suivante : le panneau vient de se démonter, le focus est rendu tout de suite.
          editor.chain().focus().insertContent(char).run();
          editor.view.focus();
        }}
      />

      <Tool disabled={!state.canUndo} onClick={() => editor.chain().focus().undo().run()}>
        {ADMIN_SECTIONS_TEXTS.releaseNotesUndo}
      </Tool>
      <Tool disabled={!state.canRedo} onClick={() => editor.chain().focus().redo().run()}>
        {ADMIN_SECTIONS_TEXTS.releaseNotesRedo}
      </Tool>
    </div>
  );
}

/**
 * Un bouton de la barre. `mousedown` empêché : le clic ne vole pas le focus (ni la sélection) de
 * la zone d'édition ; `pressed` absent = bouton d'action (annuler, rétablir), sans `aria-pressed`.
 */
function Tool({ pressed, disabled, onClick, className, children }: { pressed?: boolean; disabled?: boolean; onClick: () => void; className?: string; children: ReactNode }) {
  return (
    <button
      type="button"
      aria-pressed={pressed}
      disabled={disabled}
      onMouseDown={(event) => event.preventDefault()}
      onClick={onClick}
      className={cn(TOOL_CLASS, className)}
    >
      {children}
    </button>
  );
}
