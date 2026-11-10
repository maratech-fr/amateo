import { wrappingInputRule } from "@tiptap/core";
import { OrderedList } from "@tiptap/extension-list";

/** Bornes du départ d'une liste numérotée — `ReleaseNoteRichDoc` côté serveur (1 à 9999). */
const MIN_START = 1;
const MAX_START = 9999;

/** « 1. » à « 9999. » en début de ligne : jamais « 0. » ni un départ au-delà de la borne. */
const BOUNDED_INPUT = /^([1-9]\d{0,3})\.\s$/;

function clampStart(raw: string | null): number {
  const value = Number.parseInt(raw ?? "", 10);
  return Number.isNaN(value) ? MIN_START : Math.min(MAX_START, Math.max(MIN_START, value));
}

/**
 * La liste numérotée des notes, ramenée à la liste blanche serveur : numérotation toujours en
 * chiffres (`type` forcé à null — une liste collée en lettres ou en chiffres romains reste une
 * liste, numérotée 1, 2, 3) et départ borné à [1, 9999] (un `start` collé hors bornes est ramené
 * à la borne). La règle de saisie ne crée une liste que pour « 1. » à « 9999. ».
 */
export const NoteOrderedList = OrderedList.extend({
  addAttributes() {
    return {
      start: {
        default: MIN_START,
        parseHTML: (element: HTMLElement) => clampStart(element.getAttribute("start")),
      },
      type: {
        default: null,
        parseHTML: () => null,
        renderHTML: () => ({}),
      },
    };
  },
  addInputRules() {
    return [
      wrappingInputRule({
        find: BOUNDED_INPUT,
        type: this.type,
        getAttributes: (match) => ({ start: Number(match[1]) }),
        joinPredicate: (match, node) => node.childCount + (node.attrs.start as number) === Number(match[1]),
      }),
    ];
  },
});
