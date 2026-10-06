import { CalendarDays } from "lucide-react";
import { type ReactNode, useState } from "react";

import { StatusPill } from "@/shared/components/ui/badge";
import { Button } from "@/shared/components/ui/button";
import { Modal } from "@/shared/components/ui/modal";
import { Table, TableBody, TableCaption, TableCell, TableHead, TableHeader, TableRow } from "@/shared/components/ui/table";
import { VenueSwatch } from "@/shared/components/ui/venue-swatch";
import { coachFullName } from "@/shared/lib/coachName";
import { frDateWeekdayNoYear } from "@/shared/lib/date";
import { cn } from "@/shared/lib/utils";

import type { Coach, Conflict, ConflictType, Fixture, Team, Venue } from "./api";
import { ConflictLine, ConflictSeverityGroups } from "./ConflictLine";
import { CONFLICT_FAMILIES, CONFLICT_FAMILY_LABEL } from "./lib/conflictLabels";
import { FIXTURE_STATUS_LABEL } from "./lib/fixtureStatusLabel";

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
  /** Les coachs — pour NOMMER la personne d'un conflit personne-en-double + le détail par côté. */
  coaches: Map<string, Coach>;
  /** fixtureId → conflits DÉJÀ filtrés par famille (une pastille par famille présente). */
  conflictsByFixture: Map<string, Conflict[]>;
  /** En vue coach : rôle du coach filtré sur l'équipe, en pastille (comme `AwayList`). */
  coachRoles?: Map<string, string>;
  /** Renvoi vers la Semaine du Calendrier sur le week-end du match (la page pose la semaine). */
  onSelectFixture: (fixtureId: string) => void;
  /**
   * Correctif 6 — « Voir la semaine » depuis le panneau d'un conflit : FOCALISE ce conflit sur le
   * Calendrier (filtre coach + surbrillance des deux rencontres, masques levés). La page bascule en
   * Semaine, pose le week-end et déclenche le focus déjà livré (`conflictFocus.ts`).
   */
  onFocusConflict: (conflict: Conflict) => void;
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
 * `<tr>` nu) qui renvoie vers la Semaine du Calendrier sur le week-end du match. Présentation pure :
 * libellés en table (🔴 `.claude/rules/frontend.md`), aucun verdict recalculé.
 */
export function MatchRowsTable({ caption, groups, teams, venues, coaches, conflictsByFixture, coachRoles, onSelectFixture, onFocusConflict }: MatchRowsTableProps) {
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
            coaches={coaches}
            conflictsByFixture={conflictsByFixture}
            coachRoles={coachRoles}
            onSelectFixture={onSelectFixture}
            onFocusConflict={onFocusConflict}
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
  coaches,
  conflictsByFixture,
  coachRoles,
  onSelectFixture,
  onFocusConflict,
}: {
  group: MatchRowsGroup;
  teams: Map<string, Team>;
  venues: Map<string, Venue>;
  coaches: Map<string, Coach>;
  conflictsByFixture: Map<string, Conflict[]>;
  coachRoles?: Map<string, string>;
  onSelectFixture: (fixtureId: string) => void;
  onFocusConflict: (conflict: Conflict) => void;
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
        return (
          <TableRow key={fixture.id}>
            <TableCell className="whitespace-nowrap">
              <button
                type="button"
                className="rounded text-left underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                aria-label={`Ouvrir ${teamName} contre ${fixture.opponentLabel} le ${frDateWeekdayNoYear(fixture.matchDate)} dans la semaine`}
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
                // L'invite « à rattacher » ne vaut que pour un match À DOMICILE — c'est là qu'un
                // libellé FBI doit pointer un gymnase du club. Pour un extérieur, le libellé est
                // celui de la salle de l'adversaire : rien à rattacher, on l'affiche seul.
                <span className="text-muted-foreground">
                  {fixture.fbiVenueLabel}
                  {"HOME" === fixture.homeAway ? " · à rattacher dans Importer" : ""}
                </span>
              ) : (
                <span className="text-muted-foreground">—</span>
              )}
            </TableCell>
            <TableCell className="whitespace-nowrap text-muted-foreground">{FIXTURE_STATUS_LABEL[fixture.status]}</TableCell>
            <TableCell>
              <ConflictPills fixtureConflicts={conflictsByFixture.get(fixture.id)} teams={teams} coaches={coaches} venues={venues} onFocusConflict={onFocusConflict} />
            </TableCell>
          </TableRow>
        );
      })}
    </>
  );
}

/**
 * Le libellé de la pastille d'une famille pour CE match. La famille PERSONNE (MATCH_MATCH) NOMME
 * la personne en double (« Emerick en double » ; plusieurs → « Emerick +1 ») via `coachId` + la map
 * des coachs — retour terrain 2026-09-27 : un badge « Personne en double » nu ne disait pas QUI.
 * Les autres familles gardent leur libellé (présentation pure, `CONFLICT_FAMILY_LABEL`).
 */
function pillLabel(family: ConflictType, fixtureConflicts: Conflict[], coaches: Map<string, Coach>): string {
  if ("MATCH_MATCH" !== family) {
    return CONFLICT_FAMILY_LABEL[family];
  }
  const coachIds = [...new Set(fixtureConflicts.filter((c) => "MATCH_MATCH" === c.type && undefined !== c.coachId).map((c) => c.coachId as string))];
  if (0 === coachIds.length) {
    return CONFLICT_FAMILY_LABEL.MATCH_MATCH;
  }
  const names = coachIds.map((id) => coachFullName(coaches.get(id)));
  return 1 === names.length ? `${names[0]} en double` : `${names[0]} +${names.length - 1}`;
}

/**
 * Correctif 6 — les pastilles de conflit d'une ligne de match : UNE pastille CLIQUABLE par famille
 * présente. La famille PERSONNE nomme la personne (voir `pillLabel`) ; cliquer une pastille ouvre un
 * petit panneau (Modal) qui LISTE les conflits de cette famille pour ce match avec `ConflictLine`
 * (détail par côté, chevauchement) et un bouton « Voir la semaine » qui déclenche le focus déjà
 * livré (`onFocusConflict`). PRÉSENTATION pure : aucun verdict recalculé (🔴 `.claude/rules/frontend.md`).
 */
function ConflictPills({
  fixtureConflicts,
  teams,
  coaches,
  venues,
  onFocusConflict,
}: {
  fixtureConflicts: Conflict[] | undefined;
  teams: Map<string, Team>;
  coaches: Map<string, Coach>;
  venues: Map<string, Venue>;
  onFocusConflict: (conflict: Conflict) => void;
}) {
  const [openFamily, setOpenFamily] = useState<ConflictType | null>(null);
  const conflicts = fixtureConflicts ?? [];
  const families = familiesOf(conflicts);
  if (0 === families.length) {
    return null;
  }

  const familyConflicts = null === openFamily ? [] : conflicts.filter((c) => c.type === openFamily);

  const renderConflict = (conflict: Conflict, meta: { tone: "destructive" | "warning" | "muted"; isNew: boolean }): ReactNode => (
    <ConflictLine
      conflict={conflict}
      teams={teams}
      coaches={coaches}
      venues={venues}
      tone={meta.tone}
      isNew={meta.isNew}
      trailing={
        <Button
          variant="outline"
          size="sm"
          onClick={() => {
            onFocusConflict(conflict);
            setOpenFamily(null);
          }}
        >
          <CalendarDays className="size-4" aria-hidden="true" />
          Voir la semaine
        </Button>
      }
    />
  );

  return (
    <>
      <span className="flex flex-wrap gap-1">
        {families.map((family) => (
          <button
            key={family}
            type="button"
            title="Voir le détail du conflit"
            className="rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            onClick={() => setOpenFamily(family)}
          >
            <StatusPill variant="warning">{pillLabel(family, conflicts, coaches)}</StatusPill>
          </button>
        ))}
      </span>
      {null !== openFamily ? (
        <Modal label="Détail du conflit" title={pillLabel(openFamily, conflicts, coaches)} size="md" onClose={() => setOpenFamily(null)}>
          <ConflictSeverityGroups conflicts={familyConflicts} teams={teams} coaches={coaches} venues={venues} renderConflict={renderConflict} />
        </Modal>
      ) : null}
    </>
  );
}
