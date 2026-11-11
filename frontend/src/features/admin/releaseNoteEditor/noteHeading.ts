import type { Editor } from "@tiptap/core";
import { Heading } from "@tiptap/extension-heading";

/** Vrai quand la sélection est dans une puce : un titre n'y entre jamais (liste blanche serveur). */
function insideListItem(state: Editor["state"]): boolean {
  const { $from } = state.selection;
  for (let depth = $from.depth; depth > 0; depth--) {
    if ("listItem" === $from.node(depth).type.name) return true;
  }
  return false;
}

/**
 * Le titre UNIQUE des notes (niveau 3, celui de la liste blanche serveur). Un collage garde ses
 * titres : tout `h1`–`h6` devient ce titre (le `Heading` natif ne parse que ses niveaux configurés,
 * un `h1` collé deviendrait un paragraphe). Dans une puce, `setHeading`/`toggleHeading` refusent au
 * lieu de sortir la ligne de la liste (le natif fait `clearNodes` puis pose le titre).
 */
export const NoteHeading = Heading.extend({
  parseHTML() {
    return [1, 2, 3, 4, 5, 6].map((level) => ({ tag: `h${level}`, attrs: { level: 3 } }));
  },
  addCommands() {
    const parent = this.parent?.();
    return {
      setHeading:
        (attributes) =>
        (props) =>
          !insideListItem(props.state) && (parent?.setHeading?.(attributes)(props) ?? false),
      toggleHeading:
        (attributes) =>
        (props) =>
          !insideListItem(props.state) && (parent?.toggleHeading?.(attributes)(props) ?? false),
    };
  },
}).configure({ levels: [3] });
