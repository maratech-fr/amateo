import { addDays, mondayOf, type WeekWindow } from "@/features/cockpit/lib/date";

/** Le minimum d'une entrée calendrier pour rattacher un planning à sa période. */
interface EntryLike {
  id: string;
  parentEntryId: string | null;
}

/** Le minimum d'un planning pour en déduire sa semaine type. */
interface PlanLike {
  calendarEntryId: string | null;
  startDate: string;
}

/**
 * P2-63 PR 4 (Q8 / D-c, fondateur 2026-10-09) — les semaines d'une collecte de doléances
 * DÉRIVENT des plannings de la période, elles ne se cochent plus à la main.
 *
 * Chaque planning de la période (celui de la MÈRE pour un bloc non découpé, ceux des
 * semaines-ENFANTS pour une période scindée) contribue UNE semaine, ancrée au lundi de sa
 * PREMIÈRE semaine (« on attache à la première semaine ») : un planning scindé = sa semaine,
 * un planning d'un bloc = sa semaine type. Le serveur reste l'autorité (garde 422 à l'écriture,
 * `CoachWishCampaignStateProcessor`) : ce dérivé ne sert qu'à AFFICHER le choix (règle d'or —
 * le front affiche, il ne décide pas).
 *
 * Fonction PURE (hors React) : testable sans monter d'écran.
 */
export function planDerivedWeeks(motherId: string, entries: EntryLike[], plans: PlanLike[]): WeekWindow[] {
  const belongsToPeriod = new Set<string>([motherId]);
  for (const entry of entries) {
    if (entry.parentEntryId === motherId) {
      belongsToPeriod.add(entry.id);
    }
  }

  const mondays = new Set<string>();
  for (const plan of plans) {
    if (null !== plan.calendarEntryId && belongsToPeriod.has(plan.calendarEntryId)) {
      mondays.add(mondayOf(plan.startDate));
    }
  }

  return [...mondays].sort().map((monday) => ({ monday, startDate: monday, endDate: addDays(monday, 6) }));
}
