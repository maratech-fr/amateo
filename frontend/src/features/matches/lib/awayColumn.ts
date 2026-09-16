import { compareNamesFr } from "@/shared/lib/nameOrder";
import { formatMinutes } from "@/shared/lib/time";

import type { Fixture, OpponentTravel, Team, TeamMatchHabit } from "../api";
import { awayHour, awayTravelByKey, awayTravelKey } from "./awayKickoff";
import { timeToMinutes } from "./envelope";
import type { WeekendCell } from "./weekendGrid";

/**
 * Colonne EXTÉRIEUR de la grille week-end (lot 3 PR-3a) — la présentation des matchs à
 * l'extérieur, À CÔTÉ des domiciles. Rien de nouveau côté métier : on AFFICHE l'heure
 * servie (ou l'habitude, marquée estimée) et le trajet déjà calculé par le serveur
 * (🔴 `.claude/rules/frontend.md`). Aucun enchaînement (`blockBounds` reste le domicile),
 * mais les couloirs (`assignLanes`) quand deux extérieurs se chevauchent.
 */

/** Libellé de trajet d'un bloc extérieur : « 45 min » (`~` si approché), `null` quand
 *  le trajet est inconnu/indisponible. `travelMinutes`/`approximated` viennent du serveur. */
export function awayTravelLabel(travel: OpponentTravel | undefined): string | null {
  if (undefined === travel || !travel.located || null === travel.precision || null === travel.travelMinutes) {
    return null;
  }
  return `${travel.approximated ? "~" : ""}${travel.travelMinutes} min`;
}

/** Ordre commun des extérieurs (colonne de grille ET bande `AwayList`) : date, puis
 *  sans-heure d'abord, puis heure croissante, puis nom d'équipe. */
export function compareAway(a: Fixture, b: Fixture, teams: Map<string, Team>, habits: TeamMatchHabit[]): number {
  if (a.matchDate !== b.matchDate) {
    return a.matchDate.localeCompare(b.matchDate);
  }
  const ha = awayHour(a, habits).hour;
  const hb = awayHour(b, habits).hour;
  if (ha !== hb) {
    if (null === ha) {
      return -1;
    }
    if (null === hb) {
      return 1;
    }
    return ha.localeCompare(hb);
  }
  return compareNamesFr(teams.get(a.teamId)?.name ?? "", teams.get(b.teamId)?.name ?? "");
}

/** Hauteur (rangées de 16 px) de la bande « sans heure » en tête : 2 rangées par bloc,
 *  sur la colonne (date) qui en porte le plus. 0 quand aucun extérieur n'est sans heure. */
export function awayBandRows(awayFixtures: Fixture[], habits: TeamMatchHabit[]): number {
  const unknownByDate = new Map<string, number>();
  for (const fixture of awayFixtures) {
    if (null === awayHour(fixture, habits).hour) {
      unknownByDate.set(fixture.matchDate, (unknownByDate.get(fixture.matchDate) ?? 0) + 1);
    }
  }
  return 2 * Math.max(0, ...unknownByDate.values());
}

/** Étiquette de jour courte (« sam. », « mer. ») pour le nom accessible d'un bloc
 *  extérieur — le bloc est loin de l'en-tête daté de son groupe de colonnes. */
function shortWeekday(dateKey: string): string {
  return new Date(`${dateKey}T00:00:00`).toLocaleDateString("fr-FR", { weekday: "short" });
}

export interface AwayLayout {
  awayFixtures: Fixture[];
  teams: Map<string, Team>;
  habits: TeamMatchHabit[];
  travel: OpponentTravel[];
  /** Durée effective (min) du match d'une équipe — partagée avec le layout domicile. */
  matchMinutesOf: (teamId: string) => number;
  /** Clé de colonne `${dateKey}:away` → index 0-based dans le tableau `columns`. */
  columnIndex: Map<string, number>;
  startMin: number;
  stepMin: number;
  /** Nombre de rangées de la bande « sans heure », insérées AVANT les rangées horaires. */
  bandRows: number;
}

/**
 * Cellules de la (des) colonne(s) EXTÉRIEUR d'un week-end. Un match à l'heure
 * (réelle/estimée) va dans les rangées horaires (jamais enchaîné, en couloirs via
 * `intervals`) ; un match SANS heure ni habitude va dans la bande « sans heure » en
 * tête (blocs empilés, pleine largeur, jamais en couloirs). `intervals` reçoit les
 * cellules À HEURE pour qu'`assignLanes` les réparte.
 */
export function buildAwayCells(layout: AwayLayout, intervals: { startMin: number; endMin: number; cell: WeekendCell }[]): WeekendCell[] {
  const { awayFixtures, teams, habits, travel, matchMinutesOf, columnIndex, startMin, stepMin, bandRows } = layout;
  const travelByKey = awayTravelByKey(travel);
  const cells: WeekendCell[] = [];
  // Un compteur de blocs sans heure PAR colonne (par date) : ils s'empilent verticalement.
  const unknownSlotByColumn = new Map<number, number>();
  const sorted = [...awayFixtures].sort((a, b) => compareAway(a, b, teams, habits));
  for (const fixture of sorted) {
    const idx = columnIndex.get(`${fixture.matchDate}:away`);
    if (undefined === idx) {
      continue;
    }
    const { hour, estimated } = awayHour(fixture, habits);
    const key = awayTravelKey(fixture.opponentOrganismeCode, fixture.opponentTeamKey);
    const travelLabel = awayTravelLabel(null === key ? undefined : travelByKey.get(key));
    const base = {
      fixtureId: fixture.id,
      gridColumn: 2 + idx,
      lane: 0,
      laneCount: 1,
      teamLabel: teams.get(fixture.teamId)?.name ?? "Équipe ?",
      opponentLabel: fixture.opponentLabel,
      venueLabel: fixture.fbiVenueLabel ?? "",
      venueColor: null,
      externalRef: fixture.externalRef,
      outOfEnvelope: false,
      ghost: false,
      locked: false,
      away: true,
      travelLabel,
      awayWeekday: shortWeekday(fixture.matchDate),
    };

    if (null === hour) {
      const slot = unknownSlotByColumn.get(2 + idx) ?? 0;
      unknownSlotByColumn.set(2 + idx, slot + 1);
      cells.push({
        ...base,
        key: `away:${fixture.id}`,
        gridRowStart: 3 + slot * 2,
        gridRowSpan: 2,
        kickoffLabel: "",
        footprintLabel: "",
        estimated: false,
        unknownHour: true,
      });
      continue;
    }

    const start = timeToMinutes(hour);
    const end = start + matchMinutesOf(fixture.teamId);
    const cell: WeekendCell = {
      ...base,
      key: `away:${fixture.id}`,
      gridRowStart: 3 + bandRows + Math.round((start - startMin) / stepMin),
      gridRowSpan: Math.max(1, Math.round((end - start) / stepMin)),
      kickoffLabel: formatMinutes(start),
      footprintLabel: `${formatMinutes(start)}–${formatMinutes(end)}`,
      estimated,
      unknownHour: false,
    };
    cells.push(cell);
    intervals.push({ startMin: start, endMin: end, cell });
  }
  return cells;
}
