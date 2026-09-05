import { Pencil, Wand2 } from "lucide-react";

import { StatusPill } from "@/shared/components/ui/badge";

/**
 * SourceBadge — l'origine d'une valeur : AUTO (calculée) ou MANUEL (saisie). Icône + mot, jamais la
 * couleur seule (WCAG 1.4.1). Maison UNIQUE (P4-177) : la matrice de trajets entre gymnases
 * (`wizard/steps/TravelMatrixModal`) et les trajets adverses (`matches/OpponentTravelCard`) en
 * partageaient deux copies. Adossé à la pastille partagée `StatusPill` :
 *  - AUTO → variante `neutral` (état informatif) ;
 *  - MANUEL → variante `accent` (couleur du club) ; l'ambre… pardon, l'accent est porté par l'icône
 *    `text-accent` (le texte est `text-foreground` pour l'AA — cf. `badge.tsx`).
 */
export function SourceBadge({ source }: { source: "AUTO" | "MANUAL" }) {
  return "AUTO" === source ? (
    <StatusPill icon={<Wand2 className="size-3.5" aria-hidden="true" />}>Auto</StatusPill>
  ) : (
    <StatusPill variant="accent" icon={<Pencil className="size-3.5 text-accent" aria-hidden="true" />}>
      Manuel
    </StatusPill>
  );
}
