<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Fixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FixtureTest extends TestCase
{
    public function testKeptVenueLabelDefaultsToNull(): void
    {
        self::assertNull(new Fixture()->getKeptVenueLabel());
    }

    public function testSettingANonNullVenueClearsTheKeptVenueLabel(): void
    {
        // « Garder l'appli » pose keptVenueLabel ; re-placer le match (salle non nulle)
        // éteint ce pense-bête — même maison que unplacedReason.
        $fixture = new Fixture;
        $fixture->setKeptVenueLabel('salle raphael de barros');
        self::assertSame('salle raphael de barros', $fixture->getKeptVenueLabel());

        $fixture->setVenueId('11111111-1111-4111-8111-111111111111');
        self::assertNull($fixture->getKeptVenueLabel(), 'poser une salle efface le libellé gardé');
    }

    public function testSettingANullVenueKeepsTheKeptVenueLabel(): void
    {
        // Un dépointage (venueId null) NE touche PAS l'idempotence : seul un
        // re-placement (venueId non null) l'éteint.
        $fixture = new Fixture;
        $fixture->setKeptVenueLabel('salle raphael de barros');
        $fixture->setVenueId(null);
        self::assertSame('salle raphael de barros', $fixture->getKeptVenueLabel());
    }
}
