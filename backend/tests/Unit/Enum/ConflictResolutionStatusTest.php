<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\ConflictResolutionStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ValueError;

#[Group('unit')]
final class ConflictResolutionStatusTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        self::assertSame('DEROGATION_REQUESTED', ConflictResolutionStatus::DEROGATION_REQUESTED->value);
        self::assertSame('RESOLVED_INTERNALLY', ConflictResolutionStatus::RESOLVED_INTERNALLY->value);
        self::assertSame('NO_SOLUTION_YET', ConflictResolutionStatus::NO_SOLUTION_YET->value);
        self::assertSame('COACHES_NOT_PLAYING', ConflictResolutionStatus::COACHES_NOT_PLAYING->value);
        self::assertSame('PLAYS_NOT_COACHING', ConflictResolutionStatus::PLAYS_NOT_COACHING->value);
    }

    public function testOnlyFiveStoredCases(): void
    {
        // « À traiter » ne se stocke JAMAIS (c'est l'absence de ligne) : exactement
        // cinq cas persistables, sinon la colonne (length 30) et l'API divergent. Les deux
        // derniers sont réservés aux conflits où la personne joue (garde côté contrôleur).
        self::assertSame(
            ['DEROGATION_REQUESTED', 'RESOLVED_INTERNALLY', 'NO_SOLUTION_YET', 'COACHES_NOT_PLAYING', 'PLAYS_NOT_COACHING'],
            ConflictResolutionStatus::values(),
        );
        // La plus longue valeur tient dans la colonne length: 30 (aucune migration).
        self::assertLessThanOrEqual(30, max(array_map('strlen', ConflictResolutionStatus::values())));
    }

    public function testFromValidValue(): void
    {
        self::assertSame(ConflictResolutionStatus::DEROGATION_REQUESTED, ConflictResolutionStatus::from('DEROGATION_REQUESTED'));
    }

    public function testTryFromUnknownValueIsNull(): void
    {
        self::assertNull(ConflictResolutionStatus::tryFrom('A_TRAITER'));
        self::assertNull(ConflictResolutionStatus::tryFrom(''));
    }

    public function testFromInvalidValueThrows(): void
    {
        $this->expectException(ValueError::class);
        ConflictResolutionStatus::from('UNKNOWN');
    }
}
