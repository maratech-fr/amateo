<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\CoachWishCampaign;
use App\Service\CoachWishUpserter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-312 — garde de dernier recours du foyer d'écriture public : un jour ne peut pas être à
 * la fois souhaité ET indisponible. Le contrôleur public refuse déjà en amont (422) ; ce
 * test atteint la garde DIRECTEMENT (sans le court-circuit du contrôleur), pour qu'elle
 * reste falsifiable — la retirer fait rougir ici.
 */
#[Group('unit')]
final class CoachWishUpserterTest extends TestCase
{
    public function testRejectsADayBothWishedAndUnavailable(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        // La garde est la PREMIÈRE instruction : l'EM n'est jamais sollicité sur un conflit.
        $em->expects(self::never())->method('getRepository');
        $upserter = new CoachWishUpserter($em);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Un jour ne peut pas être à la fois souhaité et indisponible.');

        $upserter->upsert(new CoachWishCampaign, 'team-1', new DateTimeImmutable('2026-02-16 00:00:00'), 'coach-1', 2, [3], [3], null);
    }
}
