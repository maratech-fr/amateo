import { Modal } from "@/shared/components/ui/modal";
import { TeamLinksSection } from "@/features/matches/TeamLinksSection";

import type { PriorityTier, Team } from "../api";
import { SharedTrainingBlockPanel } from "./SharedTrainingBlockPanel";

/**
 * Liens d'une équipe (P2-45) — l'écran unique par équipe : passerelles PUIS mutualisation.
 * Ouvert depuis l'étape Équipes (saison via `TeamsEditor`, période via `PeriodTeamsPanel`).
 *
 * Deux liens, deux sections (correction fondateur D13 : mutualisation et « bloc » = UNE seule
 * notion ; l'ancien `MutualisationPanel` du modèle {équipes, K} ne se monte plus ici — il vit encore
 * sous le capot jusqu'au nettoyage PR-7) :
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
  return (
    <Modal label={`Liens de ${team.name}`} title={`Liens de ${team.name}`} onClose={onClose} size="lg">
      <div className="flex flex-col gap-5">
        <TeamLinksSection teams={teams} tiers={tiers} filterTeamId={team.id} readOnly={readOnlyLinks} />

        <section className="flex flex-col gap-2 border-t border-border pt-4">
          <h3 className="text-sm font-semibold">Mutualisation</h3>
          <SharedTrainingBlockPanel teams={teams} tiers={tiers} pausedTeamIds={pausedTeamIds} schedulePlanId={schedulePlanId} initialTeamId={team.id} />
        </section>
      </div>
    </Modal>
  );
}
