/**
 * L'onglet d'ouverture de la modale Liens : Passerelles, sauf si l'équipe a un groupe et aucune
 * passerelle. `blocks` = les groupes de la portée ouverte (`blocksInScope`).
 */
export function initialLinksTab(teamId: string, links: readonly { teamAId: string; teamBId: string }[], blocks: readonly { teamIds: string[] }[]): "passerelles" | "mutualisation" {
  const hasLink = links.some((l) => l.teamAId === teamId || l.teamBId === teamId);
  const hasBlock = blocks.some((b) => b.teamIds.includes(teamId));
  return !hasLink && hasBlock ? "mutualisation" : "passerelles";
}
