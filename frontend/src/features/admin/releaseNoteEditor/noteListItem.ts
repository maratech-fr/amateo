import { ListItem } from "@tiptap/extension-list";

/** Profondeur maximale d'imbrication des listes — `ReleaseNoteRichDoc::MAX_LIST_DEPTH` côté serveur. */
const MAX_LIST_DEPTH = 3;

/**
 * La puce des notes : un paragraphe d'abord, puis paragraphes ou sous-listes — jamais un titre (le
 * `listItem` natif accepte `paragraph block*`, hors liste blanche serveur). `Tab` ne crée pas de
 * 4ᵉ niveau : au 3ᵉ, il est refusé (non consommé, le focus suit le comportement natif) ;
 * `Maj+Tab` reste libre. Seul un collage peut dépasser 3 niveaux, le serveur le refuse en clair.
 */
export const NoteListItem = ListItem.extend({
  content: "paragraph (paragraph | bulletList | orderedList)*",
  addKeyboardShortcuts() {
    return {
      ...this.parent?.(),
      Tab: () => {
        const { $from } = this.editor.state.selection;
        let lists = 0;
        for (let depth = $from.depth; depth > 0; depth--) {
          const name = $from.node(depth).type.name;
          if ("bulletList" === name || "orderedList" === name) lists++;
        }
        if (lists >= MAX_LIST_DEPTH) return false;
        return this.editor.commands.sinkListItem(this.name);
      },
    };
  },
});
