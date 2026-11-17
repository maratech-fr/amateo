import { AlertTriangle } from "lucide-react";

import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { useCanManage } from "@/shared/session/queries";

import type { VenueOccupancy, VenueTrainingSlot } from "../api";
import { occupanciesOverlappingSlots, occupancyLongWhen } from "../lib/slotOverlap";
import { WIZARD_TEXTS } from "../texts";

/**
 * Un créneau et une occupation superposés restent tous les deux (décision fondateur) : ce bandeau
 * le signale au gestionnaire, pour le gymnase affiché, et lui dit quel bloc bouger. Il naît d'une
 * copie, d'une version chargée ou de deux gestionnaires — jamais de la saisie, que le serveur
 * refuse. Rien pour un Membre, rien sans chevauchement. Les deux listes sont celles du gymnase et
 * de la couche affichés.
 */
export function OccupancyOverlapNotice({ slots, occupancies }: { slots: VenueTrainingSlot[]; occupancies: VenueOccupancy[] }) {
  const canManage = useCanManage();
  const overlapping = occupanciesOverlappingSlots(slots, occupancies);
  if (!canManage || 0 === overlapping.length) {
    return null;
  }

  const message =
    1 === overlapping.length
      ? WIZARD_TEXTS.occupancyOverlapOne(overlapping[0].label, occupancyLongWhen(overlapping[0]))
      : WIZARD_TEXTS.occupancyOverlapMany(overlapping.map((o) => WIZARD_TEXTS.occupancyOverlapItem(o.label, occupancyLongWhen(o))));

  return <NoticeBanner tone="warning" role="status" className="mt-2" icon={<AlertTriangle className="size-4 text-warning" />} message={message} />;
}
