import { Lock } from "lucide-react";
import { useState } from "react";
import { Navigate } from "react-router";

import { useMe } from "@/shared/session/queries";
import { useSchedules } from "@/features/planning/queries";
import { LoadErrorHint } from "@/shared/components/ui/load-error-hint";
import { NoticeBanner } from "@/shared/components/ui/notice-banner";
import { PageHeader } from "@/shared/components/ui/page-header";
import { FullPageSpinner } from "@/shared/components/ui/spinner";
import { readFailed, readLoading } from "@/shared/lib/readState";

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
  // UXS-09 — on garde les objets query (pas un `data ?? []` destructuré) pour distinguer « vide »
  // d'« échec de lecture » et dégrader PAR ZONE (décision fondateur), au lieu d'un calendrier nu.
  const entriesQuery = useCalendarEntries(from, to);
  const entries = entriesQuery.data ?? [];
  // The radar surfaces upcoming to-dos season-wide, not just the visible month.
  const radarToday = todayISO();
  const radarEntriesQuery = useCalendarEntries(radarToday, addDays(radarToday, 300));
  const radarEntries = radarEntriesQuery.data ?? [];
  // School holidays: season-wide for the radar (reminders), visible-month for the
  // calendar (so summer — and any month outside the season — shows when browsed).
  const holidaysQuery = useSchoolHolidays();
  const { data: holidays, isLoading: holidaysLoading } = holidaysQuery;
  const monthHolidaysQuery = useSchoolHolidays(from, to);
  const monthHolidays = monthHolidaysQuery.data;
  // Toutes les vacances sont adaptables — l'été inclus (planning de reprise,
  // retour fondateur 2026-07-18 ; lève l'exclusion `ete` de la revue #204, P2-5 E2).
  // Le radar clampe les dates à la fenêtre de saison avant toute création.
  const radarHolidays = holidays?.items ?? [];
  // Two explicit windows (the endpoint 400s without one when no season is active):
  // the visible month grid for the calendar dots, the radar horizon for reminders.
  const monthPublicHolidaysQuery = usePublicHolidays(from, to);
  const publicHolidays = monthPublicHolidaysQuery.data;
  const radarPublicHolidaysQuery = usePublicHolidays(radarToday, addDays(radarToday, PUBLIC_HOLIDAY_HORIZON_DAYS));
  const { data: radarPublicHolidays, isLoading: publicHolidaysLoading } = radarPublicHolidaysQuery;
  const schedulesQuery = useSchedules();
  const { data: schedules = [], isLoading: schedulesLoading } = schedulesQuery;
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
      {/* En-tête d'accueil : le tableau de bord n'avait aucun titre ni porte « Signaler » —
          désormais le même en-tête que les autres écrans, pour remonter une erreur dès l'accueil. */}
      <PageHeader title="Accueil" screen="/" />
      {!socleValidated ? (
        <NoticeBanner
          tone="accent"
          role="status"
          icon={<Lock className="size-4 text-accent" />}
          message={
            <span className="text-muted-foreground">
              Planning de saison <strong className="text-foreground">non validé</strong> — validez-le pour débloquer les <strong className="text-foreground">matchs</strong> et les <strong className="text-foreground">plannings secondaires</strong>.
            </span>
          }
        />
      ) : null}
      {/* UXS-09 — échec de lecture des plannings : « Réessayer » à la place du bandeau (jamais un
          bandeau calculé sur zéro plan). S'il a lu (donnée, même périmée), le bandeau reste. */}
      {readFailed(schedulesQuery) ? (
        <div className="rounded-lg border border-border bg-card p-4">
          <LoadErrorHint onRetry={() => void schedulesQuery.refetch()}>Le planning de saison n'a pas pu être chargé.</LoadErrorHint>
        </div>
      ) : (
        <SeasonPlanBanner schedules={schedules} socleValidated={socleValidated} loading={schedulesLoading} entries={radarEntries} />
      )}
      {/* RMM-6 PR-3 — le rappel de saisie FBI « remonte dès le login » : pleine largeur
          sous le bandeau planning, au-dessus de la grille. MUET (rend null) hors d'une
          fenêtre J-7 servie par le backend — zéro encombrement dans le cas courant. */}
      <FbiDeadlineCard />
      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
        {/* P2-5 E1 : mères ET semaines enfants s'affichent — une semaine pleine
            DÉBORDE sa mère (queue/tête hors incident), la filtrer laisserait ces
            jours sans marqueur ni accès (revue #262 round 1). Le calendrier
            empile les entrées chevauchantes comme avant. */}
        {/* UXS-09 (décision A) — l'en-tête du mois et les flèches de navigation restent TOUJOURS
            montés : le chargement/échec de la lecture des entrées ne touche QUE la grille (le
            calendrier reçoit `loading`/`failed`/`onRetry`), sinon chaque navigation de mois démontait
            l'en-tête et faisait rater les clics successifs. Bandeau discret sous la grille si seules
            les vacances/fériés du mois ont échoué (le reste vit). */}
        <div className="space-y-3">
          <MonthCalendar
            year={cursor.year}
            month={cursor.month}
            entries={entries}
            holidays={monthHolidays?.items ?? []}
            publicHolidays={publicHolidays?.items ?? []}
            onPrev={prev}
            onNext={next}
            loading={readLoading(entriesQuery)}
            failed={readFailed(entriesQuery)}
            onRetry={() => void entriesQuery.refetch()}
          />
          {!readFailed(entriesQuery) && !readLoading(entriesQuery) && (readFailed(monthHolidaysQuery) || readFailed(monthPublicHolidaysQuery)) ? (
            <NoticeBanner tone="warning" role="status" message="Les vacances scolaires ou les jours fériés du mois n'ont pas pu être chargés — réessayez." />
          ) : null}
        </div>
        <div className="flex flex-col gap-4">
          <RadarPanel
            entries={radarEntries}
            entriesFailed={readFailed(radarEntriesQuery)}
            entriesLoading={readLoading(radarEntriesQuery)}
            holidays={radarHolidays}
            publicHolidays={radarPublicHolidays?.items ?? []}
            publicHolidaysLoading={publicHolidaysLoading}
            publicHolidaysFailed={readFailed(radarPublicHolidaysQuery)}
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
