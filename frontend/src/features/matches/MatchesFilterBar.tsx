import { ResourceFilter } from "@/features/planning/ResourceFilter";
import type { GridResourceGroup } from "@/features/planning/lib/grid";
import { Button } from "@/shared/components/ui/button";
import { groupTeamsByTier, tierGroupLabel } from "@/shared/lib/teamTiers";
import { cn } from "@/shared/lib/utils";

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
      {/* Contrôle segmenté (patron VIEWS de PlanningToolbar) — `aria-pressed` porte
          l'état à l'AT, l'axe courant est en variante pleine. */}
      <div className="flex items-center gap-1 rounded-md border border-border p-0.5">
        {SEGMENTS.map((segment) => (
          <Button
            key={segment.key}
            type="button"
            size="sm"
            aria-pressed={segment.key === mode}
            variant={segment.key === mode ? "default" : "ghost"}
            className={cn("h-7", segment.key === mode ? "" : "text-muted-foreground")}
            onClick={() => onModeChange(segment.key)}
          >
            {segment.label}
          </Button>
        ))}
      </div>
      <ResourceFilter viewMode={mode} groups={groups} selected={selected} onToggle={onToggle} onClear={onClear} />
    </div>
  );
}
