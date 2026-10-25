import { CalendarClock } from "lucide-react";

import { NoticeBanner } from "@/shared/components/ui/notice-banner";

import type { PlacedRelay } from "./api";
import { placedRelayLabel } from "./lib/placedRelayLabel";

/**
 * Chantier H — l'encart des RELAIS d'adjoint (retards tolérés ouverts par un adjoint) sur le
 * planning d'entraînement EN VIGUEUR. Affiché À CÔTÉ des conflits « personne à deux endroits »,
 * mais DISTINCT : un relais n'est PAS un conflit (il est toléré), il n'entre pas dans leur compte.
 *
 * PRÉSENTATION pure sur {@link NoticeBanner} : chaque relais vient du backend
 * (`getPlacedConflicts().relays`), on ne fait que le formuler ({@link placedRelayLabel}). Rien si la
 * liste est vide — l'écran au repos (aucun relais) est inchangé.
 */
export function PlacedRelaysNotice({ relays, role, className }: { relays: PlacedRelay[]; role?: "status" | "alert"; className?: string }) {
  if (0 === relays.length) {
    return null;
  }

  const heading = 1 === relays.length ? "1 relais d'adjoint" : `${relays.length} relais d'adjoint`;

  return (
    <NoticeBanner
      tone="warning"
      role={role}
      className={className}
      icon={<CalendarClock className="size-4 text-warning" aria-hidden="true" />}
      message={<span className="font-medium">{heading}</span>}
    >
      <ul className="ml-6 list-disc space-y-1 text-foreground">
        {relays.map((relay) => (
          <li key={`${relay.personId}-${relay.first.venueId}-${relay.second.venueId}-${relay.dayOfWeek}`}>{placedRelayLabel(relay)}</li>
        ))}
      </ul>
    </NoticeBanner>
  );
}
