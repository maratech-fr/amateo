import { CalendarOff, ChevronLeft, ChevronRight } from "lucide-react";
import { useState } from "react";

import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { Spinner } from "@/shared/components/ui/spinner";
import { cn } from "@/shared/lib/utils";

import type { CalendarEntry, PublicHoliday, SchoolHoliday } from "./api";
import { DayDialog, type CalendarVenueUnavailability } from "./DayDialog";
import { buildMonthGrid, isWithin, monthLabel, todayISO } from "./lib/date";
import { entryIcon, entryLabel, holidayIcon, isClosureChildWithMotherPresent, isHolidayAnchor, isHolidayWeekChild } from "./lib/markers";
import { COCKPIT_TEXTS } from "./texts";

const WEEKDAYS = ["L", "M", "M", "J", "V", "S", "D"];

interface MonthCalendarProps {
  year: number;
  month: number;
  entries: CalendarEntry[];
  holidays: SchoolHoliday[];
  publicHolidays: PublicHoliday[];
  /** Indisponibilités de gymnase RÉSOLUES couvrant le mois (option B) — marque distincte + ligne
   *  dans la modale du jour ; vide par défaut (une lecture en échec côté page ne casse pas la grille). */
  venueUnavailabilities?: CalendarVenueUnavailability[];
  onPrev: () => void;
  onNext: () => void;
  /** UXS-09 (décision A) : le chargement/échec de la LECTURE des entrées ne touche QUE la grille —
   *  l'en-tête du mois et les flèches restent montés pour pouvoir changer de mois sans démontage. */
  loading?: boolean;
  failed?: boolean;
  onRetry?: () => void;
}

/** Month grid of the exception layer (events / closures / holidays) — NOT the weekly base plan. */
export function MonthCalendar({ year, month, entries, holidays, publicHolidays, venueUnavailabilities = [], onPrev, onNext, loading, failed, onRetry }: MonthCalendarProps) {
  const [selectedDay, setSelectedDay] = useState<string | null>(null);
  const grid = buildMonthGrid(year, month);
  const today = todayISO();

  const entriesOn = (iso: string): CalendarEntry[] => entries.filter((e) => isWithin(iso, e.startDate, e.endDate));
  const holidayOn = (iso: string): SchoolHoliday | undefined => holidays.find((h) => isWithin(iso, h.startDate, h.endDate));
  const publicHolidayOn = (iso: string): PublicHoliday | undefined => publicHolidays.find((h) => h.date === iso);
  const unavailabilitiesOn = (iso: string): CalendarVenueUnavailability[] => venueUnavailabilities.filter((u) => isWithin(iso, u.startDate, u.endDate));

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

      {/* UXS-09 (décision A) — seule la GRILLE charge/échoue ; l'en-tête du mois + les flèches
          ci-dessus restent toujours montés pour pouvoir changer de mois pendant le fetch (sinon
          chaque navigation les démontait et faisait rater les clics successifs). */}
      {failed ? (
        <div className="p-6">
          <LoadErrorHint onRetry={onRetry}>Le calendrier n'a pas pu être chargé.</LoadErrorHint>
        </div>
      ) : loading ? (
        <div className="flex items-center justify-center p-10" role="status" aria-live="polite">
          <Spinner className="size-5" />
        </div>
      ) : (
        <>
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
              // Un enfant de FERMETURE dont la mère couvre le jour est masqué aussi (sa mère
              // porte déjà le ⛔ — sinon double pastille, retour fondateur 2026-10-28).
              // Toutes restent dans entriesOn (passé à DayDialog → HolidayBlock/chips).
              const onDay = entriesOn(cell.iso);
              const dayEntries = onDay.filter((e) => !isHolidayAnchor(e) && !isHolidayWeekChild(e) && !isClosureChildWithMotherPresent(e, onDay));
              const holiday = holidayOn(cell.iso);
              const publicHoliday = publicHolidayOn(cell.iso);
              const unavailabilities = unavailabilitiesOn(cell.iso);
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
                // Option B (2026-10-28) : l'indispo de gymnase s'annonce AUSSI au lecteur d'écran,
                // distincte de la fermeture (« {gymnase} indisponible » + son libellé).
                ...unavailabilities.map((u) => COCKPIT_TEXTS.venueUnavailableTitle(u.venueName, u.label)),
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
                    // A11Y-25 — surface OPAQUE (`bg-surface-warning`, P4-265), pas une teinte `/NN` :
                    // le texte (numéro du jour, libellé) reste `text-foreground` et tient l'AA.
                    holiday && cell.inMonth ? "bg-surface-warning" : "",
                    // Jour férié : case DISTINCTE (surface rouge OPAQUE `bg-surface-destructive`,
                    // AA en clair/sombre, text-foreground) + le TEXTE « Férié » à côté du numéro
                    // (passe design ui-ux-pro-max 2026-10-28 : color-not-only — un mot, pas qu'une
                    // couleur). Les vacances scolaires gardent la priorité du fond (amber) si les deux
                    // tombent le même jour ; le mot « Férié » reste visible dans tous les cas.
                    publicHoliday && !holiday && cell.inMonth ? "bg-surface-destructive" : "",
                  )}
                  aria-label={isPast ? `${dayLabel}, passé (non modifiable)` : dayLabel}
                >
                  <span className="flex w-full items-center justify-between gap-1">
                    <span className={cn("flex size-5 items-center justify-center rounded-full", isToday ? "bg-accent font-semibold text-accent-foreground" : "")}>{cell.day}</span>
                    {publicHoliday ? (
                      // « Férié » en TOUTES LETTRES à côté du numéro (le badge « F » seul était illisible).
                      <span className="truncate text-[10px] font-semibold leading-none text-foreground">Férié</span>
                    ) : null}
                  </span>
                  {/* Markers are purely visual — the button's aria-label already names them
                      to a screen reader, so hide them from the a11y tree (no double read). */}
                  <span aria-hidden className="flex flex-wrap items-center gap-0.5">
                    {holiday ? <span title={`Vacances — ${holiday.label}`}>{holidayIcon(holiday)}</span> : null}
                    {dayEntries.map((e) => (
                      <span key={e.id} title={e.title || entryLabel(e)}>
                        {entryIcon(e)}
                      </span>
                    ))}
                    {/* Indispo de gymnase : glyphe DISTINCT du ⛔ (lucide CalendarOff, `text-warning`),
                        cohérent avec la carte « Indisponibilités gymnase » (passe design 2026-10-28). */}
                    {unavailabilities.map((u) => (
                      <span key={u.id} title={COCKPIT_TEXTS.venueUnavailableTitle(u.venueName, u.label)} className="inline-flex items-center text-warning">
                        <CalendarOff className="size-3" />
                      </span>
                    ))}
                  </span>
                  {holiday && cell.inMonth ? (
                    <span className="w-full truncate text-[10px] leading-tight text-foreground" title={holiday.label}>
                      {holiday.label}
                    </span>
                  ) : null}
                </button>
              );
            })}
          </div>

          {/* UXS-15 — légende discrète des pictogrammes du calendrier : le sens ne vit plus au seul
              survol (`title`). Emojis décoratifs (`aria-hidden`), le mot porte le sens. */}
          <p className="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
            <span><span aria-hidden>⛔</span> fermeture</span>
            <span><span aria-hidden>🛑</span> date butoir</span>
            <span><span aria-hidden>🚫</span> événement perturbant</span>
            <span className="inline-flex items-center gap-1">
              <CalendarOff aria-hidden className="size-3 text-warning" /> gymnase indisponible
            </span>
          </p>
        </>
      )}

      {selectedDay !== null ? (
        <DayDialog
          iso={selectedDay}
          entries={entriesOn(selectedDay)}
          holiday={holidayOn(selectedDay)}
          publicHoliday={publicHolidayOn(selectedDay)}
          venueUnavailabilities={unavailabilitiesOn(selectedDay)}
          onClose={() => setSelectedDay(null)}
        />
      ) : null}
    </div>
  );
}
