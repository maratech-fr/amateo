import { useMemo } from "react";

import { useEntryConflicts, useSchedulePlans } from "@/features/cockpit/queries";
import { readFailed, readLoading } from "@/shared/lib/readState";

import type { Schedule } from "../api";
import { isSeasonPlanType } from "./versions";

/**
 * Fermetures de gymnase et gymnases désactivés de la période affichée : le plan de période, son
 * entrée de calendrier, l'état de fermeture SERVI, sa résolution et les gymnases désactivés.
 * Déplacement pur depuis la page, entrées en paramètres individuels. Retourne aussi `allSchedulePlans`
 * et `entryConflicts` : d'autres blocs restés en page les lisent (en-tête, réouverture, fenêtres fermées).
 */
export function usePeriodClosures(displayed: Schedule | null, calendarEntryId: string | null) {
  // P2-43 volet (v) — l'état de fermeture des gymnases SERVI par le backend pour la PÉRIODE
  // affichée (`GET /calendar-entries/{id}/conflicts`, foyer unique déjà consommé par le wizard).
  // L'entrée de calendrier : prop en embarqué (Génération l'a en main), sinon dérivée du plan de
  // la version affichée — JAMAIS le socle (une version de saison n'a pas d'entrée de période).
  const { data: allSchedulePlans } = useSchedulePlans();
  const periodPlan =
    null !== displayed && !isSeasonPlanType(displayed.planType) && null !== displayed.schedulePlanId
      ? ((allSchedulePlans ?? []).find((p) => p.id === displayed.schedulePlanId) ?? null)
      : null;
  const periodEntryId = calendarEntryId ?? periodPlan?.calendarEntryId ?? null;
  const entryConflicts = useEntryConflicts(periodEntryId);
  const conflictsUnresolved = readLoading(entryConflicts) || readFailed(entryConflicts);
  // FAIL-CLOSED sur l'OFFRE : on n'ARME pas un geste cible tant que l'état de fermeture n'est pas
  // connu (le moteur refuserait un placement sur un couple fermé). Le socle n'a rien à attendre ;
  // une version de PÉRIODE dont le plan n'est pas encore résolu compte comme non résolue (on ne
  // DEVINE pas l'absence de fermeture). Fail-CLOSED sur l'offre, fail-OPEN sur l'affichage.
  const periodPlanPending = null !== displayed && !isSeasonPlanType(displayed.planType) && null === calendarEntryId && undefined === allSchedulePlans;
  const closuresResolved = !periodPlanPending && (null === periodEntryId || !conflictsUnresolved);
  // P2-15 — un gymnase DÉSACTIVÉ pour la période garde ses créneaux en base (le backend
  // les écarte du payload, il ne les supprime pas) : sans ce filtre, l'écran de génération
  // affichait TOUS les gymnases du club alors qu'un seul sert — « du bruit pour rien ».
  // On filtre à la SOURCE : la grille, ses fenêtres vides et le sélecteur en dérivent tous.
  // On lit l'état SERVI (`disabledVenueIds`), plus de re-dérivation locale depuis les overrides
  // (le wizard a migré de même — règle d'or). FAIL-CLOSED sur l'AFFICHAGE (P4-20) : lecture ratée
  // / pas encore résolue ⇒ on ne masque rien.
  const disabledVenueIds = useMemo(
    () => new Set(conflictsUnresolved ? [] : (entryConflicts.data?.disabledVenueIds ?? [])),
    [conflictsUnresolved, entryConflicts.data],
  );

  return { allSchedulePlans, entryConflicts, conflictsUnresolved, closuresResolved, disabledVenueIds };
}
