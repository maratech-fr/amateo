import { CalendarClock } from "lucide-react";

import { StatusPill } from "@/shared/components/ui/badge";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";

import { DAYS, toHourMinute } from "./lib/grid";
import { type ToReplaceEntry, toReplaceReasonLabel } from "./lib/toReplaceReason";

const DAY_LABEL = new Map(DAYS.map((d) => [d.n, d.label]));

interface ToReplaceListProps {
  entries: ToReplaceEntry[];
  teamName: (teamId: string) => string;
  venueName: (venueId: string) => string;
}

/**
 * P2-44 (PR-2) — les séances du socle que la transcription N'A PAS reprises (« à replacer »),
 * SERVIES par la route de transcription (`PeriodTranscriptionResult`). Le front ne redérive RIEN :
 * équipe, jour, heure, gymnase d'origine et raison viennent tous du backend ; ici c'est de la
 * PRÉSENTATION pure.
 *
 * Portée de vie : cette liste vit tant que la SESSION D'ÉCRAN vit (elle enrichit — jour/heure/
 * gymnase/raison — ce que `DriftBanner` dit déjà par nature, les équipes sous leur quota). La
 * réponse n'est pas re-servable (la route est un POST qui crée la V1 ; la re-consulter sur un plan
 * déjà versionné rendrait 409), donc après navigation c'est `DriftBanner` qui prend le relais —
 * jamais une redérivation ici. Nom de région DISTINCT de `DriftBanner` pour ne pas dupliquer.
 */
export function ToReplaceList({ entries, teamName, venueName }: ToReplaceListProps) {
  if (0 === entries.length) {
    return null;
  }
  return (
    <NoticeBanner
      tone="warning"
      role="region"
      ariaLabel={`Séances non reprises du planning de saison (${entries.length})`}
      className="mb-4"
    >
      <p className="flex items-center gap-1.5 font-medium text-foreground">
        <CalendarClock aria-hidden="true" className="size-4 text-warning" />
        Séances non reprises du planning de saison ({entries.length})
      </p>
      <p className="text-xs text-muted-foreground">
        Elles n'ont pas pu être copiées telles quelles — à replacer sur un créneau libre.
      </p>
      <ul className="flex flex-col gap-1">
        {entries.map((entry, i) => (
          <li
            key={`${entry.teamId}-${entry.dayOfWeek}-${entry.startTime}-${entry.venueId}-${i}`}
            className="flex flex-wrap items-center gap-x-2 gap-y-0.5"
          >
            <span className="font-medium text-foreground">{teamName(entry.teamId)}</span>
            <span className="text-muted-foreground">
              {DAY_LABEL.get(entry.dayOfWeek) ?? "?"} {toHourMinute(entry.startTime)}
            </span>
            <span className="text-muted-foreground">· {venueName(entry.venueId)}</span>
            <StatusPill variant="warning">{toReplaceReasonLabel(entry.reason)}</StatusPill>
          </li>
        ))}
      </ul>
    </NoticeBanner>
  );
}
