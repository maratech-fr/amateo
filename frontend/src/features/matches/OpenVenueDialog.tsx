import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { Input } from "@/shared/components/ui/input";
import { Modal } from "@/shared/components/ui/modal";
import { frDateShort, frDateWeekdayNoYear } from "@/shared/lib/date";
import { useWorkingSeason } from "@/shared/session/queries";

import type { Venue } from "./api";
import { useCreateVenueMatchWindow } from "./queries";
import { MATCHES_TEXTS } from "./texts";

interface OpenVenueDialogProps {
  venue: Venue;
  matchDate: string;
  defaultStart: string;
  defaultEnd: string;
  onClose: () => void;
  onOpened: () => void;
}

/**
 * La petite fenêtre du raccourci : ouvrir CE gymnase, CE jour-là. Elle écrit un fait du gymnase et
 * jamais la pose : le gestionnaire clique ensuite « Placer ». « Annuler » n'écrit rien.
 */
export function OpenVenueDialog({ venue, matchDate, defaultStart, defaultEnd, onClose, onOpened }: OpenVenueDialogProps) {
  const create = useCreateVenueMatchWindow();
  const season = useWorkingSeason();
  const [startTime, setStartTime] = useState(defaultStart);
  const [endTime, setEndTime] = useState(defaultEnd);
  const [label, setLabel] = useState("");
  const [submitted, setSubmitted] = useState(false);

  const rangeInvalid = "" === startTime || "" === endTime || startTime >= endTime;
  const outOfSeason = null !== season && (matchDate < season.startDate || matchDate > season.endDate);
  const title = MATCHES_TEXTS.openVenueDialogTitle(venue.name, frDateWeekdayNoYear(matchDate));

  const confirm = (): void => {
    setSubmitted(true);
    if (rangeInvalid || create.isPending) {
      return;
    }
    const trimmed = label.trim();
    create.mutate(
      { venueId: venue.id, openingDate: matchDate, startTime, endTime, ...("" === trimmed ? {} : { label: trimmed }) },
      {
        onSuccess: () => {
          onOpened();
          onClose();
        },
      },
    );
  };

  return (
    <Modal
      label={title}
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="outline" size="sm" onClick={onClose}>
            {MATCHES_TEXTS.openVenueCancel}
          </Button>
          <Button size="sm" disabled={create.isPending} onClick={confirm}>
            {MATCHES_TEXTS.openVenueConfirm}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        <p className="text-sm text-muted-foreground">{MATCHES_TEXTS.openVenueDialogBody(venue.name, startTime, endTime)}</p>
        {outOfSeason && null !== season ? (
          <p className="text-xs text-warning">{MATCHES_TEXTS.openingOutOfSeason(frDateShort(season.startDate), frDateShort(season.endDate))}</p>
        ) : null}
        <div className="flex flex-wrap items-end gap-2">
          <label className="flex flex-col gap-1 text-xs text-muted-foreground">
            {MATCHES_TEXTS.openingStartLabel}
            <Input type="time" value={startTime} onChange={(e) => setStartTime(e.target.value)} />
          </label>
          <label className="flex flex-col gap-1 text-xs text-muted-foreground">
            {MATCHES_TEXTS.openingEndLabel}
            <Input type="time" value={endTime} onChange={(e) => setEndTime(e.target.value)} />
          </label>
          <label className="flex min-w-40 flex-1 flex-col gap-1 text-xs text-muted-foreground">
            {MATCHES_TEXTS.openingLabelLabel}
            <Input maxLength={120} placeholder={MATCHES_TEXTS.openingLabelPlaceholder} value={label} onChange={(e) => setLabel(e.target.value)} />
          </label>
        </div>
        {submitted && rangeInvalid ? <p className="text-xs text-destructive">{MATCHES_TEXTS.matchWindowRangeInvalid}</p> : null}
      </div>
    </Modal>
  );
}
