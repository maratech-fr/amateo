import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";

import { DAY_LABELS, frDate, isSectionDirty, sectionKey, type SectionState } from "./wishSections";

interface WishRecapProps {
  teams: { id: string; name: string }[];
  weeks: string[];
  sections: Map<string, SectionState>;
  initial: Map<string, SectionState>;
  onEditTeam: (teamId: string) => void;
}

const dayLabel = (day: number): string => DAY_LABELS.find((d) => d.day === day)?.label ?? String(day);

/** Résumé lisible d'une semaine modifiée. */
function weekSummary(s: SectionState): string {
  const parts = [`${s.slotsWanted} séance${s.slotsWanted > 1 ? "s" : ""}`];
  if (s.days.size > 0) {
    parts.push(`indispo ${[...s.days].sort((a, b) => a - b).map(dayLabel).join(", ")}`);
  }
  return parts.join(" · ");
}

/**
 * Récapitulatif avant envoi (lot E, P2-24) — une ligne par équipe. Les équipes non
 * touchées affichent « aucune modification » (le dirty-tracking fait déjà le tri).
 * « Modifier » saute à l'étape de l'équipe puis revient droit ici.
 */
export function WishRecap({ teams, weeks, sections, initial, onEditTeam }: WishRecapProps) {
  return (
    <ul className="space-y-3">
      {teams.map((team) => {
        const changedWeeks = weeks.filter((week) => isSectionDirty(sections.get(sectionKey(team.id, week)), initial.get(sectionKey(team.id, week))));
        return (
          <li key={team.id} className="rounded-lg border border-border bg-card p-3">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="text-sm font-medium">{team.name}</p>
                {0 === changedWeeks.length ? (
                  <EmptyHint className="mt-0.5">aucune modification</EmptyHint>
                ) : (
                  <ul className="mt-0.5 space-y-0.5 text-sm text-muted-foreground">
                    {changedWeeks.map((week) => (
                      <li key={week}>
                        semaine du {frDate(week)} : {weekSummary(sections.get(sectionKey(team.id, week)) as SectionState)}
                      </li>
                    ))}
                  </ul>
                )}
              </div>
              <Button variant="ghost" size="sm" className="shrink-0" onClick={() => onEditTeam(team.id)}>
                Modifier
              </Button>
            </div>
          </li>
        );
      })}
    </ul>
  );
}
