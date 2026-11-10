import { Fragment, type ReactNode } from "react";

import { cn } from "@/shared/lib/utils";
import { RICH_COLOR_CLASS, type RichColor, type RichDoc } from "@/shared/lib/richDoc";
import type { SurfaceSkin } from "@/shared/lib/surfaceSkin";

/**
 * Rend le corps riche d'une note du journal en ÉLÉMENTS React uniquement — `<p>`, `<h3>`/`<h4>`,
 * `<ul>`/`<ol>`/`<li>`, `<strong>`, `<em>`, `<span class>`, `<br>` — jamais une chaîne HTML :
 * une balise saisie s'affiche littéralement. Un nœud ou une marque inconnus sont IGNORÉS sans
 * lever (le serveur les refuse déjà ; ce rendu tient même si une forme interdite passait).
 *
 * `headingLevel` place les titres du corps sous le titre de la note (page : 3 sous le `<h2>` de
 * carte ; modale : 4 sous le `<h3>`). `variant` choisit les jetons de couleur de la peau.
 */
export function RichText({ doc, variant = "app", headingLevel = 3, className }: { doc: RichDoc; variant?: SurfaceSkin; headingLevel?: 3 | 4; className?: string }) {
  const ctx: RenderContext = { colors: RICH_COLOR_CLASS[variant], headingLevel };
  return <div className={cn("space-y-2", className)}>{renderChildren(doc.content, ctx)}</div>;
}

interface RenderContext {
  colors: ReadonlyMap<RichColor, string>;
  headingLevel: 3 | 4;
}

/** Nœud tel que lu, sans confiance : la forme est vérifiée champ par champ. */
type LooseNode = { type?: unknown; text?: unknown; attrs?: unknown; content?: unknown; marks?: unknown };

/**
 * Retraits : un numéro (« 1. ») est plus large qu'une puce et déborde à gauche du même retrait —
 * la liste numérotée prend `pl-6` pour que son numéro démarre au niveau de la puce.
 */
const BULLET_LIST_CLASS = "list-disc space-y-1 pl-5";
const ORDERED_LIST_CLASS = "list-decimal space-y-1 pl-6";

function renderChildren(content: unknown, ctx: RenderContext): ReactNode[] {
  if (!Array.isArray(content)) return [];
  return content.map((child, index) => renderNode(child as LooseNode, ctx, index));
}

function renderNode(node: LooseNode, ctx: RenderContext, key: number): ReactNode {
  if (null === node || "object" !== typeof node) return null;
  switch (node.type) {
    case "paragraph":
      return <p key={key}>{renderChildren(node.content, ctx)}</p>;
    case "heading": {
      const Heading = 4 === ctx.headingLevel ? "h4" : "h3";
      return (
        <Heading key={key} className="mt-3 text-base font-semibold first:mt-0">
          {renderChildren(node.content, ctx)}
        </Heading>
      );
    }
    case "bulletList":
      return (
        <ul key={key} className={BULLET_LIST_CLASS}>
          {renderChildren(node.content, ctx)}
        </ul>
      );
    case "orderedList": {
      const start = orderedStart(node.attrs);
      return (
        <ol key={key} className={ORDERED_LIST_CLASS} start={start}>
          {renderChildren(node.content, ctx)}
        </ol>
      );
    }
    case "listItem":
      return (
        <li key={key} className="space-y-1">
          {renderChildren(node.content, ctx)}
        </li>
      );
    case "hardBreak":
      return <br key={key} />;
    case "text":
      return "string" === typeof node.text ? renderMarked(node.text, node.marks, ctx, key) : null;
    default:
      return null;
  }
}

function orderedStart(attrs: unknown): number | undefined {
  if (null === attrs || "object" !== typeof attrs) return undefined;
  const start = (attrs as { start?: unknown }).start;
  return "number" === typeof start && Number.isInteger(start) && 1 !== start ? start : undefined;
}

/** Texte enveloppé de ses marques connues (gras, italique, couleur) ; les autres sont ignorées. */
function renderMarked(text: string, marks: unknown, ctx: RenderContext, key: number): ReactNode {
  let out: ReactNode = text;
  for (const mark of Array.isArray(marks) ? marks : []) {
    const type = (mark as { type?: unknown } | null)?.type;
    if ("bold" === type) out = <strong>{out}</strong>;
    else if ("italic" === type) out = <em>{out}</em>;
    else if ("noteColor" === type) {
      const name = (mark as { attrs?: { name?: unknown } }).attrs?.name;
      const colorClass = "string" === typeof name ? ctx.colors.get(name as RichColor) : undefined;
      if (undefined !== colorClass) out = <span className={colorClass}>{out}</span>;
    }
  }
  return <Fragment key={key}>{out}</Fragment>;
}
