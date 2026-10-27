import { Slot } from "@radix-ui/react-slot";
import { type VariantProps } from "class-variance-authority";
import { useId } from "react";
import type * as React from "react";

import { buttonVariants } from "@/shared/components/ui/button-variants";
import { cn } from "@/shared/lib/utils";

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  asChild?: boolean;
  /**
   * Raison de la désactivation RENDUE ACCESSIBLE (A11Y-30). Quand elle est fournie ET que le bouton
   * est `disabled`, le bouton reste FOCALISABLE (`aria-disabled` au lieu du `disabled` natif, qui
   * sortirait l'élément de l'ordre de tabulation et rendrait son `title` muet au clavier/lecteur
   * d'écran), le clic est neutralisé, et la raison est annoncée via `aria-describedby` vers un
   * `<span class="sr-only">` frère (hors flux, donc sans gap flex). L'infobulle `title` (souris)
   * reste posée. ⚠ Complète — ne remplace pas — la « raison en clair à côté » des cas importants.
   */
  disabledReason?: string;
}

export function Button({ className, variant, size, asChild = false, disabledReason, disabled, onClick, children, ...props }: ButtonProps) {
  const Comp = asChild ? Slot : "button";
  const reasonId = useId();

  // Désactivation DÉCOUVRABLE : seulement pour un vrai `<button>` (le Slot `asChild` n'accepte
  // qu'un enfant, il ne peut pas recevoir le `<span>` frère). Sinon, comportement natif inchangé.
  if (!asChild && undefined !== disabledReason && disabled) {
    return (
      <>
        <button
          className={cn(buttonVariants({ variant, size }), "cursor-not-allowed opacity-50", className)}
          aria-disabled="true"
          aria-describedby={reasonId}
          title={disabledReason}
          // Clic et activation clavier (Entrée/Espace produisent un `click`) neutralisés.
          onClick={(e) => e.preventDefault()}
          {...props}
        >
          {children}
        </button>
        <span id={reasonId} className="sr-only">
          {disabledReason}
        </span>
      </>
    );
  }

  return (
    <Comp className={cn(buttonVariants({ variant, size, className }))} disabled={disabled} onClick={onClick} {...props}>
      {children}
    </Comp>
  );
}
