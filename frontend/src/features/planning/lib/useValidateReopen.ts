import { useMemo, useState } from "react";
import type { NavigateFunction } from "react-router";

import type { SchedulePlan } from "@/features/cockpit/api";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { useWizardStore } from "@/features/wizard/store";

import type { Schedule } from "../api";
import { useReopenSchedule, useValidateImpact, useValidateSchedule } from "../queries";
import { isSeasonPlanType } from "./versions";

/**
 * Validation et réouverture — le cœur du lifecycle : les états de la modale/des escalades 409, les
 * mutations, l'impact de dépointage et les fonctions `validate()` / `reopen()` (dont le passage en
 * mode période du wizard). Déplacement pur depuis la page, entrées en paramètres individuels.
 * ⚠ `actionBusy` est recomposé au CALL-SITE depuis les mutations retournées (deleteMutation et
 * regenerateFromMutation restent en page).
 */
export function useValidateReopen(
  validScheduleId: string | null,
  displayed: Schedule | null,
  allSchedulePlans: SchedulePlan[] | undefined,
  navigate: NavigateFunction,
) {
  const validateMutation = useValidateSchedule();
  const reopenMutation = useReopenSchedule();
  const [validateOpen, setValidateOpen] = useState(false);
  // P2-52 — l'impact de dépointage de la validation, interrogé UNIQUEMENT quand la modale « Valider »
  // est ouverte (le geste est envisagé). N=0 → l'annonce ne s'affiche pas ; N>0 → le confirm gagne
  // l'avertissement « salle perdue » ; en vol / échec → le bouton Valider reste désactivé.
  const validateImpactQuery = useValidateImpact(validateOpen ? validScheduleId : null);
  const orphanImpact = useMemo(
    () => ({
      orphanCount: validateImpactQuery.data?.orphanedFixtures ?? 0,
      declaredCount: validateImpactQuery.data?.declaredOrphanedFixtures ?? 0,
      loading: readLoading(validateImpactQuery),
      failed: readFailed(validateImpactQuery),
      onRetry: () => void validateImpactQuery.refetch(),
    }),
    [validateImpactQuery],
  );

  // Lot 5 — ce que la validation change par rapport au planning en vigueur, lu sur la même annonce ; la
  // confirmation porte le jeton de la liste vue (le serveur l'exige quand des plannings de période partent).
  const announcement = useMemo(() => {
    const data = validateImpactQuery.data;
    return undefined === data ? null : { changes: data.changes ?? [], destroyedTitles: (data.destroyedPeriodPlans ?? []).map((plan) => plan.title), keptWishes: data.keptWishes ?? 0 };
  }, [validateImpactQuery.data]);
  const validate = () => {
    if (!validScheduleId) {
      return;
    }
    validateMutation.mutate(
      { id: validScheduleId, confirmChanges: validateImpactQuery.data?.changesToken },
      {
        onSuccess: () => {
          setValidateOpen(false);
          // Validated → land on /planning, the screen of the version IN FORCE. Valider
          // est la SORTIE de l'espace de travail (l'étape Génération du wizard) : le socle
          // validé devient la version en vigueur, et /planning en porte le badge de statut
          // et « Retravailler » (lot 5).
          navigate("/planning");
        },
      },
    );
  };

  // Rouvrir : seul un plan de PÉRIODE se rouvre (lot 5), ce qui ne détruit rien — aucune escalade.
  const reopen = () => {
    if (!validScheduleId) {
      return;
    }
    reopenMutation.mutate(
      { id: validScheduleId },
      {
        onSuccess: () => {
          // RÈGLE : toute navigation vers /wizard DÉCLARE son mode — aucun héritage du mode
          // ambiant du localStorage. Sans quoi rouvrir un overlay ouvrait la SAISON (ou la
          // mauvaise période) : `jumpTo("generate")` SEUL laissait le mode persisté décider.
          // On le dérive de la version rouverte : plan non-SEASON → mode période ancré sur SON
          // entrée (schedulePlanId → plan → calendarEntryId) ; plan SEASON → mode saison.
          const reopened = displayed; // === selectedSchedule ; `displayed` est en portée ici
          const reopenedEntryId =
            null !== reopened && !isSeasonPlanType(reopened.planType) && null !== reopened.schedulePlanId
              ? ((allSchedulePlans ?? []).find((p) => p.id === reopened.schedulePlanId)?.calendarEntryId ?? null)
              : null;
          if (null !== reopenedEntryId) {
            useWizardStore.getState().startPeriodMode(reopenedEntryId);
          } else {
            useWizardStore.getState().exitPeriodMode();
          }
          // Reopened to rework the plan → the wizard's generation step (mode already declared).
          useWizardStore.getState().jumpTo("generate");
          navigate("/wizard");
        },
      },
    );
  };

  return { validateOpen, setValidateOpen, validateMutation, reopenMutation, orphanImpact, announcement, validate, reopen };
}
