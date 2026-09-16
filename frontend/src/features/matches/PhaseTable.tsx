import { Filter, Link2 } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { EmptyState } from "@/shared/components/ui/empty-hint";

import type { Competition, Conflict, Fixture, Team, Venue } from "./api";
import type { CoachTeamRole } from "./lib/matchFilter";
import { MatchRowsTable } from "./MatchRowsTable";

interface MatchRowsGroup {
  key: string;
  label: string;
  fixtures: Fixture[];
}

interface PhaseTableProps {
  /** Nombre de compétitions appariées (0 = état « aucune compétition »). */
  phaseCount: number;
  /** Groupes déjà bucketés par journée par la page (chaîne Consulter). */
  groups: MatchRowsGroup[];
  /** Compétition appariée affichée (pour le libellé de la table). */
  competition: Competition | undefined;
  teams: Map<string, Team>;
  venues: Map<string, Venue>;
  /** fixtureId → conflits déjà scopés à la phase ET filtrés par famille. */
  conflictsByFixture: Map<string, Conflict[]>;
  coachRoles?: Map<string, CoachTeamRole>;
  onSelectFixture: (fixtureId: string) => void;
  /** Ouvre le dialogue « Engagements FFBB » (état « aucune compétition appariée »). */
  onOpenFfbb: () => void;
}

/**
 * PR 3b — la temporalité PHASE du Calendrier : la table des journées d'une
 * compétition appariée (`MatchRowsTable`). Extraite de l'ancien onglet Consulter :
 * trois états (aucune compétition appariée · aucune rencontre sur la phase · table),
 * LECTURE des données déjà dérivées par la page.
 */
export function PhaseTable({ phaseCount, groups, competition, teams, venues, conflictsByFixture, coachRoles, onSelectFixture, onOpenFfbb }: PhaseTableProps) {
  if (0 === phaseCount) {
    return (
      <div className="flex flex-col items-start gap-3">
        <EmptyState icon={Link2} title="Aucune compétition appariée" description="Appariez une compétition FFBB à une de vos équipes pour la consulter par phase." />
        <Button variant="outline" size="sm" onClick={onOpenFfbb}>
          <Link2 className="size-4" />
          Engagements FFBB
        </Button>
      </div>
    );
  }
  if (0 === groups.length) {
    return <EmptyState icon={Filter} title="Aucun match pour cette phase" description="Aucune rencontre importée sur cette compétition. Choisissez une autre phase ou ajustez les filtres." />;
  }
  return (
    <MatchRowsTable
      caption={`Journées de ${competition?.name ?? "la phase"}`}
      groups={groups}
      teams={teams}
      venues={venues}
      conflictsByFixture={conflictsByFixture}
      coachRoles={coachRoles}
      onSelectFixture={onSelectFixture}
    />
  );
}
