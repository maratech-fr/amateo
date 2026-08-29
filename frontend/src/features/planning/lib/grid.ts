import { compareTeamsByRank, groupTeamsByTier, tierGroupLabel, type TierLike } from "@/shared/lib/teamTiers";

import type { Coach, LockOrigin, Slot, Team, Venue } from "../api";
import type { ViewMode } from "../store";

/**
 * Les SEPT jours ISO. Un jour sans séance est masqué plus bas (`continue`), donc la
 * colonne du dimanche n'apparaît que pour les clubs qui s'entraînent ce jour-là.
 *
 * ⚠ Ce tableau s'arrêtait au samedi, comme la grille du wizard — troisième miroir de la
 * même règle, trouvé en revue de P4-37. La conséquence n'était pas cosmétique : le
 * backend accepte `dayOfWeek` jusqu'à 7 (`VenueTrainingSlotInput`), le solveur place la
 * séance et l'export serveur l'imprime « Dimanche » (`ScheduleExportData::DAY_LABELS`)
 * — mais l'écran où le planning se TRAVAILLE l'escamotait, `dayLabelOf` la libellait
 * « ? » dans les diagnostics, et le select « Jour » de `SlotDetail` (qui lit ce même
 * tableau) la rendait non déplaçable. Un planning à six colonnes se donnait pour complet.
 */
// D-22 : les sept jours vivent en `shared/lib/days` — une copie de ce tableau
// s'arrêtait au samedi et rendait un planning à six colonnes « complet ».
import { DAYS, dayLabelLong } from "@/shared/lib/days";

export { DAYS };

export const NO_COACH = "__none__";

/** Extract the first HH:MM from a time-ish string ("18:00:00", "1970-01-01T18:00:00+00:00", "18:00"). */
function firstHourMinute(time: string): [number, number] {
  const match = time.match(/(\d{1,2}):(\d{2})/);
  if (null === match) {
    return [0, 0];
  }
  return [Number(match[1]), Number(match[2])];
}

/** Time-ish string → minutes since midnight (tolerates ISO datetimes from the API). */
export function parseTimeToMinutes(time: string): number {
  // D-21 : lecture partagée ; le repli 0 est conservé (une grille place un bloc, elle ne
  // valide pas une saisie) mais il est désormais EXPLICITE.
  return parseTime(time) ?? 0;
}

/** minutes → "HH:MM" (zero-padded). */
// D-20 : le formateur vit en `shared/lib/time` — cette copie ne clampait pas et rendait « 25:15 ».
import { formatMinutes, parseTime } from "@/shared/lib/time";
import { coachFullName } from "@/shared/lib/coachName";
import { assignLanes } from "@/shared/lib/gridLayout";
import { compareNamesFr } from "@/shared/lib/nameOrder";

export { formatMinutes };

/** Time-ish string → "HH:MM" (zero-padded). */
export function toHourMinute(time: string): string {
  const [h, m] = firstHourMinute(time);
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
}

/**
 * Clé d'une FENÊTRE de gymnase : `venueId|jour|minute-de-début`. Sert au libellé de
 * groupe (P2-17) — le libellé vit sur la fenêtre (`VenueTrainingSlot`) et rejoint les
 * séances placées par cette clé. Même forme que la clé d'appariement des créneaux vides
 * (`emptySlots.ts`), minute-normalisée pour qu'un « 18:00 » placé colle à une fenêtre
 * « 18:00:00 ».
 */
export function slotGroupKey(venueId: string, dayOfWeek: number, startTime: string): string {
  return `${venueId}|${dayOfWeek}|${parseTimeToMinutes(startTime)}`;
}

export interface TimeBounds {
  startMin: number;
  endMin: number;
}

/** Grid vertical extent: floor(min start) → ceil(max end) to the hour; sane fallback when empty. */
export function computeTimeBounds(slots: Slot[], fallback: TimeBounds = { startMin: 17 * 60, endMin: 21 * 60 }): TimeBounds {
  if (0 === slots.length) {
    return fallback;
  }
  let min = Infinity;
  let max = -Infinity;
  for (const slot of slots) {
    const start = parseTimeToMinutes(slot.startTime);
    const end = start + slot.durationMinutes;
    min = Math.min(min, start);
    max = Math.max(max, end);
  }
  return { startMin: Math.floor(min / 60) * 60, endMin: Math.ceil(max / 60) * 60 };
}

export interface Lookups {
  teams: Map<string, Team>;
  venues: Map<string, Venue>;
  coaches: Map<string, Coach>;
  /** teamId → main coachId. The engine leaves slot.coachId empty; the coach is the team's coach. */
  teamCoach: Map<string, string>;
  /** teamId → coachIds that are PLAYERS of the team (a coach can also play elsewhere). */
  teamPlayerCoaches: Map<string, string[]>;
  /**
   * P2-17 : `slotGroupKey(venue, jour, début)` → libellé de groupe non vide de la
   * fenêtre. Purement esthétique (le backend calcule et normalise ce champ ; le front
   * ne fait que l'AFFICHER). Absent = aucun créneau mutualisé libellé — la fusion de la
   * vue gymnase (D4) est alors inerte.
   */
  groupLabels?: Map<string, string>;
}

/** The (main) coach of a slot: the slot's own coach if set, else its team's main coach. */
export function slotCoachId(slot: Slot, lookups: Lookups): string | null {
  return slot.coachId ?? lookups.teamCoach.get(slot.teamId) ?? null;
}

/**
 * The resource ids a slot belongs to for the current view. Usually one; in the
 * coach view a slot appears under EVERY coach concerned by the team — its main
 * coach AND the coaches who play in it — so a coach sees his full schedule.
 */
export function resourceKeysForSlot(slot: Slot, viewMode: ViewMode, lookups: Lookups): string[] {
  if ("gymnase" === viewMode) {
    return [slot.venueId];
  }
  // P3-20 : la vue « club » (matrice équipes × jours) se filtre PAR ÉQUIPE, comme « equipe ».
  if ("equipe" === viewMode || "club" === viewMode) {
    return [slot.teamId];
  }
  // P2-33 : en vue « jour », la ressource FILTRABLE est le jour ISO (les colonnes de grille,
  // elles, restent les gymnases — cf. `buildGrid`/`columnView`).
  if ("jour" === viewMode) {
    return [String(slot.dayOfWeek)];
  }
  const keys = new Set<string>();
  const main = slotCoachId(slot, lookups);
  if (null !== main) {
    keys.add(main);
  }
  for (const player of lookups.teamPlayerCoaches.get(slot.teamId) ?? []) {
    keys.add(player);
  }
  return keys.size > 0 ? [...keys] : [NO_COACH];
}

/** Libellé sentinelle d'une équipe sans coach — comparé par SlotDetail pour substituer la croix. */
export const NO_COACH_LABEL = "Sans coach";

function coachName(coaches: Map<string, Coach>, coachId: string | null): string {
  if (null === coachId) {
    return NO_COACH_LABEL;
  }
  const coach = coaches.get(coachId);
  // D-33 : formatage partagé — cette version omettait le `.trim()`, laissant un espace
  // final visible quand le coach n'a pas de nom de famille.
  return coachFullName(coach);
}

function resourceLabel(id: string, viewMode: ViewMode, lookups: Lookups): string {
  if ("gymnase" === viewMode) {
    return lookups.venues.get(id)?.name ?? "Gymnase ?";
  }
  if ("coach" === viewMode) {
    return id === NO_COACH ? NO_COACH_LABEL : coachName(lookups.coaches, id);
  }
  // P2-33 : la ressource « jour » a pour clé le numéro ISO ; son libellé est le nom du jour
  // EN TOUTES LETTRES (retour fondateur) — `dayLabelLong`, maison unique des libellés longs.
  // Les en-têtes de la grille gardent l'abrégé (`DAYS`), où la place manque.
  if ("jour" === viewMode) {
    const long = dayLabelLong(Number(id));

    return "" === long ? "?" : long;
  }
  // Repli = l'équipe (vues « equipe » et « club », dont l'axe filtrable est le même).
  return lookups.teams.get(id)?.name ?? "Équipe ?";
}

export interface GridResource {
  id: string;
  label: string;
}

/**
 * Canonical order for a view's resources (filter picker AND grid sub-columns):
 * teams by RANK (priority tier id asc = S…D, then manual tierOrder, then name —
 * same rule as shared/lib/teamTiers), every other view alphabetical. One place
 * so the team selector and the equipe grid stay in the order managers expect.
 */
function resourceComparator(viewMode: ViewMode, lookups: Lookups): (a: GridResource, b: GridResource) => number {
  // P2-33 : les jours se rangent en ordre ISO (lundi→dimanche), jamais alphabétique — sinon
  // « Dim/Jeu/Lun… » sortirait le dimanche en tête. La clé EST le numéro ISO.
  if ("jour" === viewMode) {
    return (a, b) => Number(a.id) - Number(b.id);
  }
  // « equipe » ET « club » rangent par RANG (même axe : l'équipe) ; tout le reste, alphabétique.
  if ("equipe" !== viewMode && "club" !== viewMode) {
    return (a, b) => compareNamesFr(a.label, b.label);
  }
  return (a, b) => {
    const ta = lookups.teams.get(a.id);
    const tb = lookups.teams.get(b.id);
    // A team absent from the lookup (shouldn't happen) sorts last, by label.
    if (undefined === ta || undefined === tb) {
      return (undefined === ta ? 1 : 0) - (undefined === tb ? 1 : 0) || compareNamesFr(a.label, b.label);
    }
    return compareTeamsByRank(ta, tb);
  };
}

/** Distinct resources present across the schedule for the current view (for the filter picker). */
export function availableResources(slots: Slot[], viewMode: ViewMode, lookups: Lookups): GridResource[] {
  const ids = [...new Set(slots.flatMap((s) => resourceKeysForSlot(s, viewMode, lookups)))];
  return ids.map((id) => ({ id, label: resourceLabel(id, viewMode, lookups) })).sort(resourceComparator(viewMode, lookups));
}

export interface GridResourceGroup {
  /** Rank header ("S · Fanion") in the equipe view; null = flat (no header row). */
  label: string | null;
  resources: GridResource[];
}

/**
 * Resources for the filter picker, grouped by rank in the equipe view so the
 * S/A/B/C/D headers are VISIBLE (a flat rank-sorted list reads as unsorted to
 * the manager). Other views (and equipe while tiers load) stay a single flat
 * group. Same grouping rules as the optgroup selects (shared/lib/teamTiers).
 */
export function availableResourceGroups(slots: Slot[], viewMode: ViewMode, lookups: Lookups, tiers: TierLike[]): GridResourceGroup[] {
  const flat = availableResources(slots, viewMode, lookups);
  if (("equipe" !== viewMode && "club" !== viewMode) || 0 === tiers.length || 0 === flat.length) {
    return [{ label: null, resources: flat }];
  }
  const byId = new Map(flat.map((r) => [r.id, r]));
  const teams = flat.map((r) => lookups.teams.get(r.id)).filter((t): t is Team => undefined !== t);
  const groups = groupTeamsByTier(teams, tiers).map((g) => ({
    label: tierGroupLabel(g.tier),
    resources: g.teams.map((t) => byId.get(t.id)).filter((r): r is GridResource => undefined !== r),
  }));
  // A resource with no team in the lookup ("Équipe ?") is not groupable — keep
  // it visible in a trailing flat group rather than silently dropping it.
  const grouped = new Set(groups.flatMap((g) => g.resources.map((r) => r.id)));
  const leftovers = flat.filter((r) => !grouped.has(r.id));
  return leftovers.length > 0 ? [...groups, { label: null, resources: leftovers }] : groups;
}

export interface GridColumn {
  key: string;
  day: number;
  resourceId: string;
  label: string;
  color: string | null;
}

export interface DayGroup {
  day: number;
  label: string;
  /** 1-based CSS grid column where this day's block starts (col 1 = time gutter). */
  startColumn: number;
  span: number;
}

/**
 * Une équipe À L'INTÉRIEUR d'une carte fusionnée (vue gymnase, créneau mutualisé libellé —
 * P2-17 D4). Chaque membre reste cliquable individuellement : son `slotId` ouvre le même
 * panneau de détail que sa carte séparée l'aurait fait.
 */
export interface GridCellMember {
  slotId: string;
  teamLabel: string;
  coachLabel: string;
  locked: boolean;
  /** L'ORIGINE du verrou (F1), pour la lentille verrous : `null` quand le membre n'est
   *  pas verrouillé. Le front l'AFFICHE (couleur/icône par catégorie), il ne re-dérive rien. */
  lockOrigin: LockOrigin | null;
}

export interface GridCell {
  /** Unique per rendered cell (a slot may appear in several coach columns). */
  key: string;
  slotId: string;
  gridColumn: number;
  gridRowStart: number;
  gridRowSpan: number;
  /** Horizontal lane within the column for time-overlapping slots (side-by-side). */
  lane: number;
  laneCount: number;
  /** Contextual labels shown on the slot: the two dimensions other than the view axis. */
  primaryLabel: string;
  secondaryLabel: string;
  /** In the coach view, "joueur" when the coach is a player (not the team's coach) of this slot. */
  roleTag: string | null;
  teamLabel: string;
  venueLabel: string;
  /** Le gymnase de la cellule (colonne). Sert au repérage des couples (gymnase, jour) FERMÉS
   *  pour MARQUER les fenêtres vides d'une période (P2-43) — le libellé ne suffit pas (homonymes). */
  venueId: string;
  venueColor: string | null;
  coachLabel: string;
  day: number;
  startLabel: string;
  endLabel: string;
  locked: boolean;
  /** L'ORIGINE du verrou (F1) de la séance de tête de cette cellule ; `null` si non
   *  verrouillée. Sur une carte fusionnée, chaque `members[i].lockOrigin` porte la sienne. */
  lockOrigin: LockOrigin | null;
  /**
   * P2-17 D4 — libellé d'une carte FUSIONNÉE (vue gymnase, ≥ 2 équipes partageant un
   * créneau mutualisé libellé) ; `null` sur une cellule ordinaire à une seule équipe.
   */
  groupLabel: string | null;
  /** Les équipes d'une carte fusionnée, chacune cliquable ; vide sur une cellule ordinaire. */
  members: GridCellMember[];
}

interface Interval {
  startMin: number;
  endMin: number;
  cell: GridCell;
}

// D-27 : le placement en couloirs vit en `shared/lib/gridLayout` — il était recopié
// caractere pour caractere entre les deux grilles.

export interface GridRow {
  /** Displayed only on hour / half-hour rows; null elsewhere (keeps the grid line). */
  label: string | null;
  /** True on the hour — drawn with a stronger separator. */
  major: boolean;
}

export interface GridModel {
  columns: GridColumn[];
  dayGroups: DayGroup[];
  bounds: TimeBounds;
  stepMin: number;
  rows: GridRow[];
  cells: GridCell[];
}

/**
 * Pure layout. A day is a super-column split into one sub-column per resource —
 * but ONLY the resources actually used that day are shown (empty columns are
 * hidden). An optional resource filter narrows what is displayed. Changing the
 * view only changes which resource forms the sub-columns (same slots, re-grouped).
 */
export function buildGrid(slots: Slot[], viewMode: ViewMode, lookups: Lookups, filter: Set<string> = new Set(), stepMin = 15): GridModel {
  // P2-33 — vue « jour » : l'axe FILTRABLE est le jour (`viewMode`, via `resourceKeysForSlot`),
  // mais l'axe des COLONNES reste le gymnase. On n'écrit PAS un second moteur de layout : on
  // aiguille toute la composition colonnes / libellés / couleurs / fusion (P2-17) sur
  // `columnView` = "gymnase", tandis que le FILTRE seul lit `viewMode` (= le jour). Les autres
  // vues gardent `columnView === viewMode`, comportement inchangé.
  const columnView: ViewMode = "jour" === viewMode ? "gymnase" : viewMode;

  const visible = slots.filter(
    (s) => s.dayOfWeek >= 1 && s.dayOfWeek <= 7 && (0 === filter.size || resourceKeysForSlot(s, viewMode, lookups).some((k) => filter.has(k))),
  );

  // When a filter is active, a slot shows ONLY under the selected columns — not
  // under every coach it concerns. Looking at Mara, SF2 stays under Mara and does
  // not drag in the other coach-players of SF2.
  // ⚠ En vue « jour » le filtre porte sur le JOUR, pas sur la colonne (gymnase) : on ne
  // rétrécit donc PAS les colonnes par lui (le jour a déjà filtré `visible` ci-dessus).
  const keysFor = (slot: Slot): string[] => {
    const keys = resourceKeysForSlot(slot, columnView, lookups);
    return "jour" !== viewMode && filter.size > 0 ? keys.filter((k) => filter.has(k)) : keys;
  };

  const bounds = computeTimeBounds(visible);

  const columns: GridColumn[] = [];
  const dayGroups: DayGroup[] = [];
  let cssColumn = 2; // col 1 is the time gutter

  for (const day of DAYS) {
    const daySlots = visible.filter((s) => s.dayOfWeek === day.n);
    if (0 === daySlots.length) {
      continue; // hide days with no slot
    }
    const idSet = new Set(daySlots.flatMap((s) => keysFor(s)));
    const resourceIds = [...idSet].map((id) => ({ id, label: resourceLabel(id, columnView, lookups) })).sort(resourceComparator(columnView, lookups));

    dayGroups.push({ day: day.n, label: day.label, startColumn: cssColumn, span: resourceIds.length });
    for (const { id, label } of resourceIds) {
      columns.push({
        key: `${day.n}:${id}`,
        day: day.n,
        resourceId: id,
        label,
        color: "gymnase" === columnView ? (lookups.venues.get(id)?.color ?? null) : null,
      });
      cssColumn += 1;
    }
  }

  const columnIndex = new Map(columns.map((c, i) => [c.key, i]));

  // P2-17 D4 — le libellé de groupe d'une séance placée, lu de sa fenêtre (vue gymnase,
  // séance non vide seulement). Le backend possède la règle (capacité ≥ 2, trim, vide→null) ;
  // le front l'AFFICHE, il ne la re-dérive pas.
  const labelOf = (s: Slot): string =>
    "gymnase" === columnView && "" !== s.teamId ? (lookups.groupLabels?.get(slotGroupKey(s.venueId, s.dayOfWeek, s.startTime)) ?? "").trim() : "";
  const mergeKeyOf = (s: Slot, key: string, label: string): string => `${s.dayOfWeek}:${key}:${parseTimeToMinutes(s.startTime)}:${label}`;

  // Une carte fusionnée n'existe qu'à partir de DEUX équipes (« plusieurs partagent » —
  // décision D4) : on compte d'abord les membres par groupe. Une seule équipe sous un
  // libellé retombe sur la cellule ordinaire (pas de carte titrée pour une équipe seule).
  const groupSize = new Map<string, number>();
  for (const slot of visible) {
    const label = labelOf(slot);
    if ("" === label) {
      continue;
    }
    for (const key of keysFor(slot)) {
      if (undefined === columnIndex.get(`${slot.dayOfWeek}:${key}`)) {
        continue;
      }
      const mk = mergeKeyOf(slot, key, label);
      groupSize.set(mk, (groupSize.get(mk) ?? 0) + 1);
    }
  }

  const cells: GridCell[] = [];
  const intervals: Interval[] = [];
  const merged = new Map<string, { cell: GridCell; interval: Interval }>();
  for (const slot of visible) {
    const start = parseTimeToMinutes(slot.startTime);
    const venue = lookups.venues.get(slot.venueId);
    const teamLabel = lookups.teams.get(slot.teamId)?.name ?? "Équipe ?";
    const venueLabel = venue?.name ?? "Gymnase ?";
    const mainCoachId = slotCoachId(slot, lookups);
    const coachLabel = coachName(lookups.coaches, mainCoachId);
    const locked = "NONE" !== slot.lockLevel;
    const label = labelOf(slot);

    for (const key of keysFor(slot)) {
      const idx = columnIndex.get(`${slot.dayOfWeek}:${key}`);
      if (undefined === idx) {
        continue;
      }

      // Créneau mutualisé libellé partagé par ≥ 2 équipes → UNE carte fusionnée titrée
      // par le libellé, chaque équipe restant un membre cliquable (D4).
      const mk = "" !== label ? mergeKeyOf(slot, key, label) : "";
      if ("" !== mk && (groupSize.get(mk) ?? 0) >= 2) {
        const member: GridCellMember = { slotId: slot.id, teamLabel, coachLabel, locked, lockOrigin: slot.lockOrigin };
        const existing = merged.get(mk);
        if (undefined === existing) {
          const cell: GridCell = {
            key: `group@${mk}@${idx}`,
            slotId: slot.id,
            gridColumn: 2 + idx,
            gridRowStart: 3 + Math.round((start - bounds.startMin) / stepMin),
            gridRowSpan: Math.max(1, Math.round(slot.durationMinutes / stepMin)),
            lane: 0,
            laneCount: 1,
            primaryLabel: label,
            secondaryLabel: "",
            roleTag: null,
            teamLabel,
            venueLabel,
            venueId: slot.venueId,
            venueColor: venue?.color ?? null,
            coachLabel,
            day: slot.dayOfWeek,
            startLabel: formatMinutes(start),
            endLabel: formatMinutes(start + slot.durationMinutes),
            locked,
            lockOrigin: slot.lockOrigin,
            groupLabel: label,
            members: [member],
          };
          const interval: Interval = { startMin: start, endMin: start + slot.durationMinutes, cell };
          merged.set(mk, { cell, interval });
          cells.push(cell);
          intervals.push(interval);
        } else {
          existing.cell.members.push(member);
          // Étendre la carte (et son intervalle de placement) à la plus longue séance du groupe.
          const spanRows = Math.max(1, Math.round(slot.durationMinutes / stepMin));
          if (spanRows > existing.cell.gridRowSpan) {
            existing.cell.gridRowSpan = spanRows;
            existing.cell.endLabel = formatMinutes(start + slot.durationMinutes);
          }
          existing.interval.endMin = Math.max(existing.interval.endMin, start + slot.durationMinutes);
        }
        continue;
      }

      // Show the two dimensions OTHER than the view axis.
      let primaryLabel = teamLabel;
      let secondaryLabel = coachLabel;
      let roleTag: string | null = null;
      if ("coach" === columnView) {
        primaryLabel = teamLabel;
        secondaryLabel = venueLabel;
        roleTag = key !== mainCoachId ? "joueur" : null;
      } else if ("equipe" === columnView) {
        primaryLabel = venueLabel;
        secondaryLabel = coachLabel;
      }

      const cell: GridCell = {
        key: `${slot.id}@${idx}`,
        slotId: slot.id,
        gridColumn: 2 + idx,
        gridRowStart: 3 + Math.round((start - bounds.startMin) / stepMin),
        gridRowSpan: Math.max(1, Math.round(slot.durationMinutes / stepMin)),
        lane: 0,
        laneCount: 1,
        primaryLabel,
        secondaryLabel,
        roleTag,
        teamLabel,
        venueLabel,
        venueId: slot.venueId,
        venueColor: venue?.color ?? null,
        coachLabel,
        day: slot.dayOfWeek,
        startLabel: formatMinutes(start),
        endLabel: formatMinutes(start + slot.durationMinutes),
        locked,
        lockOrigin: slot.lockOrigin,
        groupLabel: null,
        members: [],
      };
      cells.push(cell);
      intervals.push({ startMin: start, endMin: start + slot.durationMinutes, cell });
    }
  }
  assignLanes(intervals);

  const rows: GridRow[] = [];
  for (let t = bounds.startMin; t < bounds.endMin; t += stepMin) {
    const onHalfHour = 0 === t % 30;
    rows.push({ label: onHalfHour ? formatMinutes(t) : null, major: 0 === t % 60 });
  }

  return { columns, dayGroups, bounds, stepMin, rows, cells };
}

export interface ConcernedSlot {
  slotId: string;
  dayLabel: string;
  timeLabel: string;
  teamLabel: string;
  venueLabel: string;
}

const dayLabelOf = (day: number): string => DAYS.find((d) => d.n === day)?.label ?? "?";

/**
 * The slots a diagnostic points at (its team / venue / coach), sorted by day+time
 * so the "when + which teams" of a conflict is spelled out instead of implied.
 *
 * Quand le diagnostic PORTE (jour, heure) ET un discriminant — gymnase, coach OU équipe — on
 * RESSERRE sur les créneaux exacts de ce moment (heure à la minute : l'engine émet « HH:MM », un
 * slot peut être « HH:MM:SS »). Sans jour+heure, comportement inchangé (correspondance
 * équipe / gymnase / coach).
 *
 * ⚠ P4-95 — le resserrement exigeait le `venueId`, or c'est précisément le champ qu'un CONFLIT DE
 * COACH ne peut pas porter : tout son sens est que le coach est attendu dans DEUX gymnases au même
 * moment (`diag-conflict-coach-*` pose coach + jour + heure, jamais un gymnase). Une ERREUR dont le
 * moteur donne l'instant exact retombait donc sur « tous les créneaux de ce coach ». La clé est
 * désormais (jour + heure + n'importe quel discriminant), ce qui couvre aussi le gymnase sans rien
 * changer pour lui.
 */
export function concernedSlots(
  diagnostic: { teamId: string | null; venueId: string | null; coachId: string | null; dayOfWeek?: number | null; startTime?: string | null },
  slots: Slot[],
  lookups: Lookups,
): ConcernedSlot[] {
  const pinDay = diagnostic.dayOfWeek ?? null;
  const pinTime = diagnostic.startTime ?? null;
  const pinned = null !== pinDay && null !== pinTime && (null !== diagnostic.venueId || null !== diagnostic.coachId || null !== diagnostic.teamId);
  const matches = pinned
    ? slots.filter(
        (s) =>
          s.dayOfWeek === pinDay &&
          parseTimeToMinutes(s.startTime) === parseTimeToMinutes(pinTime) &&
          ((null !== diagnostic.venueId && s.venueId === diagnostic.venueId) ||
            (null !== diagnostic.coachId && s.coachId === diagnostic.coachId) ||
            (null !== diagnostic.teamId && s.teamId === diagnostic.teamId)),
      )
    : slots.filter(
          (s) =>
            (null !== diagnostic.teamId && diagnostic.teamId === s.teamId) ||
            (null !== diagnostic.venueId && diagnostic.venueId === s.venueId) ||
            (null !== diagnostic.coachId && diagnostic.coachId === s.coachId),
        );

  return matches
    .map((s) => ({
      slotId: s.id,
      day: s.dayOfWeek,
      startMin: parseTimeToMinutes(s.startTime),
      dayLabel: dayLabelOf(s.dayOfWeek),
      timeLabel: toHourMinute(s.startTime),
      teamLabel: lookups.teams.get(s.teamId)?.name ?? "Équipe ?",
      venueLabel: lookups.venues.get(s.venueId)?.name ?? "Gymnase ?",
    }))
    .sort((a, b) => a.day - b.day || a.startMin - b.startMin)
    .map(({ slotId, dayLabel, timeLabel, teamLabel, venueLabel }) => ({ slotId, dayLabel, timeLabel, teamLabel, venueLabel }));
}
