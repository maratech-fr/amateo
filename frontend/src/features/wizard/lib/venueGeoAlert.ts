import type { VenueGeoAlert } from "../api";

/** Distance lisible pour l'humain : « 1,9 km » au-delà du kilomètre, « 450 m » en deçà. */
export function humanDistance(meters: number): string {
  if (meters >= 1000) {
    return `${(meters / 1000).toFixed(1).replace(".", ",")} km`;
  }
  return `${Math.round(meters)} m`;
}

/**
 * Le message du bandeau « Position à vérifier » d'un gymnase, dérivé du VERDICT SERVEUR
 * ({@link VenueGeoAlert}). Le front ne fait qu'afficher : aucune règle de cohérence n'est recalculée
 * ici. Deux formulations selon la raison (autre rue / trop loin de l'adresse).
 */
export function venueGeoAlertMessage(alert: VenueGeoAlert): string {
  if ("OTHER_STREET" === alert.reason) {
    return `Position à vérifier : le point enregistré tombe ${alert.pointStreet ?? "ailleurs"}, alors que l'adresse de la FFBB est « ${alert.ffbbAddress} ». Vérifiez sur la carte ou collez les coordonnées exactes.`;
  }
  const distance = null !== alert.distanceM ? humanDistance(alert.distanceM) : "loin";
  return `Position à vérifier : le point enregistré est à ${distance} de l'adresse de la FFBB « ${alert.ffbbAddress} ». Vérifiez sur la carte ou collez les coordonnées exactes.`;
}
