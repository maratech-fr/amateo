<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Geo;

use App\Entity\Club;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentTravel;
use App\Entity\Season;
use App\Entity\Venue;
use App\Entity\VenueTravelTime;
use App\Enum\OpponentLocationPrecision;
use App\Enum\OpponentTravelSource;
use App\Enum\SeasonStatus;
use App\Enum\VenueTravelTimeSource;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\TravelTimeCache;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260920120000;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * C4 — le SEED one-shot de la migration `club_travel_cache`. On rejoue EXACTEMENT les
 * instructions de la migration ({@see Version20260920120000::seedStatements()}, foyer
 * partagé, aucune dérive) sur des sources fraîchement seedées, et on vérifie que le
 * cache se rétro-alimente : le trajet adverse (siège → lieu effectif) et la matrice des
 * gymnases (conduite/marche, DANS LES DEUX SENS).
 */
#[Group('integration')]
final class ClubTravelCacheSeedTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private TravelTimeCache $cache;

    public function testTheSeedBackfillsFromOpponentTravelAndVenueMatrix(): void
    {
        [$clubId, $seasonId, $venueAId, $venueBId] = $this->seedSources();

        foreach (Version20260920120000::seedStatements() as $sql) {
            $this->em->getConnection()->executeStatement($sql);
        }

        // (1) Trajet adverse : siège (45.70, 4.90) → lieu de l'annuaire (45.76, 4.86) = 55.
        self::assertSame(55, $this->cache->lookup($clubId, IgnRoutingClient::PROFILE_CAR, 45.70, 4.90, 45.76, 4.86), 'le trajet adverse est rétro-alimenté');

        // (2) Matrice des gymnases, DANS LES DEUX SENS : conduite → car (30), marche → pedestrian (90).
        self::assertSame(30, $this->cache->lookup($clubId, IgnRoutingClient::PROFILE_CAR, 45.10, 4.10, 45.20, 4.20), 'A → B conduite');
        self::assertSame(30, $this->cache->lookup($clubId, IgnRoutingClient::PROFILE_CAR, 45.20, 4.20, 45.10, 4.10), 'B → A conduite (sens inverse seedé)');
        self::assertSame(90, $this->cache->lookup($clubId, IgnRoutingClient::PROFILE_PEDESTRIAN, 45.10, 4.10, 45.20, 4.20), 'A → B marche');

        // Falsification : un profil marche pour le trajet ADVERSE n'existe pas (car seul).
        self::assertNull($this->cache->lookup($clubId, IgnRoutingClient::PROFILE_PEDESTRIAN, 45.70, 4.90, 45.76, 4.86), 'le trajet adverse ne seede que la voiture');

        unset($seasonId, $venueAId, $venueBId); // seedés pour les FK logiques, pas relus ici.
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->cache = self::getContainer()->get(TravelTimeCache::class);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string} clubId, seasonId, venueAId, venueBId
     */
    private function seedSources(): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club seed ' . $uid);
        $club->setSlug('club-seed-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setLatitude(45.70);
        $club->setLongitude(4.90);
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('today'));
        $season->setEndDate(new DateTimeImmutable('+300 days'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);

        $venueA = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Gymnase A')->setSource('manual')->setLatitude('45.10000')->setLongitude('4.10000');
        $venueB = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Gymnase B')->setSource('manual')->setLatitude('45.20000')->setLongitude('4.20000');
        $this->em->persist($venueA);
        $this->em->persist($venueB);
        $this->em->flush();

        $matrix = (new VenueTravelTime)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setVenueAId($venueA->getId())->setVenueBId($venueB->getId())
            ->setDrivingMinutes(30)->setDrivingSource(VenueTravelTimeSource::AUTO)
            ->setWalkingMinutes(90)->setWalkingSource(VenueTravelTimeSource::AUTO);
        $this->em->persist($matrix);

        $travel = (new OpponentTravel)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setOpponentOrganismeCode('ARA0069SEED')
            ->setTravelMinutes(55)->setSource(OpponentTravelSource::AUTO);
        $this->em->persist($travel);
        $this->em->flush();

        // Annuaire GLOBAL (hors tenant) : le lieu de l'adversaire.
        $entry = new OpponentDirectoryEntry('ARA0069SEED', 'Adverse seed', OpponentLocationPrecision::CITY);
        $entry->setLatitude(45.76)->setLongitude(4.86)->setCity('Lyon');
        $this->em->persist($entry);
        $this->em->flush();

        return [$club->getId(), $season->getId(), $venueA->getId(), $venueB->getId()];
    }
}
