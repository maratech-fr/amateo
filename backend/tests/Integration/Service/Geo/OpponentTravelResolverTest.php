<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Geo;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentTravel;
use App\Entity\Season;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Enum\OpponentTravelSource;
use App\Enum\SeasonStatus;
use App\Repository\ClubRepository;
use App\Repository\FixtureRepository;
use App\Repository\OpponentDirectoryEntryRepository;
use App\Repository\OpponentTravelRepository;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\OpponentTravelResolver;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * P2-54 RMM-9 PR-3 — le calcul AUTO des trajets adverses depuis le siège du club.
 * Best-effort (IGN muet → minutes null) et, LE cœur du patron, une valeur MANUAL
 * n'est JAMAIS écrasée par la passe AUTO.
 */
#[Group('integration')]
final class OpponentTravelResolverTest extends WebTestCase
{
    use TenantGucTrait;

    private const string OPPONENT_CODE = 'ARA0069123';

    private EntityManagerInterface $em;

    public function testAutoComputesTravelFromTheClubSiegeToTheDirectoryLocation(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $this->seedDirectoryEntry(45.76, 4.86);

        $result = $this->resolverWithIgn(1320)->resolve($club->getId(), $season->getId());

        self::assertSame(1, $result['resolved']);
        self::assertSame([], $result['unresolved']);

        $this->scopeGucToClub($club->getId());
        $row = $this->travelRepository()->findOneByCode($season->getId(), self::OPPONENT_CODE);
        self::assertInstanceOf(OpponentTravel::class, $row);
        self::assertSame(22, $row->getTravelMinutes(), '1320 s → 22 min (aller simple voiture)');
        self::assertSame(OpponentTravelSource::AUTO, $row->getSource());
        self::assertFalse($row->hasOverride());
    }

    public function testManualRowIsNeverOverwrittenByTheAutoPass(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        $this->seedDirectoryEntry(45.76, 4.86);

        $this->scopeGucToClub($club->getId());
        $manual = (new OpponentTravel)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setOpponentOrganismeCode(self::OPPONENT_CODE)
            ->setSource(OpponentTravelSource::MANUAL)
            ->setTravelMinutes(7)
            ->setOverrideVenueLabel('Le vrai gymnase')
            ->setOverrideLatitude(45.5)
            ->setOverrideLongitude(4.5)
            ->setResolvedAt(new DateTimeImmutable);
        $this->em->persist($manual);
        $this->em->flush();

        $result = $this->resolverWithIgn(9999)->resolve($club->getId(), $season->getId());

        self::assertSame(1, $result['skippedManual']);
        self::assertSame(0, $result['resolved']);

        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        $row = $this->travelRepository()->findOneByCode($season->getId(), self::OPPONENT_CODE);
        self::assertInstanceOf(OpponentTravel::class, $row);
        self::assertSame(7, $row->getTravelMinutes(), 'la valeur MANUAL survit à la passe AUTO');
        self::assertSame(OpponentTravelSource::MANUAL, $row->getSource());
        self::assertSame('Le vrai gymnase', $row->getOverrideVenueLabel());
    }

    public function testAnOpponentWithNoDirectoryLocationComesBackUnresolved(): void
    {
        [$club, $season] = $this->seedClubWithAwayOpponent();
        // No directory entry seeded → nothing to route to.

        $result = $this->resolverWithIgn(1320)->resolve($club->getId(), $season->getId());

        self::assertSame(0, $result['resolved']);
        self::assertSame([self::OPPONENT_CODE], $result['unresolved']);

        $this->scopeGucToClub($club->getId());
        self::assertNull($this->travelRepository()->findOneByCode($season->getId(), self::OPPONENT_CODE));
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * The real resolver, but its IGN client rides a MockHttpClient returning a
     * fixed duration (seconds) for every pair.
     */
    private function resolverWithIgn(int $durationSeconds): OpponentTravelResolver
    {
        $ign = new IgnRoutingClient(new MockHttpClient(
            static fn (): MockResponse => new MockResponse((string) json_encode(['duration' => $durationSeconds])),
        ), new MockClock);

        return new OpponentTravelResolver(
            $this->em,
            $ign,
            $this->travelRepository(),
            self::getContainer()->get(OpponentDirectoryEntryRepository::class),
            self::getContainer()->get(ClubRepository::class),
            self::getContainer()->get(FixtureRepository::class),
        );
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seedClubWithAwayOpponent(): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club trajet ' . $uid);
        $club->setSlug('club-trajet-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setLatitude(45.70);
        $club->setLongitude(4.90);
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) SeasonResolver::seasonYear(new DateTimeImmutable('today')));
        $season->setStartDate(new DateTimeImmutable('today'));
        $season->setEndDate(new DateTimeImmutable('+300 days'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel('Adverse Trajet');
        $fixture->setOpponentOrganismeCode(self::OPPONENT_CODE);
        $this->em->persist($fixture);
        $this->em->flush();

        return [$club, $season];
    }

    private function seedDirectoryEntry(float $lat, float $lon): void
    {
        $entry = new OpponentDirectoryEntry(self::OPPONENT_CODE, 'Adverse Trajet', OpponentLocationPrecision::CITY);
        $entry->setLatitude($lat)->setLongitude($lon)->setCity('Lyon');
        $this->em->persist($entry);
        $this->em->flush();
    }

    private function travelRepository(): OpponentTravelRepository
    {
        $repository = self::getContainer()->get(OpponentTravelRepository::class);
        self::assertInstanceOf(OpponentTravelRepository::class, $repository);

        return $repository;
    }
}
