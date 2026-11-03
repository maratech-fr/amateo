<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\TestCase;

/**
 * L'empreinte de structure décrit des PLACEMENTS verrouillés : l'id d'une ligne, l'ordre des lignes
 * et le coach calculé n'y sont pour rien. Sans quoi une version régénérée, dont les verrous ont des
 * ids neufs, se dirait « périmée » sans que rien n'ait bougé (plan « version chargée », A8).
 */
final class ScheduleConstraintBuilderStructureHashTest extends TestCase
{
    /** Deux verrous, ids inversés et ordre inversé : même empreinte. */
    public function testIdsAndOrderOfTheLocksDoNotMatter(): void
    {
        self::assertSame(
            $this->hash([$this->pin('id-1', 2), $this->pin('id-2', 4)]),
            $this->hash([$this->pin('id-9', 4), $this->pin('id-8', 2)]),
        );
    }

    /** Le même placement répété compte une fois. */
    public function testADuplicatedPlacementCountsOnce(): void
    {
        self::assertSame(
            $this->hash([$this->pin('id-1', 2)]),
            $this->hash([$this->pin('id-1', 2), $this->pin('id-2', 2)]),
        );
    }

    /** Un placement différent change l'empreinte : le signal « périmé » reste vivant. */
    public function testADifferentPlacementChangesTheHash(): void
    {
        self::assertNotSame($this->hash([$this->pin('id-1', 2)]), $this->hash([$this->pin('id-1', 4)]));
    }

    /** Le coach stocké est un résultat de la tenue, pas une donnée d'entrée. */
    public function testTheStoredCoachIsIgnored(): void
    {
        self::assertSame(
            $this->hash([$this->pin('id-1', 2, 'coach-a')]),
            $this->hash([$this->pin('id-1', 2, 'coach-b')]),
        );
    }

    /** @param list<array<string, mixed>> $pins */
    private function hash(array $pins): string
    {
        return ScheduleConstraintBuilder::structureHash(['version' => '1.9', 'slotTemplates' => $pins, 'teams' => []]);
    }

    /** @return array<string, mixed> */
    private function pin(string $id, int $day, ?string $coachId = null): array
    {
        return [
            'id' => $id, 'teamId' => 'team', 'venueId' => 'venue', 'coachId' => $coachId, 'dayOfWeek' => $day,
            'startTime' => '18:00:00', 'durationMinutes' => 90, 'lockLevel' => 'HARD',
            'coachLateToleranceMinutes' => 0, 'pendingConstraintSuggestion' => null,
        ];
    }
}
