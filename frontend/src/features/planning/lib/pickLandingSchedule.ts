import { IN_FLIGHT_STATUSES, type ScheduleStatus } from "@/shared/lib/scheduleStatus";

import { isSeasonPlanType, visibleSeasonPlans } from "./versions";

// D-31 : « en vol » a UN foyer, `shared/lib/scheduleStatus.ts`. Ce fichier en avait repris une
// copie littérale née après la consolidation (P4-147) — un statut ajouté côté serveur aurait
// bougé le foyer sans bouger la copie, et l'atterrissage aurait choisi un planning encore en
// cours de génération.
type LandingSchedule = { id: string; status: ScheduleStatus; createdAt: string; planType: string | null; schedulePlanId: string | null; isChosen?: boolean };

export function pickDefaultSchedule(schedules: LandingSchedule[]): string | null {
  const seasonPlans = visibleSeasonPlans(schedules);
  if (0 === seasonPlans.length) {
    return null;
  }
  const byRecent = [...seasonPlans].sort((a, b) => b.createdAt.localeCompare(a.createdAt));
  return (byRecent.find((s) => "COMPLETED" === s.status) ?? byRecent[0]).id;
}

export function pickLandingScheduleId(schedules: LandingSchedule[]): string | null {
  // La version en vigueur se lit sur elle-même (isChosen) : la redemander à
  // /api/me obligerait l'appelant à porter un pointeur qu'il a déjà sous la main,
  // et à le tenir synchrone. Une seule source.
  const base = schedules.find((s) => true === s.isChosen && isSeasonPlanType(s.planType));

  return base && !IN_FLIGHT_STATUSES.includes(base.status) ? base.id : pickDefaultSchedule(schedules);
}
