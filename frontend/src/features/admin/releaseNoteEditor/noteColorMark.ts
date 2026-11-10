import { Mark } from "@tiptap/core";

import { RICH_COLOR_CLASS, type RichColor } from "@/shared/lib/richDoc";

/**
 * La couleur NOMMÉE d'un texte de note (attribut `name` ∈ les 5 couleurs de la liste blanche
 * serveur). `parseHTML: []` : une couleur collée (style, `<font>`) ne devient JAMAIS une
 * `noteColor` — seule la barre d'outils en pose. Affichée dans l'éditeur par le jeton console
 * (`RICH_COLOR_CLASS.console`, l'éditeur vit dans la console).
 */
export const NoteColor = Mark.create({
  name: "noteColor",
  addAttributes() {
    return { name: { default: null, rendered: false } };
  },
  parseHTML() {
    return [];
  },
  renderHTML({ mark }) {
    const colorClass = RICH_COLOR_CLASS.console.get(mark.attrs.name as RichColor);
    return ["span", undefined === colorClass ? {} : { class: colorClass }, 0];
  },
});
