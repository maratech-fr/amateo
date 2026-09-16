import { Filter } from "lucide-react";

import { EmptyState } from "@/shared/components/ui/empty-hint";

import type { Conflict, Fixture, Team, Venue } from "./api";
import type { CoachTeamRole } from "./lib/matchFilter";
import { MatchRowsTable } from "./MatchRowsTable";

interface MatchRowsGroup {
  key: string;
  label: string;
  fixtures: Fixture[];
}

interface MonthTableProps {
  /** Mois affiché `YYYY-MM` ; null = aucun match. */
  activeMonth: string | null;
  /** Libellé FR du mois affiché (ex. « octobre 2026 »). */
  monthLabel: string;
  /** Groupes déjà bucketés PAR JOUR par la page (chaîne Consulter). */
  groups: MatchRowsGroup[];
  teams: Map<string, Team>;
  venues: Map<string, Venue>;
  /** fixtureId → conflits déjà scopés au mois ET filtrés par famille. */
  conflictsByFixture: Map<string, Conflict[]>;
  coachRoles?: Map<string, CoachTeamRole>;
  filterActive: boolean;
  onSelectFixture: (fixtureId: string) => void;
}

/**
 * PR 3b — la temporalité MOIS du Calendrier : une table groupée par jour
 * (`MatchRowsTable`, maison partagée). Extraite de l'ancien onglet Consulter,
 * LECTURE des données déjà dérivées par la page (aucune re-dérivation ici).
 */
export function MonthTable({ activeMonth, monthLabel, groups, teams, venues, conflictsByFixture, coachRoles, filterActive, onSelectFixture }: MonthTableProps) {
  if (0 === groups.length) {
    return (
      <EmptyState
        icon={Filter}
        title={null === activeMonth ? "Aucun match" : `Aucun match ${filterActive ? "pour ce filtre " : ""}en ${monthLabel}`}
        description="Aucun match placé sur le mois affiché. Changez de mois ou ajustez les filtres."
      />
    );
  }
  return (
    <MatchRowsTable
      caption={`Matchs de ${null === activeMonth ? "" : monthLabel}`}
      groups={groups}
      teams={teams}
      venues={venues}
      conflictsByFixture={conflictsByFixture}
      coachRoles={coachRoles}
      onSelectFixture={onSelectFixture}
    />
  );
}
