import { Bus, CalendarCheck2, ChevronLeft, ChevronRight, RotateCcw } from "lucide-react";
import { useRef } from "react";

import { Button } from "@/shared/components/ui/button";
import { Select } from "@/shared/components/ui/select";
import { todayISO } from "@/shared/lib/clock";
import { cn } from "@/shared/lib/utils";

import type { ConflictType } from "./api";
import { CONFLICT_FAMILIES, CONFLICT_FAMILY_LABEL } from "./lib/conflictLabels";
import { DEFAULT_KINDS, KINDS, type Kind, normalizeKinds } from "./lib/consultFilter";
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
 * PR 3b — les contrôles du Calendrier : chips de type de compétition (groupe « Types »),
 * interrupteurs « Semaine type » (Semaine seule) et « Extérieurs » (les trois temporalités)
 * dans le groupe « Afficher », un « Réinitialiser » quand l'état diffère des défauts, les
 * chips de familles de conflits (« Familles de conflits »), le contrôle segmenté « Période »
 * (Semaine·Mois·Phase), le navigateur propre à la temporalité et le rappel de fraîcheur. Lit
 * l'état Consulter + `selectedWeekend` du store ; PRÉSENTATION pure (les compteurs/chips
 * arrivent déjà dérivés par la page).
 */
export function CalendarControls(props: CalendarControlsProps) {
  const { familyChips, familyCounts, weekends, activeWeekend, weekendIndex, months, activeMonth, monthIndex, phases, activePhaseId, completeness, depositReminder } = props;
  const {
    consultKinds,
    consultFamilies,
    consultTypicalWeek,
    consultAway,
    consultTemporality,
    consultMonth,
    setConsultKinds,
    setConsultFamilies,
    setConsultTypicalWeek,
    setConsultAway,
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
  const typesRef = useRef<HTMLDivElement>(null);

  const effectiveKinds = consultKinds ?? DEFAULT_KINDS;
  const isKindChecked = (kind: Kind): boolean => effectiveKinds.includes(kind);
  const toggleKind = (kind: Kind): void => {
    const active = new Set<Kind>(effectiveKinds);
    if (active.has(kind)) {
      active.delete(kind);
    } else {
      active.add(kind);
    }
    setConsultKinds(normalizeKinds([...active]));
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

  // « Réinitialiser » : visible dès que Types, Extérieurs, Semaine type ou Familles diffèrent
  // des défauts ; ne touche NI pivot NI temporalité NI semaine ; focus → première puce de type.
  const dirty = null !== consultKinds || consultAway || consultTypicalWeek || null !== consultFamilies;
  const reset = (): void => {
    setConsultKinds(null);
    setConsultFamilies(null);
    setConsultTypicalWeek(false);
    setConsultAway(false);
    requestAnimationFrame(() => typesRef.current?.querySelector("button")?.focus());
  };

  return (
    <>
      {/* Rangée « Types » + « Afficher » (interrupteurs) + « Réinitialiser ». */}
      <div className="flex flex-wrap items-center gap-2">
        <div role="group" aria-labelledby="calendar-types-label" className="flex flex-wrap items-center gap-2">
          <span id="calendar-types-label" className="text-xs font-medium text-muted-foreground">
            Types
          </span>
          <div ref={typesRef} className="flex flex-wrap items-center gap-1 rounded-md border border-border p-0.5">
            {KINDS.map((kind) => (
              <Button key={kind} type="button" size="sm" aria-pressed={isKindChecked(kind)} variant={isKindChecked(kind) ? "default" : "ghost"} className={chipClass(isKindChecked(kind))} onClick={() => toggleKind(kind)}>
                {KIND_LABEL[kind]}
              </Button>
            ))}
          </div>
        </div>

        <div role="group" aria-labelledby="calendar-afficher-label" className="flex flex-wrap items-center gap-2">
          <span id="calendar-afficher-label" className="text-xs font-medium text-muted-foreground">
            Afficher
          </span>
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
          <Button
            type="button"
            role="switch"
            size="sm"
            aria-checked={consultAway}
            variant={consultAway ? "default" : "ghost"}
            className={cn("h-7 gap-1.5", consultAway ? "" : "text-muted-foreground")}
            onClick={() => setConsultAway(!consultAway)}
          >
            <Bus className="size-3.5" aria-hidden="true" />
            Extérieurs
          </Button>
        </div>

        {dirty ? (
          <Button type="button" variant="ghost" size="sm" className="h-7 gap-1.5" onClick={reset}>
            <RotateCcw className="size-3.5" aria-hidden="true" />
            Réinitialiser
          </Button>
        ) : null}
      </div>

      {/* Chips familles de conflits présentes, avec compteur. */}
      {familyChips.length > 0 ? (
        <div role="group" aria-labelledby="calendar-familles-label" className="flex flex-wrap items-center gap-1.5">
          <span id="calendar-familles-label" className="text-xs font-medium text-muted-foreground">
            Familles de conflits
          </span>
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

      {/* Contrôle segmenté « Période » + navigateur propre + rappel de fraîcheur. */}
      <div className="flex flex-wrap items-center gap-2">
        <div role="group" aria-labelledby="calendar-periode-label" className="flex flex-wrap items-center gap-2">
          <span id="calendar-periode-label" className="text-xs font-medium text-muted-foreground">
            Période
          </span>
          <div className="flex flex-wrap items-center gap-1 rounded-md border border-border p-0.5">
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
