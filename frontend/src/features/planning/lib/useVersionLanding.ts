import { useEffect, useMemo } from "react";

import type { Schedule } from "../api";
import { IN_FLIGHT_STATUSES } from "@/shared/lib/scheduleStatus";

import { isSeasonPlanType, planRepresentative, representativeVersion, visibleOverlayVersions, visibleSeasonPlans } from "./versions";
import { pickLandingScheduleId } from "./pickLandingSchedule";

/**
 * Portée et atterrissage de version : `scoped`, les versions en portée, la version sur laquelle
 * atterrir, l'effet de sélection, la version valide affichée et sa couche de créneaux. Aucun état
 * propre — la sélection vit dans `usePlanningStore` (qui reste en page) : elle est passée en
 * paramètres (`selectedScheduleId` / `setSelectedScheduleId`). Déplacement pur depuis la page.
 */
export function useVersionLanding(
  schedules: Schedule[],
  scopePlanId: string | null,
  embedded: boolean,
  selectedScheduleId: string | null,
  setSelectedScheduleId: (id: string | null) => void,
  sessionLaunchedScheduleId: string | null = null,
) {
  // Portée d'affichage (bug fondateur 2026-08-19). `scoped` ⇒ l'écran ne connaît QUE les
  // versions de ce plan de période : le socle et les autres périodes n'entrent ni dans
  // l'atterrissage, ni dans la toolbar, ni dans le titre. Sans portée, tout est inchangé.
  const scoped = null !== scopePlanId;
  const scopeVersions = useMemo(() => (scoped ? visibleOverlayVersions(schedules, scopePlanId) : null), [scoped, schedules, scopePlanId]);
  // La version sur laquelle atterrir (règle ARBITRÉE fondateur 2026-08-19). EMBARQUÉ (étape
  // Génération) en PÉRIODE ⇒ la version la plus RÉCENTE de la portée, génération EN VOL comprise :
  // le gestionnaire doit revoir la génération qu'il vient de lancer (le seed BCCL, V1 transcrite
  // POINTÉE, ramenait sinon toujours la V1). EMBARQUÉ en SAISON ⇒ la version CHARGÉE, là où il
  // travaille, sauf une génération en vol, ou un échec dans la session qui l'a lancée : hors de cette
  // session, un vieil échec ne détourne plus de la version chargée. NON embarqué (`/planning` autonome, cockpit) ⇒ POINTEUR
  // d'abord, STRICTEMENT inchangé (frontière de `pickLanding.test.ts`). Fail-closed en portée :
  // on atterrit DANS la portée ou nulle part, JAMAIS via `pickLandingScheduleId` (socle).
  const landingScheduleId = useMemo(() => {
    if (scoped) {
      const versions = scopeVersions ?? [];
      return embedded ? (versions.at(-1)?.id ?? null) : (planRepresentative(versions)?.id ?? versions.at(-1)?.id ?? null);
    }
    if (embedded) {
      const versions = visibleSeasonPlans(schedules);
      const inFlight = versions.findLast((s) => IN_FLIGHT_STATUSES.includes(s.status));
      const failedInSession = versions.find((s) => s.id === sessionLaunchedScheduleId && "FAILED" === s.status);
      return inFlight?.id ?? failedInSession?.id ?? versions.find((s) => true === s.isLiveContext && "COMPLETED" === s.status)?.id ?? representativeVersion(versions)?.id ?? versions.at(-1)?.id ?? null;
    }
    return schedules.length > 0 ? pickLandingScheduleId(schedules) : null;
  }, [scoped, embedded, scopeVersions, schedules, sessionLaunchedScheduleId]);

  // Keep a valid selection: default to the season base plan, else the latest
  // completed. A selection archived concurrently (sibling validation in another
  // tab) is invalid too — the selector has no option for it. En portée, la sélection
  // n'est valide que si elle appartient À la portée : une sélection de saison laissée
  // par un autre écran ne survit donc pas (le bug d'origine).
  const selectionInScope = !scoped || (null !== scopeVersions && scopeVersions.some((s) => s.id === selectedScheduleId));
  const validScheduleId = schedules.some((s) => s.id === selectedScheduleId) && selectionInScope ? selectedScheduleId : null;
  useEffect(() => {
    if (null !== validScheduleId) {
      return;
    }
    if (null !== landingScheduleId && landingScheduleId !== selectedScheduleId) {
      setSelectedScheduleId(landingScheduleId);
    }
  }, [validScheduleId, landingScheduleId, selectedScheduleId, setSelectedScheduleId]);

  // La COUCHE de créneaux de la version affichée (#8) : le socle lit la grille de
  // saison, une période lit la sienne. Dérivée ici, avant les requêtes, pour que
  // l'écran et l'export montrent les mêmes créneaux vides.
  const displayed = schedules.find((s) => s.id === validScheduleId) ?? null;
  const slotLayerId = null !== displayed && !isSeasonPlanType(displayed.planType) ? (displayed.schedulePlanId ?? null) : null;

  return { scoped, scopeVersions, landingScheduleId, validScheduleId, displayed, slotLayerId };
}
