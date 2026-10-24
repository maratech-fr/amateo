import { useState } from "react";

import { Modal } from "@/shared/components/ui/modal";
import { Tabs, TabPanel } from "@/shared/components/ui/tabs";
import { TeamLinksSection } from "@/features/matches/TeamLinksSection";

import type { PriorityTier, Team } from "../api";
import { SharedTrainingBlockPanel } from "./SharedTrainingBlockPanel";

/**
 * Liens d'une équipe (P2-45) — l'écran unique par équipe : passerelles ET mutualisation.
 * Ouvert depuis l'étape Équipes (saison via `TeamsEditor`, période via `PeriodTeamsPanel`).
 *
 * #10 (améliorations légères, retour Rillieux 2026-10-21) — les deux liens vivaient dans le MÊME
 * corps de modale, trop chargé : ils sont désormais SÉPARÉS en deux ONGLETS (primitive `Tabs`),
 * « Passerelles » actif par défaut (l'ordre historique haut → bas). Les DEUX panneaux restent
 * montés (l'inactif est `hidden`), chacun garde son propre état.
 *
 * Deux liens, deux onglets (correction fondateur D13 : mutualisation et « bloc » = UNE seule
 * notion — le modèle groupe {équipes, K} a été retiré, le BLOC est la seule mutualisation) :
 *  - les PASSERELLES sont de la structure de SAISON — éditables en saison, LECTURE SEULE en
 *    période/vacances (`readOnlyLinks`), filtrées à l'équipe d'ouverture ;
 *  - la MUTUALISATION (rendue par `SharedTrainingBlockPanel` — « bloc » est l'image interne côté
 *    backend) est ancrée au PLAN (`schedulePlanId` : `null` = socle, un UUID = période), donc
 *    éditable partout. L'équipe d'ouverture y est pré-cochée (`initialTeamId`).
 */
export function TeamLinksModal({
  team,
  teams,
  tiers,
  schedulePlanId,
  pausedTeamIds,
  readOnlyLinks,
  onClose,
}: {
  team: Pick<Team, "id" | "name">;
  teams: Team[];
  tiers: PriorityTier[];
  /** `null` = socle (saison) ; un UUID = plan de période. Gouverne l'écriture de la mutualisation. */
  schedulePlanId: string | null;
  pausedTeamIds?: ReadonlySet<string>;
  /** Passerelles en lecture seule (période/vacances) : la saison seule les déclare. */
  readOnlyLinks: boolean;
  onClose: () => void;
}) {
  const [activeTab, setActiveTab] = useState<"passerelles" | "mutualisation">("passerelles");

  return (
    <Modal label={`Liens de ${team.name}`} title={`Liens de ${team.name}`} onClose={onClose} size="lg">
      <div className="flex flex-col gap-4">
        <Tabs
          tabs={[
            { id: "passerelles", label: "Passerelles" },
            { id: "mutualisation", label: "Mutualisation" },
          ]}
          activeTab={activeTab}
          onTabChange={(id) => setActiveTab(id as "passerelles" | "mutualisation")}
          ariaLabel="Type de lien"
          idPrefix="team-links"
        />
        <TabPanel tabId="passerelles" idPrefix="team-links" active={"passerelles" === activeTab}>
          <TeamLinksSection teams={teams} tiers={tiers} filterTeamId={team.id} readOnly={readOnlyLinks} />
        </TabPanel>
        <TabPanel tabId="mutualisation" idPrefix="team-links" active={"mutualisation" === activeTab}>
          <SharedTrainingBlockPanel teams={teams} tiers={tiers} pausedTeamIds={pausedTeamIds} schedulePlanId={schedulePlanId} initialTeamId={team.id} />
        </TabPanel>
      </div>
    </Modal>
  );
}
