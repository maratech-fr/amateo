import { StatusPill } from "@/shared/components/ui/badge";
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from "@/shared/components/ui/table";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";
import { frDateWeekdayNoYear } from "@/shared/lib/date";
import { cn } from "@/shared/lib/utils";

import type { Conflict, Fixture, Team, Venue } from "./api";
import { CONFLICT_FAMILIES, CONFLICT_FAMILY_LABEL } from "./lib/conflictLabels";
import { FIXTURE_STATUS_LABEL } from "./lib/fixtureStatusLabel";
import type { CoachTeamRole } from "./lib/matchFilter";

interface MatchRowsGroup {
  key: string;
  /** En-tête de groupe : jour (Mois) ou week-end/journée (Phase). */
  label: string;
  fixtures: Fixture[];
}

interface MatchRowsTableProps {
  /** Nom accessible du tableau (`<caption>`, visible mais discret). */
  caption: string;
  groups: MatchRowsGroup[];
  teams: Map<string, Team>;
  venues: Map<string, Venue>;
  /** fixtureId → conflits DÉJÀ filtrés par famille (une pastille par famille présente). */
  conflictsByFixture: Map<string, Conflict[]>;
  /** En vue coach : rôle du coach filtré sur l'équipe, en pastille (comme `AwayList`). */
  coachRoles?: Map<string, CoachTeamRole>;
  /** Renvoi vers Placer sur le week-end du match (la page pose la semaine + navigue). */
  onSelectFixture: (fixtureId: string) => void;
}

const COLUMN_COUNT = 7;

const HOME_AWAY_LABEL = { HOME: "Domicile", AWAY: "Extérieur" } as const;

/** Les familles DISTINCTES portées par les conflits d'un match, dans l'ordre de la table. */
function familiesOf(conflicts: Conflict[] | undefined): (typeof CONFLICT_FAMILIES)[number][] {
  if (undefined === conflicts || 0 === conflicts.length) {
    return [];
  }
  const present = new Set(conflicts.map((c) => c.type));
  return CONFLICT_FAMILIES.filter((family) => present.has(family));
}

/**
 * PR-2b — la ligne de match TABULAIRE, partagée par les temporalités Mois et Phase de
 * l'onglet Consulter (lecture seule). Une colonne par fait : date/heure, équipe (+ rôle
 * coach), domicile/extérieur, adversaire, gymnase, statut, conflits (une pastille par
 * famille présente). Chaque ligne porte UN bouton accessible (jamais un `onClick` sur
 * `<tr>` nu) qui renvoie vers Placer sur le week-end du match. Présentation pure :
 * libellés en table (🔴 `.claude/rules/frontend.md`), aucun verdict recalculé.
 */
export function MatchRowsTable({ caption, groups, teams, venues, conflictsByFixture, coachRoles, onSelectFixture }: MatchRowsTableProps) {
  return (
    <Table className="text-xs">
      <TableCaption>{caption}</TableCaption>
      <TableHeader>
        <TableRow>
          <TableHead>Date</TableHead>
          <TableHead>Équipe</TableHead>
          <TableHead>Lieu</TableHead>
          <TableHead>Adversaire</TableHead>
          <TableHead>Gymnase</TableHead>
          <TableHead>Statut</TableHead>
          <TableHead>Conflits</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {groups.map((group) => (
          <GroupRows
            key={group.key}
            group={group}
            teams={teams}
            venues={venues}
            conflictsByFixture={conflictsByFixture}
            coachRoles={coachRoles}
            onSelectFixture={onSelectFixture}
          />
        ))}
      </TableBody>
    </Table>
  );
}

function GroupRows({
  group,
  teams,
  venues,
  conflictsByFixture,
  coachRoles,
  onSelectFixture,
}: {
  group: MatchRowsGroup;
  teams: Map<string, Team>;
  venues: Map<string, Venue>;
  conflictsByFixture: Map<string, Conflict[]>;
  coachRoles?: Map<string, CoachTeamRole>;
  onSelectFixture: (fixtureId: string) => void;
}) {
  return (
    <>
      <TableRow>
        <TableCell colSpan={COLUMN_COUNT} className="bg-muted text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          {group.label}
        </TableCell>
      </TableRow>
      {group.fixtures.map((fixture) => {
        const team = teams.get(fixture.teamId);
        const teamName = team?.name ?? "Équipe ?";
        const role = coachRoles?.get(fixture.teamId);
        const venue = null === fixture.venueId ? undefined : venues.get(fixture.venueId);
        const families = familiesOf(conflictsByFixture.get(fixture.id));
        return (
          <TableRow key={fixture.id}>
            <TableCell className="whitespace-nowrap">
              <button
                type="button"
                className="rounded text-left underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                aria-label={`Ouvrir ${teamName} contre ${fixture.opponentLabel} le ${frDateWeekdayNoYear(fixture.matchDate)} dans Placer`}
                onClick={() => onSelectFixture(fixture.id)}
              >
                <span>{frDateWeekdayNoYear(fixture.matchDate)}</span>{" "}
                <span className={cn("tabular-nums", null === fixture.kickoffTime ? "text-muted-foreground" : "")}>{fixture.kickoffTime ?? "heure non publiée"}</span>
              </button>
            </TableCell>
            <TableCell className="whitespace-nowrap">
              <span className="font-medium">{teamName}</span>
              {undefined !== role ? <StatusPill className="ml-1.5 text-foreground">{role}</StatusPill> : null}
            </TableCell>
            <TableCell className="whitespace-nowrap text-muted-foreground">{HOME_AWAY_LABEL[fixture.homeAway]}</TableCell>
            <TableCell>{fixture.opponentLabel}</TableCell>
            <TableCell className="whitespace-nowrap">
              {undefined !== venue ? (
                <span className="inline-flex items-center gap-1.5">
                  <VenueSwatch color={venue.color} />
                  {venue.name}
                </span>
              ) : null !== fixture.fbiVenueLabel ? (
                <span className="text-muted-foreground">
                  {fixture.fbiVenueLabel} · à rattacher dans Importer
                </span>
              ) : (
                <span className="text-muted-foreground">—</span>
              )}
            </TableCell>
            <TableCell className="whitespace-nowrap text-muted-foreground">{FIXTURE_STATUS_LABEL[fixture.status]}</TableCell>
            <TableCell>
              {families.length > 0 ? (
                <span className="flex flex-wrap gap-1">
                  {families.map((family) => (
                    <StatusPill key={family} variant="warning">
                      {CONFLICT_FAMILY_LABEL[family]}
                    </StatusPill>
                  ))}
                </span>
              ) : null}
            </TableCell>
          </TableRow>
        );
      })}
    </>
  );
}
