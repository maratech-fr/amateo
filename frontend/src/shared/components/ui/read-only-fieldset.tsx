import type { ReactNode } from "react";

import { cn } from "@/shared/lib/utils";

/**
 * Zone de SAISIE pure en consultation : `<fieldset disabled>` désactive d'un coup tous ses
 * contrôles, SANS les griser (opacité pleine, curseur normal — c'est une lecture, pas un état
 * d'erreur). Réservé aux zones de saisie : filtres, onglets, tri, « voir » et export restent
 * hors du fieldset pour rester utilisables.
 */
export function ReadOnlyFieldset({ readOnly, children, className }: { readOnly: boolean; children: ReactNode; className?: string }) {
  return (
    <fieldset disabled={readOnly} className={cn("m-0 min-w-0 border-0 p-0 [&_:disabled]:cursor-default [&_:disabled]:opacity-100", className)}>
      {children}
    </fieldset>
  );
}
