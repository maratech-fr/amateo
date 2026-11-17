import type { VenueOccupancy, VenueTrainingSlot } from "../api";
import { dayLabelLong } from "@/shared/lib/days";
import { formatDuration } from "@/shared/lib/duration";

import { WIZARD_TEXTS } from "../texts";
import { dayLabel, fmtMinutes, hhmm, toMinutes } from "./days";

/**
 * Overlap guard (same gym): two slots on the SAME weekday may never share any
 * time. Divisibility is a within-slot capacity, not a licence to stack slots.
 * Returns the first conflicting slot, or null when the [start, start+duration)
 * window is free. `slots` should already exclude the slot being edited.
 */
export function findSlotConflict(slots: VenueTrainingSlot[], day: number, startTime: string, durationMinutes: number): VenueTrainingSlot | null {
  const start = toMinutes(startTime);
  const end = start + durationMinutes;
  return (
    slots.find((s) => {
      if (s.dayOfWeek !== day) {
        return false;
      }
      const sStart = toMinutes(s.startTime);
      return start < sStart + s.durationMinutes && sStart < end;
    }) ?? null
  );
}

/** Minutes dans une journée — un créneau ne peut pas franchir minuit. */
const DAY_END = 24 * 60;

/**
 * Un créneau doit finir dans SA journée.
 *
 * Personne ne gardait cette borne : `durationMinutes` n'a pas de maximum côté API
 * (`VenueTrainingSlotInput` ne porte qu'un `Range(min: 15)`) et le chevauchement, seule
 * vérification côté client, ne regarde que les créneaux VOISINS. Tant que la grille
 * s'arrêtait à 21:45 et les durées à 2h, le pire cas tombait à 23:45 et la question ne se
 * posait pas. P4-37 ayant ouvert les deux bouts (départ jusqu'à 22:45, durée jusqu'à
 * 2h30), « 22:45 + 2h30 » se posait en un clic, se persistait, et ressortait en
 * **« 25:15 »** partout où une fin d'horaire s'affiche — `divmod(1515, 60)` ne se plaint
 * de rien, côté front comme côté engine.
 *
 * Rend le message d'erreur, ou null si le créneau tient dans la journée.
 */
export function pastMidnightMessage(startTime: string, durationMinutes: number): string | null {
  const start = toMinutes(startTime);
  // ⚠ Une heure ILLISIBLE n'est pas une heure tardive : `toMinutes("")` rend NaN et
  // `NaN <= DAY_END` est faux, si bien que la garde annonçait « un créneau qui commence à
  // ⟨rien⟩ ne peut pas durer 1h30 » en désignant la durée, que personne n'avait touchée.
  // Elle est signalée par `slotPlacementError`, qui nomme le CHAMP fautif.
  if (!Number.isFinite(start) || start + durationMinutes <= DAY_END) {
    return null;
  }

  return `Un créneau qui commence à ${hhmm(startTime)} ne peut pas durer ${formatDuration(durationMinutes)} : il finirait après minuit.`;
}

export const conflictMessage = (c: VenueTrainingSlot): string =>
  `Chevauchement avec le créneau ${dayLabel(c.dayOfWeek)} ${hhmm(c.startTime)}–${fmtMinutes(toMinutes(c.startTime) + c.durationMinutes)}. Les créneaux ne peuvent pas se superposer.`;

/**
 * LA validation de pose d'un créneau — heure lisible, puis minuit, puis chevauchement.
 *
 * La séquence était recopiée sur les quatre sites qui posent ou déplacent un créneau
 * (création au clic et édition, en saison et en période). Quatre copies d'une règle, c'est
 * quatre occasions qu'elles divergent : la revue de P4-37 a précisément trouvé un site où
 * la borne de minuit manquait encore. Un seul foyer, appelé par les quatre.
 *
 * Le message de minuit fut un temps surchargeable par appelant : la pose au clic en période
 * imposait 90 min sans offrir de sélecteur, donc y désigner « la durée » aurait renvoyé
 * vers un réglage que cet écran n'avait pas. P4-43 lui a donné sa barre « À poser » — les
 * quatre sites règlent désormais la durée, et le message par défaut est vrai partout. Le
 * point de surcharge est retiré plutôt que laissé mort avec une justification périmée.
 *
 * Rend le message à afficher, ou null si la pose est valide.
 */
export function slotPlacementError(others: VenueTrainingSlot[], day: number, startTime: string, durationMinutes: number, occupancies: VenueOccupancy[]): string | null {
  // L'heure d'ABORD : le champ « Début » est un `<input type="time">` sans `required`,
  // dans une modale sans `<form>` — rien ne l'exige. Vidé en cours de frappe, il rendait
  // NaN, et NaN traversait tout : la borne de minuit se taisait (correctement), puis
  // `findSlotConflict` comparait des NaN — toute comparaison avec NaN étant fausse, aucun
  // chevauchement n'était détecté — et l'écriture partait avec `startTime: ""`, que l'API
  // rejette par un 422 générique sans désigner le champ. Le contrôle se fait ici, où le
  // geste a lieu.
  if (!Number.isFinite(toMinutes(startTime))) {
    return "Renseignez une heure de début (format HH:MM).";
  }

  const midnight = pastMidnightMessage(startTime, durationMinutes);
  if (null !== midnight) {
    return midnight;
  }

  const conflict = findSlotConflict(others, day, startTime, durationMinutes);
  if (null !== conflict) {
    return conflictMessage(conflict);
  }

  // Un créneau ne se pose pas non plus sur une occupation du même gymnase (même règle de
  // chevauchement, bornes jointives acceptées).
  const occupancy = findOccupancyConflict(occupancies, day, startTime, durationMinutes);

  return null === occupancy ? null : WIZARD_TEXTS.slotOnOccupancyConflict(occupancy.label, occupancyWhen(occupancy, dayLabel));
}

/** Le jour et la plage d'une occupation, « Mar 14:00–16:15 » (le libellé de jour est choisi par l'appelant). */
const occupancyWhen = (o: VenueOccupancy, day: (n: number) => string): string =>
  `${day(o.dayOfWeek)} ${hhmm(o.startTime)}–${fmtMinutes(toMinutes(o.startTime) + o.durationMinutes)}`;

/** La première occupation du même jour qui partage du temps avec [début, début + durée), ou null. */
function findOccupancyConflict(occupancies: VenueOccupancy[], day: number, startTime: string, durationMinutes: number): VenueOccupancy | null {
  const start = toMinutes(startTime);
  const end = start + durationMinutes;
  return (
    occupancies.find((o) => {
      if (o.dayOfWeek !== day) {
        return false;
      }
      const oStart = toMinutes(o.startTime);
      return start < oStart + o.durationMinutes && oStart < end;
    }) ?? null
  );
}

/**
 * LA validation de pose d'une OCCUPATION — heure lisible, minuit, puis chevauchement avec un
 * créneau, puis avec une autre occupation du même gymnase. Pré-contrôle : le serveur reste juge.
 * `otherOccupancies` exclut déjà l'occupation éditée. Rend le message, ou null si la pose est valide.
 */
export function occupancyPlacementError(slots: VenueTrainingSlot[], otherOccupancies: VenueOccupancy[], day: number, startTime: string, durationMinutes: number): string | null {
  if (!Number.isFinite(toMinutes(startTime))) {
    return "Renseignez une heure de début (format HH:MM).";
  }
  if (toMinutes(startTime) + durationMinutes > DAY_END) {
    return WIZARD_TEXTS.occupancyPastMidnight(hhmm(startTime), formatDuration(durationMinutes));
  }

  const slot = findSlotConflict(slots, day, startTime, durationMinutes);
  if (null !== slot) {
    return WIZARD_TEXTS.occupancyOnSlotConflict(`${dayLabel(slot.dayOfWeek)} ${hhmm(slot.startTime)}–${fmtMinutes(toMinutes(slot.startTime) + slot.durationMinutes)}`);
  }

  const occupancy = findOccupancyConflict(otherOccupancies, day, startTime, durationMinutes);

  return null === occupancy ? null : WIZARD_TEXTS.occupancyOnOccupancyConflict(occupancy.label, occupancyWhen(occupancy, dayLabel));
}

/**
 * Les occupations qui partagent du temps avec un créneau du même jour (état né d'une copie, d'une
 * version chargée ou de deux gestionnaires) — ce que le bandeau signale, triées jour puis heure.
 */
export function occupanciesOverlappingSlots(slots: VenueTrainingSlot[], occupancies: VenueOccupancy[]): VenueOccupancy[] {
  return occupancies
    .filter((o) => null !== findSlotConflict(slots, o.dayOfWeek, o.startTime, o.durationMinutes))
    .sort((a, b) => a.dayOfWeek - b.dayOfWeek || toMinutes(a.startTime) - toMinutes(b.startTime));
}

/** « mardi 18:00–19:00 » : le jour en toutes lettres et la plage d'une occupation, pour le bandeau. */
export const occupancyLongWhen = (o: VenueOccupancy): string => occupancyWhen(o, dayLabelLong);
