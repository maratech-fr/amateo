import { AlertTriangle } from "lucide-react";

import { NoticeBanner } from "@/shared/components/ui/notice-banner";

import type { PlacedAbsence } from "./api";
import { PLANNING_TEXTS } from "./texts";

/**
 * Les séances de la version affichée dont aucun coach attendu n'est disponible à leur début. PRÉSENTATION
 * pure : le backend a jugé (`getPlacedConflicts(scheduleId)`), on formule. Rien si la liste est vide.
 */
export function PlacedAbsencesNotice({ absences, className }: { absences: PlacedAbsence[]; className?: string }) {
  if (0 === absences.length) {
    return null;
  }

  return (
    <NoticeBanner
      tone="warning"
      className={className}
      icon={<AlertTriangle className="size-4 text-warning" aria-hidden="true" />}
      message={<span className="font-medium">{PLANNING_TEXTS.absencesHeading(absences.length)}</span>}
    >
      <ul className="ml-6 list-disc space-y-1 text-foreground">
        {absences.map((absence) => (
          <li key={`${absence.dayOfWeek}-${absence.startTime}-${absence.venueName}-${absence.teamNames.join("-")}`}>{PLANNING_TEXTS.absenceLine(absence)}</li>
        ))}
      </ul>
    </NoticeBanner>
  );
}
