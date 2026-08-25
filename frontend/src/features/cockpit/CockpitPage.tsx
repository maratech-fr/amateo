import { Lock } from "lucide-react";
import { useState } from "react";
import { Navigate } from "react-router";

import { useMe } from "@/shared/session/queries";
import { useSchedules } from "@/features/planning/queries";
import { FullPageSpinner } from "@/shared/components/ui/spinner";

import { SeasonPlanBanner } from "./SeasonPlanBanner";
import { FbiDeadlineCard } from "./FbiDeadlineCard";
import { MonthCalendar } from "./MonthCalendar";
import { PUBLIC_HOLIDAY_HORIZON_DAYS, RadarPanel } from "./RadarPanel";
import { VenueUnavailabilityCard } from "./VenueUnavailabilityCard";
import { useCalendarEntries, usePublicHolidays, useSchoolHolidays } from "./queries";
import { addDays, monthWindow, todayISO } from "./lib/date";
import { useSocleValidated } from "@/shared/lib/socle";

/** Home cockpit — unlocked once the season's plan carries a first COMPLETED version
 *  (inv. 8/16 : avoir généré une fois suffit, donc rouvrir ne re-verrouille pas).
 *  Before that, the work-loop is home. */
export function CockpitPage() {
  const { data: me, isLoading } = useMe();
  // Le mois d'ouverture suit le « aujourd'hui » du front, horloge de dev comprise (revue
  // #344 round 2) : avec un `new Date()` nu, `?today=2026-12-20` décalait tous les filtres
  // mais laissait le calendrier sur le mois RÉEL — 42 cases grisées « passé », aucune
  // journée ouvrable, et le scénario que l'horloge existe pour rejouer devenait injouable.
  const [openingYear, openingMonth] = todayISO().split("-").map(Number);
  const [cursor, setCursor] = useState({ year: openingYear, month: openingMonth - 1 });

  const { from, to } = monthWindow(cursor.year, cursor.month);
  const { data: entries = [] } = useCalendarEntries(from, to);
  // The radar surfaces upcoming to-dos season-wide, not just the visible month.
  const radarToday = todayISO();
  const { data: radarEntries = [] } = useCalendarEntries(radarToday, addDays(radarToday, 300));
  // School holidays: season-wide for the radar (reminders), visible-month for the
  // calendar (so summer — and any month outside the season — shows when browsed).
  const { data: holidays, isLoading: holidaysLoading } = useSchoolHolidays();
  const { data: monthHolidays } = useSchoolHolidays(from, to);
  // Toutes les vacances sont adaptables — l'été inclus (planning de reprise,
  // retour fondateur 2026-07-18 ; lève l'exclusion `ete` de la revue #204, P2-5 E2).
  // Le radar clampe les dates à la fenêtre de saison avant toute création.
  const radarHolidays = holidays?.items ?? [];
  // Two explicit windows (the endpoint 400s without one when no season is active):
  // the visible month grid for the calendar dots, the radar horizon for reminders.
  const { data: publicHolidays } = usePublicHolidays(from, to);
  const { data: radarPublicHolidays, isLoading: publicHolidaysLoading } = usePublicHolidays(radarToday, addDays(radarToday, PUBLIC_HOLIDAY_HORIZON_DAYS));
  const { data: schedules = [], isLoading: schedulesLoading } = useSchedules();
  // D-28 : le prédicat partagé est un HOOK — il s'appelle donc ici, avec les autres, et
  // jamais après un early return (la version inline qu'il remplace n'était pas un hook,
  // et vivait plus bas : les règles des hooks l'auraient refusée là).
  const socleValidated = useSocleValidated();

  if (isLoading) {
    return <FullPageSpinner />;
  }
  // Onboarding (the club has never generated) → the wizard is home (AuthGuard
  // also enforces this; kept here as a defensive redirect). Once a version has
  // been produced the cockpit is the home screen, whether or not the manager has
  // settled on one yet.
  if (!me?.seasonPlan?.hasFinishedVersion) {
    return <Navigate to="/wizard" replace />;
  }
  // State 2 (versions exist but the plan points at none): the cockpit is
  // reachable, but matches + secondary plans stay locked until it does.

  const prev = () => setCursor((c) => (c.month === 0 ? { year: c.year - 1, month: 11 } : { year: c.year, month: c.month - 1 }));
  const next = () => setCursor((c) => (c.month === 11 ? { year: c.year + 1, month: 0 } : { year: c.year, month: c.month + 1 }));

  return (
    <div className="space-y-4">
      {!socleValidated ? (
        <div className="flex items-start gap-2 rounded-md border border-accent/40 bg-accent/10 px-3 py-2 text-sm" role="status">
          <Lock className="mt-0.5 size-4 shrink-0 text-accent" />
          <span className="text-muted-foreground">
            Planning principal <strong className="text-foreground">non validé</strong> — validez-le pour débloquer les <strong className="text-foreground">matchs</strong> et les <strong className="text-foreground">plannings secondaires</strong>.
          </span>
        </div>
      ) : null}
      <SeasonPlanBanner schedules={schedules} socleValidated={socleValidated} loading={schedulesLoading} entries={radarEntries} />
      {/* RMM-6 PR-3 — le rappel de saisie FBI « remonte dès le login » : pleine largeur
          sous le bandeau planning, au-dessus de la grille. MUET (rend null) hors d'une
          fenêtre J-7 servie par le backend — zéro encombrement dans le cas courant. */}
      <FbiDeadlineCard />
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        {/* P2-5 E1 : mères ET semaines enfants s'affichent — une semaine pleine
            DÉBORDE sa mère (queue/tête hors incident), la filtrer laisserait ces
            jours sans marqueur ni accès (revue #262 round 1). Le calendrier
            empile les entrées chevauchantes comme avant. */}
        <MonthCalendar year={cursor.year} month={cursor.month} entries={entries} holidays={monthHolidays?.items ?? []} publicHolidays={publicHolidays?.items ?? []} onPrev={prev} onNext={next} />
        <div className="flex flex-col gap-4">
          <RadarPanel
            entries={radarEntries}
            holidays={radarHolidays}
            publicHolidays={radarPublicHolidays?.items ?? []}
            publicHolidaysLoading={publicHolidaysLoading}
            zone={holidays?.zone ?? null}
            zoneLoading={holidaysLoading}
          />
          {/* P1-4 PR B — les indisponibilités gymnase vivent au calendrier :
              « ça affecte les matchs et le planning n'est qu'une conséquence ». */}
          <VenueUnavailabilityCard />
        </div>
      </div>
    </div>
  );
}
