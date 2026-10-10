/**
 * P2-63 PR 5 (D-e) — le tri d'équipes par défaut d'un plan de période de reprise, en lib PURE.
 *
 * ⚠ MIROIR DÉCLARÉ de `App\Service\PlanTeamSelectionSeeder::keptTeamIds` (backend = MAISON
 * UNIQUE, décision fondateur 2026-10-10). Le wizard sème ce défaut à l'ouverture de l'étape
 * Équipes d'un plan neuf ; le transfert au planning le matérialise côté serveur AVANT sa 1re
 * écriture. Deux producteurs du même tri — gardés en parité MÉCANIQUE par
 * `periodSeedSelection.parity.json` (les MÊMES cas traversent les deux implémentations) :
 * `periodSeedSelection.parity.test.ts` (front) et `PeriodSeedSelectionMirrorParityTest.php`
 * (backend). Ce module figure au registre `FrontRederivationRegistryTest`.
 *
 * Règle (E3) :
 *  - REPRISE : seuls les 2 premiers RANGS (par id de rang croissant : S puis A) reprennent ;
 *    repli sur le MEILLEUR rang réellement présent si aucune équipe en S/A (jamais « personne ») ;
 *  - FERMETURE : structure verrouillée — tout le club reste actif (aucun retrait).
 */
export interface SeedTeam {
  id: string;
  priorityTierId: number;
}

export interface SeedTier {
  id: number;
}

/** Les ids d'équipe RETENUES (actives) par le défaut. */
export function periodSeedKeptTeamIds(teams: SeedTeam[], tiers: SeedTier[], isClosure: boolean): Set<string> {
  if (isClosure) {
    return new Set(teams.map((t) => t.id));
  }

  const topRankIds = new Set(
    [...tiers]
      .map((t) => t.id)
      .sort((a, b) => a - b)
      .slice(0, 2),
  );
  const keptByRank = teams.filter((t) => topRankIds.has(t.priorityTierId));
  if (keptByRank.length > 0) {
    return new Set(keptByRank.map((t) => t.id));
  }

  // Aucune équipe en S/A : repli sur le meilleur rang présent (le plus petit id d'équipe présent).
  let bestPresent = Number.POSITIVE_INFINITY;
  for (const t of teams) {
    if (t.priorityTierId < bestPresent) {
      bestPresent = t.priorityTierId;
    }
  }
  return new Set(teams.filter((t) => t.priorityTierId === bestPresent).map((t) => t.id));
}
