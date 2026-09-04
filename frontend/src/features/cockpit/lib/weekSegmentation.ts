import { toISODate } from "@/shared/lib/clock";

import type { WeekWindow } from "./date";

/**
 * DÉCOUPAGE d'une indisponibilité (mère CLOSURE) en segments début·milieu·fin — décision
 * fondateur 2026-09-05.
 *
 * ⚠️ MIROIR DÉCLARÉ (régime 2, cf. `.claude/rules/frontend.md`) — PARITÉ MÉCANIQUE avec le backend
 * `App\Service\WeekSegmentationRule::segments`, qui applique EXACTEMENT le même algorithme pour
 * GARDER le POST d'une semaine-enfant de fermeture (422 sinon) et le geste « d'un bloc » (422 si la
 * décomposition compte plus d'un segment). Ici on qualifie l'OFFRE du picker : un segment coché
 * devient un plan. Deux implémentations sont inévitables (le cockpit doit segmenter sans
 * aller-retour réseau) ; elles sont tenues alignées par la parité MÉCANIQUE
 * `WeekSegmentationMirrorParityTest` : les mêmes cas (`weekSegmentation.parity.json`) traversent les
 * DEUX implémentations. Ce module figure au registre `FrontRederivationRegistryTest`.
 *
 * La règle (fondateur) : une indisponibilité se découpe en DÉBUT (semaine entamée de tête), MILIEU
 * (toutes les semaines PLEINES contiguës, UN SEUL plan — un trou de vacances lun→ven ou une fenêtre
 * déjà planifiée coupe le milieu en deux runs) et FIN (semaine entamée de queue). Ruptures
 * GÉOMÉTRIQUES seulement (semaines offertes + fenêtre de l'événement), aucune règle solveur
 * redérivée.
 */

export type WeekSegmentKind = "start" | "middle" | "end";

/** Un segment brut : ses semaines OFFERTES, ses bornes (clamp saison porté par les semaines) et son genre. */
export interface RawSegment {
  monday: string;
  startDate: string;
  endDate: string;
  kind: WeekSegmentKind;
  weeks: WeekWindow[];
}

/** ISO date `n` jours après `iso` (foyer local pour éviter un import circulaire depuis date.ts). */
function addDaysISO(iso: string, n: number): string {
  const [y, m, d] = iso.split("-").map(Number);
  return toISODate(new Date(y, m - 1, d + n));
}

/** L'événement [start, end] couvre-t-il TOUTE la semaine calendaire (lun→dim) dont `monday` est le lundi ? */
const eventCoversFullWeek = (monday: string, eventStart: string, eventEnd: string): boolean => eventStart <= monday && eventEnd >= addDaysISO(monday, 6);

const makeSegment = (weeks: WeekWindow[], kind: WeekSegmentKind): RawSegment => ({
  monday: weeks[0].monday,
  startDate: weeks[0].startDate,
  endDate: weeks[weeks.length - 1].endDate,
  kind,
  weeks,
});

/**
 * Le découpage d'une fenêtre en segments début·milieu·fin, aux ruptures GÉOMÉTRIQUES seulement :
 *  - une semaine que l'événement ne couvre pas ENTIÈREMENT lun→dim → bout de taille 1 (kind 'start'
 *    si l'événement commence après le lundi — entame de tête —, sinon 'end' — entame de queue) ;
 *  - une discontinuité de l'offre (trou de vacances lun→ven, fenêtre déjà planifiée) → chaque run
 *    contigu de semaines pleines = un 'middle'.
 */
export function weekSegments(offered: WeekWindow[], eventStart: string, eventEnd: string): RawSegment[] {
  const segments: RawSegment[] = [];
  let run: WeekWindow[] = [];
  const flush = (): void => {
    if (run.length > 0) {
      segments.push(makeSegment(run, "middle"));
      run = [];
    }
  };
  for (let i = 0; i < offered.length; i += 1) {
    const week = offered[i];
    if (!eventCoversFullWeek(week.monday, eventStart, eventEnd)) {
      flush();
      segments.push(makeSegment([week], eventStart > week.monday ? "start" : "end"));
      continue;
    }
    if (i > 0 && week.monday !== addDaysISO(offered[i - 1].monday, 7)) {
      flush();
    }
    run.push(week);
  }
  flush();
  return segments;
}
