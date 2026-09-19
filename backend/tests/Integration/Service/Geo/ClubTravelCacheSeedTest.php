<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Geo;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\Venue;
use App\Entity\VenueTravelTime;
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
 * C4 — le SEED one-shot de la migration `club_travel_cache`. On rejoue les instructions de
 * la migration ({@see Version20260920120000::seedStatements()}, foyer partagé) sur des
 * sources fraîchement seedées, et on vérifie que le cache se rétro-alimente depuis la
 * MATRICE des gymnases (conduite/marche, DANS LES DEUX SENS).
 *
 * ⚠ Amendement 2026-09-20 : `opponent_travel` a été SUPPRIMÉE (migration
 * `Version20260920140000`). Le premier statement du seed historique la lit — on le SAUTE
 * ici (il reste correct sur une base fraîche, où il tourne AVANT la suppression, dans
 * l'ordre des migrations). La part matrice, elle, reste vérifiable.
 */
#[Group('integration')]
final class ClubTravelCacheSeedTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private TravelTimeCache $cache;

    public function testTheSeedBackfillsFromTheVenueMatrix(): void
    {
        [$clubId, $seasonId, $venueAId, $venueBId] = $this->seedSources();

        // On saute le premier statement (il lit opponent_travel, table supprimée depuis) et
        // rejoue la part MATRICE des gymnases.
        foreach (Version20260920120000::seedStatements() as $sql) {
            if (str_contains($sql, 'opponent_travel')) {
                continue;
            }
            $this->em->getConnection()->executeStatement($sql);
        }

        // Matrice des gymnases, DANS LES DEUX SENS : conduite → car (30), marche → pedestrian (90).
        self::assertSame(30, $this->cache->lookup($clubId, IgnRoutingClient::PROFILE_CAR, 45.10, 4.10, 45.20, 4.20), 'A → B conduite');
        self::assertSame(30, $this->cache->lookup($clubId, IgnRoutingClient::PROFILE_CAR, 45.20, 4.20, 45.10, 4.10), 'B → A conduite (sens inverse seedé)');
        self::assertSame(90, $this->cache->lookup($clubId, IgnRoutingClient::PROFILE_PEDESTRIAN, 45.10, 4.10, 45.20, 4.20), 'A → B marche');

        // Falsification : un profil voiture pour la marche A→B n'existe pas au même trajet.
        self::assertNull($this->cache->lookup($clubId, IgnRoutingClient::PROFILE_CAR, 45.30, 4.30, 45.40, 4.40), 'une paire jamais seedée reste absente');

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
        $this->em->flush();

        return [$club->getId(), $season->getId(), $venueA->getId(), $venueB->getId()];
    }
}
