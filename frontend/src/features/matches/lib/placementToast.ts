import type { PlaceMatchesResult } from "../api";

/**
 * Maison UNIQUE du toast « N placés · M non plaçables » du placement automatique
 * (RMM-1 PR2) — PRÉSENTATION pure. Le bouton principal de la boucle ET le bouton
 * de fin d'import lancent LE MÊME rail : ils partagent donc le même message, au
 * lieu d'en dupliquer la chaîne à deux endroits.
 */
export function placementToastMessage(result: PlaceMatchesResult): string {
  const placed = `${result.placed} match${result.placed > 1 ? "s" : ""} placé${result.placed > 1 ? "s" : ""}`;
  const unplaced = result.unplaced.length > 0 ? ` · ${result.unplaced.length} non plaçable${result.unplaced.length > 1 ? "s" : ""}` : "";
  return placed + unplaced;
}
