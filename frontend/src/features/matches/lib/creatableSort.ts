import type { RencontreCreatable } from "../api";

/**
 * Ordre d'affichage des rencontres FFBB à créer (`ReconciliationView`) : le
 * gestionnaire lit un calendrier, pas un tas. Tri PUR (copie, jamais de mutation) :
 * date croissante, puis heure croissante — une heure absente en DERNIER de SON jour
 * (jamais après le jour suivant) —, puis l'adversaire en collation française.
 *
 * Les valeurs viennent du backend (`GET /api/ffbb/rencontres`) : `date` ISO,
 * `kickoff` « HH:MM » ou null. On compare `date`/`kickoff` comme des chaînes — les
 * deux formats trient correctement en lexicographique.
 */
export function sortCreatable(list: readonly RencontreCreatable[]): RencontreCreatable[] {
  return [...list].sort((a, b) => {
    if (a.date !== b.date) {
      return a.date < b.date ? -1 : 1;
    }
    // Même jour : l'heure absente ferme la journée, jamais l'inverse.
    if (a.kickoff !== b.kickoff) {
      if (null === a.kickoff) {
        return 1;
      }
      if (null === b.kickoff) {
        return -1;
      }
      return a.kickoff < b.kickoff ? -1 : 1;
    }
    return a.opponentLabel.localeCompare(b.opponentLabel, "fr");
  });
}
