import type { Extensions } from "@tiptap/core";
import { Bold } from "@tiptap/extension-bold";
import { Document } from "@tiptap/extension-document";
import { Italic } from "@tiptap/extension-italic";
import { BulletList, ListKeymap } from "@tiptap/extension-list";
import { Paragraph } from "@tiptap/extension-paragraph";
import { Text } from "@tiptap/extension-text";
import { UndoRedo } from "@tiptap/extensions";

import { NoteColor } from "./noteColorMark";
import { NoteHardBreak } from "./noteHardBreak";
import { NoteHeading } from "./noteHeading";
import { NoteListItem } from "./noteListItem";
import { NoteOrderedList } from "./noteOrderedList";

/**
 * Le SEUL schéma de l'éditeur de notes, partagé par `ReleaseNoteEditor` et par les tests headless
 * (`editorOutput.test.ts`) : il ne produit que ce que la liste blanche serveur
 * (`App\Service\ReleaseNoteRichDoc`) accepte — fixtures croisées. Pas de `StarterKit` (il embarque
 * lien, souligné, citation, code, 6 niveaux de titre, listes de tâches).
 */
export function releaseNoteExtensions(): Extensions {
  return [Document, Paragraph, Text, NoteHardBreak, Bold, Italic, NoteHeading, BulletList, NoteOrderedList, NoteListItem, ListKeymap, UndoRedo, NoteColor];
}
