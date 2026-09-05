import { useState } from "react";
import { Send } from "lucide-react";

import type { CalendarEntry } from "@/features/cockpit/api";
import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";

import type { CoachWishCampaign } from "./campaignApi";
import { CampaignDialog } from "./CampaignDialog";

interface RadarCoachWishActionProps {
  entry: CalendarEntry;
  season: { startDate: string; endDate: string } | null;
  /** Campagne déjà créée pour cette période (résolue par le radar), ou null. */
  campaign: CoachWishCampaign | null;
}

/**
 * Sur une carte vacances du radar (feature #10, lot C2) : bouton « Solliciter les coachs »
 * + badge de suivi « X/Y coachs ont répondu · N à traiter » dès qu'une campagne existe.
 * Le gestionnaire copie ensuite chaque lien dans WhatsApp depuis le dialog.
 */
export function RadarCoachWishAction({ entry, season, campaign }: RadarCoachWishActionProps) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <Button variant="ghost" size="sm" onClick={() => setOpen(true)}>
        <Send className="size-4" />
        Solliciter les coachs
      </Button>
      {null !== campaign ? (
        <StatusPill variant="accent">
          {campaign.respondedCoachCount}/{campaign.totalCoachCount} coachs ont répondu{campaign.openWishCount > 0 ? ` · ${campaign.openWishCount} à traiter` : ""}
        </StatusPill>
      ) : null}
      {open ? <CampaignDialog entry={entry} season={season} existing={campaign} onClose={() => setOpen(false)} /> : null}
    </>
  );
}
