import type { Fixture, SportCategoryDuration, Team, TeamMatchHabit, Venue } from "../api";
import { awayBandRows, buildAwayCells, shortWeekday } from "./awayColumn";
import { awayHour } from "./awayKickoff";
import { isoWeekday, timeToMinutes } from "./envelope";

/**
 * Règle de DESSIN des blocs de match sur la grille week-end (retour fondateur
 * 2026-09-14). Un bloc COMMENCE au coup d'envoi (plus de −30 min d'échauffement
 * dessiné) et dure la durée du match (`matchMinutes` de la catégorie de l'équipe
 * quand le front la connaît — 8ᵉ argument `durations`, sinon le repli `MATCH_MINUTES`
 * = 105, celui de `MatchDurationProfile::fallback()` côté backend).
 *
 * ENCHAÎNEMENT : deux domiciles du même gymnase le même jour « se suivent » — le
 * temps d'échauffement est juste plus court. Tant que le coup d'envoi SUIVANT tombe
 * au plus `MAX_CHAIN_GAP_MINUTES` (30) après la fin naturelle du bloc, celui-ci
 * s'étire jusqu'à ce coup d'envoi : quatre matchs collés se lisent comme quatre
 * blocs sans trou. Au-delà de 30 min, le trou RESTE visible — c'est du temps que le
 * gestionnaire peut optimiser en avançant les matchs, il doit le voir. Deux gymnases
 * ne s'enchaînent jamais entre eux ; un chevauchement (coup d'envoi suivant AVANT la
 * fin naturelle) n'étire rien et part en couloirs.
 *
 * ⚠ Ce dessin ne parle QUE de la grille : le radar de conflits serveur
 * (`GET /api/fixtures/conflicts`) et le solveur sont souverains et INCHANGÉS — la
 * grille illustre, elle ne calcule aucun conflit et n'applique aucune règle métier
 * (durée effective = override ?? défaut de famille, résolue par le serveur : on la
 * REÇOIT déjà résolue dans `durations`, on ne la recalcule jamais ici — cf.
 * `.claude/rules/frontend.md`).
 */
export const WARMUP_MINUTES = 30;
export const MATCH_MINUTES = 105;
/** Écart maximal (min) entre la fin naturelle d'un bloc et le coup d'envoi suivant
 *  du même gymnase pour que les deux s'enchaînent sans trou (retour fondateur 2026-09-14). */
export const MAX_CHAIN_GAP_MINUTES = 30;

/**
 * Table `sportCategoryId → durée EFFECTIVE de match (min)` à passer à
 * `buildWeekendGrid` : l'override de club (`matchMinutes`) quand il est posé, sinon le
 * défaut de FAMILLE `defaultMatchMinutes` — celui-ci DÉJÀ résolu par le serveur
 * (`SportCategoryResource::fromEntity(..., MatchDurationProfile)`). On SÉLECTIONNE une
 * valeur servie ; on ne redérive JAMAIS la règle de famille (U7–U11 75 / U13–U15 90 /
 * U18+ 105) côté front (🔴 `.claude/rules/frontend.md`). La clé `id` d'une catégorie
 * est le `sportCategoryId` d'une équipe (même entité `sport_categories`).
 */
export function matchMinutesByCategory(durations: SportCategoryDuration[]): Map<string, number> {
  return new Map(durations.map((category) => [category.id, category.matchMinutes ?? category.defaultMatchMinutes]));
}

/** minutes since midnight → "HH:MM". */
// D-20 : c'était la seule des trois copies à clamper — elle est devenue le foyer partagé.
import { formatMinutes } from "@/shared/lib/time";
import { assignLanes } from "@/shared/lib/gridLayout";
import { compareNamesFr } from "@/shared/lib/nameOrder";

export { formatMinutes };

/** Local Y-m-d (never toISOString, which shifts to UTC and can flip the day). */
function toYmd(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

/** The Saturday (Y-m-d) of the week (Mon..Sun) containing a date — a match's weekend bucket. */
export function weekendKeyOf(dateStr: string): string {
  const date = new Date(`${dateStr}T00:00:00`);
  date.setDate(date.getDate() + (6 - isoWeekday(dateStr))); // shift to that week's Saturday
  return toYmd(date);
}

/** Human label for a weekend bucket ("Week-end du 4 oct."). */
export function weekendLabel(saturdayKey: string): string {
  const date = new Date(`${saturdayKey}T00:00:00`);
  return `Week-end du ${date.toLocaleDateString("fr-FR", { day: "numeric", month: "short" })}`;
}

/**
 * Étiquette COURTE d'un week-end (samedi + dimanche) — « 3-4 oct. » quand les deux
 * jours partagent le mois, « 31 oct.-1 nov. » à cheval sur deux mois. `saturdayKey`
 * est le samedi du bucket (cf. `weekendKeyOf`) ; le dimanche est samedi + 1. Pour les
 * titres et infobulles compacts de l'onglet Conflits (l'année est du bruit, la fenêtre
 * tient dans la saison — même parti que `frDateShortNoYear`).
 */
export function weekendShortLabel(saturdayKey: string): string {
  const saturday = new Date(`${saturdayKey}T00:00:00`);
  const sunday = new Date(saturday);
  sunday.setDate(saturday.getDate() + 1);
  const day = (d: Date): string => d.toLocaleDateString("fr-FR", { day: "numeric" });
  const month = (d: Date): string => d.toLocaleDateString("fr-FR", { month: "short" });
  if (saturday.getMonth() === sunday.getMonth()) {
    return `${day(saturday)}-${day(sunday)} ${month(saturday)}`;
  }
  return `${day(saturday)} ${month(saturday)}-${day(sunday)} ${month(sunday)}`;
}

/**
 * Bornes calendaires (Y-m-d) de la semaine Lun→Dim contenant un bucket week-end
 * (`saturdayKey` = son samedi) : lundi = samedi − 5, dimanche = samedi + 1. Maison
 * unique de cette dérivation, partagée par `weekLabel` (l'étiquette) et le scoping
 * des conflits de l'onglet Consulter (PR-2a) — pas de duplication du calcul lundi/dimanche.
 */
export function weekBounds(saturdayKey: string): { monday: string; sunday: string } {
  const saturday = new Date(`${saturdayKey}T00:00:00`);
  const monday = new Date(saturday);
  monday.setDate(saturday.getDate() - 5);
  const sunday = new Date(saturday);
  sunday.setDate(saturday.getDate() + 1);
  return { monday: toYmd(monday), sunday: toYmd(sunday) };
}

/**
 * RMM-1 PR3 (L7) — la SEMAINE calendaire est l'axe primaire : « Semaine du {lundi}
 * au {dimanche} », le grain réel du gestionnaire sur FBI. Le bucket reste le
 * samedi de la semaine Lun→Dim (`weekendKeyOf`) — on n'étiquette que ses bornes.
 */
export function weekLabel(saturdayKey: string): string {
  const { monday, sunday } = weekBounds(saturdayKey);
  const fmt = (ymd: string): string => new Date(`${ymd}T00:00:00`).toLocaleDateString("fr-FR", { day: "numeric", month: "short" });
  return `Semaine du ${fmt(monday)} au ${fmt(sunday)}`;
}

/** Sorted distinct weekend buckets that contain at least one fixture. */
export function listWeekends(fixtures: Fixture[]): string[] {
  return [...new Set(fixtures.map((f) => weekendKeyOf(f.matchDate)))].sort();
}

/**
 * La semaine à afficher par défaut. Si la sélection est encore dans la liste, on
 * la garde. Sinon (aucune sélection, ou une sélection retirée par un filtre) : la
 * PREMIÈRE semaine ≥ la semaine courante (`todayKey` = `weekendKeyOf(today)`) —
 * pour atterrir sur « maintenant », pas sur la plus vieille rencontre — sinon la
 * dernière (tout est passé), sinon `null` (aucune semaine). `weekends` est trié
 * croissant (cf. `listWeekends`), donc les clés se comparent lexicographiquement.
 */
export function resolveActiveWeekend(weekends: string[], selected: string | null, todayKey: string): string | null {
  if (null !== selected && weekends.includes(selected)) {
    return selected;
  }
  if (0 === weekends.length) {
    return null;
  }
  return weekends.find((w) => w >= todayKey) ?? weekends[weekends.length - 1];
}

/** A placed home fixture = one we can lay on the dated grid (venue + kickoff known). */
export function isPlacedOnGrid(fixture: Fixture): boolean {
  return "HOME" === fixture.homeAway && null !== fixture.venueId && null !== fixture.kickoffTime;
}

export interface WeekendColumn {
  key: string;
  dateKey: string;
  /** null for the « Extérieur » column (it is venue-less by nature). */
  venueId: string | null;
  label: string;
  color: string | null;
  /** lot 3 PR-3a — the trailing « Extérieur » column of a date group (away matches). */
  away?: boolean;
}

export interface DateGroup {
  dateKey: string;
  label: string;
  /** 1-based CSS grid column where this date's block starts (col 1 = time gutter). */
  startColumn: number;
  span: number;
}

export interface WeekendCell {
  key: string;
  fixtureId: string;
  gridColumn: number;
  gridRowStart: number;
  gridRowSpan: number;
  lane: number;
  laneCount: number;
  teamLabel: string;
  opponentLabel: string;
  venueLabel: string;
  venueColor: string | null;
  kickoffLabel: string;
  footprintLabel: string;
  /** RMM-1 PR3 (L7) — n° de rencontre FBI, repère discret. null pour un ghost/manuel. */
  externalRef: string | null;
  outOfEnvelope: boolean;
  /** P1-4 PR C — a HABIT ghost, not a match: the team's protected window on a
   * weekend its calendar has not reached yet. Purely visual, never blocking. */
  ghost: boolean;
  /** P1-4 PR E1 — anchor badge (cadenas resserré 2026-09-17) : un placement RÉEL
   * (PLACED/SUBMITTED/VALIDATED) posé MANUELLEMENT (`placementSource === "MANUAL"`),
   * que le solveur ne bouge plus. Un placement SOLVER reste re-solvable (pas de
   * cadenas) ; un UNPLACED n'est JAMAIS verrouillé (rien n'est encore posé). */
  locked: boolean;
  /** Case « À confirmer » : un domicile porté sur la grille (gymnase + heure connus,
   * `isPlacedOnGrid`) mais dont le STATUT servi par le backend reste `UNPLACED` — le
   * gymnase et l'heure ont été repris de l'import, la rencontre n'est pas encore
   * placée. PRÉSENTATION d'un statut backend, pas une décision front (le front affiche
   * `status`, il ne le calcule pas — 🔴 `.claude/rules/frontend.md`). Faux pour un
   * match réellement placé, un fantôme, un extérieur. */
  toConfirm: boolean;
  /** Jour court (« sam. ») du match — pour le nom accessible d'une case « à confirmer »,
   * loin de l'en-tête daté de sa colonne. Non renseigné pour un fantôme. */
  weekday?: string;
  /** lot 3 PR-3a — an AWAY block (in the trailing « Extérieur » column). */
  away?: boolean;
  /** AWAY with an ESTIMATED hour (habitual kickoff borrowed) — « heure estimée ». */
  estimated?: boolean;
  /** AWAY with no real hour AND no habit that weekday → « heure inconnue » band. */
  unknownHour?: boolean;
  /** AWAY travel footprint « 45 min » (`~` if approximated), null when unknown. */
  travelLabel?: string | null;
  /** AWAY short weekday (« sam. ») for the block's accessible name. */
  awayWeekday?: string;
}

export interface WeekendGridRow {
  label: string | null;
  major: boolean;
  /** lot 3 PR-3a — a row of the « sans heure » band prepended above the time rows. */
  unknownHour?: boolean;
}

export interface WeekendGridModel {
  columns: WeekendColumn[];
  dateGroups: DateGroup[];
  rows: WeekendGridRow[];
  cells: WeekendCell[];
  startMin: number;
  stepMin: number;
  empty: boolean;
}

interface Interval {
  startMin: number;
  endMin: number;
  cell: WeekendCell;
}

// D-27 : le placement en couloirs vit en `shared/lib/gridLayout` — il était recopié
// caractere pour caractere entre les deux grilles.

function dateLabel(dateKey: string): string {
  return new Date(`${dateKey}T00:00:00`).toLocaleDateString("fr-FR", { weekday: "short", day: "numeric", month: "short" });
}

/** The date (Y-m-d) of an ISO weekday inside the bucket week of a Saturday key. */
function dateOfWeekday(saturdayKey: string, isoDay: number): string {
  const date = new Date(`${saturdayKey}T00:00:00`);
  date.setDate(date.getDate() - (6 - isoDay));
  return toYmd(date);
}

interface GhostSlot {
  dateKey: string;
  venueId: string;
  teamId: string;
  kickoffMin: number;
}

/**
 * Habit ghosts of a weekend bucket (P1-4 PR C): one per venue-anchored habit
 * whose team has NO fixture on that weekday's date — the reality (any fixture,
 * home OR away) dissolves the ghost: an away match FREES the habitual slot
 * (« la fenêtre se libère — signalée, comblable »).
 */
function ghostSlots(habits: TeamMatchHabit[], fixtures: Fixture[], weekendKey: string | null): GhostSlot[] {
  if (null === weekendKey) {
    return [];
  }
  const ghosts: GhostSlot[] = [];
  for (const habit of habits) {
    if (null === habit.venueId) {
      continue; // the grid is venue-columned — a day+time habit has no column
    }
    const dateKey = dateOfWeekday(weekendKey, habit.dayOfWeek);
    if (fixtures.some((f) => f.teamId === habit.teamId && f.matchDate === dateKey)) {
      continue;
    }
    ghosts.push({ dateKey, venueId: habit.venueId, teamId: habit.teamId, kickoffMin: timeToMinutes(habit.kickoffTime) });
  }
  return ghosts;
}

/** Durée effective (min) du match d'une équipe : `matchMinutes` de sa catégorie
 *  quand le front la connaît (déjà résolue côté serveur), sinon le repli 105. */
function matchMinutesOf(teamId: string, teams: Map<string, Team>, durations: Map<string, number>): number {
  const categoryId = teams.get(teamId)?.sportCategoryId;
  const minutes = undefined === categoryId ? undefined : durations.get(categoryId);
  return undefined === minutes ? MATCH_MINUTES : minutes;
}

/**
 * Bornes DESSINÉES d'un bloc de match (retour fondateur 2026-09-14) : début = coup
 * d'envoi ; fin = coup d'envoi + durée, ÉTIRÉE jusqu'au coup d'envoi suivant du même
 * gymnase le même jour tant que l'écart ≤ `MAX_CHAIN_GAP_MINUTES`. `placed` est
 * l'ensemble des domiciles posés ; renvoie `{ start, end }` par id de rencontre.
 */
function blockBounds(placed: Fixture[], teams: Map<string, Team>, durations: Map<string, number>): Map<string, { start: number; end: number }> {
  const bounds = new Map<string, { start: number; end: number }>();
  const groups = new Map<string, Fixture[]>();
  for (const fixture of placed) {
    const key = `${fixture.matchDate}:${fixture.venueId as string}`;
    const list = groups.get(key) ?? [];
    list.push(fixture);
    groups.set(key, list);
  }
  for (const group of groups.values()) {
    const sorted = [...group].sort((a, b) => timeToMinutes(a.kickoffTime as string) - timeToMinutes(b.kickoffTime as string));
    const kickoffs = sorted.map((f) => timeToMinutes(f.kickoffTime as string));
    sorted.forEach((fixture, i) => {
      const kickoff = kickoffs[i];
      const naturalEnd = kickoff + matchMinutesOf(fixture.teamId, teams, durations);
      const nextKickoff = kickoffs.slice(i + 1).find((k) => k > kickoff);
      const chained = undefined !== nextKickoff && nextKickoff >= naturalEnd && nextKickoff - naturalEnd <= MAX_CHAIN_GAP_MINUTES;
      bounds.set(fixture.id, { start: kickoff, end: chained ? (nextKickoff as number) : naturalEnd });
    });
  }
  return bounds;
}

/**
 * Pure layout of the placed home matches of ONE weekend. A date is a super-column
 * split into one sub-column per venue used that date; rows are 15-min steps from
 * the earliest kickoff to the latest block end. Each match block STARTS at kickoff
 * and spans the match duration, chained to the next kickoff of the same venue/day
 * when the gap is ≤ 30 min (see the module header); labelled at the kickoff time.
 * Habit ghosts (P1-4 PR C) join the layout as translucent, non-blocking blocks —
 * same kickoff-start + match-duration rule, but never chained (a ghost is not a match).
 * AWAY matches (lot 3 PR-3a) get a trailing « Extérieur » column per date, laid out by
 * `lib/awayColumn.ts`: at their (real or estimated) hour, or in a « sans heure » band
 * prepended above the time rows when no hour and no habit resolves.
 */
export function buildWeekendGrid(
  fixtures: Fixture[],
  venues: Map<string, Venue>,
  teams: Map<string, Team>,
  outOfEnvelope: Set<string> = new Set(),
  habits: TeamMatchHabit[] = [],
  weekendKey: string | null = null,
  stepMin = 15,
  durations: Map<string, number> = new Map(),
  showGhosts = true,
): WeekendGridModel {
  const placed = fixtures.filter(isPlacedOnGrid);
  // `showGhosts` ne gouverne QUE les fantômes d'habitude (interrupteur « Semaine type ») ;
  // `habits` reste PLEIN pour l'estimation d'heure des extérieurs — sinon la colonne
  // « Extérieur » dirait « heure inconnue » pendant que la bande AwayList estime (même écran).
  const ghosts = showGhosts ? ghostSlots(habits, fixtures, weekendKey) : [];
  const awayFixtures = fixtures.filter((f) => "AWAY" === f.homeAway);
  if (0 === placed.length && 0 === ghosts.length && 0 === awayFixtures.length) {
    return { columns: [], dateGroups: [], rows: [], cells: [], startMin: 0, stepMin, empty: true };
  }

  const bounds = blockBounds(placed, teams, durations);
  // La bande « sans heure » (extérieurs sans heure ni habitude) décale toutes les
  // rangées horaires vers le bas ; 0 quand aucun extérieur n'est sans heure (domicile
  // byte-identique sans extérieur).
  const bandRows = awayBandRows(awayFixtures, habits);

  let min = Infinity;
  let max = -Infinity;
  for (const fixture of placed) {
    const block = bounds.get(fixture.id) as { start: number; end: number };
    min = Math.min(min, block.start);
    max = Math.max(max, block.end);
  }
  for (const ghost of ghosts) {
    min = Math.min(min, ghost.kickoffMin);
    max = Math.max(max, ghost.kickoffMin + matchMinutesOf(ghost.teamId, teams, durations));
  }
  // Les extérieurs À HEURE (réelle/estimée) participent à l'amplitude horaire ; ceux
  // sans heure vivent dans la bande, hors des rangées horaires.
  for (const fixture of awayFixtures) {
    const { hour } = awayHour(fixture, habits);
    if (null !== hour) {
      const start = timeToMinutes(hour);
      min = Math.min(min, start);
      max = Math.max(max, start + matchMinutesOf(fixture.teamId, teams, durations));
    }
  }
  const hasTimed = Infinity !== min;
  const startMin = hasTimed ? Math.floor(min / 60) * 60 : 0;
  const endMin = hasTimed ? Math.ceil(max / 60) * 60 : 0;

  const dateKeys = [...new Set([...placed.map((f) => f.matchDate), ...ghosts.map((g) => g.dateKey), ...awayFixtures.map((f) => f.matchDate)])].sort();
  const columns: WeekendColumn[] = [];
  const dateGroups: DateGroup[] = [];
  let cssColumn = 2; // col 1 is the time gutter
  for (const dateKey of dateKeys) {
    const dayFixtures = placed.filter((f) => f.matchDate === dateKey);
    const dayGhosts = ghosts.filter((g) => g.dateKey === dateKey);
    const hasAway = awayFixtures.some((f) => f.matchDate === dateKey);
    const venueIds = [...new Set([...dayFixtures.map((f) => f.venueId as string), ...dayGhosts.map((g) => g.venueId)])].sort((a, b) =>
      compareNamesFr(venues.get(a)?.name ?? "", venues.get(b)?.name ?? ""),
    );
    dateGroups.push({ dateKey, label: dateLabel(dateKey), startColumn: cssColumn, span: venueIds.length + (hasAway ? 1 : 0) });
    for (const venueId of venueIds) {
      columns.push({
        key: `${dateKey}:${venueId}`,
        dateKey,
        venueId,
        label: venues.get(venueId)?.name ?? "Gymnase ?",
        color: venues.get(venueId)?.color ?? null,
      });
      cssColumn += 1;
    }
    // La colonne « Extérieur », TOUJOURS en dernier du groupe de date.
    if (hasAway) {
      columns.push({ key: `${dateKey}:away`, dateKey, venueId: null, label: "Extérieur", color: null, away: true });
      cssColumn += 1;
    }
  }
  const columnIndex = new Map(columns.map((c, i) => [c.key, i]));

  const cells: WeekendCell[] = [];
  const intervals: Interval[] = [];
  for (const fixture of placed) {
    const idx = columnIndex.get(`${fixture.matchDate}:${fixture.venueId as string}`);
    if (undefined === idx) {
      continue;
    }
    const kickoff = timeToMinutes(fixture.kickoffTime as string);
    const { start, end } = bounds.get(fixture.id) as { start: number; end: number };
    const cell: WeekendCell = {
      key: fixture.id,
      fixtureId: fixture.id,
      gridColumn: 2 + idx,
      gridRowStart: 3 + bandRows + Math.round((start - startMin) / stepMin),
      gridRowSpan: Math.max(1, Math.round((end - start) / stepMin)),
      lane: 0,
      laneCount: 1,
      teamLabel: teams.get(fixture.teamId)?.name ?? "Équipe ?",
      opponentLabel: fixture.opponentLabel,
      venueLabel: venues.get(fixture.venueId as string)?.name ?? "Gymnase ?",
      venueColor: venues.get(fixture.venueId as string)?.color ?? null,
      kickoffLabel: formatMinutes(kickoff),
      footprintLabel: `${formatMinutes(start)}–${formatMinutes(end)}`,
      externalRef: fixture.externalRef,
      outOfEnvelope: outOfEnvelope.has(fixture.id),
      ghost: false,
      // Cadenas resserré (2026-09-17) : PLACED-ou-plus ET posé à la main. Un UNPLACED
      // (une case « à confirmer ») n'est jamais verrouillé, un SOLVER reste re-solvable.
      locked: "UNPLACED" !== fixture.status && "MANUAL" === fixture.placementSource,
      // « À confirmer » : sur la grille (gymnase + heure) mais statut backend UNPLACED.
      toConfirm: "UNPLACED" === fixture.status,
      weekday: shortWeekday(fixture.matchDate),
    };
    cells.push(cell);
    intervals.push({ startMin: start, endMin: end, cell });
  }

  // Habit ghosts share the lane layout so a manual placement lands BESIDE the
  // protected window instead of hiding it.
  for (const ghost of ghosts) {
    const idx = columnIndex.get(`${ghost.dateKey}:${ghost.venueId}`);
    if (undefined === idx) {
      continue;
    }
    const start = ghost.kickoffMin;
    const end = ghost.kickoffMin + matchMinutesOf(ghost.teamId, teams, durations);
    const cell: WeekendCell = {
      key: `ghost:${ghost.teamId}:${ghost.dateKey}`,
      fixtureId: "",
      gridColumn: 2 + idx,
      gridRowStart: 3 + bandRows + Math.round((start - startMin) / stepMin),
      gridRowSpan: Math.max(1, Math.round((end - start) / stepMin)),
      lane: 0,
      laneCount: 1,
      teamLabel: teams.get(ghost.teamId)?.name ?? "Équipe ?",
      opponentLabel: "",
      venueLabel: venues.get(ghost.venueId)?.name ?? "Gymnase ?",
      venueColor: venues.get(ghost.venueId)?.color ?? null,
      kickoffLabel: formatMinutes(ghost.kickoffMin),
      footprintLabel: `${formatMinutes(start)}–${formatMinutes(end)}`,
      externalRef: null,
      outOfEnvelope: false,
      ghost: true,
      locked: false,
      toConfirm: false,
    };
    cells.push(cell);
    intervals.push({ startMin: start, endMin: end, cell });
  }

  // lot 3 PR-3a — les blocs de la colonne « Extérieur » (à heure : couloirs partagés ;
  // sans heure : bande en tête). Ils rejoignent les couloirs via `intervals`.
  const awayCells = buildAwayCells(
    { awayFixtures, teams, habits, matchMinutesOf: (teamId) => matchMinutesOf(teamId, teams, durations), columnIndex, startMin, stepMin, bandRows },
    intervals,
  );
  cells.push(...awayCells);

  assignLanes(intervals);

  const rows: WeekendGridRow[] = [];
  // La bande « sans heure » en TÊTE (gouttière « h ? » sur sa première rangée).
  for (let i = 0; i < bandRows; i += 1) {
    rows.push({ label: 0 === i ? "h ?" : null, major: false, unknownHour: true });
  }
  for (let t = startMin; t < endMin; t += stepMin) {
    rows.push({ label: 0 === t % 30 ? formatMinutes(t) : null, major: 0 === t % 60 });
  }

  return { columns, dateGroups, rows, cells, startMin, stepMin, empty: false };
}
