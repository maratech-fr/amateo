import { ResourceFilter } from "@/features/planning/ResourceFilter";
import type { GridResourceGroup } from "@/features/planning/lib/grid";
import { SegmentedControl } from "@/shared/components/ui/segmented-control";
import { groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";

import type { Coach, PriorityTier, Team, Venue } from "./api";
import type { MatchFilterMode } from "./lib/matchFilter";

/**
 * PR-1 — la barre de filtres de la vue Semaine : un contrôle segmenté (axe :
 * équipe/coach/gymnase) accolé à la puce `ResourceFilter` de planning, réutilisée
 * TELLE QUELLE (son libellé suit l'axe courant — patron P4-43). Changer d'axe VIDE
 * la sélection (géré par le store). Les groupes se construisent comme dans
 * `PlanningPage` : équipes groupées par rang (S/A/B/C/D), coachs et gymnases à plat.
 */
const SEGMENTS: { key: MatchFilterMode; label: string }[] = [
  { key: "equipe", label: "Par équipe" },
  { key: "coach", label: "Par coach" },
  { key: "gymnase", label: "Par gymnase" },
];

interface MatchesFilterBarProps {
  mode: MatchFilterMode;
  selected: string[];
  teams: Team[];
  coaches: Coach[];
  venues: Venue[];
  tiers: PriorityTier[];
  onModeChange: (mode: MatchFilterMode) => void;
  onToggle: (id: string) => void;
  onClear: () => void;
}

function groupsFor(mode: MatchFilterMode, teams: Team[], coaches: Coach[], venues: Venue[], tiers: PriorityTier[]): GridResourceGroup[] {
  if ("equipe" === mode) {
    return groupTeamsByTier(teams, tiers).map((g) => ({ label: tierGroupLabel(g.tier), resources: g.teams.map((t) => ({ id: t.id, label: t.name })) }));
  }
  if ("coach" === mode) {
    return [{ label: null, resources: coaches.map((c) => ({ id: c.id, label: `${c.firstName} ${c.lastName}`.trim() })) }];
  }
  return [{ label: null, resources: venues.map((v) => ({ id: v.id, label: v.name })) }];
}

export function MatchesFilterBar({ mode, selected, teams, coaches, venues, tiers, onModeChange, onToggle, onClear }: MatchesFilterBarProps) {
  const groups = groupsFor(mode, teams, coaches, venues, tiers);
  return (
    <div className="flex flex-wrap items-center gap-2">
      {/* Contrôle segmenté partagé — axe équipe/coach/gymnase (choix exclusif). */}
      <SegmentedControl mode="single" label="Filtrer par" options={SEGMENTS} value={mode} onValueChange={onModeChange} className="flex-nowrap" />
      <ResourceFilter viewMode={mode} groups={groups} selected={selected} onToggle={onToggle} onClear={onClear} />
    </div>
  );
}
