import { Modal } from "@/shared/components/ui/modal";
import { TeamLinksSection } from "@/features/matches/TeamLinksSection";

import type { PriorityTier, Team } from "../api";
import { MutualisationPanel } from "./MutualisationPanel";
import { SharedTrainingBlockPanel } from "./SharedTrainingBlockPanel";

/**
 * Liens d'une équipe (P2-45) — l'écran unique par équipe : passerelles PUIS mutualisation.
 * Ouvert depuis l'étape Équipes (saison via `TeamsEditor`, période via `PeriodTeamsPanel`).
 *
 * Deux sections, deux portées :
 *  - les PASSERELLES sont de la structure de SAISON — éditables en saison, LECTURE SEULE en
 *    période/vacances (`readOnlyLinks`), filtrées à l'équipe d'ouverture ;
 *  - la MUTUALISATION est ancrée au PLAN (`schedulePlanId` : `null` = socle, un UUID = période),
 *    donc éditable partout. L'équipe d'ouverture y est pré-cochée (`initialTeamId`), et le bouton
 *    « Gérer les passerelles » y est masqué (`hideLinksManager`) — la section passerelles est
 *    juste au-dessus, jamais un dialog dans le dialog.
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
          <MutualisationPanel teams={teams} tiers={tiers} pausedTeamIds={pausedTeamIds} schedulePlanId={schedulePlanId} initialTeamId={team.id} hideLinksManager />
        </section>

        {/* P2-51 — le BLOC est une notion DISTINCTE (D9), sœur de la mutualisation ci-dessus mais
            jamais la même vérité : les deux coexistent. Titre propre (« Bloc d'équipes », jamais un
            second « Mutualisation ») + son propre helper pour que le gestionnaire sache d'un coup
            d'œil laquelle utiliser (garde-fou UX du cadrage §3). */}
        <section className="flex flex-col gap-2 border-t border-border pt-4">
          <h3 className="text-sm font-semibold">Bloc d'équipes</h3>
          <SharedTrainingBlockPanel teams={teams} tiers={tiers} pausedTeamIds={pausedTeamIds} schedulePlanId={schedulePlanId} initialTeamId={team.id} />
        </section>
      </div>
    </Modal>
  );
}
