import { AlertTriangle, ArrowRight, CalendarClock, ClipboardList } from "lucide-react";
import { Link } from "react-router";

import { useDeadlineOutlook } from "@/features/matches/queries";
import { daysUntilDeadline, frShortDate } from "@/features/matches/lib/deadlineLabel";
import { visitDeltaSegments } from "@/features/matches/lib/visitDeltaSegments";
import { Button } from "@/shared/components/ui/button";
import { cn } from "@/shared/lib/utils";

import { todayISO } from "./lib/date";

/**
 * La carte « Saisie FBI » du cockpit — le rappel de tout ce qui reste à porter dans le
 * portail fédéral, qui « remonte dès le login » (le placement de match est une urgence).
 *
 * 🔴 Le front n'invente AUCUNE règle : les fenêtres J-7 (`withinWindow`) et le compteur
 * GLOBAL `fbiTodo` (à saisir = domiciles PLACED · à corriger = entrées ouvertes) sont
 * calculés par le backend (`EntryDeadlineOutlook`) — la carte les AFFICHE. « Dépassée »
 * n'est qu'une présentation (la date est passée), pas une redérivation de règle.
 *
 * Trois régimes : (1) une échéance en fenêtre → ton ACCENT (rappel calme), escaladé en
 * WARNING si dépassée, avec la ligne globale au-dessus des échéances ; (2) hors fenêtre
 * mais du travail (`fbiTodo` > 0) → ton NEUTRE, la seule ligne globale ; (3) rien à faire
 * ET aucune fenêtre → `null` (le cockpit reste muet). Le lien unique ouvre la liste
 * « FBI — à faire » (`/matchs?fbi=1`). L'escalade du gardien fusionne dans LA MÊME carte.
 */
export function FbiDeadlineCard() {
  const { data } = useDeadlineOutlook();
  const today = todayISO();

  const windows = (data?.windows ?? []).filter((w) => w.withinWindow);
  const toEnter = data?.fbiTodo?.toEnter ?? 0;
  const toCorrect = data?.fbiTodo?.toCorrect ?? 0;
  const total = toEnter + toCorrect;

  // (3) Rien à faire ET aucune fenêtre → muet.
  if (0 === windows.length && 0 === total) {
    return null;
  }

  const inWindow = windows.length > 0;
  const anyOverdue = windows.some((w) => daysUntilDeadline(w.deadline, today) < 0);
  const deltaSegments = undefined !== data?.guardianDelta ? visitDeltaSegments(data.guardianDelta) : [];

  const tone = !inWindow ? "border-border bg-card" : anyOverdue ? "border-warning/40 bg-warning/5" : "border-accent/40 bg-accent/5";
  const globalLine = `${total} FBI à faire${toCorrect > 0 ? `, dont ${toCorrect} à corriger` : ""}`;

  return (
    <section role="status" className={cn("rounded-lg border p-3 text-sm", tone)}>
      <h2 className="mb-2 flex items-center gap-2 text-sm font-semibold">
        {!inWindow ? (
          <ClipboardList className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
        ) : anyOverdue ? (
          <AlertTriangle className="size-4 shrink-0 text-warning" aria-hidden="true" />
        ) : (
          <CalendarClock className="size-4 shrink-0 text-accent" aria-hidden="true" />
        )}
        Saisie FBI
      </h2>

      {total > 0 ? <p className="font-medium">{globalLine}</p> : null}

      {inWindow ? (
        <ul className={cn("flex flex-col gap-1.5", total > 0 ? "mt-2" : "")}>
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
      ) : null}

      {deltaSegments.length > 0 ? (
        <p className="mt-2 border-t border-border/60 pt-2 text-xs text-muted-foreground">
          <span className="font-medium text-foreground">Depuis votre dernière visite :</span> {deltaSegments.join(" · ")}
        </p>
      ) : null}

      <Button variant="outline" size="sm" className="mt-3" asChild>
        <Link to="/matchs?fbi=1">
          Ouvrir la liste FBI
          <ArrowRight className="size-4" aria-hidden="true" />
        </Link>
      </Button>
    </section>
  );
}
