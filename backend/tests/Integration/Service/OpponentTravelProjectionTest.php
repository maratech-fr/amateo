<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\ClubTravelCache;
use App\Entity\Fixture;
use App\Entity\OpponentVenueLink;
use App\Entity\Season;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentVenueLinkSource;
use App\Enum\SeasonStatus;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\ConflictRadarLoader;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\TravelTimeCache;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\OpponentTravelProjection;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * P2-54 (amendement 2026-09-20) — la MAISON UNIQUE du trajet aller-retour par rencontre
 * AWAY, partagée par le radar de conflits ET le payload de placement. Le trajet se résout
 * désormais par le LIEN de la salle de la rencontre ({@see OpponentVenueLink}) puis le cache
 * CONSTANT ({@see ClubTravelCache}, siège → gymnase) — jamais une ligne stockée.
 */
#[Group('phase1')]
#[Group('integration')]
final class OpponentTravelProjectionTest extends KernelTestCase
{
    use TenantGucTrait;

    /** Le siège du club, fixé pour que le cache des trajets ait une origine connue. */
    private const float SIEGE_LAT = 45.70;

    private const float SIEGE_LON = 4.90;

    private EntityManagerInterface $em;

    private OpponentTravelProjection $projection;

    private TravelTimeCache $cache;

    private Club $club;

    private Season $season;

    public function testAwayFixtureGetsTwiceTheCachedTravelOfItsLinkedGym(): void
    {
        $this->link('ORG1', 'SALLE A', 45.76, 4.86);
        $this->cacheTravel(45.76, 4.86, 40);
        $away = $this->fixture(FixtureHomeAway::AWAY, 'ORG1', 'ASVEL - 2', 'SALLE A');

        self::assertSame([$away->getId() => 80], $this->projection->roundTripByFixtureId($this->season->getId(), [$away]));
    }

    public function testTwoSallesOfSameOpponentGiveTwoDifferentTravels(): void
    {
        // Le gymnase se rattache au LIBELLÉ, pas à l'équipe : deux salles du même club adverse
        // → deux trajets différents sur deux rencontres.
        $this->link('ORG1', 'SALLE A', 45.76, 4.86);
        $this->link('ORG1', 'SALLE B', 45.80, 5.00);
        $this->cacheTravel(45.76, 4.86, 40);
        $this->cacheTravel(45.80, 5.00, 55);
        $a = $this->fixture(FixtureHomeAway::AWAY, 'ORG1', 'ASVEL - 1', 'SALLE A');
        $b = $this->fixture(FixtureHomeAway::AWAY, 'ORG1', 'ASVEL - 2', 'SALLE B');

        self::assertSame(
            [$a->getId() => 80, $b->getId() => 110],
            $this->projection->roundTripByFixtureId($this->season->getId(), [$a, $b]),
        );
    }

    public function testAFixtureWithoutLinkFallsBackToTheMostFrequentGymApproximated(): void
    {
        $this->link('ORG1', 'SALLE A', 45.76, 4.86);
        $this->cacheTravel(45.76, 4.86, 40);
        // Une rencontre du même adversaire dont la salle n'a AUCUN lien → repli sur le gymnase
        // le plus fréquent (Salle A), marqué approché.
        $resolved = $this->fixture(FixtureHomeAway::AWAY, 'ORG1', 'ASVEL - 1', 'SALLE A');
        $orphan = $this->fixture(FixtureHomeAway::AWAY, 'ORG1', 'ASVEL - 2', 'SALLE INCONNUE');

        $detail = $this->projection->roundTripDetailByFixtureId($this->season->getId(), [$resolved, $orphan]);
        self::assertSame(['minutes' => 80, 'approximated' => false], $detail[$resolved->getId()]);
        self::assertSame(['minutes' => 80, 'approximated' => true], $detail[$orphan->getId()]);
    }

    public function testACodeLessOpponentGetsLinkedTravelViaItsSentinelKey(): void
    {
        // Un adversaire SANS code fédéral : son lien est posé sous la clé SENTINELLE dérivée de
        // son libellé (comme le contrôleur l'écrit). La projection le retrouve → trajet `linked`,
        // là où le filtre `code !== null` laissait ces rencontres hors trajet.
        $label = 'Club Sans Code';
        $key = 'X' . substr(hash('sha256', mb_strtolower($label)), 0, 40);
        $this->link($key, 'SALLE AMICALE', 45.76, 4.86);
        $this->cacheTravel(45.76, 4.86, 40);
        $away = $this->fixture(FixtureHomeAway::AWAY, null, $label, 'SALLE AMICALE');

        self::assertSame([$away->getId() => 80], $this->projection->roundTripByFixtureId($this->season->getId(), [$away]));
    }

    public function testALinkWithNoCachedTravelYetIsAbsent(): void
    {
        // Lien présent mais trajet pas encore calculé (cache vide) → absent (pending), jamais un repli.
        $this->link('ORG1', 'SALLE A', 45.76, 4.86);
        $away = $this->fixture(FixtureHomeAway::AWAY, 'ORG1', 'ASVEL - 2', 'SALLE A');

        self::assertSame([], $this->projection->roundTripByFixtureId($this->season->getId(), [$away]));
    }

    public function testUnknownOpponentNullCodeAndHomeCarryNothing(): void
    {
        $this->link('ORG1', 'SALLE A', 45.76, 4.86);
        $this->cacheTravel(45.76, 4.86, 40);
        $unknown = $this->fixture(FixtureHomeAway::AWAY, 'ORG9', 'Autre', 'SALLE Z');
        $noCode = $this->fixture(FixtureHomeAway::AWAY, null, 'Sans code', 'SALLE A');
        $home = $this->fixture(FixtureHomeAway::HOME, 'ORG1', 'ASVEL - 2', 'SALLE A');

        self::assertSame([], $this->projection->roundTripByFixtureId($this->season->getId(), [$unknown, $noCode, $home]));
    }

    public function testNullSeasonProjectsNothing(): void
    {
        $this->link('ORG1', 'SALLE A', 45.76, 4.86);
        $this->cacheTravel(45.76, 4.86, 40);
        $away = $this->fixture(FixtureHomeAway::AWAY, 'ORG1', 'ASVEL - 2', 'SALLE A');

        self::assertSame([], $this->projection->roundTripByFixtureId(null, [$away]));
    }

    public function testNoClubSiegeProjectsNothing(): void
    {
        $this->club->setLatitude(null);
        $this->club->setLongitude(null);
        $this->em->flush();
        $this->link('ORG1', 'SALLE A', 45.76, 4.86);
        $this->cacheTravel(45.76, 4.86, 40);
        $away = $this->fixture(FixtureHomeAway::AWAY, 'ORG1', 'ASVEL - 2', 'SALLE A');

        self::assertSame([], $this->projection->roundTripByFixtureId($this->season->getId(), [$away]));
    }

    public function testRadarAndPayloadShareTheSameProjection(): void
    {
        // Parité de SOURCE : les deux consommateurs INJECTENT la projection partagée, jamais
        // une copie inline de la boucle de trajet.
        foreach ([ConflictRadarLoader::class, MatchPlacementPayloadBuilder::class] as $consumer) {
            $types = [];
            foreach (new ReflectionClass($consumer)->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType) {
                    $types[] = $type->getName();
                }
            }
            self::assertContains(OpponentTravelProjection::class, $types, $consumer . ' doit consommer OpponentTravelProjection (projection partagée)');
        }
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->projection = self::getContainer()->get(OpponentTravelProjection::class);
        $this->cache = self::getContainer()->get(TravelTimeCache::class);

        $uid = uniqid('', true);
        $this->club = new Club;
        $this->club->setName('BC Trajet ' . $uid);
        $this->club->setSlug('bc-trajet-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->club->setLatitude(self::SIEGE_LAT);
        $this->club->setLongitude(self::SIEGE_LON);
        $this->em->persist($this->club);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->season->setName((string) $year);
        $this->season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $this->season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->season->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();
    }

    private function link(string $code, string $fbiLabel, float $lat, float $lon): void
    {
        $normalizer = self::getContainer()->get(VenueLabelNormalizer::class);
        $link = (new OpponentVenueLink)
            ->setClubId($this->club->getId())
            ->setOpponentOrganismeCode($code)
            ->setFbiLabel($fbiLabel)
            ->setFbiLabelNorm($normalizer->normalize($fbiLabel))
            ->setVenueLabel($fbiLabel)
            ->setLatitude($lat)
            ->setLongitude($lon)
            ->setSource(OpponentVenueLinkSource::AUTO);
        $this->em->persist($link);
        $this->em->flush();
    }

    private function cacheTravel(float $destLat, float $destLon, int $minutes): void
    {
        $this->cache->store($this->club->getId(), IgnRoutingClient::PROFILE_CAR, self::SIEGE_LAT, self::SIEGE_LON, $destLat, $destLon, $minutes);
    }

    private function fixture(FixtureHomeAway $homeAway, ?string $organismeCode, string $opponentLabel, string $fbiVenueLabel): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-24'));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel($opponentLabel);
        $fixture->setFbiVenueLabel($fbiVenueLabel);
        if (null !== $organismeCode) {
            $fixture->setOpponentOrganismeCode($organismeCode);
        }
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }
}
