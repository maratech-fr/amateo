import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";

import type { PriorityTier, Team } from "./api";
import { TeamLinksSection } from "./TeamLinksSection";

interface HabitsLinksDialogProps {
  teams: Team[];
  tiers: PriorityTier[];
  onClose: () => void;
}

/**
 * Passerelles (P1-4 PR C, refondu P4-271) — les liens déclarés entre équipes qui partagent des
 * joueurs. Les CRÉNEAUX IDÉAUX (ex-« habitudes ») ne se saisissent plus ici : ils ont UNE seule
 * maison, l'éditeur en place `IdealSlotsEditor` de la Semaine type. Cette modale ne porte donc
 * plus que la section passerelles (éditable ici, en lecture seule dans le wizard).
 */
export function HabitsLinksDialog({ teams, tiers, onClose }: HabitsLinksDialogProps) {
  return (
    <Modal
      label="Passerelles"
      title="Passerelles"
      onClose={onClose}
      size="lg"
      footer={
        <Button variant="outline" size="sm" onClick={onClose}>
          Fermer
        </Button>
      }
    >
      <div className="flex flex-col gap-4">
        <TeamLinksSection teams={teams} tiers={tiers} />
      </div>
    </Modal>
  );
}
