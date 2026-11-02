import type { Fixture } from "../api";
import type { ConsultTemporality } from "../store";

interface ExportRangeContext {
  temporality: ConsultTemporality;
  /** Samedi Y-m-d du week-end affiché. */
  activeWeekend: string | null;
  /** Y-m du mois affiché. */
  activeMonth: string | null;
  phase: { teamId: string; firstDate: string | null; lastDate: string | null } | null;
  /** L'équipe, quand le filtre « Équipe » en coche exactement une. */
  filterTeamId: string | null;
  /** Y-m-d */
  today: string;
}

function ymd(year: number, monthIndex: number, day: number): string {
  return new Date(Date.UTC(year, monthIndex, day)).toISOString().slice(0, 10);
}

function monthBounds(yearMonth: string): { from: string; to: string } {
  const [year, month] = yearMonth.split("-").map(Number);
  return { from: ymd(year, month - 1, 1), to: ymd(year, month, 0) };
}

/** Du lundi au dimanche de la semaine dont `saturday` est le samedi. */
function weekBounds(saturday: string): { from: string; to: string } {
  const [year, month, day] = saturday.split("-").map(Number);
  return { from: ymd(year, month - 1, day - 5), to: ymd(year, month - 1, day + 1) };
}

/** Premier et dernier match d'une phase, tous filtres d'affichage ignorés. */
export function phaseDateBounds(fixtures: Fixture[], competitionId: string): { firstDate: string | null; lastDate: string | null } {
  const dates = fixtures
    .filter((fixture) => fixture.competitionId === competitionId)
    .map((fixture) => fixture.matchDate)
    .sort();
  return { firstDate: dates[0] ?? null, lastDate: dates[dates.length - 1] ?? null };
}

/** Ce que la fenêtre d'export propose à l'ouverture : ce qui est à l'écran, sinon le mois en cours. */
export function defaultExportRange({ temporality, activeWeekend, activeMonth, phase, filterTeamId, today }: ExportRangeContext): { teamId: string | null; from: string; to: string } {
  const currentMonth = monthBounds(today.slice(0, 7));
  if ("phase" === temporality && null !== phase) {
    const hasDates = null !== phase.firstDate && null !== phase.lastDate;
    return { teamId: phase.teamId, ...(hasDates ? { from: phase.firstDate as string, to: phase.lastDate as string } : currentMonth) };
  }
  if ("semaine" === temporality && null !== activeWeekend) {
    return { teamId: filterTeamId, ...weekBounds(activeWeekend) };
  }
  if ("mois" === temporality && null !== activeMonth) {
    return { teamId: filterTeamId, ...monthBounds(activeMonth) };
  }
  return { teamId: filterTeamId, ...currentMonth };
}
