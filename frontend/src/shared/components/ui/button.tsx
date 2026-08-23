import { Slot } from "@radix-ui/react-slot";
import { cva, type VariantProps } from "class-variance-authority";
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
        default: "bg-accent text-accent-foreground hover:enabled:opacity-90",
        outline: "border border-border bg-transparent hover:enabled:bg-muted",
        ghost: "hover:enabled:bg-muted",
        destructive: "bg-destructive text-white hover:enabled:opacity-90",
      },
      size: {
        default: "h-10 px-4 py-2",
        sm: "h-9 px-3",
        lg: "h-11 px-6",
        icon: "h-10 w-10",
      },
    },
    defaultVariants: { variant: "default", size: "default" },
  },
);

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  asChild?: boolean;
}

export function Button({ className, variant, size, asChild = false, ...props }: ButtonProps) {
  const Comp = asChild ? Slot : "button";
  return <Comp className={cn(buttonVariants({ variant, size, className }))} {...props} />;
}
