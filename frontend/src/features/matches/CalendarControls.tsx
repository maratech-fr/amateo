import { CalendarCheck2, ChevronLeft, ChevronRight } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { Select } from "@/shared/components/ui/select";
import { todayISO } from "@/shared/lib/clock";
import { cn } from "@/shared/lib/utils";

import type { ConflictType } from "./api";
import { CONFLICT_FAMILIES, CONFLICT_FAMILY_LABEL } from "./lib/conflictLabels";
import { KINDS, type Kind } from "./lib/consultFilter";
import { monthLabel } from "./lib/monthView";
import { weekendKeyOf, weekLabel } from "./lib/weekendGrid";
import { useMatchesStore, type ConsultTemporality } from "./store";

const KIND_LABEL: Record<Kind, string> = { amical: "Amical", championnat: "Championnat", coupe: "Coupe", brassage: "Brassage" };
const TEMPORALITIES: ConsultTemporality[] = ["semaine", "mois", "phase"];
const TEMPORALITY_LABEL: Record<ConsultTemporality, string> = { semaine: "Semaine", mois: "Mois", phase: "Phase" };

interface CalendarControlsProps {
  /** Familles de conflits PRÉSENTES sur la temporalité affichée (chips visibles). */
  familyChips: ConflictType[];
  familyCounts: Map<ConflictType, number>;
  weekends: string[];
  activeWeekend: string | null;
  weekendIndex: number;
  months: string[];
  activeMonth: string | null;
  monthIndex: number;
  phases: { competitionId: string; label: string }[];
  activePhaseId: string | null;
  completeness: { imported: number; expected: number | null } | null;
  depositReminder: string;
}

/**
 * PR 3b — les contrôles du Calendrier : chips de type de compétition + interrupteur
 * « Semaine type » (Semaine seule), chips de familles de conflits (compteur), contrôle
 * segmenté Semaine·Mois·Phase, navigateur ‹ › + « Aujourd'hui » propre à la temporalité,
 * et rappel de fraîcheur. Lit l'état Consulter + `selectedWeekend` du store ; PRÉSENTATION
 * pure (les compteurs/chips arrivent déjà dérivés par la page).
 */
export function CalendarControls(props: CalendarControlsProps) {
  const { familyChips, familyCounts, weekends, activeWeekend, weekendIndex, months, activeMonth, monthIndex, phases, activePhaseId, completeness, depositReminder } = props;
  const {
    consultKinds,
    consultFamilies,
    consultTypicalWeek,
    consultTemporality,
    consultMonth,
    setConsultKinds,
    setConsultFamilies,
    setConsultTypicalWeek,
    setConsultTemporality,
    setConsultMonth,
    setConsultPhaseId,
    selectedWeekend,
    setSelectedWeekend,
  } = useMatchesStore();

  const isWeek = "semaine" === consultTemporality;
  const isMonth = "mois" === consultTemporality;
  const isPhase = "phase" === consultTemporality;
  const todayWeekKey = weekendKeyOf(todayISO());
  const todayMonthKey = todayISO().slice(0, 7);
  const chipClass = (checked: boolean): string => cn("h-7", checked ? "" : "text-muted-foreground");

  const isKindChecked = (kind: Kind): boolean => null === consultKinds || consultKinds.includes(kind);
  const toggleKind = (kind: Kind): void => {
    const active = new Set<Kind>(consultKinds ?? KINDS);
    if (active.has(kind)) {
      active.delete(kind);
    } else {
      active.add(kind);
    }
    const next = KINDS.filter((k) => active.has(k));
    setConsultKinds(next.length === KINDS.length ? null : next);
  };
  const isFamilyChecked = (family: ConflictType): boolean => null === consultFamilies || consultFamilies.includes(family);
  const toggleFamily = (family: ConflictType): void => {
    const active = new Set<ConflictType>(consultFamilies ?? CONFLICT_FAMILIES);
    if (active.has(family)) {
      active.delete(family);
    } else {
      active.add(family);
    }
    const next = CONFLICT_FAMILIES.filter((f) => active.has(f));
    setConsultFamilies(next.length === CONFLICT_FAMILIES.length ? null : next);
  };

  return (
    <>
      {/* Chips type de compétition + interrupteur « Semaine type » (Semaine seule). */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex items-center gap-1 rounded-md border border-border p-0.5">
          {KINDS.map((kind) => (
            <Button key={kind} type="button" size="sm" aria-pressed={isKindChecked(kind)} variant={isKindChecked(kind) ? "default" : "ghost"} className={chipClass(isKindChecked(kind))} onClick={() => toggleKind(kind)}>
              {KIND_LABEL[kind]}
            </Button>
          ))}
        </div>
        {isWeek ? (
          <Button
            type="button"
            role="switch"
            size="sm"
            aria-checked={consultTypicalWeek}
            variant={consultTypicalWeek ? "default" : "ghost"}
            className={cn("h-7 gap-1.5", consultTypicalWeek ? "" : "text-muted-foreground")}
            onClick={() => setConsultTypicalWeek(!consultTypicalWeek)}
          >
            <CalendarCheck2 className="size-3.5" aria-hidden="true" />
            Semaine type
          </Button>
        ) : null}
      </div>

      {/* Chips familles de conflits présentes, avec compteur. */}
      {familyChips.length > 0 ? (
        <div role="group" aria-label="Familles de conflits" className="flex flex-wrap items-center gap-1.5">
          {familyChips.map((family) => (
            <Button
              key={family}
              type="button"
              size="sm"
              aria-pressed={isFamilyChecked(family)}
              variant={isFamilyChecked(family) ? "default" : "ghost"}
              className={cn("h-7 gap-1.5 border border-border", isFamilyChecked(family) ? "" : "text-muted-foreground")}
              onClick={() => toggleFamily(family)}
            >
              {CONFLICT_FAMILY_LABEL[family]}
              <span className={cn("tabular-nums text-xs", 0 === (familyCounts.get(family) ?? 0) ? "text-muted-foreground" : undefined)}>{familyCounts.get(family) ?? 0}</span>
            </Button>
          ))}
        </div>
      ) : null}

      {/* Contrôle segmenté de temporalité + navigateur propre + rappel de fraîcheur. */}
      <div className="flex flex-wrap items-center gap-2">
        <div role="group" aria-label="Temporalité" className="flex items-center gap-1 rounded-md border border-border p-0.5">
          {TEMPORALITIES.map((temporality) => (
            <Button
              key={temporality}
              type="button"
              size="sm"
              aria-pressed={temporality === consultTemporality}
              variant={temporality === consultTemporality ? "default" : "ghost"}
              className={chipClass(temporality === consultTemporality)}
              onClick={() => setConsultTemporality(temporality)}
            >
              {TEMPORALITY_LABEL[temporality]}
            </Button>
          ))}
        </div>

        {isWeek ? (
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" disabled={weekendIndex <= 0} onClick={() => setSelectedWeekend(weekends[weekendIndex - 1] ?? null)} aria-label="Semaine précédente">
              <ChevronLeft className="size-4" />
            </Button>
            <span className="text-sm font-medium">{null === activeWeekend ? "Aucun match" : weekLabel(activeWeekend)}</span>
            <Button variant="outline" size="sm" disabled={weekendIndex < 0 || weekendIndex >= weekends.length - 1} onClick={() => setSelectedWeekend(weekends[weekendIndex + 1] ?? null)} aria-label="Semaine suivante">
              <ChevronRight className="size-4" />
            </Button>
            <Button variant="ghost" size="sm" disabled={null === selectedWeekend || activeWeekend === todayWeekKey} onClick={() => setSelectedWeekend(null)} aria-label="Revenir à la semaine d'aujourd'hui">
              Aujourd'hui
            </Button>
          </div>
        ) : null}

        {isMonth ? (
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" disabled={monthIndex <= 0} onClick={() => setConsultMonth(months[monthIndex - 1] ?? null)} aria-label="Mois précédent">
              <ChevronLeft className="size-4" />
            </Button>
            <span className="text-sm font-medium first-letter:uppercase">{null === activeMonth ? "Aucun match" : monthLabel(activeMonth)}</span>
            <Button variant="outline" size="sm" disabled={monthIndex < 0 || monthIndex >= months.length - 1} onClick={() => setConsultMonth(months[monthIndex + 1] ?? null)} aria-label="Mois suivant">
              <ChevronRight className="size-4" />
            </Button>
            <Button variant="ghost" size="sm" disabled={null === consultMonth || activeMonth === todayMonthKey} onClick={() => setConsultMonth(null)} aria-label="Revenir au mois d'aujourd'hui">
              Aujourd'hui
            </Button>
          </div>
        ) : null}

        {isPhase && phases.length > 0 ? (
          <div className="flex items-center gap-3">
            <div className="w-64">
              <Select aria-label="Phase (compétition appariée)" value={activePhaseId ?? ""} onChange={(e) => setConsultPhaseId(e.target.value)}>
                {phases.map((phase) => (
                  <option key={phase.competitionId} value={phase.competitionId}>
                    {phase.label}
                  </option>
                ))}
              </Select>
            </div>
            {null !== completeness ? (
              <span className="text-sm font-medium text-foreground tabular-nums">
                {null === completeness.expected
                  ? `${completeness.imported} journée${completeness.imported > 1 ? "s" : ""} importée${completeness.imported > 1 ? "s" : ""}`
                  : `${completeness.imported} / ${completeness.expected} journées importées`}
              </span>
            ) : null}
          </div>
        ) : null}

        <span className="ml-auto text-xs text-muted-foreground">{depositReminder}</span>
      </div>
    </>
  );
}
