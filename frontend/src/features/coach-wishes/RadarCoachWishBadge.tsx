import { StatusPill } from "@/shared/components/ui/badge";

import type { CoachWishCampaign } from "./campaignApi";

/**
 * Badge de suivi d'une collecte de doléances sur une carte vacances du radar (feature #10) :
 * « X/Y coachs ont répondu · N à traiter » dès qu'une campagne existe. Depuis la fusion de la
 * fenêtre (2026-10-09), il ne porte plus de bouton : l'ouverture passe par l'unique bouton
 * « Doléances » de la carte (→ `CoachWishesHub`). Le badge reste VISIBLE sans clic et HORS du
 * repli (le compteur de collecte n'existe nulle part ailleurs — revue #344).
 */
export function RadarCoachWishBadge({ campaign }: { campaign: CoachWishCampaign | null }) {
  if (null === campaign) {
    return null;
  }
  return (
    <StatusPill variant="accent">
      {campaign.respondedCoachCount}/{campaign.totalCoachCount} coachs ont répondu{campaign.openWishCount > 0 ? ` · ${campaign.openWishCount} à traiter` : ""}
    </StatusPill>
  );
}
