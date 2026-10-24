import { Flag } from "lucide-react";
import { useState } from "react";

import { Button, type ButtonProps } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";

import { FeedbackDialog } from "./FeedbackDialog";

interface FeedbackButtonProps {
  /** Libellé d'écran joint au contexte (ex. "/planning", "wizard/teams"). */
  screen?: string;
  /** Planning affiché par l'écran, s'il en tient un. */
  scheduleId?: string | null;
  /** Libellé d'écran LISIBLE joint au signalement (ex. « Assistant · Étape 2/6 · Gymnases »). */
  screenLabel?: string;
  /** Filtres actifs composés par l'écran (joints au signalement). */
  activeFilters?: string;
  /** Plage affichée par l'écran (jointe au signalement). */
  displayedRange?: string;
  variant?: ButtonProps["variant"];
  className?: string;
}

/**
 * Porte contextuelle du canal de signalement (P5-6) : un bouton discret « Signaler »
 * posé dans l'en-tête d'un écran, qui ouvre la modale en variante contextuelle (topic
 * « bug » figé + contexte joint automatiquement).
 */
export function FeedbackButton({ screen, scheduleId, screenLabel, activeFilters, displayedRange, variant = "ghost", className }: FeedbackButtonProps) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <Button type="button" variant={variant} size="sm" className={cn("h-8 gap-1.5 px-2 text-muted-foreground", className)} onClick={() => setOpen(true)}>
        {/* D6 (lot 2) — l'icône passe au ton destructif (repère visuel du canal de signalement) ;
            le libellé reste gris (`text-muted-foreground` du bouton) : on attire l'œil sans crier. */}
        <Flag className="size-4 text-destructive" />
        Signaler
      </Button>
      {open ? (
        <FeedbackDialog
          variant="contextual"
          screen={screen}
          scheduleId={scheduleId}
          screenLabel={screenLabel}
          activeFilters={activeFilters}
          displayedRange={displayedRange}
          onClose={() => setOpen(false)}
        />
      ) : null}
    </>
  );
}
