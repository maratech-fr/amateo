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
 * 🔴 Le front n'invente AUCUNE règle : les fenêtres J-7 (`withinWindow`), les deux compteurs
 * par échéance (`toPlaceCount` = domiciles UNPLACED à placer · `toEnterCount` = domiciles PLACED
 * à saisir dans FBI) et le compteur GLOBAL `fbiTodo` (à saisir = domiciles PLACED · à corriger =
 * entrées ouvertes) sont calculés par le backend (`EntryDeadlineOutlook`) — la carte les AFFICHE.
 * « Dépassée » n'est qu'une présentation (la date est passée), pas une redérivation de règle.
 *
 * Trois régimes : (1) une échéance en fenêtre → ton ACCENT (rappel calme), escaladé en
 * WARNING si dépassée, avec la ligne globale au-dessus des échéances ; (2) hors fenêtre
 * mais du travail (`fbiTodo` > 0) → ton NEUTRE, la seule ligne globale ; (3) rien à faire
 * ET aucune fenêtre → `null` (le cockpit reste muet). Chaque échéance distingue « à placer »
 * (au calendrier) et « à saisir dans FBI ». Le bouton mène à la liste « FBI — à faire »
 * (`/matchs?fbi=1`) SEULEMENT si cette liste a du contenu (à saisir/corriger) ; sinon tout est
 * encore à placer et il renvoie au calendrier (`/matchs`) — sans quoi il ouvrait une modale VIDE
 * (retour fondateur 2026-09-27). L'escalade du gardien fusionne dans LA MÊME carte.
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

  const tone = !inWindow ? "border-border bg-card" : anyOverdue ? "border-warning/40 bg-surface-warning" : "border-accent/40 bg-surface-accent";
  const globalLine = `${total} FBI à faire${toCorrect > 0 ? `, dont ${toCorrect} à corriger` : ""}`;

  // La liste « FBI — à faire » (à corriger + à saisir) n'a de contenu que si `total` > 0. Tant
  // que tout est UNPLACED, la modale serait VIDE : on renvoie alors au calendrier pour placer.
  const fbiListHasContent = total > 0;

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
            // Un segment par compteur, seulement s'il est > 0 ; pluriels corrects.
            const segments: string[] = [];
            if (window.toPlaceCount > 0) {
              segments.push(`${window.toPlaceCount} match${window.toPlaceCount > 1 ? "s" : ""} à placer`);
            }
            if (window.toEnterCount > 0) {
              segments.push(`${window.toEnterCount} à saisir dans FBI`);
            }
            const body = segments.join(" · ");
            return (
              <li key={`${window.deadline}|${window.source}`}>
                <span className={cn("font-medium", overdue ? "text-warning" : "text-foreground")}>
                  {overdue ? `échéance dépassée — ${body}` : `${body} avant le ${frShortDate(window.deadline)}`}
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
        {fbiListHasContent ? (
          <Link to="/matchs?fbi=1">
            Ouvrir la liste FBI
            <ArrowRight className="size-4" aria-hidden="true" />
          </Link>
        ) : (
          <Link to="/matchs">
            Placer les matchs
            <ArrowRight className="size-4" aria-hidden="true" />
          </Link>
        )}
      </Button>
    </section>
  );
}
