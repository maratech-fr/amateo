import { Coins } from "lucide-react";
import { Link } from "react-router";

import { StatusPill } from "@/shared/components/ui/badge";

import { useCredits } from "./useCredits";

const TOOLTIP = "Une génération, un placement de matchs ou un export consomme 1 crédit — ajuster et consulter sont gratuits.";

/**
 * P1-3 §4bis pt 1 — la pastille d'OFFRE du club dans le shell, cas Découverte bridée SEULEMENT
 * (rien du tout en payant/bêta/démo, où `useCredits()` rend null). Elle nomme l'offre et son solde
 * (« Découverte · N crédits ») en AMBRE PERMANENT (variante `warning` : l'offre bridée est un état
 * qui appelle une action, indépendamment du solde), et renvoie vers `/club` — le seul écran où
 * consulter/faire évoluer l'offre. La valeur vient du serveur (`entitlements`) — aucun recalcul de
 * règle ici (P2-8) ; l'ICÔNE porte l'ambre, le texte reste `text-foreground` (repli AA, cf.
 * `badge.tsx`). L'annonce du lien enrichit le texte visible (solde exact + explication), d'où
 * l'`aria-label` porté par le `Link`.
 */
export function CreditBadge() {
  const credits = useCredits();
  if (null === credits) {
    return null;
  }

  const unit = 1 === credits.remaining ? "crédit" : "crédits";
  return (
    <Link
      to="/club"
      title={TOOLTIP}
      aria-label={`Offre Découverte — crédits gratuits restants : ${credits.remaining} sur ${credits.max}. ${TOOLTIP}`}
      className="shrink-0 rounded-full transition-opacity hover:opacity-80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
    >
      <StatusPill variant="warning" icon={<Coins className="size-3.5 text-warning" aria-hidden="true" />}>
        {`Découverte · ${credits.remaining} ${unit}`}
      </StatusPill>
    </Link>
  );
}
