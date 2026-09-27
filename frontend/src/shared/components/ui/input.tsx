import type * as React from "react";

import { cn } from "@/shared/lib/utils";

export function Input({ className, type, ref, ...props }: React.InputHTMLAttributes<HTMLInputElement> & { ref?: React.Ref<HTMLInputElement> }) {
  return (
    <input
      ref={ref}
      type={type}
      className={cn(
        // Fond OPAQUE `bg-card` (jamais nu) : le champ vit souvent sur le fond à motifs du body ;
        // transparent, son placeholder (« Rechercher un club ou un gymnase ») devenait illisible
        // (retour terrain 2026-09-27). Contraste `text-muted-foreground` sur `--card` = 5,60.
        "flex h-10 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50",
        className,
      )}
      {...props}
    />
  );
}
