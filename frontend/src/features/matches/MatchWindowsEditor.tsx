import { Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Select } from "@/shared/components/ui/select";

import { useCreateVenueMatchWindow, useDeleteVenueMatchWindow, useVenueMatchWindows } from "./queries";

const DAY_LABELS = ["", "Lundi", "Mardi", "Mercredi", "Jeudi", "Vendredi", "Samedi", "Dimanche"];

interface MatchWindowsEditorProps {
  venueId: string;
}

/**
 * The « Accès match » editor of ONE venue (cadrage P1-4 §5.1) : the day+range
 * windows the city hall grants for MATCHES — distinct from the training slots.
 * A venue with ≥ 1 window IS a match venue (derived, no boolean). Shared by
 * the wizard venues step and the matches page dialog: one editor, one truth.
 */
export function MatchWindowsEditor({ venueId }: MatchWindowsEditorProps) {
  const windowsQuery = useVenueMatchWindows();
  const create = useCreateVenueMatchWindow();
  const remove = useDeleteVenueMatchWindow();
  const [dayOfWeek, setDayOfWeek] = useState(6);
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
    create.mutate({ venueId, dayOfWeek, startTime, endTime });
  };

  if (windowsQuery.isError) {
    return <p className="text-sm text-destructive">Les fenêtres d’accès match n’ont pas pu être chargées.</p>;
  }

  return (
    <div className="flex flex-col gap-2">
      {0 === windows.length && !windowsQuery.isLoading ? (
        <p className="text-xs text-muted-foreground">
          Aucune fenêtre d’accès match — ce gymnase n’accueille pas de matchs. Ajoutez les créneaux qui vous sont
          accordés les jours de match.
        </p>
      ) : null}

      <ul className="flex flex-col gap-1">
        {windows.map((window) => (
          <li key={window.id} className="flex items-center justify-between gap-2 rounded-md border border-border bg-card px-2 py-1 text-sm">
            <span>
              {DAY_LABELS[window.dayOfWeek] ?? "?"} {window.startTime} – {window.endTime}
            </span>
            <Button
              variant="ghost"
              size="icon"
              className="size-8 text-destructive"
              aria-label={`Supprimer la fenêtre ${DAY_LABELS[window.dayOfWeek] ?? "?"} ${window.startTime}`}
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
          Jour
          <Select aria-label="Jour de la fenêtre match" className="h-8" wrapperClassName="w-32" value={dayOfWeek} onChange={(e) => setDayOfWeek(Number(e.target.value))}>
            {[1, 2, 3, 4, 5, 6, 7].map((day) => (
              <option key={day} value={day}>
                {DAY_LABELS[day]}
              </option>
            ))}
          </Select>
        </label>
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          Début
          <input aria-label="Début de la fenêtre match" type="time" className="h-8 rounded-md border border-border bg-background px-2 text-sm" value={startTime} onChange={(e) => setStartTime(e.target.value)} />
        </label>
        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
          Fin
          <input aria-label="Fin de la fenêtre match" type="time" className="h-8 rounded-md border border-border bg-background px-2 text-sm" value={endTime} onChange={(e) => setEndTime(e.target.value)} />
        </label>
        <Button size="icon" className="size-8" aria-label="Ajouter la fenêtre match" title="Ajouter la fenêtre match" disabled={rangeInvalid || create.isPending} onClick={add}>
          <Plus className="size-4" />
        </Button>
      </div>
      {rangeInvalid && "" !== startTime && "" !== endTime ? (
        <p className="text-xs text-destructive">La fenêtre doit finir après son début, le même jour.</p>
      ) : null}

      <ConfirmDialog
        open={null !== pendingDelete}
        title="Supprimer cette fenêtre d’accès match ?"
        description={pendingDelete ? <>La fenêtre {DAY_LABELS[pendingDelete.dayOfWeek] ?? "?"} {pendingDelete.startTime} – {pendingDelete.endTime} sera retirée de ce gymnase.</> : null}
        confirmLabel="Supprimer"
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
