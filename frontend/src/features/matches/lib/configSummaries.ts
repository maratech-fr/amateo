import type { Competition, MatchSlotRotation, OpponentTravel, SportCategoryDuration } from "../api";

/**
 * P4-185 — les résumés d'en-tête des sections de `/matchs/configuration` (accordéon
 * « une section = un écran »). Fonctions PURES, servies par le backend : le front
 * COMPTE ce que le serveur a calculé, il n'invente aucune règle métier
 * (🔴 `.claude/rules/frontend.md`). Entrée `undefined` (chargement / échec de lecture)
 * ⇒ `null` ⇒ en-tête SANS compte — jamais un « 0 » fabriqué qui ferait croire à un vide.
 */

export function rotationsSummary(rotations?: MatchSlotRotation[]): string | null {
  if (undefined === rotations) {
    return null;
  }
  const n = rotations.length;
  if (0 === n) {
    return "aucune rotation";
  }
  return `${n} rotation${n > 1 ? "s" : ""}`;
}

export function deadlinesSummary(competitions?: Competition[]): string | null {
  if (undefined === competitions) {
    return null;
  }
  const m = competitions.length;
  // Une échéance EFFECTIVE (club OU proposée par la communauté) est servie dans
  // `effectiveEntryDeadline` — jamais recalculée côté front (api.ts:86-92).
  const n = competitions.filter((c) => null != c.effectiveEntryDeadline).length;
  // « N renseignée(s) sur M compétition(s) » : chaque accord porte sur son propre nombre.
  return `${n} renseignée${n > 1 ? "s" : ""} sur ${m} compétition${m > 1 ? "s" : ""}`;
}

export function durationsSummary(categories?: SportCategoryDuration[]): string | null {
  if (undefined === categories) {
    return null;
  }
  // Personnalisée = une valeur propre (match ou échauffement) ; sinon la catégorie
  // hérite du défaut de famille SERVI (jamais 75/90/105 en dur côté front).
  const n = categories.filter((c) => null !== c.matchMinutes || null !== c.warmupMinutes).length;
  if (0 === n) {
    return "défauts par catégorie";
  }
  return `${n} personnalisée${n > 1 ? "s" : ""}`;
}

export function opponentsSummary(travel?: OpponentTravel[]): string | null {
  if (undefined === travel) {
    return null;
  }
  const m = travel.length;
  if (0 === m) {
    return "aucun adversaire";
  }
  const n = travel.filter((o) => !o.located).length;
  if (0 === n) {
    return "tous localisés";
  }
  return `${n} à localiser sur ${m}`;
}
