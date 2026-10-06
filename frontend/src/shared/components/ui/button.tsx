import { Slot } from "@radix-ui/react-slot";
import { cva, type VariantProps } from "class-variance-authority";
import { useId } from "react";
import type * as React from "react";

import { cn } from "@/shared/lib/utils";

/**
 * P4-127 (e), décision fondateur 2026-08-23 — un bouton désactivé INFORME au lieu de disparaître
 * du monde : `disabled:pointer-events-none` est parti. Il rendait mortes les infobulles
 * d'explication (~9 `title={lockTitle}` vivants dans le cockpit qu'aucun survol ne pouvait
 * déclencher) et interdisait tout curseur. Le clic reste inerte par le NATIF (`disabled`), pas
 * par une classe. Contreparties tenues ensemble : `disabled:cursor-not-allowed` (possible
 * seulement sans pointer-events), et chaque style de survol borné aux boutons ACTIFS
 * (`hover:enabled:`) — sinon le survol d'un bouton mort afficherait l'affordance d'un vivant.
 * ⚠ La doctrine « raison en clair à côté » (WeekPickerDialog, generationInFlight) reste la norme
 * pour les cas importants : une infobulle est souris-seule, elle complète, elle ne remplace pas.
 */
const buttonVariants = cva(
  "inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0",
  {
    variants: {
      variant: {
        default: "bg-accent text-accent-foreground hover:enabled:bg-accent-hover",
        outline: "border border-border bg-transparent hover:enabled:bg-muted",
        ghost: "hover:enabled:bg-muted",
        destructive: "bg-destructive text-destructive-foreground hover:enabled:opacity-90",
      },
      size: {
        default: "h-10 px-4 py-2",
        sm: "h-9 px-3",
        lg: "h-11 px-6",
        icon: "h-10 w-10",
        // Bouton-icône de LIGNE : 36 px (= `h-9`), pour s'aligner sur les champs/sélecteurs/boutons
        // d'une même ligne (décision fondateur B, série « uniformité des écrans » PR 7/7). `icon`
        // (40 px) reste le bouton-icône AUTONOME ; une ligne dense garde son `size-8` nommé localement.
        "icon-sm": "size-9",
      },
    },
    defaultVariants: { variant: "default", size: "default" },
  },
);

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
