import type { HTMLAttributes, TdHTMLAttributes, ThHTMLAttributes } from "react";

import { cn } from "@/shared/lib/utils";

/**
 * Table — la primitive de tableau PARTAGÉE (patron shadcn, jetons maison), maison
 * unique d'un `<table>` stylé (« même chose, au même endroit, de la même façon »).
 * Née pour l'onglet Consulter (PR-2b, temporalités Mois/Phase) ; à réutiliser
 * partout où une liste tabulaire dense a du sens plutôt que de recoder un `<table>`.
 *
 * `Table` enveloppe le `<table>` dans un conteneur `overflow-x-auto` : le tableau
 * défile HORIZONTALEMENT dans sa boîte, jamais la page (règle `.claude/rules/frontend.md`).
 * `TableHead` pose `scope="col"` (en-tête de colonne, a11y) et le style d'en-tête
 * (`text-muted-foreground`, ≥ `text-xs`). Jetons du thème uniquement — aucun `#hex`.
 *
 * `variant="inline"` — un tableau NU (ni bordure, ni fond, ni arrondi), en `text-xs`, destiné à
 * s'imbriquer dans un encart déjà cadré (le détail par côté d'un conflit) ; il garde son
 * `overflow-x-auto`. `TableHead`/`TableCell` acceptent un `className` pour resserrer les cellules
 * (`px-1.5 py-0.5`) et laisser les en-têtes en `normal-case`.
 */
export function Table({ className, variant = "default", ...props }: HTMLAttributes<HTMLTableElement> & { variant?: "default" | "inline" }) {
  const wrapper = "inline" === variant ? "w-full overflow-x-auto" : "w-full overflow-x-auto rounded-lg border border-border bg-card";
  const table = "inline" === variant ? "w-full border-collapse text-xs text-foreground" : "w-full border-collapse text-sm text-foreground";
  return (
    <div className={wrapper}>
      <table className={cn(table, className)} {...props} />
    </div>
  );
}

export function TableHeader({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
  return <thead className={cn("border-b border-border", className)} {...props} />;
}

export function TableBody({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
  return <tbody className={className} {...props} />;
}

export function TableRow({ className, ...props }: HTMLAttributes<HTMLTableRowElement>) {
  return <tr className={cn("border-b border-border last:border-0", className)} {...props} />;
}

export function TableHead({ className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
  return <th scope="col" className={cn("px-3 py-2 text-left align-middle text-xs font-semibold uppercase tracking-wide text-muted-foreground", className)} {...props} />;
}

export function TableCell({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
  return <td className={cn("px-3 py-2 align-middle", className)} {...props} />;
}

export function TableCaption({ className, ...props }: HTMLAttributes<HTMLTableCaptionElement>) {
  return <caption className={cn("px-3 py-2 text-left text-xs text-muted-foreground", className)} {...props} />;
}
