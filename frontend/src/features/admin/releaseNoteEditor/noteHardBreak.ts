import { HardBreak } from "@tiptap/extension-hard-break";

/**
 * Le saut de ligne (Maj+Entrée) des notes : il PORTE les marques du texte qu'il coupe (gras,
 * couleur…), si bien que la suite tapée après lui les garde même quand la frappe ne passe pas par
 * les marques mémorisées de l'éditeur. Le serveur retire ces marques à la normalisation (un saut
 * de ligne ne s'affiche pas) — la liste blanche les accepte pour cette raison.
 */
export const NoteHardBreak = HardBreak.extend({
  addCommands() {
    return {
      setHardBreak:
        () =>
        ({ state, chain }) => {
          const { selection, storedMarks } = state;
          if (selection.$from.parent.type.spec.isolating) return false;
          const marks = storedMarks ?? (0 !== selection.$to.parentOffset ? selection.$from.marks() : []);
          return chain()
            .insertContent({ type: this.name, marks: marks.map((mark) => mark.toJSON() as { type: string; attrs?: Record<string, unknown> }) })
            .run();
        },
    };
  },
});
