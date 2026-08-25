import { AlertTriangle, ArrowRight, CalendarClock } from "lucide-react";
import { Link } from "react-router";

import { useDeadlineOutlook } from "@/features/matches/queries";
import { daysUntilDeadline, frShortDate } from "@/features/matches/lib/deadlineLabel";
import { visitDeltaSegments } from "@/features/matches/lib/visitDeltaSegments";
import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";

import { todayISO } from "./lib/date";

/**
 * RMM-6 PR-3 — la PREMIÈRE incursion des matchs au cockpit d'accueil : un rappel de
 * SAISIE FBI qui « remonte dès le login » (décision fondateur : le placement de match
 * est une urgence). Carte AUTONOME, consommée cross-feature comme le radar consomme
 * déjà `features/matches/queries`.
 *
 * 🔴 Le front n'invente AUCUNE fenêtre : la carte ne rend QUE les échéances que le
 * backend a marquées `withinWindow` (règle J-7 = maison unique `EntryDeadlineOutlook`).
 * Hors fenêtre → aucun rendu, le cockpit reste muet sur les matchs. « Dépassée » n'est
 * qu'une PRÉSENTATION (la date est passée) — même formatage pur que la liste FBI
 * (`deadlineLabel`), pas une redérivation de règle métier.
 *
 * Passe design (`ui-ux-pro-max`) : pleine largeur au-dessus de la grille (surface au
 * login sans déloger le bandeau planning) ; ton ACCENT (rappel calme, `role="status"`,
 * jamais une alarme), escaladé d'un cran en WARNING quand une échéance est dépassée —
 * jamais destructive. L'escalade du gardien (delta de visite) fusionne dans LA MÊME
 * carte, en note subordonnée sous un filet, jamais un second bloc concurrent.
 */
export function FbiDeadlineCard() {
  const { data } = useDeadlineOutlook();
  const today = todayISO();

  const windows = (data?.windows ?? []).filter((w) => w.withinWindow);
  if (0 === windows.length) {
    return null;
  }

  const anyOverdue = windows.some((w) => daysUntilDeadline(w.deadline, today) < 0);
  const deltaSegments = undefined !== data?.guardianDelta ? visitDeltaSegments(data.guardianDelta) : [];

  return (
    <section
      role="status"
      className={cn("rounded-lg border p-3 text-sm", anyOverdue ? "border-warning/40 bg-warning/5" : "border-accent/40 bg-accent/5")}
    >
      <h2 className="mb-2 flex items-center gap-2 text-sm font-semibold">
        {anyOverdue ? <AlertTriangle className="size-4 shrink-0 text-warning" aria-hidden="true" /> : <CalendarClock className="size-4 shrink-0 text-accent" aria-hidden="true" />}
        Saisie FBI
      </h2>

      <ul className="flex flex-col gap-1.5">
        {windows.map((window) => {
          const overdue = daysUntilDeadline(window.deadline, today) < 0;
          const plural = window.toEnterCount > 1 ? "s" : "";
          return (
            <li key={`${window.deadline}|${window.source}`}>
              <span className={cn("font-medium", overdue ? "text-warning" : "text-foreground")}>
                {overdue
                  ? `échéance dépassée — ${window.toEnterCount} match${plural} toujours non saisi${plural}`
                  : `${window.toEnterCount} match${plural} à saisir avant le ${frShortDate(window.deadline)}`}
              </span>
              {window.competitionNames.length > 0 ? <span className="text-muted-foreground"> · {window.competitionNames.join(", ")}</span> : null}
              {"community" === window.source ? <span className="text-muted-foreground"> · proposée</span> : null}
            </li>
          );
        })}
      </ul>

      {deltaSegments.length > 0 ? (
        <p className="mt-2 border-t border-border/60 pt-2 text-xs text-muted-foreground">
          <span className="font-medium text-foreground">Depuis votre dernière visite :</span> {deltaSegments.join(" · ")}
        </p>
      ) : null}

      <Button variant="outline" size="sm" className="mt-3" asChild>
        <Link to="/matchs">
          Aller à la saisie
          <ArrowRight className="size-4" aria-hidden="true" />
        </Link>
      </Button>
    </section>
  );
}
