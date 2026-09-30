import type { MatchWeek, Team, TeamMatchHabit } from "../api";

/**
 * P1-4 PR E2 — the « week-end type » view (founder reframing of « semaine
 * type », 2026-08-03): the manager's IDEAL weekend template — every team's
 * ideal slot laid out Sat/Sun × venues, date-less.
 *
 * P4-206 — mise en page identique à la grille DATÉE : chaque créneau va du COUP D'ENVOI à
 * coup d'envoi + la durée RÉELLE de la catégorie de l'équipe (servie par le serveur via
 * `durations`, jamais redérivée — 🔴 `.claude/rules/frontend.md`), SANS échauffement dessiné.
 * `buildTypicalWeekend` reçoit donc `teams` + `durations` et délègue à `matchMinutesOf`
 * (repli 105 pour une catégorie sans durée servie). Aucune notion d'enchaînement ici (pas de
 * dates) : la vue est un gabarit, pas un planning.
 *
 * P4-271 — la semaine type A/B est une AIDE VISUELLE portée par le tag `week` de
 * chaque créneau idéal (plus aucune entité de rotation). Le club DÉCLARE s'il alterne
 * (`me.club.weekendAlternates`, vérité serveur — le front ne le REDÉRIVE pas des créneaux,
 * 🔴 `.claude/rules/frontend.md`). Appelée avec une `week`, `buildTypicalWeekend` ne garde
 * que les créneaux tagués cette semaine ; appelée sans `week` (club sans alternance), elle
 * rend TOUS les créneaux (vue unique).
 */

import { matchMinutesOf } from "./weekendGrid";
import { parseTime } from "@/shared/lib/time";

export interface TypicalColumn {
  key: string;
  dayOfWeek: 6 | 7;
  venueId: string;
}

export interface TypicalBlock {
  key: string;
  teamId: string;
  columnKey: string;
  /** Minutes since midnight : début = coup d'envoi, fin = coup d'envoi + durée de la catégorie. */
  startMin: number;
  endMin: number;
  kickoff: string;
  lane: number;
  laneCount: number;
}

export interface TypicalWeekendModel {
  columns: TypicalColumn[];
  blocks: TypicalBlock[];
  /** Ideal slots without a venue — listed apart (the grid is venue-columned). */
  venueless: TeamMatchHabit[];
  startMin: number;
  endMin: number;
  empty: boolean;
}

function toMinutes(time: string): number {
  // D-21 : lecture partagée, repli 0 explicite (mise en page seule).
  return parseTime(time) ?? 0;
}

const isWeekendDay = (day: number): day is 6 | 7 => 6 === day || 7 === day;

/** Les créneaux visibles pour la semaine `week` : ceux tagués cette semaine (sans `week`, tous). */
function habitsForWeek(habits: TeamMatchHabit[], week: MatchWeek | undefined): TeamMatchHabit[] {
  if (undefined === week) {
    return habits;
  }
  return habits.filter((h) => h.week === week);
}

export function buildTypicalWeekend(habits: TeamMatchHabit[], teams: Map<string, Team>, durations: Map<string, number>, week?: MatchWeek): TypicalWeekendModel {
  const scoped = habitsForWeek(habits, week);
  const weekend = scoped.filter((h) => isWeekendDay(h.dayOfWeek));
  const withVenue = weekend.filter((h) => null !== h.venueId);
  const venueless = weekend.filter((h) => null === h.venueId);

  const empty = 0 === weekend.length;

  const columnKeys = new Set<string>();
  for (const h of withVenue) {
    columnKeys.add(`${h.dayOfWeek}:${h.venueId as string}`);
  }

  const columns: TypicalColumn[] = [...columnKeys].sort().map((key) => {
    const [day, venueId] = key.split(":") as [string, string];
    return { key, dayOfWeek: Number(day) as 6 | 7, venueId };
  });

  if (0 === columns.length) {
    return { columns: [], blocks: [], venueless, startMin: 0, endMin: 0, empty };
  }

  let min = Infinity;
  let max = -Infinity;
  const blocks: TypicalBlock[] = [];

  const pushBlock = (key: string, teamId: string, day: number, venueId: string, kickoff: string): void => {
    const kickoffMin = toMinutes(kickoff);
    const startMin = kickoffMin;
    const endMin = kickoffMin + matchMinutesOf(teamId, teams, durations);
    min = Math.min(min, startMin);
    max = Math.max(max, endMin);
    blocks.push({ key, teamId, columnKey: `${day}:${venueId}`, startMin, endMin, kickoff, lane: 0, laneCount: 1 });
  };

  for (const habit of withVenue) {
    pushBlock(habit.id, habit.teamId, habit.dayOfWeek, habit.venueId as string, habit.kickoffTime);
  }

  // Lane overlapping blocks of the same column side by side (same rule as the
  // dated grid: a template collision must be SEEN, not hidden).
  for (const column of columns) {
    const columnBlocks = blocks.filter((b) => b.columnKey === column.key).sort((a, b) => a.startMin - b.startMin);
    const laneEnds: number[] = [];
    for (const block of columnBlocks) {
      let lane = laneEnds.findIndex((end) => end <= block.startMin);
      if (-1 === lane) {
        lane = laneEnds.length;
        laneEnds.push(0);
      }
      laneEnds[lane] = block.endMin;
      block.lane = lane;
    }
    for (const block of columnBlocks) {
      block.laneCount = laneEnds.length;
    }
  }

  return {
    columns,
    blocks,
    venueless,
    startMin: Math.floor(min / 60) * 60,
    endMin: Math.ceil(max / 60) * 60,
    empty: false,
  };
}
