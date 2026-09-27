import { CalendarClock } from "lucide-react";

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
    <section
      className="mb-4 rounded-md border border-warning/40 bg-surface-warning px-3 py-2 text-sm"
      aria-label={`Séances non reprises du planning de saison (${entries.length})`}
    >
      <p className="flex items-center gap-1.5 font-medium text-foreground">
        <CalendarClock aria-hidden="true" className="size-4 text-warning" />
        Séances non reprises du planning de saison ({entries.length})
      </p>
      <p className="mt-0.5 text-xs text-muted-foreground">
        Elles n'ont pas pu être copiées telles quelles — à replacer sur un créneau libre.
      </p>
      <ul className="mt-1.5 flex flex-col gap-1">
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
            <span className="rounded-full border border-warning/50 px-2 py-0.5 text-xs text-foreground">
              {toReplaceReasonLabel(entry.reason)}
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}
