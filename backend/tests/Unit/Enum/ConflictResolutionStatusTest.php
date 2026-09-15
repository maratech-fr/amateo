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
    }

    public function testOnlyThreeStoredCases(): void
    {
        // « À traiter » ne se stocke JAMAIS (c'est l'absence de ligne) : exactement
        // trois cas persistables, sinon la migration/colonne et l'API divergent.
        self::assertSame(
            ['DEROGATION_REQUESTED', 'RESOLVED_INTERNALLY', 'NO_SOLUTION_YET'],
            ConflictResolutionStatus::values(),
        );
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
