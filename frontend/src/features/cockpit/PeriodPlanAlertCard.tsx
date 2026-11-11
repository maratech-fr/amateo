import { AlertTriangle, CalendarCheck } from "lucide-react";

import { Button } from "@/shared/components/ui/button";
import { ManagerOnly } from "@/shared/session/ManagerOnly";

import type { SchedulePlan } from "./api";
import { useMarkPlanAlertSeen } from "./queries";
import { COCKPIT_TEXTS } from "./texts";

interface PeriodPlanAlertCardProps {
  /** Le planning de période qui porte une alerte SERVIE (`plan.alert`) — la carte n'en dérive aucune. */
  plan: SchedulePlan;
  /** « Voir le planning » : ouvre la version validée automatiquement. */
  onView: (scheduleId: string) => void;
  /** « Reprendre » : rouvre le wizard sur la période (commencée sans planning). */
  onResume: () => void;
  /** « Reprendre » bloqué (planning de saison non validé) : la raison, lisible au clavier. */
  resumeDisabledReason?: string;
}

/**
 * Carte d'alerte du radar pour un planning de période commencé (validation automatique 7 jours après
 * le début de la période) : « validé automatiquement » (des versions sont gardées : à rouvrir pour
 * choisir) ou « commencé sans planning ». Titrée du nom du planning servi. « J'ai vu » et
 * « Reprendre » sont des ÉCRITURES : réservés aux gestionnaires ; « Voir le planning » reste à tous.
 */
export function PeriodPlanAlertCard({ plan, onView, onResume, resumeDisabledReason }: PeriodPlanAlertCardProps) {
  const markSeen = useMarkPlanAlertSeen();
  const alert = plan.alert ?? null;
  if (null === alert) {
    return null;
  }

  const autoValidated = "auto_validated" === alert.kind;
  const chosenScheduleId = plan.chosenScheduleId;

  return (
    <div className="rounded-md border border-border p-3">
      <div className="flex items-start gap-2">
        <span className="mt-0.5">{autoValidated ? <CalendarCheck className="size-4 text-accent" /> : <AlertTriangle className="size-4 text-warning" />}</span>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-medium">{plan.name}</p>
          <p className="text-xs text-muted-foreground">
            {autoValidated ? COCKPIT_TEXTS.autoValidatedDetail(alert.chosenVersionNumber, alert.keptVersionCount) : COCKPIT_TEXTS.periodStartedWithoutPlan}
          </p>
        </div>
      </div>
      <div className="mt-2 flex flex-wrap justify-end gap-1">
        {autoValidated && null !== chosenScheduleId ? (
          <Button variant="outline" size="sm" onClick={() => onView(chosenScheduleId)}>
            {COCKPIT_TEXTS.viewPlanning}
          </Button>
        ) : null}
        {autoValidated ? null : (
          <ManagerOnly>
            <Button variant="outline" size="sm" disabled={undefined !== resumeDisabledReason} disabledReason={resumeDisabledReason} onClick={onResume}>
              {COCKPIT_TEXTS.resume}
            </Button>
          </ManagerOnly>
        )}
        <ManagerOnly>
          <Button variant="ghost" size="sm" aria-label={COCKPIT_TEXTS.seenFor(plan.name)} disabled={markSeen.isPending} onClick={() => markSeen.mutate({ planId: plan.id, kind: alert.kind })}>
            {COCKPIT_TEXTS.seen}
          </Button>
        </ManagerOnly>
      </div>
    </div>
  );
}
