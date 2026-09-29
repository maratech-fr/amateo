import type { MatchWeek, TeamMatchHabit } from "../api";

/**
 * P1-4 PR E2 — the « week-end type » view (founder reframing of « semaine
 * type », 2026-08-03): the manager's IDEAL weekend template — every team's
 * ideal slot laid out Sat/Sun × venues, date-less. Pure layout, MÊME empreinte
 * que la grille datée (constantes importées de `weekendGrid`, elles-mêmes
 * alignées sur `MatchFootprint.php`).
 *
 * P4-271 — la semaine type A/B est une AIDE VISUELLE portée par le tag `week` de
 * chaque créneau idéal (plus aucune entité de rotation). `buildTypicalWeekend(habits, week)`
 * garde les créneaux tagués `week` OU `ALL` (un club sans alternance) ; appelée
 * sans `week` (ou avec `ALL`), elle rend TOUS les créneaux (vue unique).
 */

// D-02 : ces deux constantes valaient 30/135 ici et 30/105 dans `weekendGrid` — or le
// serveur fait foi (`MatchFootprint.php` : 30 + 105). Le « week-end type » dessinait donc des
// blocs de 2h15 pour des matchs que le solveur traite comme 1h45, et l'en-tête ci-dessus
// affirmait pourtant « same footprint geometry as the dated grid ».
import { MATCH_MINUTES, WARMUP_MINUTES } from "./weekendGrid";
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
  /** Minutes since midnight of the 2h15 footprint. */
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

/** Au moins un créneau idéal est tagué A ou B → le club alterne, la vue se segmente. */
export function hasAlternatingWeeks(habits: TeamMatchHabit[]): boolean {
  return habits.some((h) => "A" === h.week || "B" === h.week);
}

/** Les créneaux visibles pour la semaine `week` : ceux tagués `week` ou `ALL`. */
function habitsForWeek(habits: TeamMatchHabit[], week: MatchWeek | undefined): TeamMatchHabit[] {
  if (undefined === week || "ALL" === week) {
    return habits;
  }
  return habits.filter((h) => h.week === week || "ALL" === h.week);
}

export function buildTypicalWeekend(habits: TeamMatchHabit[], week?: MatchWeek): TypicalWeekendModel {
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
    const startMin = kickoffMin - WARMUP_MINUTES;
    const endMin = kickoffMin + MATCH_MINUTES;
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
