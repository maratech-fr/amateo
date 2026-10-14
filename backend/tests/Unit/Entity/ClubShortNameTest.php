<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Club;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * D1 — la RÉSOLUTION du libellé d'e-mail du club (nom court sinon nom long), foyer sur l'entité.
 *
 * La normalisation du nom court (trim, chaîne vide → null) est prouvée bout en bout par l'API dans
 * {@see \App\Tests\Integration\Api\ClubShortNameTest::testShortNameIsWritableTrimmedAndExposed} et
 * {@see \App\Tests\Integration\Api\ClubShortNameTest::testEmptyStringClearsTheShortName} : les cas
 * unitaires redondants ont été retirés (passe de réduction). `emailLabel()` n'est PAS exercé par
 * l'API (rendu d'e-mail, pas le champ) — c'est l'angle propre que garde ce test.
 */
#[Group('phase1')]
final class ClubShortNameTest extends TestCase
{
    public function testEmailLabelPrefersShortNameThenFallsBackToLongName(): void
    {
        $withShort = new Club()->setName('BASKET CLUB DE LA VALLÉE')->setShortName('BC Vallée');
        self::assertSame('BC Vallée', $withShort->emailLabel());

        $withoutShort = new Club()->setName('BASKET CLUB DE LA VALLÉE');
        self::assertSame('BASKET CLUB DE LA VALLÉE', $withoutShort->emailLabel());
    }
}
