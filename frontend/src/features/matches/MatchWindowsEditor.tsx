import { Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { frDateShort, frDateWeekdayNoYear } from "@/shared/lib/date";
import { dayLabelLongCap } from "@/shared/lib/days";
import { useWorkingSeason } from "@/shared/session/queries";

import type { VenueMatchWindow } from "./api/venues";
import { MATCHES_TEXTS } from "./texts";
import { useCreateVenueMatchWindow, useDeleteVenueMatchWindow, useVenueMatchWindows } from "./queries";

interface MatchWindowsEditorProps {
  venueId: string;
  /** La ligne d'ouverture préremplie, depuis le lien « Ouvrir ce gymnase ce jour-là » d'un conflit. */
  prefillOpening?: { date: string; start: string; end: string };
}

/** « mer. 11 nov. 2026 » — une ouverture peut vivre hors de la saison, l'année reste dite. */
function openingDay(date: string): string {
  return `${frDateWeekdayNoYear(date)} ${date.slice(0, 4)}`;
}

/**
 * La valeur sentinelle du sélecteur « Jour » pour la 8ᵉ ligne « Jours fériés » (PR2). On la cale
 * sur le LIBELLÉ affiché pour que l'option reste sélectionnable par son texte (userEvent apparie
 * sur `value`). L'option elle-même ne porte PAS de nœud texte (`label` pour l'affichage natif,
 * `aria-label` pour le nom accessible) : ainsi « Jours fériés » n'apparaît qu'une fois en nœud
 * texte — sur la LIGNE férié —, jamais en double avec l'option.
 */
const HOLIDAY = MATCHES_TEXTS.matchWindowHolidayOption;

/** Le libellé jour d'une fenêtre : « Jours fériés » pour la ligne férié, sinon le jour de semaine. */
function windowDayLabel(window: VenueMatchWindow): string {
  return window.appliesOnPublicHolidays ? MATCHES_TEXTS.matchWindowHolidayOption : dayLabelLongCap(window.dayOfWeek ?? 0) || "?";
}

/**
 * The « Accès match » editor of ONE venue (cadrage P1-4 §5.1) : the day+range
 * windows the city hall grants for MATCHES — distinct from the training slots.
 * A venue with ≥ 1 window IS a match venue (derived, no boolean). Shared by
 * the wizard venues step and the matches page dialog: one editor, one truth.
 *
 * PR2 (Q1 option c, décision fondateur 2026-10-27) : une 8ᵉ ligne « Jours fériés » SOUS
 * lundi…dimanche s'applique à tout jour férié officiel du territoire (pas de jour de semaine).
 */
export function MatchWindowsEditor({ venueId, prefillOpening }: MatchWindowsEditorProps) {
  const windowsQuery = useVenueMatchWindows();
  const create = useCreateVenueMatchWindow();
  const remove = useDeleteVenueMatchWindow();
  const [daySelection, setDaySelection] = useState<string>("6");
  const [startTime, setStartTime] = useState("14:00");
  const [endTime, setEndTime] = useState("22:00");
  const [pendingDeleteId, setPendingDeleteId] = useState<string | null>(null);
  const season = useWorkingSeason();
  const [openingDate, setOpeningDate] = useState(prefillOpening?.date ?? "");
  const [openingStart, setOpeningStart] = useState(prefillOpening?.start ?? "14:00");
  const [openingEnd, setOpeningEnd] = useState(prefillOpening?.end ?? "20:00");
  const [openingLabel, setOpeningLabel] = useState("");

  const venueWindows = (windowsQuery.data ?? []).filter((w) => w.venueId === venueId);
  const windows = venueWindows.filter((w) => null == w.openingDate);
  const openings = venueWindows.filter((w) => null != w.openingDate).sort((a, b) => `${a.openingDate}${a.startTime}`.localeCompare(`${b.openingDate}${b.startTime}`));
  const pendingDelete = venueWindows.find((w) => w.id === pendingDeleteId) ?? null;
  const openingRangeInvalid = "" === openingStart || "" === openingEnd || openingStart >= openingEnd;
  const openingOutOfSeason = null !== season && "" !== openingDate && (openingDate < season.startDate || openingDate > season.endDate);
  const rangeInvalid = "" === startTime || "" === endTime || startTime >= endTime;

  const add = (): void => {
    if (rangeInvalid || create.isPending) {
      return;
    }
    create.mutate(
      HOLIDAY === daySelection
        ? { venueId, appliesOnPublicHolidays: true, startTime, endTime }
        : { venueId, dayOfWeek: Number(daySelection), startTime, endTime },
    );
  };

  const addOpening = (): void => {
    if (openingRangeInvalid || "" === openingDate || create.isPending) {
      return;
    }
    const label = openingLabel.trim();
    create.mutate({ venueId, openingDate, startTime: openingStart, endTime: openingEnd, ...("" === label ? {} : { label }) });
  };

  if (windowsQuery.isError) {
    return <p className="text-sm text-destructive">{MATCHES_TEXTS.matchWindowsLoadError}</p>;
  }

  return (
    <div className="flex flex-col gap-2">
      {0 === windows.length && !windowsQuery.isLoading ? (
        <p className="text-xs text-muted-foreground">{MATCHES_TEXTS.matchWindowsEmpty}</p>
      ) : null}

      <ul className="flex flex-col gap-1">
        {windows.map((window) => (
          <li key={window.id} className="flex items-center justify-between gap-2 rounded-md border border-border bg-card px-2 py-1 text-sm">
            <span>
              {windowDayLabel(window)} {window.startTime} – {window.endTime}
            </span>
            <Button
              variant="ghost"
              size="icon"
              className="size-8 text-destructive"
              aria-label={MATCHES_TEXTS.matchWindowDelete(`${windowDayLabel(window)} ${window.startTime}`)}
              disabled={remove.isPending}
              onClick={() => setPendingDeleteId(window.id)}
            >
              <Trash2 className="size-4" />
            </Button>
          </li>
        ))}
      </ul>

      <div className="flex items-end gap-2">
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          {MATCHES_TEXTS.matchWindowDayLabel}
          <Select
            aria-label={MATCHES_TEXTS.matchWindowDayFieldName}
            wrapperClassName="w-32"
            value={daySelection}
            onChange={(e) => setDaySelection(e.target.value)}
          >
            {[1, 2, 3, 4, 5, 6, 7].map((day) => (
              <option key={day} value={String(day)}>
                {dayLabelLongCap(day)}
              </option>
            ))}
            {/* Pas de nœud texte : `label` pour l'affichage natif, `aria-label` pour le nom
                accessible — « Jours fériés » ne vit qu'une fois en texte, sur la ligne férié. */}
            <option value={HOLIDAY} label={HOLIDAY} aria-label={HOLIDAY} />
          </Select>
        </label>
        {/* Pas de `<label>` englobant : le nom accessible est l'`aria-label`, et « Début »/« Fin » restent
            ceux de la section Ouvertures, juste dessous. */}
        <div className="flex flex-col gap-1 text-xs text-muted-foreground">
          <span aria-hidden="true">{MATCHES_TEXTS.matchWindowStartLabel}</span>
          <Input aria-label={MATCHES_TEXTS.matchWindowStartFieldName} type="time" value={startTime} onChange={(e) => setStartTime(e.target.value)} />
        </div>
        <div className="flex flex-col gap-1 text-xs text-muted-foreground">
          <span aria-hidden="true">{MATCHES_TEXTS.matchWindowEndLabel}</span>
          <Input aria-label={MATCHES_TEXTS.matchWindowEndFieldName} type="time" value={endTime} onChange={(e) => setEndTime(e.target.value)} />
        </div>
        <Button size="sm" disabled={rangeInvalid || create.isPending} onClick={add}>
          <Plus className="size-3.5" />
          {MATCHES_TEXTS.matchWindowAdd}
        </Button>
      </div>
      {rangeInvalid && "" !== startTime && "" !== endTime ? (
        <p className="text-xs text-destructive">{MATCHES_TEXTS.matchWindowRangeInvalid}</p>
      ) : null}

      <section className="mt-2 flex flex-col gap-2 border-t border-border pt-3">
        <h3 className="text-sm font-medium">{MATCHES_TEXTS.openingSectionTitle}</h3>
        <p className="text-xs text-muted-foreground">{MATCHES_TEXTS.openingSectionHelp}</p>
        {0 === openings.length && !windowsQuery.isLoading ? <p className="text-xs text-muted-foreground">{MATCHES_TEXTS.openingsEmpty}</p> : null}
        <ul className="flex flex-col gap-1">
          {openings.map((opening) => {
            const day = openingDay(opening.openingDate ?? "");
            return (
              <li key={opening.id} className="flex items-center justify-between gap-2 rounded-md border border-border bg-card px-2 py-1 text-sm">
                <span>{`${day} · ${opening.startTime} – ${opening.endTime}${null != opening.label ? ` · ${opening.label}` : ""}`}</span>
                <Button
                  variant="ghost"
                  size="icon"
                  className="size-8 text-destructive"
                  aria-label={MATCHES_TEXTS.openingDelete(`${day} ${opening.startTime}`)}
                  disabled={remove.isPending}
                  onClick={() => setPendingDeleteId(opening.id)}
                >
                  <Trash2 className="size-4" />
                </Button>
              </li>
            );
          })}
        </ul>
        <div className="flex flex-wrap items-end gap-2">
          <label className="flex flex-col gap-1 text-xs text-muted-foreground">
            {MATCHES_TEXTS.openingDateLabel}
            <Input type="date" value={openingDate} onChange={(e) => setOpeningDate(e.target.value)} />
          </label>
          <label className="flex flex-col gap-1 text-xs text-muted-foreground">
            {MATCHES_TEXTS.openingStartLabel}
            <Input type="time" value={openingStart} onChange={(e) => setOpeningStart(e.target.value)} />
          </label>
          <label className="flex flex-col gap-1 text-xs text-muted-foreground">
            {MATCHES_TEXTS.openingEndLabel}
            <Input type="time" value={openingEnd} onChange={(e) => setOpeningEnd(e.target.value)} />
          </label>
          <label className="flex min-w-40 flex-1 flex-col gap-1 text-xs text-muted-foreground">
            {MATCHES_TEXTS.openingLabelLabel}
            <Input maxLength={120} placeholder={MATCHES_TEXTS.openingLabelPlaceholder} value={openingLabel} onChange={(e) => setOpeningLabel(e.target.value)} />
          </label>
          <Button size="sm" disabled={create.isPending} onClick={addOpening}>
            <Plus className="size-3.5" />
            {MATCHES_TEXTS.openingAdd}
          </Button>
        </div>
        {openingRangeInvalid && "" !== openingStart && "" !== openingEnd ? <p className="text-xs text-destructive">{MATCHES_TEXTS.matchWindowRangeInvalid}</p> : null}
        {openingOutOfSeason ? <p className="text-xs text-warning">{MATCHES_TEXTS.openingOutOfSeason(frDateShort(season.startDate), frDateShort(season.endDate))}</p> : null}
      </section>

      <ConfirmDialog
        open={null !== pendingDelete}
        title={null != pendingDelete?.openingDate ? MATCHES_TEXTS.openingDeleteTitle : MATCHES_TEXTS.matchWindowDeleteTitle}
        description={
          null === pendingDelete
            ? null
            : null != pendingDelete.openingDate
              ? MATCHES_TEXTS.openingDeleteDescription(openingDay(pendingDelete.openingDate), `${pendingDelete.startTime} – ${pendingDelete.endTime}`)
              : MATCHES_TEXTS.matchWindowDeleteDescription(`${windowDayLabel(pendingDelete)} ${pendingDelete.startTime} – ${pendingDelete.endTime}`)
        }
        confirmLabel={MATCHES_TEXTS.matchWindowDeleteConfirm}
        destructive
        onConfirm={() => {
          if (null !== pendingDelete) {
            remove.mutate(pendingDelete.id);
          }
          setPendingDeleteId(null);
        }}
        onCancel={() => setPendingDeleteId(null)}
      />
    </div>
  );
}
