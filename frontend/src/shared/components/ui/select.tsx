import { ChevronDown } from "lucide-react";
import type { SelectHTMLAttributes } from "react";

import { cn } from "@/shared/lib/utils";

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  /**
   * Wraps the field (largeur/flex y vivent — le `<select>` intérieur est toujours `w-full`, la
   * flèche est positionnée sur ce conteneur). Même piège que `Listbox`/`TeamSelect`/`VenueSelect` :
   * une classe de largeur passée en `className` vise le contrôle intérieur, jamais la boîte que la
   * ligne flex mesure. Défaut rétro-compatible : sans valeur le conteneur ne porte que `relative`,
   * donc le rendu des ~20 consommateurs actuels est inchangé.
   */
  wrapperClassName?: string;
  /**
   * Variante DENSE nommée (série « uniformité des écrans », PR 7/7, 2026-10-01) : `h-8` (32 px) au
   * lieu de `h-9` (36 px, le défaut) — réservée aux TABLEAUX très denses (ex. `TeamsStep`). Jamais
   * mélangée à du `h-9` dans la même ligne (décision fondateur B) : la hauteur se nomme, elle ne se
   * force plus en className (gardée par le lint, cf. `.claude/rules/frontend.md`).
   */
  compact?: boolean;
}

/** Thin styled wrapper over a native <select> (options passed as children). */
export function Select({ className, wrapperClassName, compact = false, children, ...props }: SelectProps) {
  return (
    <div className={cn("relative", wrapperClassName)}>
      <select
        className={cn(
          // Hauteur UNIFORME `h-9` (36 px, décision fondateur B) ; `compact` → `h-8` pour un tableau dense.
          compact ? "h-8" : "h-9",
          "w-full appearance-none rounded-md border border-input bg-background px-3 pr-8 text-sm outline-none focus:ring-2 focus:ring-ring disabled:opacity-50",
          className,
        )}
        {...props}
      >
        {children}
      </select>
      <ChevronDown className="pointer-events-none absolute right-2 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
    </div>
  );
}
