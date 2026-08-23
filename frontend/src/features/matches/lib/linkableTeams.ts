import type { TeamLink } from "../api";

/**
 * P2-45 (suite) — équipes proposables comme « équipe B » d'une NOUVELLE passerelle, depuis la
 * modale ANCRÉE à une équipe A (« Liens de SM1 »). B = toutes les équipes SAUF A et SAUF celles
 * DÉJÀ passerelées à A (un couple {A,B} est unique côté serveur, qui refuse le doublon en 422).
 *
 * 🔴 Ce module ne DÉCIDE rien : le serveur reste seul juge (422 sur doublon). Il RETIRE seulement
 * de la liste ce qui serait de toute façon refusé — un garde-fou d'ergonomie, jamais une permission.
 *
 * ⚠ Ce filtrage n'est BRANCHÉ que dans la modale ANCRÉE (`filterTeamId` fourni), jamais depuis
 * `/matchs` (`HabitsLinksDialog`) : décision fondateur du 2026-08-23 (le module matchs sera refondu,
 * le comportement y sera demandé en temps voulu). C'est l'APPELANT qui conditionne l'appel.
 *
 * ⚠ On DUPLIQUE ici la lecture « autre bout d'un lien nommant A » (cf. wizard
 * `sharedTraining.ts::teamsLinkedTo`) au lieu de l'importer : le wizard importe DÉJÀ `matches`,
 * l'import inverse créerait un CYCLE inter-features. ~12 lignes dupliquées valent mieux qu'un cycle.
 */
export function linkableBTeams<T extends { id: string }>(teams: T[], teamAId: string, links: readonly Pick<TeamLink, "teamAId" | "teamBId">[]): T[] {
  const linkedToA = new Set<string>();
  for (const link of links) {
    if (link.teamAId === teamAId) {
      linkedToA.add(link.teamBId);
    } else if (link.teamBId === teamAId) {
      linkedToA.add(link.teamAId);
    }
  }

  return teams.filter((t) => t.id !== teamAId && !linkedToA.has(t.id));
}
