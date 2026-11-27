<?php

declare(strict_types=1);

namespace App\Tests\Integration\Plans;

use App\Entity\Constraint;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Plans\InForce;
use App\Tests\EngineRequestDecorTrait;
use App\Tests\EngineRequestTravelDecorTrait;
use App\Tests\Integration\EngineRequestReferenceTest;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : la porte de l'en vigueur ne rend que les lignes de la saison demandée — chez un club qui prépare sa
 * saison suivante, le placement des matchs, le radar et la demande d'une période d'une saison ne lisent jamais les
 * équipes, gymnases, coachs, règles ou créneaux de l'autre.
 *
 * Le worker de placement ne pose que le club (`PlaceMatchesHandler`, jamais le `season_filter`) : avant la porte, il
 * lisait toutes les saisons du club. Les références de demande au moteur ({@see EngineRequestReferenceTest})
 * n'ont de club à deux saisons que pour le socle (cas 9), qui ne passe pas par la porte : une porte sans clause de
 * saison les laisse vertes. Ce cas tient la frontière pour tous les lecteurs nommés d'un coup.
 *
 * Décor : `EngineRequestDecorTrait` (saison en cours) et `EngineRequestTravelDecorTrait` (saison suivante, saisie à
 * la main), identifiants écrits en dur.
 */
#[Group('phase1')]
#[Group('integration')]
final class InForceTest extends KernelTestCase
{
    use EngineRequestDecorTrait;
    use EngineRequestTravelDecorTrait;

    public function testTheDoorReadsOnlyTheRowsOfTheAskedSeason(): void
    {
        $this->seedEngineRequestTravelOfCurrentSeason();
        $this->seedEngineRequestNextSeason();
        $door = self::getContainer()->get(InForce::class);

        $witnesses = [
            Team::class => [self::ER_TEAM_U13, self::ER_NEXT_TEAM_U13],
            Venue::class => [self::ER_VENUE_NORD, self::ER_NEXT_VENUE_NORD],
            TeamCoach::class => [self::erId('7c0a', 1), self::erId('7c0a', 21)],
            Constraint::class => [self::erId('c057', 4), self::erId('c057', 21)],
            VenueTrainingSlot::class => [self::erId('a7a1', 7), self::erId('a7a1', 21)],
        ];
        foreach ($witnesses as $family => [$current, $next]) {
            foreach ([[self::ER_SEASON, $current, $next], [self::ER_NEXT_SEASON, $next, $current]] as [$season, $own, $other]) {
                $ids = array_map(static fn (object $row): string => $row->getId(), $door->rows($season, $family));
                $seasons = array_values(array_unique(array_map(static fn (object $row): string => $row->getSeasonId(), $door->rows($season, $family))));

                self::assertContains($own, $ids, \sprintf('témoin : %s de la saison %s est lue', $family, $season));
                self::assertNotContains($other, $ids, \sprintf('%s de l\'autre saison n\'est jamais rendue pour la saison %s', $family, $season));
                self::assertSame([$season], $seasons, \sprintf('%s : toutes les lignes rendues sont de la saison %s', $family, $season));
            }
        }
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->pinEngineRequestClock();
        $this->seedEngineRequestBase();
    }

    protected function tearDown(): void
    {
        $this->unpinEngineRequestClock();
        parent::tearDown();
    }
}
