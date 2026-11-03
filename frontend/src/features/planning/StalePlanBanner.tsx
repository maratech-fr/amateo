import type { ClosureImpact } from "@/features/cockpit/api";
import { useEntryImpactList } from "@/features/cockpit/queries";
import { useVenueClosures } from "@/features/matches/queries";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";

import { PLANNING_TEXTS } from "./texts";

/** « 10/3 » — jour/mois sans zéro de tête. */
const dayMonth = (iso: string): string => `${Number(iso.slice(8, 10))}/${Number(iso.slice(5, 7))}`;

/**
 * Fermetures de gymnase (C5) — sur l'écran d'un plan de période qu'une fermeture DÉCLARÉE rend
 * périmé (il garde des séances au gymnase fermé ces jours-là), un bandeau nomme le gymnase, les
 * jours et les équipes, et la consigne. Il AFFICHE `governingPlans[].sessions` servi par `/impact`
 * pour l'entrée du plan affiché (`planEntryId`) — jamais pour un autre plan, jamais pour un plan
 * sans version. Distinct de `stalenessMessage` (`lib/staleness.ts`), muet sur un plan validé.
 */
export function StalePlanBanner({ impact, planEntryId }: { impact: ClosureImpact | null; planEntryId: string }) {
  const plan = impact?.governingPlans.find((p) => p.entryId === planEntryId);
  if (undefined === plan || !plan.hasVersions || 0 === plan.sessions.length) {
    return null;
  }
  const dates = [...new Set(plan.sessions.map((s) => s.date))].sort();
  const teams = [...new Set(plan.sessions.map((s) => s.teamName))];
  const venueId = plan.sessions[0].venueId;
  const venue = impact?.closedSessions.find((s) => s.venueId === venueId)?.venueName ?? "";
  return (
    <NoticeBanner
      tone="warning"
      role="status"
      className="mb-4"
      message={PLANNING_TEXTS.staleByClosure(venue, dayMonth(dates[0]), dayMonth(dates[dates.length - 1]), teams, plan.sessions.length)}
    />
  );
}

/**
 * La source du bandeau sur l'écran du plan (contrat retenu, le plus simple) : les fermetures que
 * l'écran lit DÉJÀ pour la fenêtre du plan affiché (`/calendar-entries/{id}/conflicts` → `closures`,
 * transversales), rattachées à leur entrée par le feed `/api/venue_closures`, chacune lue par son
 * `/impact` (le même que la carte et le radar) ; un bandeau par fermeture qui touche CE plan. Aucune
 * lecture tant que la fenêtre n'a pas de fermeture.
 */
export function ClosureStalePlanBanners({ planEntryId, closureConstraintIds }: { planEntryId: string | null; closureConstraintIds: string[] }) {
  const closures = useVenueClosures(null !== planEntryId && closureConstraintIds.length > 0).data ?? [];
  const entryIds = [
    ...new Set(
      closures
        .filter((c) => closureConstraintIds.includes(c.id) && undefined !== c.calendarEntryId && c.calendarEntryId !== planEntryId)
        .map((c) => c.calendarEntryId as string),
    ),
  ];
  const impacts = useEntryImpactList(null !== planEntryId ? entryIds : []);
  if (null === planEntryId) {
    return null;
  }
  return (
    <>
      {impacts.map((q, i) => (
        <StalePlanBanner key={entryIds[i]} impact={q.data ?? null} planEntryId={planEntryId} />
      ))}
    </>
  );
}
