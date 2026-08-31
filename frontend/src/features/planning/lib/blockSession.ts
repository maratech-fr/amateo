import { toHourMinute } from "./grid";

/**
 * P2-51 PR-6 — dériver les SÉANCES DE BLOC de mutualisation à l'écran de planning.
 *
 * 🔴 FAIL-SAFE, le serveur reste seul juge. Le `Slot` généré ne porte AUCUN marqueur de son
 * appartenance à un bloc (le backend n'en expose pas) : on DÉRIVE donc côté front, depuis les blocs
 * de la portée + la CO-LOCALISATION. Un bloc « siège » sur une case (gymnase, jour, heure) quand
 * TOUS ses membres y ont un créneau. Cette dérivation ne décide QUE de proposer (ou non) le geste
 * « Déplacer le groupe » — le rail `move-group` renvoie le verdict du moteur, qui tranche vraiment
 * (une case source vide y répond `slot_unavailable`, affiché tel quel).
 *
 * Types STRUCTURELS (`{ teamIds }`, `{ teamId, venueId, dayOfWeek, startTime }`) pour lire bloc et
 * créneau par leur forme, sans coupler ce lib aux entités `SharedTrainingBlock`/`Slot`.
 */

export interface BlockLike {
  id: string;
  teamIds: readonly string[];
}

export interface CoLocatableSlot {
  teamId: string;
  venueId: string;
  dayOfWeek: number;
  startTime: string;
}

const caseKey = (venueId: string, dayOfWeek: number, startTime: string): string => `${venueId}|${dayOfWeek}|${toHourMinute(startTime)}`;

/**
 * Les blocs dont TOUS les membres siègent sur la case donnée (gymnase, jour, heure). Un bloc sans
 * membre n'est jamais « présent ». La case peut porter d'autres équipes (non-membres) — on exige
 * seulement que chaque membre du bloc y soit.
 */
export function blocksAtCase(
  blocks: readonly BlockLike[],
  slots: readonly CoLocatableSlot[],
  atCase: { venueId: string; dayOfWeek: number; startTime: string },
): BlockLike[] {
  const key = caseKey(atCase.venueId, atCase.dayOfWeek, atCase.startTime);
  const teamsHere = new Set(slots.filter((s) => caseKey(s.venueId, s.dayOfWeek, s.startTime) === key).map((s) => s.teamId));

  return blocks.filter((b) => b.teamIds.length > 0 && b.teamIds.every((id) => teamsHere.has(id)));
}

/**
 * Les blocs dont CE créneau est un membre ET qui sont pleinement co-localisés sur SA case — les
 * seuls pour lesquels « Déplacer le groupe » a un sens depuis ce créneau. Vide = créneau ordinaire
 * (le geste de déplacement simple s'applique).
 */
export function blocksForSlot(slot: CoLocatableSlot, blocks: readonly BlockLike[], slots: readonly CoLocatableSlot[]): BlockLike[] {
  return blocksAtCase(blocks, slots, slot).filter((b) => b.teamIds.includes(slot.teamId));
}
