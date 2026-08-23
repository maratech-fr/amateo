import { Bus, Pencil, Trash2 } from "lucide-react";
import { useState } from "react";

import { Button } from "@/shared/components/ui/button";
import { ConfirmDialog } from "@/shared/components/ui/confirm-dialog";

import type { Fixture, Team, TeamMatchHabit } from "./api";
import { isoWeekday } from "./lib/envelope";

interface AwayListProps {
  /** Fixtures of the ACTIVE weekend (already bucketed by the page). */
  fixtures: Fixture[];
  teams: Map<string, Team>;
  habits: TeamMatchHabit[];
  onEdit: (fixture: Fixture) => void;
  onDelete: (fixture: Fixture) => void;
}

function frDate(ymd: string): string {
  return new Date(`${ymd}T12:00:00Z`).toLocaleDateString("fr-FR", { weekday: "short", day: "numeric", month: "short" });
}

/**
 * P1-4 PR E2 — the away matches of the weekend, VISIBLE at last (they carry the
 * travel footprint that creates the coach conflicts). Real hour when known,
 * else the team's habitual hour of that weekday tagged « heure estimée » —
 * exactly the radar's estimation rule. `fbiVenueLabel` = the opponent's venue
 * as FBI ships it (never one of our venues).
 */
export function AwayList({ fixtures, teams, habits, onEdit, onDelete }: AwayListProps) {
  const [toDelete, setToDelete] = useState<Fixture | null>(null);
  const away = fixtures.filter((f) => "AWAY" === f.homeAway).sort((a, b) => a.matchDate.localeCompare(b.matchDate));

  if (0 === away.length) {
    return null;
  }

  return (
    <section className="rounded-lg border border-border bg-card px-3 py-2">
      <h3 className="mb-1 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
        <Bus className="size-3.5" />À l'extérieur ce week-end
      </h3>
      <ul className="flex flex-col gap-1">
        {away.map((fixture) => {
          const habit = habits.find((h) => h.teamId === fixture.teamId && h.dayOfWeek === isoWeekday(fixture.matchDate));
          const hour = fixture.kickoffTime ?? habit?.kickoffTime ?? null;
          const estimated = null === fixture.kickoffTime && null !== hour;
          const teamLabel = teams.get(fixture.teamId)?.name ?? "Équipe ?";
          // L'heure (et son badge « estimée ») portent les conflits de coach et sont en QUEUE de
          // ligne (§6bis B5) : on n'enroule plus jamais dans une troncature, et un `title` de
          // secours rend la ligne entière lisible dans la colonne étroite.
          const awayLine = `${teamLabel} · ${frDate(fixture.matchDate)} · à ${fixture.opponentLabel}${null !== fixture.fbiVenueLabel ? ` (${fixture.fbiVenueLabel})` : ""}${null !== hour ? ` · ${hour}` : " · heure inconnue"}${estimated ? " · heure estimée" : ""}`;
          return (
            <li key={fixture.id} className="flex items-center justify-between gap-2 text-sm">
              <span className="min-w-0" title={awayLine}>
                <span className="font-medium">{teamLabel}</span>
                <span className="text-muted-foreground">
                  {" "}
                  · {frDate(fixture.matchDate)} · à {fixture.opponentLabel}
                  {null !== fixture.fbiVenueLabel ? ` (${fixture.fbiVenueLabel})` : ""}
                  {null !== hour ? ` · ${hour}` : " · heure inconnue"}
                </span>
                {estimated ? <span className="ml-1 rounded bg-muted px-1 text-xs uppercase tracking-wide">heure estimée</span> : null}
              </span>
              <span className="flex shrink-0 gap-1">
                <Button variant="ghost" size="sm" aria-label={`Modifier le match contre ${fixture.opponentLabel}`} onClick={() => onEdit(fixture)}>
                  <Pencil className="size-3.5" />
                </Button>
                <Button variant="ghost" size="sm" aria-label={`Supprimer le match contre ${fixture.opponentLabel}`} onClick={() => setToDelete(fixture)}>
                  <Trash2 className="size-3.5" />
                </Button>
              </span>
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
          if (null !== toDelete) {
            onDelete(toDelete);
          }
          setToDelete(null);
        }}
        onCancel={() => setToDelete(null)}
      />
    </section>
  );
}
