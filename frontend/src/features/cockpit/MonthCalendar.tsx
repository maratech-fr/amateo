import { ChevronLeft, ChevronRight } from "lucide-react";
import { useState } from "react";

import { cn } from "@/shared/lib/utils";

import type { CalendarEntry, PublicHoliday, SchoolHoliday } from "./api";
import { DayDialog } from "./DayDialog";
import { buildMonthGrid, isWithin, monthLabel, todayISO } from "./lib/date";
import { entryIcon, entryLabel, holidayIcon, isHolidayAnchor, isHolidayWeekChild } from "./lib/markers";

const WEEKDAYS = ["L", "M", "M", "J", "V", "S", "D"];

interface MonthCalendarProps {
  year: number;
  month: number;
  entries: CalendarEntry[];
  holidays: SchoolHoliday[];
  publicHolidays: PublicHoliday[];
  onPrev: () => void;
  onNext: () => void;
}

/** Month grid of the exception layer (events / closures / holidays) — NOT the weekly base plan. */
export function MonthCalendar({ year, month, entries, holidays, publicHolidays, onPrev, onNext }: MonthCalendarProps) {
  const [selectedDay, setSelectedDay] = useState<string | null>(null);
  const grid = buildMonthGrid(year, month);
  const today = todayISO();

  const entriesOn = (iso: string): CalendarEntry[] => entries.filter((e) => isWithin(iso, e.startDate, e.endDate));
  const holidayOn = (iso: string): SchoolHoliday | undefined => holidays.find((h) => isWithin(iso, h.startDate, h.endDate));
  const publicHolidayOn = (iso: string): PublicHoliday | undefined => publicHolidays.find((h) => h.date === iso);

  return (
    <div className="rounded-lg border border-border bg-card p-4">
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-sm font-semibold">
          {monthLabel(month)} {year}
        </h2>
        <div className="flex gap-1">
          <button type="button" aria-label="Mois précédent" className="rounded p-1 text-muted-foreground hover:text-foreground" onClick={onPrev}>
            <ChevronLeft className="size-4" />
          </button>
          <button type="button" aria-label="Mois suivant" className="rounded p-1 text-muted-foreground hover:text-foreground" onClick={onNext}>
            <ChevronRight className="size-4" />
          </button>
        </div>
      </div>

      <div className="grid grid-cols-7 gap-1 text-center text-xs text-muted-foreground">
        {WEEKDAYS.map((d, i) => (
          <div key={i} className="py-1">
            {d}
          </div>
        ))}
      </div>

      <div className="grid grid-cols-7 gap-1">
        {grid.map((cell) => {
          // La mère vacances est un ancrage invisible (la vacance scolaire porte
          // déjà le surlignage) : elle ne peint aucun marqueur ni libellé a11y.
          // Ses SEMAINES-enfants non plus (fondateur 2026-07-24 : pas de ⛔ en plus,
          // la citrouille suffit — les semaines de fermeture, elles, gardent le leur).
          // Toutes restent dans entriesOn (passé à DayDialog → HolidayBlock/chips).
          const dayEntries = entriesOn(cell.iso).filter((e) => !isHolidayAnchor(e) && !isHolidayWeekChild(e));
          const holiday = holidayOn(cell.iso);
          const publicHoliday = publicHolidayOn(cell.iso);
          const isToday = cell.iso === today;
          // "On ne modifie pas le passé" : days strictly before today stay visible
          // (holidays still shown) but are not clickable — no DayDialog on the past.
          const isPast = cell.iso < today;
          // A11Y-07: one composed accessible name for the whole cell. The button's
          // aria-label overrides its children, so a bare `Jour {ISO}` used to hide
          // every marker (holiday / férié / events) from a screen reader — compose
          // them all here so nothing is lost, and read a human date, not the ISO.
          const dayMarks = [
            holiday ? `vacances — ${holiday.label}` : null,
            publicHoliday ? `jour férié — ${publicHoliday.label}` : null,
            ...dayEntries.map((e) => entryLabel(e)),
          ].filter((m): m is string => m !== null);
          // Derive the readable date from the ISO so the leading/trailing spill days
          // read "27 Avril", not the raw ISO — the same A11Y-07 fix must cover them
          // (they are clickable too), and their month differs from the grid's month.
          const [, isoMonth, isoDay] = cell.iso.split("-").map(Number);
          const dayLabel = [`${isoDay} ${monthLabel(isoMonth - 1)}`, isToday ? "aujourd'hui" : null, ...dayMarks].filter(Boolean).join(", ");
          return (
            <button
              key={cell.iso}
              type="button"
              disabled={isPast}
              onClick={isPast ? undefined : () => setSelectedDay(cell.iso)}
              className={cn(
                "flex min-h-14 flex-col items-start gap-1 rounded-md border p-1.5 text-left text-xs transition-colors",
                // Jours hors-mois : distingués par l'absence de bordure (in-month = `border-border`) et un
                // texte `text-muted-foreground` — jamais une OPACITÉ sur le texte (P4-179 : `/50` tombait à
                // 2,1:1 / 2,5:1, sous AA, y compris sous le voile d'une modale ; le token plein tient AA).
                cell.inMonth ? "border-border" : "border-transparent text-muted-foreground",
                !isPast && cell.inMonth ? "hover:bg-muted" : "",
                // Past days are dimmed and non-interactive (we prepare the future).
                isPast ? "cursor-not-allowed opacity-50" : "",
                // School-holiday days get a clear amber BACKGROUND (kept apart from
                // the accent, which marks "today") so a break is obvious at a glance.
                // Jours fériés keep only their "F" badge — no background (per product).
                holiday && cell.inMonth ? "bg-warning/30 dark:bg-warning/20" : "",
              )}
              aria-label={isPast ? `${dayLabel}, passé (non modifiable)` : dayLabel}
            >
              <span className={cn("flex size-5 items-center justify-center rounded-full", isToday ? "bg-accent font-semibold text-accent-foreground" : "")}>{cell.day}</span>
              {/* Markers are purely visual — the button's aria-label already names them
                  to a screen reader, so hide them from the a11y tree (no double read). */}
              <span aria-hidden className="flex flex-wrap items-center gap-0.5">
                {holiday ? <span title={`Vacances — ${holiday.label}`}>{holidayIcon(holiday)}</span> : null}
                {publicHoliday ? (
                  // A11Y-08: was a bare red dot (info by colour alone). A shape + the
                  // letter "F" makes "férié" legible without relying on colour.
                  <span title={`Férié — ${publicHoliday.label}`} className="rounded-sm bg-destructive/15 px-0.5 text-[10px] font-bold leading-none text-destructive">
                    F
                  </span>
                ) : null}
                {dayEntries.map((e) => (
                  <span key={e.id} title={e.title || entryLabel(e)}>
                    {entryIcon(e)}
                  </span>
                ))}
              </span>
              {holiday && cell.inMonth ? (
                <span className="w-full truncate text-[10px] leading-tight text-warning" title={holiday.label}>
                  {holiday.label}
                </span>
              ) : null}
            </button>
          );
        })}
      </div>

      {selectedDay !== null ? (
        <DayDialog iso={selectedDay} entries={entriesOn(selectedDay)} holiday={holidayOn(selectedDay)} publicHoliday={publicHolidayOn(selectedDay)} onClose={() => setSelectedDay(null)} />
      ) : null}
    </div>
  );
}
