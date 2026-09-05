import { Coins } from "lucide-react";

import { StatusPill } from "@/shared/components/ui/badge";
import { cn } from "@/shared/lib/utils";

import { useCredits } from "./useCredits";

const TOOLTIP = "Une génération, un placement de matchs ou un export consomme 1 crédit — ajuster et consulter sont gratuits.";

/**
 * P1-3 §4bis pt 1 — compteur permanent de crédits dans le shell (Découverte
 * bridée SEULEMENT ; rien du tout en payant/bêta/démo, où `useCredits()` rend
 * null). Passe en AMBRE dès qu'il reste ≤ 5 crédits. La valeur vient du serveur
 * (`entitlements`) — aucun recalcul de règle ici (P2-8).
 */
export function CreditBadge() {
  const credits = useCredits();
  if (null === credits) {
    return null;
  }

  const low = credits.remaining <= 5;
  // ≤ 5 → AMBRE : variante `warning` de la pastille partagée (le texte passe `text-foreground` pour
  // l'AA ; c'est l'ICÔNE qui porte l'ambre — cf. `badge.tsx`). Le compte a une annonce plus riche
  // que son texte visible, donc on transmet `aria-label` et `title`.
  return (
    <StatusPill
      variant={low ? "warning" : "neutral"}
      title={TOOLTIP}
      aria-label={`Crédits gratuits restants : ${credits.remaining} sur ${credits.max}. ${TOOLTIP}`}
      icon={<Coins className={cn("size-3.5", low && "text-warning")} aria-hidden="true" />}
    >
      Crédits : {credits.remaining}/{credits.max}
    </StatusPill>
  );
}
