import type { Conflict } from "../api";
import { dateOf } from "./consultFilter";

/**
 * Tri d'une LISTE de conflits par DATE croissante (décision fondateur 2026-09-17) —
 * appliqué DANS un même groupe de gravité, jamais à l'ordre des groupes ni à celui des
 * entrées de pivot. Présentation PURE : on RÉORDONNE des conflits déjà servis, on n'en
 * dérive aucune règle (🔴 `.claude/rules/frontend.md`).
 *
 * Clé de tri (maison unique `dateOf` pour la date) :
 *  1. la DATE — `conflict.start` (datetime) si présent, sinon la `matchDate` du premier
 *     côté (`left`/`right`/`fixture`), sinon `null` → rejeté EN FIN de liste ;
 *  2. départage stable par HEURE (l'heure de `start`, sinon le coup d'envoi du premier
 *     côté) ;
 *  3. puis par EMPREINTE (`fingerprint`) — un total déterministe, jamais dépendant de
 *     l'ordre d'entrée.
 *
 * Renvoie une NOUVELLE liste (l'entrée n'est jamais mutée).
 */
export function sortConflictsByDate(conflicts: Conflict[]): Conflict[] {
  return [...conflicts].sort((a, b) => {
    const da = dateOf(a);
    const db = dateOf(b);
    if (da !== db) {
      if (null === da) {
        return 1; // sans date → fin de liste
      }
      if (null === db) {
        return -1;
      }
      return da < db ? -1 : 1;
    }
    const ta = timeKeyOf(a);
    const tb = timeKeyOf(b);
    if (ta !== tb) {
      return ta < tb ? -1 : 1;
    }
    const fa = a.fingerprint ?? "";
    const fb = b.fingerprint ?? "";
    return fa < fb ? -1 : fa > fb ? 1 : 0;
  });
}

/** Heure « HH:MM » de départage : celle de `start`, sinon le coup d'envoi du premier
 *  côté ; `""` quand aucune n'est connue (trie avant les heures renseignées). */
function timeKeyOf(conflict: Conflict): string {
  if (undefined !== conflict.start) {
    return conflict.start.slice(11, 16);
  }
  return conflict.left?.kickoffTime ?? conflict.right?.kickoffTime ?? conflict.fixture?.kickoffTime ?? "";
}
