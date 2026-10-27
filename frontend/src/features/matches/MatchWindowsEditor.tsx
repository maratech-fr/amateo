import { Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { Select } from "@/shared/components/ui/select";
import { dayLabelLongCap } from "@/shared/lib/days";

import type { VenueMatchWindow } from "./api/venues";
import { MATCHES_TEXTS } from "./texts";
import { useCreateVenueMatchWindow, useDeleteVenueMatchWindow, useVenueMatchWindows } from "./queries";

interface MatchWindowsEditorProps {
  venueId: string;
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
export function MatchWindowsEditor({ venueId }: MatchWindowsEditorProps) {
  const windowsQuery = useVenueMatchWindows();
  const create = useCreateVenueMatchWindow();
  const remove = useDeleteVenueMatchWindow();
  const [daySelection, setDaySelection] = useState<string>("6");
  const [startTime, setStartTime] = useState("14:00");
  const [endTime, setEndTime] = useState("22:00");
  const [pendingDeleteId, setPendingDeleteId] = useState<string | null>(null);

  const windows = (windowsQuery.data ?? []).filter((w) => w.venueId === venueId);
  const pendingDelete = windows.find((w) => w.id === pendingDeleteId) ?? null;
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
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          {MATCHES_TEXTS.matchWindowStartLabel}
          <Input aria-label={MATCHES_TEXTS.matchWindowStartFieldName} type="time" value={startTime} onChange={(e) => setStartTime(e.target.value)} />
        </label>
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          {MATCHES_TEXTS.matchWindowEndLabel}
          <Input aria-label={MATCHES_TEXTS.matchWindowEndFieldName} type="time" value={endTime} onChange={(e) => setEndTime(e.target.value)} />
        </label>
        <Button size="sm" disabled={rangeInvalid || create.isPending} onClick={add}>
          <Plus className="size-3.5" />
          {MATCHES_TEXTS.matchWindowAdd}
        </Button>
      </div>
      {rangeInvalid && "" !== startTime && "" !== endTime ? (
        <p className="text-xs text-destructive">{MATCHES_TEXTS.matchWindowRangeInvalid}</p>
      ) : null}

      <ConfirmDialog
        open={null !== pendingDelete}
        title={MATCHES_TEXTS.matchWindowDeleteTitle}
        description={pendingDelete ? MATCHES_TEXTS.matchWindowDeleteDescription(`${windowDayLabel(pendingDelete)} ${pendingDelete.startTime} – ${pendingDelete.endTime}`) : null}
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
