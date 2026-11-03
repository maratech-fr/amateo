import { Trash2 } from "lucide-react";
import { useState } from "react";

import { useSchedules } from "@/features/planning/queries";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { ManagerOnly } from "@/shared/session/ManagerOnly";
import { toast } from "@/shared/stores/toastStore";

import { useDeleteEntry, useSchedulePlanForEntry } from "./queries";

interface DeletePlanningButtonProps {
  /** Entrée de calendrier du planning secondaire à supprimer (closure / holiday). */
  calendarEntryId: string;
  /** Plan de l'entrée, si l'appelant le connaît déjà — évite un refetch (revue B2 F6). */
  schedulePlanId?: string | null;
  /** Libellé du planning (confirmation + aria). */
  title: string;
  /** Appelé APRÈS suppression réussie (ex. retour cockpit ; la modale se rafraîchit seule). */
  onDeleted?: () => void;
  /** Rendu compact icône seule (lignes de liste) vs bouton avec texte. */
  iconOnly?: boolean;
  className?: string;
}

/**
 * Suppression PARTAGÉE d'un planning secondaire (retour fondateur 2026-07-19), utilisée par
 * « tous les plannings », l'en-tête de consultation et le bandeau wizard mode période — une
 * seule fois la logique (confirmation + cascade + mutation).
 *
 * `DELETE /calendar_entries/{id}` (useDeleteEntry) cascade l'entrée + son plan + toutes ses
 * versions, et laisse intacte la vacance scolaire (SchoolHoliday = référence FFBB) : supprimer
 * un ajustement HOLIDAY garde la vacance ; supprimer une CLOSURE emporte période + plans +
 * versions. Le plan de SAISON n'est jamais passé ici (les appelants ne rendent le bouton que
 * pour un overlay).
 */
export function DeletePlanningButton({ calendarEntryId, schedulePlanId, title, onDeleted, iconOnly = false, className }: DeletePlanningButtonProps) {
  const [confirming, setConfirming] = useState(false);
  const deleteEntry = useDeleteEntry();
  // « Porte des versions » = une Schedule pend au plan de l'entrée → l'alerte cascade doit
  // être forte. Fail-closed sur l'absence de donnée (plan/schedules pas résolus) : on avertit
  // tant qu'on ne SAIT pas, comme DayList (toDeleteHasVersions) — ne jamais sous-avertir.
  // Ne refetch le plan par entrée que si l'appelant ne l'a pas déjà fourni (revue B2 F6).
  const providedPlanId = schedulePlanId ?? null;
  const planQuery = useSchedulePlanForEntry(confirming && null === providedPlanId ? calendarEntryId : null);
  const { data: schedules } = useSchedules();
  const planId = providedPlanId ?? planQuery.data?.id ?? null;
  const planResolved = null !== providedPlanId || undefined !== planQuery.data;
  const resolved = planResolved && undefined !== schedules;
  const hasVersions = !resolved || (null !== planId && (schedules ?? []).some((s) => s.schedulePlanId === planId));

  const confirmDelete = () => {
    deleteEntry.mutate(calendarEntryId, {
      onSuccess: () => {
        toast.success("Planning supprimé");
        onDeleted?.();
      },
    });
    setConfirming(false);
  };

  // Un Membre ne supprime aucun planning : le bouton n'existe pas pour lui.
  return (
    <ManagerOnly>
      {iconOnly ? (
        <Button variant="ghost" size="icon" className={className ?? "size-8 text-muted-foreground hover:text-destructive"} aria-label={`Supprimer ${title}`} title="Supprimer" disabled={deleteEntry.isPending} onClick={() => setConfirming(true)}>
          <Trash2 className="size-4" />
        </Button>
      ) : (
        <Button variant="ghost" size="sm" className={className} aria-label={`Supprimer ${title}`} disabled={deleteEntry.isPending} onClick={() => setConfirming(true)}>
          <Trash2 className="size-4" />
          Supprimer
        </Button>
      )}

      <ConfirmDialog
        open={confirming}
        title={`Supprimer « ${title} » ?`}
        description={
          hasVersions
            ? "Supprimer ce planning supprime aussi son plan et toutes ses versions générées. Une vacance, elle, reste au calendrier (recréable via « Adapter »)."
            : "Ce planning secondaire sera retiré. Une vacance, elle, reste au calendrier."
        }
        confirmLabel="Supprimer"
        destructive={hasVersions}
        onConfirm={confirmDelete}
        onCancel={() => setConfirming(false)}
      />
    </ManagerOnly>
  );
}
