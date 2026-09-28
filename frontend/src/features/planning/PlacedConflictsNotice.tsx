import { AlertTriangle } from "lucide-react";

import { NoticeBanner } from "@/shared/components/ui/notice-banner";

import type { PlacedConflict } from "./api";
import { placedConflictLabel } from "./lib/placedConflictLabel";

/**
 * P4-269 — l'encart/bandeau « une personne à deux endroits en même temps » sur le planning
 * d'entraînement EN VIGUEUR. Consommé par l'étape Coachs du wizard (après une mutation de lien)
 * ET par la page `/planning` (version en vigueur) — même rendu, `role` réglé par l'appelant.
 *
 * PRÉSENTATION pure sur la primitive partagée {@link NoticeBanner} : chaque conflit vient du
 * backend (`getPlacedConflicts`), on ne fait que le formuler ({@link placedConflictLabel}). Rien
 * si la liste est vide (aucun conflit, ou aucun planning en vigueur → le backend a déjà tranché).
 */
export function PlacedConflictsNotice({ conflicts, role, className }: { conflicts: PlacedConflict[]; role?: "status" | "alert"; className?: string }) {
  if (0 === conflicts.length) {
    return null;
  }

  const heading = 1 === conflicts.length ? "Une personne est à deux endroits en même temps" : `${conflicts.length} personnes sont à deux endroits en même temps`;

  return (
    <NoticeBanner
      tone="warning"
      role={role}
      className={className}
      icon={<AlertTriangle className="size-4 text-warning" aria-hidden="true" />}
      message={<span className="font-medium">{heading}</span>}
    >
      <ul className="ml-6 list-disc space-y-1 text-foreground">
        {conflicts.map((conflict) => (
          <li key={`${conflict.personId}-${conflict.first.venueId}-${conflict.second.venueId}-${conflict.dayOfWeek}`}>{placedConflictLabel(conflict)}</li>
        ))}
      </ul>
    </NoticeBanner>
  );
}
