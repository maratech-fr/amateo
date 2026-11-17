import { Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { Select } from "@/shared/components/ui/select";

import type { VenueOccupancy, VenueTrainingSlot } from "../api";
import { hhmm } from "../lib/days";
import { occupancyPlacementError } from "../lib/slotOverlap";
import { WEEK } from "../lib/weekGrid";
import { useDeleteVenueOccupancy, useUpdateVenueOccupancy } from "../queries";
import { WIZARD_TEXTS } from "../texts";
import { DurationSelect, SlotEndField } from "./slotFields";

/** Longueur maximale d'un motif d'occupation (même borne que le serveur). */
const LABEL_MAX_LENGTH = 40;

/**
 * « Modifier l'occupation » — jour, début, durée, fin calculée, motif ; ou la supprimer, après
 * confirmation. Même pré-contrôle que la pose (`occupancyPlacementError`) : `otherSlots` sont les
 * créneaux du gymnase affiché, `otherOccupancies` ses autres occupations (celle-ci exclue). Le
 * serveur reste juge. Ne se referme qu'au succès de l'écriture.
 */
export function OccupancyEditor({
  occupancy,
  otherSlots,
  otherOccupancies,
  onClose,
}: {
  occupancy: VenueOccupancy;
  otherSlots: VenueTrainingSlot[];
  otherOccupancies: VenueOccupancy[];
  onClose: () => void;
}) {
  const layer = occupancy.schedulePlanId ?? undefined;
  const update = useUpdateVenueOccupancy(layer);
  const del = useDeleteVenueOccupancy(layer);
  const [day, setDay] = useState(occupancy.dayOfWeek);
  const [time, setTime] = useState(hhmm(occupancy.startTime));
  const [duration, setDuration] = useState(occupancy.durationMinutes);
  const [label, setLabel] = useState(occupancy.label);
  const [error, setError] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);

  const save = () => {
    const motif = label.trim();
    if ("" === motif) {
      setError(WIZARD_TEXTS.occupancyMissingLabel);
      return;
    }
    const invalid = occupancyPlacementError(otherSlots, otherOccupancies, day, time, duration);
    if (null !== invalid) {
      setError(invalid);
      return;
    }
    update.mutate({ id: occupancy.id, body: { venueId: occupancy.venueId, dayOfWeek: day, startTime: time, durationMinutes: duration, label: motif } }, { onSuccess: onClose });
  };

  return (
    <Modal
      label={WIZARD_TEXTS.editOccupancyTitle}
      title={WIZARD_TEXTS.editOccupancyTitle}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" className="text-destructive" onClick={() => setConfirmDelete(true)}>
            <Trash2 className="size-4" />
            {WIZARD_TEXTS.deleteLabel}
          </Button>
          <Button onClick={save} disabled={update.isPending}>
            {WIZARD_TEXTS.save}
          </Button>
        </>
      }
    >
      <div className="mt-3 flex flex-wrap items-end gap-3">
        <label className="text-xs text-muted-foreground">
          {WIZARD_TEXTS.occupancyDayLabel}
          <Select aria-label={WIZARD_TEXTS.occupancyDayLabel} className="mt-0.5 h-9" wrapperClassName="w-28" value={day} onChange={(e) => (setDay(Number(e.target.value)), setError(null))}>
            {WEEK.map((d) => (
              <option key={d.n} value={d.n}>
                {d.label}
              </option>
            ))}
          </Select>
        </label>
        <label className="text-xs text-muted-foreground">
          {WIZARD_TEXTS.occupancyStartLabel}
          <Input aria-label={WIZARD_TEXTS.occupancyStartLabel} type="time" className="mt-0.5 h-9 w-28" value={time} onChange={(e) => (setTime(e.target.value), setError(null))} />
        </label>
        <label className="text-xs text-muted-foreground">
          {WIZARD_TEXTS.occupancyDurationLabel}
          <DurationSelect aria-label={WIZARD_TEXTS.occupancyDurationLabel} className="mt-0.5 h-9" wrapperClassName="w-28" value={duration} present={[occupancy.durationMinutes]} startTime={time} onChange={(d) => (setDuration(d), setError(null))} />
        </label>
        <SlotEndField startTime={time} duration={duration} />
        <label className="text-xs text-muted-foreground">
          {WIZARD_TEXTS.occupancyLabelField}
          <Input
            aria-label={WIZARD_TEXTS.occupancyLabelField}
            placeholder={WIZARD_TEXTS.occupancyLabelPlaceholder}
            maxLength={LABEL_MAX_LENGTH}
            className="mt-0.5 h-9 w-48"
            value={label}
            onChange={(e) => (setLabel(e.target.value), setError(null))}
          />
        </label>
      </div>

      {null !== error ? (
        <p role="alert" className="mt-3 text-sm text-destructive">
          {error}
        </p>
      ) : null}

      <ConfirmDialog
        open={confirmDelete}
        destructive
        title={WIZARD_TEXTS.occupancyDeleteTitle(occupancy.label)}
        description={WIZARD_TEXTS.occupancyDeleteDescription}
        confirmLabel={WIZARD_TEXTS.deleteLabel}
        onConfirm={() => {
          del.mutate(occupancy.id, { onSuccess: onClose });
          setConfirmDelete(false);
        }}
        onCancel={() => setConfirmDelete(false)}
      />
    </Modal>
  );
}
