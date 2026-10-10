<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\PriorityTier;
use App\Entity\Team;
use App\Service\PlanTeamSelectionSeeder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P2-63 PR 5 (D-e) — CÔTÉ BACKEND de la parité mécanique du tri d'équipes par défaut d'un plan
 * de période de reprise.
 *
 * `features/wizard/steps/periodSeedSelection.ts::periodSeedKeptTeamIds` (front, sème le défaut au
 * wizard) et `PlanTeamSelectionSeeder::keptTeamIds` (backend, maison unique, matérialise le seed
 * au transfert) sont deux implémentations de la MÊME règle. Les MÊMES cas
 * (`periodSeedSelection.parity.json`, dans l'arbre front) traversent les deux : changer la règle
 * d'un seul côté rougit ce côté-là. Ce module figure au registre `FrontRederivationRegistryTest`.
 */
#[Group('contract')]
final class PeriodSeedSelectionMirrorParityTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../frontend/src/features/wizard/steps/periodSeedSelection.parity.json';

    public function testBackendKeptTeamIdsMatchesTheSharedCases(): void
    {
        foreach ($this->cases() as $case) {
            $teams = array_map(
                static fn (array $t): Team => (new Team)->setId((string) $t['id'])->setPriorityTierId((int) $t['tierId'])->setName((string) $t['id']),
                $case['teams'],
            );
            $tiers = array_map(static fn (int $id): PriorityTier => (new PriorityTier)->setId($id), $case['tiers']);

            $kept = array_keys(PlanTeamSelectionSeeder::keptTeamIds($teams, $tiers, (bool) $case['isClosure']));
            sort($kept);
            $expected = $case['kept'];
            sort($expected);

            self::assertSame($expected, $kept, \sprintf(
                "PARITÉ ROMPUE (« %s ») : le backend ne retient pas le même tri que le front.\n"
                . 'periodSeedKeptTeamIds (front) et PlanTeamSelectionSeeder::keptTeamIds (backend) doivent '
                . 'coïncider sur periodSeedSelection.parity.json — sinon le wizard et le transfert sèment des défauts différents.',
                (string) $case['name'],
            ));
        }
    }

    /** @return list<array{name: string, tiers: list<int>, teams: list<array{id: string, tierId: int}>, isClosure: bool, kept: list<string>}> */
    private function cases(): array
    {
        $raw = file_get_contents(self::CASES);
        self::assertIsString($raw, 'Illisible : ' . self::CASES);
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var list<array{name: string, tiers: list<int>, teams: list<array{id: string, tierId: int}>, isClosure: bool, kept: list<string>}> $list */
        $list = $decoded['cases'] ?? [];
        self::assertNotEmpty($list, 'periodSeedSelection.parity.json ne porte plus aucun cas.');

        return $list;
    }
}
