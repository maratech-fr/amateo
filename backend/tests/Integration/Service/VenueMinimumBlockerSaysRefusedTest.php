<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\GenerationBlockersGuard;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\CrossStack\CapacityMirrorParityTest;
use App\Tests\Integration\Api\GenerationRefusesARecapBlockerTest;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DOC-62 (petites lignes) — le motif BLOQUANT « au moins N dans ce gymnase » dit ce qui arrive vraiment :
 * **la génération est REFUSÉE** tant que ce n'est pas corrigé (422 `blocker` avant le moteur, sur les
 * guichets de génération — {@see GenerationRefusesARecapBlockerTest}), jamais « la génération échouera »
 * (le moteur n'est même pas appelé). Le même texte est lu au Récap et renvoyé par les guichets.
 *
 * Décor : le payload de saturation SANS réservation en cause de {@see CapacityMirrorParityTest} (3 minimums
 * pour 2 places de grille) — sa parité avec le moteur (échec réel) y est prouvée, ici seule la PHRASE compte.
 * Témoin : une grille libre ne produit aucun bloquant (sinon « aucun échouera » serait vrai d'une liste vide).
 */
#[Group('integration')]
final class VenueMinimumBlockerSaysRefusedTest extends KernelTestCase
{
    public function testAVenueMinimumBlockerSaysTheGenerationIsRefused(): void
    {
        $guard = self::getContainer()->get(GenerationBlockersGuard::class);
        self::assertInstanceOf(GenerationBlockersGuard::class, $guard);

        $result = $guard->venueMinimumSaturation($this->payload(['t1', 't2', 't3']));

        self::assertCount(1, $result['blockers'], 'trois minimums pour deux places de grille : un bloquant');
        self::assertStringContainsString('la génération est refusée tant que ce n\'est pas corrigé', $result['blockers'][0]);
        self::assertStringNotContainsString('échouera', $result['blockers'][0]);
    }

    public function testAFreeGridHasNoBlocker(): void
    {
        $guard = self::getContainer()->get(GenerationBlockersGuard::class);
        self::assertInstanceOf(GenerationBlockersGuard::class, $guard);

        self::assertSame([], $guard->venueMinimumSaturation($this->payload(['t1', 't2']))['blockers']);
    }

    /**
     * Gymnase V1 à deux créneaux (lundi, mercredi), une équipe par minimum demandé « au moins 1 à V1 ».
     *
     * @param list<string> $minimumTeamIds
     *
     * @return array<string, mixed>
     */
    private function payload(array $minimumTeamIds): array
    {
        return [
            'version' => ScheduleConstraintBuilder::CONTRACT_VERSION,
            'clubId' => 'club-doc62',
            'seasonId' => 'season-doc62',
            'solverSeed' => 42,
            'teams' => array_map(static fn (int $i): array => [
                'id' => 't' . $i, 'name' => 'T' . $i, 'sportCategoryId' => 'cat-1', 'priorityTierId' => 3, 'sessionsPerWeek' => 1,
            ], range(1, 3)),
            'venues' => [[
                'id' => 'v1', 'name' => 'V1',
                'trainingSlots' => [
                    ['dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1],
                    ['dayOfWeek' => 3, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1],
                ],
            ]],
            'coaches' => [],
            'constraints' => array_map(static fn (string $teamId): array => [
                'id' => 'min-' . $teamId, 'scope' => 'TEAM', 'scopeTargetId' => $teamId,
                'family' => 'FACILITY', 'ruleType' => 'HARD', 'name' => 'au moins 1 à V1',
                'config' => ['minAtVenueId' => 'v1', 'minAtVenueCount' => 1], 'sortOrder' => 0, 'isActive' => true,
            ], $minimumTeamIds),
            'slotTemplates' => [],
            'sharedBlocks' => [],
        ];
    }
}
