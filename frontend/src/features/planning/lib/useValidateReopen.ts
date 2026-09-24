import { useMemo, useState } from "react";
import type { NavigateFunction } from "react-router";

import type { SchedulePlan } from "@/features/cockpit/api";
import { readFailed, readLoading } from "@/shared/lib/readState";
import { useWizardStore } from "@/features/wizard/store";

import { OverlaysExistError, type Schedule } from "../api";
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
  // Reopening the baseline with period overlays → 409; confirm to delete them.
  const [reopenOverlayCount, setReopenOverlayCount] = useState<number | null>(null);

  // Validating a non-baseline version with overlays → 409 escalation (same
  // destructive idiom as reopen): confirm, then re-POST with the flag.
  const [validateOverlayCount, setValidateOverlayCount] = useState<number | null>(null);
  const validate = (confirmDeleteOverlays?: boolean) => {
    if (!validScheduleId) {
      return;
    }
    validateMutation.mutate(
      { id: validScheduleId, confirmDeleteOverlays },
      {
        onSuccess: () => {
          setValidateOverlayCount(null);
          setValidateOpen(false);
          // Validated → land on /planning, the screen of the version IN FORCE. Valider
          // est la SORTIE de l'espace de travail (l'étape Génération du wizard) : le socle
          // validé devient la version en vigueur, et /planning en porte le badge de statut
          // et « Rouvrir » (symétrie stricte, 2026-08-20 — Valider ↔ Rouvrir).
          navigate("/planning");
        },
        onError: (error) => {
          if (error instanceof OverlaysExistError) {
            setValidateOpen(false);
            setValidateOverlayCount(error.count);
          }
        },
      },
    );
  };

  const reopen = (confirmDeleteOverlays?: boolean) => {
    if (!validScheduleId) {
      return;
    }
    reopenMutation.mutate(
      { id: validScheduleId, confirmDeleteOverlays },
      {
        onSuccess: () => {
          setReopenOverlayCount(null);
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
        // Generic failures are toasted by the hook (unmount-safe); only the
        // 409 escalation is UI state handled here.
        onError: (error) => {
          if (error instanceof OverlaysExistError) {
            setReopenOverlayCount(error.count);
          }
        },
      },
    );
  };

  return { validateOpen, setValidateOpen, reopenOverlayCount, setReopenOverlayCount, validateOverlayCount, setValidateOverlayCount, validateMutation, reopenMutation, orphanImpact, validate, reopen };
}
