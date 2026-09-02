import type { SocleDeviation } from "../api";
import { DAYS, toHourMinute } from "./grid";

const DAY_LABEL = new Map(DAYS.map((d) => [d.n, d.label]));

/** « 18:30 » → « 18h30 » : le format du fondateur pour ces libellés (présentation pure). */
const hLabel = (time: string): string => toHourMinute(time).replace(":", "h");

/**
 * « Mar 18h30 Matéo » — un placement lisible (jour, heure, gymnase). MAISON UNIQUE partagée par le
 * panneau des écarts et la grille : les deux nomment un placement de la MÊME façon.
 */
export const placementLabel = (day: number, time: string, venue: string): string => `${DAY_LABEL.get(day) ?? "?"} ${hLabel(time)} ${venue}`;

/**
 * P2-44 PR-4 — MAPPING DE PRÉSENTATION PUR : quels créneaux de la grille (par `slotId`) portent un
 * écart avec le socle, et quelle en est l'ORIGINE (le placement de saison, pour le lecteur d'écran).
 * Le backend a DÉJÀ décidé ce qui est un écart (`moved[]`) — ici on ne fait que replier sa réponse
 * en une table `slotId → libellé d'origine` que la grille consomme. Zéro règle métier (règle d'or) :
 * aucun `switch` sur un enum, aucune redérivation. Les non replacées (`unplaced`) restent du texte
 * dans le panneau (pas de carte à viser), elles n'entrent pas dans cette table.
 *
 * La clé est `moved[].to.slotId` (le créneau de PÉRIODE que la grille affiche). Vide si `deviation`
 * est nul (route non armée : vacance, `/planning` autonome, version non COMPLETED).
 */
export function deviatedSlots(deviation: SocleDeviation | null, venueName: (venueId: string) => string): Map<string, string> {
  const map = new Map<string, string>();
  if (null === deviation) {
    return map;
  }
  for (const entry of deviation.moved) {
    map.set(entry.to.slotId, placementLabel(entry.from.dayOfWeek, entry.from.startTime, venueName(entry.from.venueId)));
  }
  return map;
}
