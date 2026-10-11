<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\PriorityTier;
use App\Entity\Team;
use App\Service\PlanTeamSelectionSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P2-63 PR 5 (D-e) — la RÈGLE du seed d'équipes par défaut d'un plan de reprise, côté
 * backend (maison unique {@see PlanTeamSelectionSeeder::keptTeamIds}). Miroir du seed du
 * wizard : garder les 2 premiers RANGS (S+A) en reprise, repli sur le meilleur rang présent
 * si aucune équipe en S/A, tout le club en fermeture. Falsifier la règle (p.ex. slice(0, 1))
 * fait rougir ici.
 */
#[Group('phase1')]
final class PlanTeamSelectionSeederTest extends TestCase
{
    public function testKeepsTopTwoRanksOnResumption(): void
    {
        $teams = [
            $this->team('t-s', 1), $this->team('t-a', 2), $this->team('t-b', 3), $this->team('t-d', 5),
        ];
        $kept = PlanTeamSelectionSeeder::keptTeamIds($teams, $this->tiers([1, 2, 3, 4, 5]), false);

        self::assertSame(['t-s' => true, 't-a' => true], $kept, 'reprise : S+A seulement');
    }

    public function testEmptyTopRanksFallBackToBestPresentRank(): void
    {
        // Petit club tout en loisir (aucune équipe en S/A) : la reprise n'est jamais vide, elle
        // retombe sur le MEILLEUR rang réellement présent — jamais « personne ».
        $teams = [$this->team('t-c1', 4), $this->team('t-c2', 4), $this->team('t-d', 5)];
        $kept = PlanTeamSelectionSeeder::keptTeamIds($teams, $this->tiers([1, 2, 3, 4, 5]), false);

        self::assertSame(['t-c1' => true, 't-c2' => true], $kept, 'repli sur le meilleur rang présent (rang 4)');
    }

    public function testClosureKeepsEveryTeamActive(): void
    {
        $teams = [$this->team('t-s', 1), $this->team('t-d', 5)];
        $kept = PlanTeamSelectionSeeder::keptTeamIds($teams, $this->tiers([1, 2, 3, 4, 5]), true);

        self::assertSame(['t-s' => true, 't-d' => true], $kept, 'fermeture : tout le club actif');
    }

    public function testRankIsByIdNotByOccupation(): void
    {
        // Un club sans Fanion (pas de rang 1) mais avec du A (2) et du B (3) : seul le rang 2
        // est dans les 2 premiers ids présents — le rang 3 ne « remonte » pas par position.
        $teams = [$this->team('t-a', 2), $this->team('t-b', 3)];
        $kept = PlanTeamSelectionSeeder::keptTeamIds($teams, $this->tiers([1, 2, 3, 4, 5]), false);

        self::assertSame(['t-a' => true], $kept, 'les 2 premiers RANGS par id (1,2) : seul le rang 2 présent');
    }

    private function team(string $id, int $tierId): Team
    {
        return (new Team)->setId($id)->setPriorityTierId($tierId)->setName($id);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<PriorityTier>
     */
    private function tiers(array $ids): array
    {
        return array_map(static fn (int $id): PriorityTier => (new PriorityTier)->setId($id), $ids);
    }
}
