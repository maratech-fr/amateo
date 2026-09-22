<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ConstraintEnumsTest extends TestCase
{
    public function testConstraintScopeValues(): void
    {
        self::assertSame('CLUB', ConstraintScope::CLUB->value);
        self::assertSame('TEAM', ConstraintScope::TEAM->value);
        self::assertSame('COACH', ConstraintScope::COACH->value);
        self::assertSame('FACILITY', ConstraintScope::FACILITY->value);
    }

    public function testConstraintFamilyValues(): void
    {
        self::assertSame('TIME', ConstraintFamily::TIME->value);
        self::assertSame('DAY', ConstraintFamily::DAY->value);
        self::assertSame('FACILITY', ConstraintFamily::FACILITY->value);
        self::assertSame('COACH_AVAILABILITY', ConstraintFamily::COACH_AVAILABILITY->value);
    }

    public function testConstraintRuleTypeValues(): void
    {
        self::assertSame('HARD', ConstraintRuleType::HARD->value);
        self::assertSame('PREFERRED', ConstraintRuleType::PREFERRED->value);
        self::assertSame('LOCK', ConstraintRuleType::LOCK->value);
    }
}
