import type { SurfaceSkin } from "./surfaceSkin";

/**
 * Le corps riche d'une note du journal (JSON ProseMirror) : la forme FERMÉE de la liste blanche
 * serveur `App\Service\ReleaseNoteRichDoc` — ce que le serveur stocke, ce que `RichText` rend.
 * Aucun type ici ne dépend de TipTap (confiné à `features/admin/releaseNoteEditor/`).
 */
export type RichColor = "rouge" | "orange" | "vert" | "bleu" | "gris";

export type RichMark = { type: "bold" } | { type: "italic" } | { type: "noteColor"; attrs: { name: RichColor } };

export interface RichTextNode {
  type: "text";
  text: string;
  marks?: RichMark[];
}

export interface RichHardBreak {
  type: "hardBreak";
  marks?: RichMark[];
}

export type RichInline = RichTextNode | RichHardBreak;

export interface RichParagraph {
  type: "paragraph";
  content?: RichInline[];
}

export interface RichHeading {
  type: "heading";
  attrs: { level: 3 };
  content?: RichInline[];
}

export interface RichListItem {
  type: "listItem";
  content: (RichParagraph | RichBulletList | RichOrderedList)[];
}

export interface RichBulletList {
  type: "bulletList";
  content: RichListItem[];
}

export interface RichOrderedList {
  type: "orderedList";
  attrs?: { start?: number };
  content: RichListItem[];
}

export type RichBlock = RichParagraph | RichHeading | RichBulletList | RichOrderedList;

export interface RichDoc {
  type: "doc";
  content: RichBlock[];
}

/**
 * Classe de texte de chaque couleur nommée, par peau. App : jetons du thème (AA mesuré par
 * `tests/e2e/a11y-contrast.spec.ts`, `text-info` compris) ; console : jetons `--console-*` (jamais
 * un jeton adaptatif de l'app sur le fond sombre). Lecture par `Map.get` : une couleur hors palette
 * (`__proto__`, `constructor`…) ne résout rien.
 */
export const RICH_COLOR_CLASS: Record<SurfaceSkin, ReadonlyMap<RichColor, string>> = {
  app: new Map<RichColor, string>([
    ["rouge", "text-destructive"],
    ["orange", "text-warning"],
    ["vert", "text-success"],
    ["bleu", "text-info"],
    ["gris", "text-muted-foreground"],
  ]),
  console: new Map<RichColor, string>([
    ["rouge", "text-console-danger"],
    ["orange", "text-console-warning"],
    ["vert", "text-console-success"],
    ["bleu", "text-console-info"],
    ["gris", "text-console-text-dim"],
  ]),
};
