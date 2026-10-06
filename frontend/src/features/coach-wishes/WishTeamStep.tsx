import { DayMultiPicker } from "@/shared/components/ui/day-multi-picker";
import { FIELD_CLASS } from "@/shared/components/ui/field";
import { Input } from "@/shared/components/ui/input";
import { cn } from "@/shared/lib/utils";

import { frDate, sectionKey, type SectionState } from "./wishSections";

interface WishTeamStepProps {
  team: { id: string; name: string };
  weeks: string[];
  sections: Map<string, SectionState>;
  onPatch: (key: string, next: Partial<SectionState>) => void;
  onToggleDay: (key: string, day: number) => void;
  onToggleWishedDay: (key: string, day: number) => void;
}

/**
 * Rejoue, jour par jour, l'écart entre l'ancienne sélection d'un `DayMultiPicker` et la
 * nouvelle — le parent gère un `Set` par bascule d'UN jour (l'exclusion souhaité/indispo
 * vit côté parent). Robuste même si plus d'un jour changeait.
 */
function replayToggles(before: Set<number>, next: number[], toggle: (day: number) => void): void {
  const after = new Set(next);
  for (const day of new Set([...before, ...after])) {
    if (before.has(day) !== after.has(day)) {
      toggle(day);
    }
  }
}

/**
 * Une étape du parcours = UNE équipe et ses semaines (lot E, P2-24). Reprend le markup
 * fieldset de la page unique historique — le coach ne voit plus que son équipe courante.
 */
export function WishTeamStep({ team, weeks, sections, onPatch, onToggleDay, onToggleWishedDay }: WishTeamStepProps) {
  return (
    <div className="space-y-4">
      {weeks.map((week) => {
        const key = sectionKey(team.id, week);
        const s = sections.get(key) as SectionState;
        return (
          <fieldset key={key} className="rounded-lg border border-border bg-card p-3">
            <legend className="px-1 text-sm font-medium">
              {team.name} · semaine du {frDate(week)}
            </legend>
            <label className="mt-2 flex items-center gap-2 text-sm">
              Séances souhaitées
              <Input
                type="number"
                min={0}
                max={7}
                aria-label={`Séances souhaitées — ${team.name}, semaine du ${frDate(week)}`}
                className="w-16"
                value={s.slotsWanted}
                onChange={(e) => onPatch(key, { slotsWanted: Math.max(0, Math.min(7, Number(e.target.value) || 0)) })}
              />
            </label>
            <div className="mt-2">
              <DayMultiPicker
                legend="Jours souhaités"
                legendVisible
                // Les deux saisies de vœux (page publique ET modale gestionnaire CoachWishForm)
                // sont en couleur du club (accent), jamais destructive (arbitrage fondateur 2026-10-01).
                tone="accent"
                value={[...s.wishedDays].sort((a, b) => a - b)}
                onChange={(next) => replayToggles(s.wishedDays, next, (day) => onToggleWishedDay(key, day))}
              />
            </div>
            <div className="mt-2">
              <DayMultiPicker
                legend="Jours d'indisponibilité"
                legendVisible
                tone="accent"
                value={[...s.days].sort((a, b) => a - b)}
                onChange={(next) => replayToggles(s.days, next, (day) => onToggleDay(key, day))}
              />
            </div>
            <label className="mt-2 block text-sm">
              <span className="text-muted-foreground">Commentaire</span>
              <textarea
                className={cn("mt-1", FIELD_CLASS)}
                rows={2}
                aria-label={`Commentaire — ${team.name}, semaine du ${frDate(week)}`}
                value={s.comment}
                onChange={(e) => onPatch(key, { comment: e.target.value })}
              />
            </label>
          </fieldset>
        );
      })}
    </div>
  );
}
