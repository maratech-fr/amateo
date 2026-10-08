<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Club;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * D1 — le nom court du club : normalisation (trim, chaîne vide → null) et résolution du libellé
 * d'e-mail (nom court sinon nom long) — foyer unique sur l'entité.
 */
#[Group('phase1')]
final class ClubShortNameTest extends TestCase
{
    public function testSetShortNameTrims(): void
    {
        $club = new Club()->setShortName('  BC Vallée  ');

        self::assertSame('BC Vallée', $club->getShortName());
    }

    public function testEmptyOrWhitespaceBecomesNull(): void
    {
        self::assertNull(new Club()->setShortName('')->getShortName());
        self::assertNull(new Club()->setShortName('   ')->getShortName());
        self::assertNull(new Club()->setShortName(null)->getShortName());
    }

    public function testEmailLabelPrefersShortNameThenFallsBackToLongName(): void
    {
        $withShort = new Club()->setName('BASKET CLUB DE LA VALLÉE')->setShortName('BC Vallée');
        self::assertSame('BC Vallée', $withShort->emailLabel());

        $withoutShort = new Club()->setName('BASKET CLUB DE LA VALLÉE');
        self::assertSame('BASKET CLUB DE LA VALLÉE', $withoutShort->emailLabel());
    }
}
