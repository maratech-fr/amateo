<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PriorityTier;
use App\Entity\Team;
use App\Plans\PlanComposition;

/**
 * La RÈGLE du tri d'équipes par défaut d'un plan de période de REPRISE (décision fondateur 2026-10-10). Depuis le
 * lot 4 (PR 3a), la pré-sélection se pose à la naissance du plan, par le serveur seul
 * ({@see PlanComposition::ensureLines}) : l'écran n'écrit plus rien à l'ouverture de l'étape Équipes. Règle (E3) :
 *  - REPRISE (holiday) : seuls les 2 premiers RANGS (par id de rang croissant : S puis A) reprennent ; REPLI sur le
 *    meilleur rang réellement présent si aucune équipe en S/A (un petit club tout en loisir ne reprend jamais
 *    « personne ») ;
 *  - FERMETURE (closure) : structure verrouillée — tout le club reste actif, aucun retrait.
 */
final class PlanTeamSelectionSeeder
{
    /**
     * Les ids d'équipe RETENUES (actives) par le défaut, sans toucher la base. Reçoit l'état déjà chargé pour rester pur.
     *
     * @param list<Team>         $teams toutes les équipes du club/saison
     * @param list<PriorityTier> $tiers tous les rangs (référentiel global)
     *
     * @return array<string, true> set des ids retenus
     */
    public static function keptTeamIds(array $teams, array $tiers, bool $isClosure): array
    {
        if ($isClosure) {
            // Fermeture : structure verrouillée, tout le club reste actif → tout est « retenu ».
            $all = [];
            foreach ($teams as $team) {
                $all[$team->getId()] = true;
            }

            return $all;
        }

        $tierIds = array_map(static fn (PriorityTier $t): int => $t->getId(), $tiers);
        sort($tierIds);
        // Les 2 premiers RANGS par id (S=1, A=2…), et non par occupation : un rang vide ne
        // doit pas promouvoir un rang inférieur.
        $topRankIds = array_fill_keys(\array_slice($tierIds, 0, 2), true);

        $keptByRank = [];
        foreach ($teams as $team) {
            if (isset($topRankIds[$team->getPriorityTierId()])) {
                $keptByRank[$team->getId()] = true;
            }
        }
        if ([] !== $keptByRank) {
            return $keptByRank;
        }

        // GARDE-FOU : aucune équipe en S/A → repli sur le MEILLEUR rang réellement présent
        // (le plus petit id d'équipe présente), jamais « personne ».
        $bestPresentTierId = null;
        foreach ($teams as $team) {
            if (null === $bestPresentTierId || $team->getPriorityTierId() < $bestPresentTierId) {
                $bestPresentTierId = $team->getPriorityTierId();
            }
        }
        $kept = [];
        foreach ($teams as $team) {
            if ($team->getPriorityTierId() === $bestPresentTierId) {
                $kept[$team->getId()] = true;
            }
        }

        return $kept;
    }
}
