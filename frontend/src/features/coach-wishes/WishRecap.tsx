import { Button } from "@/shared/components/ui/button";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { dayLabelShort } from "@/shared/lib/days";

import type { PublicStaffMember } from "./publicApi";
import { COACH_WISHES_PUBLIC_TEXTS as TEXTS } from "./texts/public";
import type { MutualizationState } from "./wishMutualizations";
import { frDate, isSectionDirty, sectionKey, type SectionState } from "./wishSections";

interface WishRecapProps {
  teams: { id: string; name: string; staff: PublicStaffMember[] }[];
  weeks: string[];
  sections: Map<string, SectionState>;
  initial: Map<string, SectionState>;
  /** Mutualisations courantes par équipe + table de noms des partenaires (pour le résumé). */
  mutualizations: Map<string, MutualizationState>;
  partnerName: Map<string, string>;
  onEditTeam: (teamId: string) => void;
}

const dayLabel = (day: number): string => dayLabelShort(day) || String(day);

const daysList = (set: Set<number>): string[] => [...set].sort((a, b) => a - b).map(dayLabel);

/** Résumé lisible d'une semaine modifiée : une ligne par partie, une ligne par personne absente. */
function weekSummary(s: SectionState, staff: PublicStaffMember[]): string[] {
  const parts = [TEXTS.recapSlots(s.slotsWanted)];
  if (s.wishedDays.size > 0) {
    parts.push(TEXTS.recapWished(daysList(s.wishedDays)));
  }
  if (s.days.size > 0) {
    parts.push(TEXTS.recapTeamClosed(daysList(s.days)));
  }
  for (const member of staff) {
    if ((s.staff.get(member.coachId)?.size ?? 0) > 0) {
      parts.push(TEXTS.recapAbsent([{ firstName: member.firstName, dayLabels: daysList(s.staff.get(member.coachId) as Set<number>) }]));
    }
  }
  if (s.keepSeasonSlots) {
    parts.push(TEXTS.recapKeepsSlots);
  }
  return parts;
}

/**
 * Récapitulatif avant envoi (lot E, P2-24) — une ligne par équipe. Les équipes non
 * touchées affichent « aucune modification » (le dirty-tracking fait déjà le tri).
 * « Modifier » saute à l'étape de l'équipe puis revient droit ici.
 */
export function WishRecap({ teams, weeks, sections, initial, mutualizations, partnerName, onEditTeam }: WishRecapProps) {
  return (
    <ul className="space-y-3">
      {teams.map((team) => {
        const changedWeeks = weeks.filter((week) => isSectionDirty(sections.get(sectionKey(team.id, week)), initial.get(sectionKey(team.id, week))));
        const mutu = mutualizations.get(team.id);
        const partners = [...(mutu?.partnerTeamIds ?? new Set<string>())].map((id) => partnerName.get(id) ?? TEXTS.recapPartnerFallback).sort((a, b) => a.localeCompare(b));
        const nothing = 0 === changedWeeks.length && 0 === partners.length;
        return (
          <li key={team.id} className="rounded-lg border border-border bg-card p-3">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="text-sm font-medium">{team.name}</p>
                {nothing ? (
                  <EmptyHint className="mt-0.5">{TEXTS.recapNothing}</EmptyHint>
                ) : (
                  <ul className="mt-0.5 space-y-0.5 text-sm text-muted-foreground">
                    {partners.length > 0 ? (
                      <li>{TEXTS.recapMutualization(mutu?.sharedSlots ?? 0, partners)}</li>
                    ) : null}
                    {changedWeeks.map((week) => (
                      <li key={week}>
                        <span>{TEXTS.recapWeek(frDate(week))}</span>
                        <ul className="ml-4 list-disc">
                          {weekSummary(sections.get(sectionKey(team.id, week)) as SectionState, team.staff).map((line) => (
                            <li key={line}>{line}</li>
                          ))}
                        </ul>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
              <Button variant="ghost" size="sm" className="shrink-0" onClick={() => onEditTeam(team.id)}>
                {TEXTS.recapEdit}
              </Button>
            </div>
          </li>
        );
      })}
    </ul>
  );
}
