import type * as React from "react";

import { cn } from "@/shared/lib/utils";

interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  ref?: React.Ref<HTMLInputElement>;
  /**
   * Variante DENSE nommée (série « uniformité des écrans », PR 7/7, 2026-10-01) : `h-8` (32 px) au
   * lieu de `h-9` (36 px, le défaut) — réservée aux TABLEAUX très denses (ex. `TeamsStep`, une ligne
   * par équipe, 6 colonnes). Jamais mélangée à du `h-9` dans la même ligne (décision fondateur B sur
   * captures A/B) : une hauteur ne se force plus en className, elle se nomme ici.
   */
  compact?: boolean;
}

export function Input({ className, type, ref, compact = false, ...props }: InputProps) {
  return (
    <input
      ref={ref}
      type={type}
      className={cn(
        // Hauteur UNIFORME `h-9` (36 px) — tout contrôle posé dans une ligne (champ, sélecteur,
        // bouton) partage cette hauteur (décision fondateur B, captures A/B 2026-10-01). La variante
        // dense `compact` descend à `h-8` pour les tableaux serrés, jamais mélangée dans une ligne.
        compact ? "h-8" : "h-9",
        // Fond OPAQUE `bg-card` (jamais nu) : le champ vit souvent sur le fond à motifs du body ;
        // transparent, son placeholder (« Rechercher un club ou un gymnase ») devenait illisible
        // (retour terrain 2026-09-27). Contraste `text-muted-foreground` sur `--card` = 5,60.
        "flex w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50",
        className,
      )}
      {...props}
    />
  );
}
