import type { PlacedRelay } from "../api";

/**
 * Chantier H — le libellé d'un RELAIS d'adjoint (retard toléré), sœur de {@link placedConflictLabel} :
 * « Sébastien arrivera ~30 minutes en retard aux U21 : Jules (adjoint) ouvre la séance. ».
 *
 * PRÉSENTATION pure : le backend a déjà décidé QU'il y a relais et QUI ouvre ; on ne fait que
 * composer la phrase. Le retard est écrit en « minutes » (mot complet) — jamais un « N min »
 * fabriqué à la main (`timeFormatGuard` ; c'est un retard d'arrivée, pas une durée de créneau).
 */
export function placedRelayLabel(relay: PlacedRelay): string {
  return `${relay.personName} arrivera ~${relay.lateMinutes} minutes en retard aux ${relay.second.teamName} : ${relay.adjointName} (adjoint) ouvre la séance.`;
}
