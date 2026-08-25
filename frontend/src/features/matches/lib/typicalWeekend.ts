import type { MatchSlotRotation, TeamMatchHabit } from "../api";

/**
 * P1-4 PR E2 — the « week-end type » view (founder reframing of « semaine
 * type », 2026-08-03): the manager's IDEAL weekend template — every team's
 * habitual window laid out Sat/Sun × venues, date-less. Read-only (habits are
 * edited in HabitsLinksDialog). Pure layout, MÊME empreinte que la grille datée
 * (constantes importées de `weekendGrid`, elles-mêmes alignées sur `MatchFootprint.php`).
 *
 * RMM-5 PR-4 — la rotation A/B entre dans le gabarit : un créneau partagé
 * dessine, à la SEMAINE k, le bloc de son membre `position k mod N` (l'ordre est
 * fictif, il ne pilote AUCUN calendrier — il ne fait que dérouler l'alternance à
 * l'écran). Le modèle reste PUR : il prend les rotations et l'index de semaine en
 * entrée. Sans rotation, `buildTypicalWeekend(habits)` rend EXACTEMENT le modèle
 * d'avant (rotations = [] par défaut) — l'anti-régression est vraie par construction.
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

/** A rotation whose slot falls OUTSIDE the weekend — listed apart (the grid is Sat/Sun only). */
export interface OffWeekendRotation {
  rotationId: string;
  dayOfWeek: number;
  kickoffTime: string;
  venueId: string;
  /** The member shown for the active week (position k mod N). */
  teamId: string;
}

export interface TypicalWeekendModel {
  columns: TypicalColumn[];
  blocks: TypicalBlock[];
  /** Habits without a venue — listed apart (the grid is venue-columned). */
  venueless: TeamMatchHabit[];
  /** Rotations declared on a non-weekend day — listed apart (§tranche 3, RMM-5 PR-4). */
  offWeekendRotations: OffWeekendRotation[];
  startMin: number;
  endMin: number;
  empty: boolean;
}

function toMinutes(time: string): number {
  // D-21 : lecture partagée, repli 0 explicite (mise en page seule).
  return parseTime(time) ?? 0;
}

const isWeekendDay = (day: number): day is 6 | 7 => 6 === day || 7 === day;

/**
 * Le nombre de SEMAINES du gabarit = la plus grande rotation (N=2 → A/B, N=3 →
 * A/B/C…), 1 s'il n'y a aucune rotation (≥ 2 membres). C'est ce compte qui décide
 * si un segmenté « Semaine A / B / … » s'affiche : 1 ⇒ pas de segmenté du tout.
 */
export function weekCountOf(rotations: MatchSlotRotation[]): number {
  const max = rotations.reduce((acc, r) => (r.teamIds.length >= 2 ? Math.max(acc, r.teamIds.length) : acc), 0);
  return Math.max(1, max);
}

/** Le membre d'une rotation à la semaine `weekIndex` (0-based) : position `weekIndex mod N`. */
function memberAtWeek(rotation: MatchSlotRotation, weekIndex: number): string {
  return rotation.teamIds[weekIndex % rotation.teamIds.length];
}

export function buildTypicalWeekend(habits: TeamMatchHabit[], rotations: MatchSlotRotation[] = [], weekIndex = 0): TypicalWeekendModel {
  const weekend = habits.filter((h) => isWeekendDay(h.dayOfWeek));
  const withVenue = weekend.filter((h) => null !== h.venueId);
  const venueless = weekend.filter((h) => null === h.venueId);

  // Seules les rotations réelles (≥ 2 membres) comptent — un créneau à une équipe n'alterne pas.
  const usableRotations = rotations.filter((r) => r.teamIds.length >= 2);
  const weekendRotations = usableRotations.filter((r) => isWeekendDay(r.dayOfWeek));
  const offWeekendRotations: OffWeekendRotation[] = usableRotations
    .filter((r) => !isWeekendDay(r.dayOfWeek))
    .map((r) => ({ rotationId: r.id, dayOfWeek: r.dayOfWeek, kickoffTime: r.kickoffTime, venueId: r.venueId, teamId: memberAtWeek(r, weekIndex) }));

  const empty = 0 === weekend.length && 0 === usableRotations.length;

  // Colonnes = (habitudes À gymnase) ∪ (rotations week-end) — les deux portent jour+gymnase.
  const columnKeys = new Set<string>();
  for (const h of withVenue) {
    columnKeys.add(`${h.dayOfWeek}:${h.venueId as string}`);
  }
  for (const r of weekendRotations) {
    columnKeys.add(`${r.dayOfWeek}:${r.venueId}`);
  }

  const columns: TypicalColumn[] = [...columnKeys].sort().map((key) => {
    const [day, venueId] = key.split(":") as [string, string];
    return { key, dayOfWeek: Number(day) as 6 | 7, venueId };
  });

  if (0 === columns.length) {
    return { columns: [], blocks: [], venueless, offWeekendRotations, startMin: 0, endMin: 0, empty };
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
  // La rotation dessine LE membre de la semaine k sur son créneau (l'ordre est fictif).
  for (const rotation of weekendRotations) {
    pushBlock(`rot:${rotation.id}`, memberAtWeek(rotation, weekIndex), rotation.dayOfWeek, rotation.venueId, rotation.kickoffTime);
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
    offWeekendRotations,
    startMin: Math.floor(min / 60) * 60,
    endMin: Math.ceil(max / 60) * 60,
    empty: false,
  };
}
