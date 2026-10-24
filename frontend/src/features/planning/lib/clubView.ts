import { dayLabelLong } from "@/shared/lib/days";
import { compareNamesFr } from "@/shared/lib/nameOrder";
import { compareTeamsByRank, groupTeamsByTier, type TeamLike, tierGroupLabel, type TierLike } from "@/shared/lib/teamTiers";
import { formatMinutes } from "@/shared/lib/time";

import type { LockOrigin, Slot, Team } from "../api";
import { type Lookups, parseTimeToMinutes } from "./grid";

/**
 * P3-20 — « la vue par club » : une ligne par ÉQUIPE, une colonne par JOUR, la cellule
 * disant « gymnase · heure ». C'est la mise en forme qui n'existait qu'à l'export (section 2
 * du PDF `PdfGenerator::buildMatrixSection`, feuille « Équipes × jours » du XLSX
 * `SpreadsheetGenerator::appendTeamDayMatrix`) — un format qu'on ne consulte pas à l'écran.
 *
 * ⚑ Ce module est une PROJECTION D'AFFICHAGE, pas une règle métier : il ne décide rien que le
 * serveur déciderait autrement, il re-range des séances déjà placées. Il ne remplace pas
 * `buildGrid` (layout temporel) — les deux moteurs ne partagent rien d'autre que `Lookups`.
 *
 * Les règles de contenu sont celles des exports, reprises À L'IDENTIQUE pour que l'écran et le
 * document remis aux familles ne se contredisent jamais :
 * - **une ligne par équipe de la saison**, y compris celle qui n'a AUCUNE séance — c'est le
 *   trou que le gestionnaire doit voir, jamais une ligne masquée ;
 * - **deux séances le même jour = deux entrées**, triées par heure (aucune n'est écrasée) ;
 * - **colonnes = les jours réellement utilisés**, en ordre ISO (samedi/dimanche n'apparaissent
 *   que s'ils portent quelque chose) ;
 * - **aucun coach** (décision fondateur des exports : il vit dans la grille et n'encombrerait
 *   que le balayage) ;
 * - **les fenêtres VIDES n'entrent pas** : elles n'appartiennent à aucune équipe.
 */

/** Une séance d'une équipe un jour donné. Pas de coach : voir le docblock du module. */
export interface ClubViewEntry {
  slotId: string;
  venueLabel: string;
  venueColor: string | null;
  startLabel: string;
  endLabel: string;
  locked: boolean;
  /** L'ORIGINE du verrou (F1) pour la lentille ; `null` quand la séance n'est pas verrouillée. */
  lockOrigin: LockOrigin | null;
}

/** La case (équipe, jour). Projetée PAR CLÉ, jamais par position — cf. `buildClubView`. */
export interface ClubViewCell {
  day: number;
  entries: ClubViewEntry[];
}

export interface ClubViewRow {
  teamId: string;
  teamLabel: string;
  /** L'équipe de la ligne est-elle « en entente (CTC) » ? Repère CtcMark devant son nom. */
  isCtc?: boolean;
  /** Le/les partenaire(s) de l'entente (nom accessible du repère) ; null hors entente. */
  ctcPartner?: string | null;
  /** Une cellule par colonne, dans l'ordre de `dayColumns`. */
  cells: ClubViewCell[];
  /** Nombre total de séances de la semaine — sert au repère « aucune séance ». */
  sessionCount: number;
}

export interface ClubViewGroup {
  /** En-tête de rang (« S · Fanion ») ; `null` = groupe plat (rangs non chargés). */
  label: string | null;
  rows: ClubViewRow[];
}

export interface ClubViewModel {
  dayColumns: { day: number; label: string }[];
  groups: ClubViewGroup[];
}

/** « lundi » → « Lundi » : l'en-tête de colonne reprend la capitale des exports. */
const dayHeader = (day: number): string => {
  const long = dayLabelLong(day);

  return "" === long ? "?" : long.charAt(0).toUpperCase() + long.slice(1);
};

/**
 * Projette les séances en matrice équipes × jours.
 *
 * `filter` porte sur les ÉQUIPES (l'axe filtrable de cette vue, cf. `resourceKeysForSlot`) :
 * vide = toutes. Les colonnes se calculent APRÈS le filtre — filtrer sur une équipe qui ne
 * joue que le jeudi ne doit pas laisser six colonnes vides à l'écran.
 *
 * ⚠ Discipline D-18 des exports : le modèle est indexé par (équipe, jour) puis PROJETÉ sur les
 * colonnes ordonnées. La cellule sous « Mardi » lit toujours le mardi de CETTE équipe — une
 * écriture positionnelle produirait un décalage parfaitement silencieux.
 */
export function buildClubView(slots: Slot[], lookups: Lookups, filter: Set<string> = new Set(), tiers: TierLike[] = []): ClubViewModel {
  // Une fenêtre vide n'a pas d'équipe : elle n'entre dans aucune ligne (même règle que les
  // deux exports). Un jour hors bornes ISO n'entre pas non plus — il n'aurait pas de colonne.
  const visible = slots.filter((s) => "" !== s.teamId && s.dayOfWeek >= 1 && s.dayOfWeek <= 7 && (0 === filter.size || filter.has(s.teamId)));

  /** teamId → jour → séances. */
  const matrix = new Map<string, Map<number, ClubViewEntry[]>>();
  const daysUsed = new Set<number>();
  for (const slot of visible) {
    const start = parseTimeToMinutes(slot.startTime);
    const venue = lookups.venues.get(slot.venueId);
    const byDay = matrix.get(slot.teamId) ?? new Map<number, ClubViewEntry[]>();
    const entries = byDay.get(slot.dayOfWeek) ?? [];
    entries.push({
      slotId: slot.id,
      venueLabel: venue?.name ?? "Gymnase ?",
      venueColor: venue?.color ?? null,
      startLabel: formatMinutes(start),
      endLabel: formatMinutes(start + slot.durationMinutes),
      locked: "NONE" !== slot.lockLevel,
      lockOrigin: slot.lockOrigin,
    });
    byDay.set(slot.dayOfWeek, entries);
    matrix.set(slot.teamId, byDay);
    daysUsed.add(slot.dayOfWeek);
  }

  const dayColumns = [1, 2, 3, 4, 5, 6, 7].filter((d) => daysUsed.has(d)).map((day) => ({ day, label: dayHeader(day) }));

  // Lignes = toutes les équipes de la saison (le trou reste visible), PLUS toute équipe qui
  // porte une séance sans exister dans les lookups (anomalie) : aucune séance ne s'évapore.
  const teamIds = new Set<string>([...lookups.teams.keys(), ...matrix.keys()]);
  const candidates = [...teamIds].filter((id) => 0 === filter.size || filter.has(id));
  // Une équipe absente des lookups n'a ni rang ni ordre : `priorityTierId: -1` la range dans le
  // panier « Autres » de `groupTeamsByTier` plutôt que de la faire passer pour une équipe de rang S.
  const teamLike = (id: string): TeamLike & { source: Team | undefined } => {
    const team = lookups.teams.get(id);

    return { id, name: team?.name ?? "Équipe ?", priorityTierId: team?.priorityTierId ?? -1, tierOrder: team?.tierOrder ?? 0, source: team };
  };
  const rowOf = (id: string): ClubViewRow => {
    const byDay = matrix.get(id);

    const team = lookups.teams.get(id);

    return {
      teamId: id,
      teamLabel: teamLike(id).name,
      isCtc: true === team?.isCtc,
      ctcPartner: team?.ctcPartner ?? null,
      cells: dayColumns.map(({ day }) => {
        const entries = [...(byDay?.get(day) ?? [])].sort((a, b) => a.startLabel.localeCompare(b.startLabel));

        return { day, entries };
      }),
      sessionCount: [...(byDay?.values() ?? [])].reduce((n, list) => n + list.length, 0),
    };
  };

  // Rangs pas encore chargés → un seul groupe PLAT (jamais un en-tête « Autres » trompeur qui
  // dirait « ces équipes n'ont pas de rang » alors qu'on ne les a simplement pas encore lus).
  if (0 === tiers.length) {
    const flat = candidates
      .map(teamLike)
      .sort((a, b) => (undefined === a.source || undefined === b.source ? compareNamesFr(a.name, b.name) : compareTeamsByRank(a.source, b.source)));

    return { dayColumns, groups: [{ label: null, rows: flat.map((t) => rowOf(t.id)) }] };
  }

  const groups = groupTeamsByTier(candidates.map(teamLike), tiers).map((group) => ({
    label: tierGroupLabel(group.tier),
    rows: group.teams.map((t) => rowOf(t.id)),
  }));

  return { dayColumns, groups };
}
