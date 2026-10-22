/**
 * #12 (retours Rillieux ; décision fondateur 2026-10-13 « groupe mutualisé = une équipe ») — FOYER de
 * la dérivation « cette case est-elle BLOC-COMPLÈTE ? » côté wizard : les équipes réservées sur une
 * case sont EXACTEMENT les membres d'un bloc de mutualisation ⇒ le groupe occupe la case en entier (UN
 * occupant), elle s'affiche « Complet · Groupe … » et jamais « 2/1 ».
 *
 * ⚠️ MIROIR DÉCLARÉ (régime 2, `.claude/rules/frontend.md`) — PARITÉ MÉCANIQUE avec la règle (b)
 * réciproque du backend `App\Service\ReservationGroupOccupancy` (`reservedSetMatchesABlock`, qui délègue
 * à la MAISON UNIQUE pure `ReservationGroupOccupancy::matchingBlock`). Deux implémentations sont
 * inévitables : le wizard doit refléter l'occupation sans aller-retour réseau à chaque rendu de grille.
 * Les MÊMES cas (`blockOccupancy.parity.json`) traversent les deux côtés, gardés par
 * `blockOccupancy.parity.test.ts` (front) et `ReservationBlockOccupancyMirrorParityTest.php` (backend) :
 * changer la règle d'un seul côté rougit ce côté-là. Ce module figure au registre
 * `FrontRederivationRegistryTest`.
 *
 * Le plafond PAR ÉQUIPE (règle d) n'est PAS ici : il reste au serveur.
 */

/** La forme structurelle d'un bloc de mutualisation dont ce module a besoin (voir `SharedTrainingBlock`). */
export interface BlockLike {
  id: string;
  teamIds: string[];
}

/**
 * Le PREMIER bloc dont les membres sont EXACTEMENT l'ensemble des équipes réservées (comparaison
 * d'ENSEMBLES : exacte, insensible à l'ordre), sinon `null`. Un ensemble réservé vide ne complète
 * jamais un bloc (miroir du `[] === $reservedSet` serveur).
 */
export function completeBlockOnCase(reservedTeamIds: Iterable<string>, blocks: BlockLike[]): BlockLike | null {
  const reserved = new Set(reservedTeamIds);
  if (0 === reserved.size) {
    return null;
  }
  for (const block of blocks) {
    const members = new Set(block.teamIds);
    if (members.size === reserved.size && [...members].every((id) => reserved.has(id))) {
      return block;
    }
  }

  return null;
}
