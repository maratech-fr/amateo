import { dayLabelLong, isoDayOf } from "@/shared/lib/days";

import type { ClosureImpact } from "./api";
import { COCKPIT_TEXTS } from "./texts";

/** Nombre de matchs nommés au plus ; au-delà, « + n autres ». */
const MATCHES_SHOWN = 5;

export type ClosureImpactState = "idle" | "loading" | "ready" | "failed" | "out_of_season";

/** « 27/12 » — jour/mois sans zéro de tête. */
const dayMonth = (iso: string): string => `${Number(iso.slice(8, 10))}/${Number(iso.slice(5, 7))}`;

/**
 * L'aperçu d'une fermeture de gymnase (chantier « Fermetures de gymnase », C3/C5/E3) : équipes à
 * l'entraînement, matchs à déplacer NOMMÉS, verrous et réservations qui seront RETIRÉS à
 * l'enregistrement, plans de période qui deviendront périmés. Il AFFICHE la réponse de
 * `/api/venue-closure-impact` — le serveur seul calcule (règle d'or : le front n'invente rien).
 * `venueName` vient du formulaire (le gestionnaire vient de choisir le gymnase).
 */
export function ClosureImpactPanel({ impact, state, venueName }: { impact: ClosureImpact | null; state: ClosureImpactState; venueName: string }) {
  if ("idle" === state || "out_of_season" === state) {
    return null;
  }
  if ("failed" === state) {
    return (
      <p role="status" className="text-xs text-destructive">
        {COCKPIT_TEXTS.closureImpactFailed}
      </p>
    );
  }
  if ("loading" === state || null === impact) {
    return (
      <p role="status" className="text-xs text-muted-foreground">
        {COCKPIT_TEXTS.closureImpactEvaluating}
      </p>
    );
  }

  const [first] = impact.teams;
  const matches = impact.matchesToMove;
  return (
    <div className="space-y-2 rounded-md border border-border bg-card p-3 text-sm" role="status">
      {undefined !== first ? (
        <p>{COCKPIT_TEXTS.closureImpactTeams(impact.teams.length, first.teamName, first.tierLabel)}</p>
      ) : impact.seasonPlanChosen ? (
        <p className="text-muted-foreground">{COCKPIT_TEXTS.closureImpactNone}</p>
      ) : (
        <p className="text-muted-foreground">{COCKPIT_TEXTS.closureImpactSeasonUnvalidated}</p>
      )}

      {0 === matches.length ? (
        <p className="text-muted-foreground">{COCKPIT_TEXTS.closureNoMatches}</p>
      ) : (
        <div>
          <p className="font-medium">{COCKPIT_TEXTS.closureMatchesHeading}</p>
          <ul className="list-disc pl-5">
            {matches.slice(0, MATCHES_SHOWN).map((m) => (
              <li key={m.fixtureId}>{COCKPIT_TEXTS.closureMatchLine(m.teamName, m.opponentLabel, dayLabelLong(isoDayOf(m.matchDate)), dayMonth(m.matchDate), m.kickoffTime ?? undefined)}</li>
            ))}
          </ul>
          {matches.length > MATCHES_SHOWN ? <p className="text-muted-foreground">{COCKPIT_TEXTS.closureMatchesMore(matches.length - MATCHES_SHOWN)}</p> : null}
        </div>
      )}

      {impact.governingPlans.map((plan) => {
        const { locks, reservations } = plan.toRemove;
        const touched = locks > 0 || reservations.length > 0 || plan.sessions.length > 0;
        return (
          <div key={plan.entryId} className="space-y-1">
            {locks > 0 ? <p>{COCKPIT_TEXTS.closureLocksRemoval(locks, venueName, plan.title)}</p> : null}
            {reservations.length > 0 ? <p>{COCKPIT_TEXTS.closureReservationsRemoval(reservations, venueName)}</p> : null}
            {touched && plan.hasVersions ? (
              <p className="font-medium">{null !== plan.chosenScheduleId ? COCKPIT_TEXTS.planStaleByClosureFuture(plan.title) : COCKPIT_TEXTS.planInProgressClosedFuture(plan.title)}</p>
            ) : null}
          </div>
        );
      })}
    </div>
  );
}
