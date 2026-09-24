import { useState } from "react";

import { useRenamePlanning } from "@/features/auth/queries";
import type { SchedulePlan } from "@/features/cockpit/api";
import type { MeResponse } from "@/shared/session/api";

import type { Schedule } from "../api";
import { isSeasonPlanType } from "./versions";

/**
 * Identité du plan affiché et renommage : l'état d'édition du nom, la mutation de renommage,
 * l'entrée de calendrier de suppression d'overlay, la version de tête, le plan affiché et son nom.
 * Déplacement pur depuis la page, entrées en paramètres individuels. `headerSchedule` reste INTERNE
 * (il ne sert que displayedPlan/displayedPlanName).
 */
export function usePlanHeader(
  selectedSchedule: Schedule | null,
  landingScheduleId: string | null,
  schedules: Schedule[],
  scoped: boolean,
  scopePlanId: string | null,
  allSchedulePlans: SchedulePlan[] | undefined,
  me: MeResponse | undefined,
) {
  const renamePlanning = useRenamePlanning();
  const [editingPlanningName, setEditingPlanningName] = useState<string | null>(null);

  // Suppression d'un planning SECONDAIRE (overlay) depuis l'en-tête (retour fondateur
  // 2026-07-19) : l'entrée de calendrier de son plan (jamais pour le socle SEASON).
  // `allSchedulePlans` est déjà lu plus haut (dérivation de la fermeture de période).
  const overlayDeleteEntryId =
    null !== selectedSchedule && !isSeasonPlanType(selectedSchedule.planType) && null !== selectedSchedule.schedulePlanId
      ? ((allSchedulePlans ?? []).find((p) => p.id === selectedSchedule.schedulePlanId)?.calendarEntryId ?? null)
      : null;
  // ADR-0002 inv. 12 : LE nom vit sur le PLAN, jamais sur la version. Tout ce que
  // l'en-tête montre ou modifie (titre, stylo, nom de fichier exporté, popup de
  // suppression) doit donc désigner le plan de la version AFFICHÉE — pas le plan de
  // saison. Il était codé en dur : renommer un planning de période renommait le
  // planning de la SAISON, et l'en-tête affichait son nom sur toutes les périodes.
  // `null` = plan pas encore résolu (collection en vol, ou plan absent) : l'appelant
  // dégrade, il ne devine pas.
  // Le club n'a AUCUNE version : on est dans le contexte SAISON par défaut, le plan de
  // saison reste le sujet de l'en-tête. Sans ce cas, un club qui n'a jamais généré perdait
  // le nom de son planning ET son stylo — il ne pouvait plus le nommer (revue #339 round 1).
  // ⚠ La condition porte sur « le club n'a aucune version » (`schedules.length`), PAS sur
  // « aucune version RÉSOLUE » : entre deux refetch, la sélection du store peut ne pas se
  // retrouver dans la liste, et un repli sur ce signal-là ré-armerait le plan de SAISON comme
  // cible du stylo alors que le gestionnaire est sur une période — le bug d'origine, de retour
  // par une porte transitoire (revue #339 round 2).
  // Entre deux refetch, la sélection du store peut ne plus être dans la liste (suppression
  // d'une version, sélection persistée d'une autre saison) : `selectedSchedule` est alors null
  // UNE passe de rendu, le temps que l'effet d'atterrissage rejoue. Plutôt que de laisser
  // l'en-tête retomber sur un générique — ou pire, sur le plan de SAISON alors qu'on regarde
  // une période —, on lit dès maintenant la version que cet effet va choisir : la MÊME
  // fonction, donc le même résultat, sans flash et sans deviner (revue #339 round 3).
  // L'en-tête lit dès maintenant la version que l'effet d'atterrissage va choisir (la MÊME
  // fonction, donc le même résultat, sans flash) : en portée, la version de la période — jamais
  // le socle ; hors portée, l'atterrissage embarqué/pointeur selon le contexte.
  const headerSchedule = selectedSchedule ?? (null !== landingScheduleId ? (schedules.find((s) => s.id === landingScheduleId) ?? null) : null);
  const displayedPlan: { id: string; name: string } | null = scoped
    ? ((allSchedulePlans ?? []).find((p) => p.id === scopePlanId) ?? null)
    : null === headerSchedule || isSeasonPlanType(headerSchedule.planType)
      ? (me?.seasonPlan ?? null)
      : ((allSchedulePlans ?? []).find((p) => p.id === headerSchedule.schedulePlanId) ?? null);
  // Le TITRE tolère un plan non encore résolu (collection des plans en vol) : la photo
  // `Schedule.name` porte le nom du plan à la création, donc un libellé juste dans l'immense
  // majorité des cas — bien mieux que le générique « Planning ». Le STYLO, lui, reste
  // conditionné au plan résolu : on ne propose pas un geste dont on n'a pas la cible.
  const displayedPlanName = displayedPlan?.name ?? headerSchedule?.name ?? null;

  return { editingPlanningName, setEditingPlanningName, renamePlanning, overlayDeleteEntryId, displayedPlan, displayedPlanName };
}
