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
}

/** Thin styled wrapper over a native <select> (options passed as children). */
export function Select({ className, wrapperClassName, children, ...props }: SelectProps) {
  return (
    <div className={cn("relative", wrapperClassName)}>
      <select
        className={cn(
          "h-9 w-full appearance-none rounded-md border border-input bg-background px-3 pr-8 text-sm outline-none focus:ring-2 focus:ring-ring disabled:opacity-50",
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
