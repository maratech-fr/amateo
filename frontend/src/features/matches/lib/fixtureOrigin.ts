import type { Fixture } from "../api";

/**
 * Correctif 3 (retour terrain 2026-09-27) — une rencontre vient-elle d'un IMPORT
 * (FBI XLSX via `externalRef`, ou canal API FFBB via `ffbbRencontreId`) ou d'une
 * SAISIE MANUELLE (les deux clés à `null`, ex. un amical créé à la main) ?
 *
 * Sert l'ergonomie du clic sur un match à l'extérieur : un extérieur importé n'est PAS
 * modifiable (la fédération en est la source — l'éditer le désynchroniserait), on l'ouvre
 * en LECTURE SEULE ; un extérieur créé à la main reste éditable. PRÉSENTATION pure — on
 * LIT deux champs déjà servis, on ne re-dérive aucune règle métier
 * (🔴 `.claude/rules/frontend.md`).
 */
export function isImportedFixture(fixture: Fixture): boolean {
  // `!=` (et non `!==`) : les deux clés valent `null` une fois normalisées, mais un objet brut
  // (mock, payload partiel) peut les laisser `undefined` — les deux comptent comme « absent ».
  return null != fixture.externalRef || null != fixture.ffbbRencontreId;
}

/**
 * Un extérieur est-il MODIFIABLE ? Seuls les extérieurs SAISIS À LA MAIN le sont ; un
 * extérieur importé passe en lecture seule. (Les domiciles ne passent jamais par ce
 * chemin : la grille les ouvre dans le panneau de placement.)
 */
export function isEditableAway(fixture: Fixture): boolean {
  return "AWAY" === fixture.homeAway && !isImportedFixture(fixture);
}
