import type { Team, TeamPayload } from "../api";

/**
 * Construit le PUT COMPLET d'une équipe à partir de son état serveur + un patch (jamais un patch
 * partiel : l'API `PUT teams/{id}` remplace l'entité). Déplacé mécaniquement depuis `TeamsStep`
 * (D4) pour être partagé avec l'onglet « Entente (CTC) » de la modale Liens (`TeamLinksModal`).
 */
export function payload(team: Team, patch: Partial<TeamPayload>): TeamPayload {
  return {
    name: team.name,
    sportCategoryId: team.sportCategoryId,
    priorityTierId: team.priorityTierId,
    tierOrder: team.tierOrder,
    gender: team.gender,
    level: team.level,
    sessionsPerWeek: team.sessionsPerWeek,
    isActive: team.isActive,
    isCtc: team.isCtc,
    ctcPartner: team.ctcPartner,
    ...patch,
  };
}
