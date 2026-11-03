import { CalendarOff, Plus, Trash2 } from "lucide-react";
import { useState } from "react";

import { useVenueClosures } from "@/features/matches/queries";
import { useSchedules, useVenues } from "@/features/planning/queries";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { EmptyHint } from "@/shared/components/ui/empty-hint";
import { Modal } from "@/shared/components/ui/modal";
import { toast } from "@/shared/stores/toastStore";

import type { CalendarEntry } from "./api";
import { frDateShort, todayISO } from "./lib/date";
import { useCalendarEntries, useDeleteEntry, useEntryImpact, useSchedulePlans } from "./queries";
import { COCKPIT_TEXTS } from "./texts";
import { VenueClosureForm } from "./VenueClosureForm";

/** Horizon des fermetures listées (à venir), en jours. */
const HORIZON_DAYS = 400;

const plusDays = (iso: string, days: number): string => {
  const date = new Date(`${iso}T12:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
};

/**
 * « Indisponibilités de gymnase » (chantier « Fermetures de gymnase », C1/C8) — un gymnase qui ferme
 * est UN SEUL fait : l'entrée `closure` du calendrier + sa contrainte `venue_closed`. La carte liste
 * les fermetures À VENIR avec leur gymnase (servi par `/api/venue_closures`), ouvre LA saisie unique
 * ({@link VenueClosureForm}, la même que depuis le jour du calendrier), et sa suppression dit
 * honnêtement ce qu'elle emporte : le planning de période (validé / en cours / ses semaines), et —
 * seulement si la déclaration a réellement retiré des épingles — que rien n'est remis.
 */
export function VenueClosureCard() {
  // « Aujourd'hui » gelé au montage (même règle que les autres saisies du cockpit).
  const [today] = useState(() => todayISO());
  const entriesQuery = useCalendarEntries(today, plusDays(today, HORIZON_DAYS));
  const closuresQuery = useVenueClosures();
  const { data: venues } = useVenues();
  const { data: plans } = useSchedulePlans();
  const { data: schedules } = useSchedules();
  const deleteEntry = useDeleteEntry();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [pendingDelete, setPendingDelete] = useState<CalendarEntry | null>(null);
  const impact = useEntryImpact(pendingDelete?.id ?? null).data;

  const entries = entriesQuery.data ?? [];
  const roots = entries.filter((e) => "closure" === e.periodType && null === e.parentEntryId && e.endDate >= today);
  const closures = closuresQuery.data ?? [];
  const venueName = (venueId: string): string => venues?.find((v) => v.id === venueId)?.name ?? "";
  const venueNamesOf = (entryId: string): string[] => [...new Set(closures.filter((c) => c.calendarEntryId === entryId).map((c) => venueName(c.venueId)))];

  const deletionLines = (entry: CalendarEntry): string[] => {
    const lines: string[] = [];
    const children = entries.filter((e) => e.parentEntryId === entry.id).length;
    const plan = (plans ?? []).find((p) => p.calendarEntryId === entry.id);
    const versions = undefined !== plan ? (schedules ?? []).filter((s) => s.schedulePlanId === plan.id).length : 0;
    if (children > 0) {
      lines.push(COCKPIT_TEXTS.deleteClosureSplit(children));
    } else if (undefined !== plan && null !== plan.chosenScheduleId) {
      lines.push(COCKPIT_TEXTS.deleteClosureWithPlan(versions));
    } else if (undefined !== plan && versions > 0) {
      lines.push(COCKPIT_TEXTS.deleteClosureInProgress(versions));
    } else {
      lines.push(COCKPIT_TEXTS.deleteClosureBare);
    }
    if (true === impact?.removedAtDeclaration) {
      lines.push(COCKPIT_TEXTS.deleteClosureNothingRestored);
    }
    const name = venueNamesOf(entry.id)[0] ?? "";
    for (const governing of impact?.governingPlans ?? []) {
      if (governing.regeneratedWithout) {
        lines.push(COCKPIT_TEXTS.deleteClosureRegeneratedWithout(governing.title, name, governing.regeneratedWithoutWeekdays ?? []));
      }
    }
    return lines;
  };

  const confirmDelete = () => {
    if (null === pendingDelete) return;
    const name = venueNamesOf(pendingDelete.id)[0] ?? "";
    const regenerated = (impact?.governingPlans ?? []).find((p) => p.regeneratedWithout)?.title;
    deleteEntry.mutate(pendingDelete.id, { onSuccess: () => toast.success(COCKPIT_TEXTS.closureDeletedToast(name, regenerated)) });
    setPendingDelete(null);
  };

  return (
    <div className="rounded-lg border border-border bg-card p-3">
      <div className="mb-2 flex items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 text-sm font-semibold">
          <CalendarOff className="size-4 text-warning" />
          {COCKPIT_TEXTS.closureCardTitle}
        </h2>
        <Button variant="outline" size="sm" onClick={() => setDialogOpen(true)}>
          <Plus className="size-4" />
          {COCKPIT_TEXTS.declareCta}
        </Button>
      </div>

      {0 === roots.length ? (
        <EmptyHint>{COCKPIT_TEXTS.closureCardEmpty}</EmptyHint>
      ) : (
        <ul className="flex flex-col gap-2">
          {roots.map((entry) => {
            const names = venueNamesOf(entry.id);
            return (
              <li key={entry.id} className="rounded-md border border-border bg-surface-warning px-3 py-2 text-sm">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="font-medium">{entry.title}</p>
                    <p className="text-xs text-muted-foreground">
                      {frDateShort(entry.startDate)} → {frDateShort(entry.endDate)}
                      {names.length > 0 ? ` · ${names.join(", ")}` : ""}
                    </p>
                    {0 === names.length ? <p className="text-xs text-muted-foreground">{COCKPIT_TEXTS.closureNoVenue}</p> : null}
                  </div>
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    className="shrink-0 text-destructive"
                    aria-label={COCKPIT_TEXTS.deleteClosureLabel(entry.title)}
                    disabled={deleteEntry.isPending}
                    onClick={() => setPendingDelete(entry)}
                  >
                    <Trash2 className="size-4" />
                  </Button>
                </div>
              </li>
            );
          })}
        </ul>
      )}

      {dialogOpen ? (
        <Modal label={COCKPIT_TEXTS.closureDialogTitle} title={COCKPIT_TEXTS.closureDialogTitle} onClose={() => setDialogOpen(false)}>
          <VenueClosureForm initialStartDate={null} onDone={() => setDialogOpen(false)} />
        </Modal>
      ) : null}

      <ConfirmDialog
        open={null !== pendingDelete}
        title={COCKPIT_TEXTS.deleteClosureTitle(pendingDelete?.title ?? "")}
        description={
          null !== pendingDelete ? (
            <span className="flex flex-col gap-1">
              {deletionLines(pendingDelete).map((line) => (
                <span key={line}>{line}</span>
              ))}
            </span>
          ) : null
        }
        confirmLabel={COCKPIT_TEXTS.deleteCta}
        destructive
        onConfirm={confirmDelete}
        onCancel={() => setPendingDelete(null)}
      />
    </div>
  );
}
