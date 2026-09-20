import { Bus, Pencil, Trash2 } from "lucide-react";
import { useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";
import { OpponentLogo } from "@/shared/components/ui/opponent-logo";
import { frDateWeekdayNoYear } from "@/shared/lib/date";

import type { Fixture, Team, TeamMatchHabit } from "./api";
import { AwayTravelChip } from "./AwayTravelChip";
import { compareAway } from "./lib/awayColumn";
import { awayHour } from "./lib/awayKickoff";
import { awayTravelTitle } from "./lib/awayTravelTitle";
import type { CoachTeamRole } from "./lib/matchFilter";
import { opponentInitials } from "./lib/opponentInitials";

interface AwayListProps {
  /** Fixtures of the ACTIVE weekend (already bucketed by the page). */
  fixtures: Fixture[];
  teams: Map<string, Team>;
  habits: TeamMatchHabit[];
  /** PR-1 — en vue coach : rôle du coach filtré sur l'équipe, affiché en pastille. */
  coachRoles?: Map<string, CoachTeamRole>;
  /**
   * PR-2a — actions OPTIONNELLES : l'onglet Consulter rend la bande en LECTURE
   * SEULE (aucun handler ⇒ ni crayon ni corbeille). La boucle (Placer) les fournit.
   */
  onEdit?: (fixture: Fixture) => void;
  onDelete?: (fixture: Fixture) => void;
}

/**
 * P1-4 PR E2 — the away matches of the weekend, VISIBLE at last (they carry the
 * travel footprint that creates the coach conflicts). Real hour when known,
 * else the team's habitual hour of that weekday tagged « heure estimée » —
 * exactly the radar's estimation rule. `fbiVenueLabel` = the opponent's venue
 * as FBI ships it (never one of our venues).
 */
export function AwayList({ fixtures, teams, habits, coachRoles, onEdit, onDelete }: AwayListProps) {
  const readOnly = undefined === onEdit && undefined === onDelete;
  const [toDelete, setToDelete] = useState<Fixture | null>(null);
  // Tri commun à la colonne « Extérieur » de la grille (lot 3 PR-3a) : date, puis
  // sans-heure d'abord, puis heure, puis équipe.
  const away = fixtures.filter((f) => "AWAY" === f.homeAway).sort((a, b) => compareAway(a, b, teams, habits));

  if (0 === away.length) {
    return null;
  }

  return (
    <section className="rounded-lg border border-border bg-card px-3 py-2">
      {/* PR 3b — `<h2>` (et non `<h3>`) : sur le Calendrier fusionné, la bande extérieur est
          au même niveau de titre que « À placer » (`CardTitle` = h2), pas imbriquée sous elle. */}
      <h2 className="mb-1 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        <Bus className="size-3.5" />À l'extérieur ce week-end
      </h2>
      <ul className="flex flex-col gap-1">
        {away.map((fixture) => {
          const { hour, estimated } = awayHour(fixture, habits);
          const teamLabel = teams.get(fixture.teamId)?.name ?? "Équipe ?";
          // Amendement 2026-09-20 : le trajet est DÉRIVÉ de la rencontre (`fixture.awayTravel`).
          const travelInfo = fixture.awayTravel;
          // L'heure (et son badge « estimée ») portent les conflits de coach et sont en QUEUE de
          // ligne (§6bis B5) : on n'enroule plus jamais dans une troncature, et un `title` de
          // secours rend la ligne entière lisible dans la colonne étroite.
          const awayLine = `${teamLabel} · ${frDateWeekdayNoYear(fixture.matchDate)} · à ${fixture.opponentLabel}${null !== fixture.fbiVenueLabel ? ` (${fixture.fbiVenueLabel})` : ""}${null !== hour ? ` · ${hour}` : " · heure inconnue"}${estimated ? " · heure estimée" : ""} · ${awayTravelTitle(travelInfo)}${null !== fixture.externalRef ? ` · n° ${fixture.externalRef}` : ""}`;
          return (
            <li key={fixture.id} className="flex items-center justify-between gap-2 text-sm">
              <span className="min-w-0" title={awayLine}>
                <span className="font-medium">{teamLabel}</span>
                {undefined !== coachRoles?.get(fixture.teamId) ? <StatusPill className="ml-1.5 text-foreground">{coachRoles.get(fixture.teamId)}</StatusPill> : null}
                <span className="text-muted-foreground">
                  {" "}
                  · {frDateWeekdayNoYear(fixture.matchDate)} ·{" "}
                  {/* C7 — logo fédéral de l'adversaire (sm) : rien si aucun logo (le libellé suffit). */}
                  <OpponentLogo code={fixture.opponentOrganismeCode} hasLogo={null !== fixture.opponentOrganismeCode} initials={opponentInitials(fixture.opponentLabel)} size="sm" className="mr-1 inline-block align-middle" />
                  à {fixture.opponentLabel}
                  {null !== fixture.fbiVenueLabel ? ` (${fixture.fbiVenueLabel})` : ""}
                  {null !== hour ? ` · ${hour}` : " · heure inconnue"}
                  {/* RMM-1 PR3 (L7) — n° de rencontre : repère discret, jamais une clé. */}
                  {null !== fixture.externalRef ? <span className="tabular-nums"> · n° {fixture.externalRef}</span> : null}
                </span>
                {estimated ? <span className="ml-1 rounded bg-muted px-1 text-xs uppercase tracking-wide">heure estimée</span> : null}
                <AwayTravelChip travel={travelInfo} />
              </span>
              {readOnly ? null : (
                <span className="flex shrink-0 gap-1">
                  {undefined !== onEdit ? (
                    <Button variant="ghost" size="sm" aria-label={`Modifier le match contre ${fixture.opponentLabel}`} onClick={() => onEdit(fixture)}>
                      <Pencil className="size-3.5" />
                    </Button>
                  ) : null}
                  {undefined !== onDelete ? (
                    <Button variant="ghost" size="sm" aria-label={`Supprimer le match contre ${fixture.opponentLabel}`} onClick={() => setToDelete(fixture)}>
                      <Trash2 className="size-3.5" />
                    </Button>
                  ) : null}
                </span>
              )}
            </li>
          );
        })}
      </ul>

      <ConfirmDialog
        open={null !== toDelete}
        title={`Supprimer le match contre « ${toDelete?.opponentLabel ?? "" } » ?`}
        description="Le match disparaît du calendrier. S'il vient d'un import FBI, le prochain import le recréera."
        confirmLabel="Supprimer"
        destructive
        onConfirm={() => {
          if (null !== toDelete && undefined !== onDelete) {
            onDelete(toDelete);
          }
          setToDelete(null);
        }}
        onCancel={() => setToDelete(null)}
      />
    </section>
  );
}
