import type { CalendarEntry } from "../api";
import { daysUntil } from "./date";

/**
 * P4-68 (recadré par le fondateur, 2026-08-06) — quelles indisponibilités de
 * gymnase méritent une carte du radar.
 *
 * Le modèle du fondateur : « on donne une indispo, le gestionnaire crée un
 * planning overlay ; s'il ne le fait pas c'est que l'indispo n'influe en rien ou
 * qu'on ne veut pas la traiter. Le gestionnaire est responsable et on fait le
 * nécessaire pour qu'il soit ALERTÉ. » D'où le périmètre : on ALERTE au bon
 * moment et on ouvre le chemin (créer la fermeture → adapter) — on ne décore ni
 * les exports ni la grille, et on ne masque aucune séance.
 *
 * Trois bornes, chacune pour une raison :
 *  - **pas révolue** (`endDate >= today`) : une fermeture passée n'est plus une
 *    action, l'afficher userait le panneau jusqu'à le rendre invisible ;
 *  - **dans l'horizon** : au-delà, « c'est trop loin pour que je m'en occupe de
 *    suite » — même doctrine que les fériés et les vacances (P3-13) ;
 *  - **pas déjà couverte** : deux sources, jamais confondues (bug fondateur BCCL
 *    local 2026-10-28) —
 *      · une période CLUB-ENTIER (`holiday`/`cutoff`) qui couvre TOUTE la plage
 *        masque quel que soit le gymnase : elle ferme tout le club, porte déjà sa
 *        carte « Adapter »/« Voir », deux cartes pour un geste = radar qui radote ;
 *      · une FERMETURE de gymnase ne masque que si elle vise le MÊME gymnase que
 *        l'indispo. `CalendarEntry` ne porte PAS de gymnase, donc une `closure`
 *        d'`entries` ne peut plus masquer (elle masquait AVEUGLÉMENT n'importe quel
 *        gymnase : une fermeture Matéo prolongée engloutissait une indispo ADN). Le
 *        gymnase d'une fermeture vient du feed `venueClosures` ({venueId}).
 *    Couverture PARTIELLE dans les deux cas : on garde l'alerte (jours hors période
 *    traités par personne).
 */
export interface RadarUnavailability {
  id: string;
  venueId: string;
  startDate: string;
  endDate: string;
  label: string | null;
}

export function unavailabilitiesToAlert(
  unavailabilities: RadarUnavailability[],
  entries: CalendarEntry[],
  today: string,
  horizonDays: number,
  venueClosures: { venueId: string; startDate: string; endDate: string }[] = [],
): RadarUnavailability[] {
  // Seules les périodes CLUB-ENTIER masquent via `entries` : une `closure` n'y masque plus (son
  // gymnase est inconnu — il vient de `venueClosures`).
  const clubWidePeriods = entries.filter((e) => "period" === e.kind && ("holiday" === e.periodType || "cutoff" === e.periodType));
  const covers = (startDate: string, endDate: string, u: RadarUnavailability): boolean => startDate <= u.startDate && endDate >= u.endDate;

  return unavailabilities
    .filter((u) => u.endDate >= today)
    .filter((u) => daysUntil(today, u.startDate) <= horizonDays)
    .filter((u) => !clubWidePeriods.some((p) => covers(p.startDate, p.endDate, u)))
    .filter((u) => !venueClosures.some((c) => c.venueId === u.venueId && covers(c.startDate, c.endDate, u)))
    .sort((a, b) => (a.startDate === b.startDate ? a.venueId.localeCompare(b.venueId) : a.startDate.localeCompare(b.startDate)));
}
