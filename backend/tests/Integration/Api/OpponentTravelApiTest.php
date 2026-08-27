<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\OpponentDirectoryEntry;
use App\Entity\OpponentTravel;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\FixtureHomeAway;
use App\Enum\OpponentLocationPrecision;
use App\Enum\OpponentTravelSource;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P2-54 RMM-9 PR-3 — the read shape of GET /api/opponents/travel (the display feed
 * the travel radar UI consumes): per distinct AWAY opponent, precision, location
 * name, one-way travel, the server-computed `approximated` flag, and the
 * AUTO/MANUAL source. A localised opponent, a MANUAL override, and a non-localised
 * one (no stamped code) are asserted together.
 */
#[Group('integration')]
final class OpponentTravelApiTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testTheReadFeedShapesEachOpponent(): void
    {
        [$club, $user, $season] = $this->seedClub();

        // 1) An opponent located at VENUE precision, with an AUTO travel of 22 min.
        $this->awayFixture($club, $season, 'ARA0069001', 'Gymnase visité FC');
        $this->directory('ARA0069001', OpponentLocationPrecision::VENUE, 'Halle Clemenceau', 'Grenoble');
        $this->travel($club, $season, 'ARA0069001', 22, OpponentTravelSource::AUTO, null);

        // 2) An opponent known only at CITY precision → approximated, no travel yet.
        $this->awayFixture($club, $season, 'ARA0069002', 'Meyzieu Basket');
        $this->directory('ARA0069002', OpponentLocationPrecision::CITY, null, 'Meyzieu');

        // 3) A MANUAL override (a hand-pinned gym) → source MANUAL, not approximated.
        $this->awayFixture($club, $season, 'ARA0069003', 'Adversaire corrigé');
        $this->directory('ARA0069003', OpponentLocationPrecision::CITY, null, 'Bron');
        $this->travel($club, $season, 'ARA0069003', 31, OpponentTravelSource::MANUAL, 'Le vrai gymnase');

        // 4) A non-localised opponent: no stamped code at all.
        $this->awayFixtureNoCode($club, $season, 'Club sans code');

        $this->client->request('GET', '/api/opponents/travel', [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(200);
        $opponents = $this->responseData()['opponents'];
        $byLabel = [];
        foreach ($opponents as $opponent) {
            $byLabel[$opponent['opponentLabel']] = $opponent;
        }
        self::assertCount(4, $byLabel);

        $venue = $byLabel['Gymnase visité FC'];
        self::assertTrue($venue['located']);
        self::assertSame('VENUE', $venue['precision']);
        self::assertSame('Halle Clemenceau', $venue['locationName']);
        self::assertSame(22, $venue['travelMinutes']);
        self::assertFalse($venue['approximated']);
        self::assertSame('AUTO', $venue['source']);

        $city = $byLabel['Meyzieu Basket'];
        self::assertTrue($city['located']);
        self::assertSame('CITY', $city['precision']);
        self::assertSame('Meyzieu', $city['locationName']);
        self::assertNull($city['travelMinutes']);
        self::assertTrue($city['approximated'], 'city precision is the server-computed « approché » flag');
        self::assertNull($city['source']);

        $manual = $byLabel['Adversaire corrigé'];
        self::assertSame('MANUAL', $manual['source']);
        self::assertSame('Le vrai gymnase', $manual['overrideVenueLabel']);
        self::assertSame('Le vrai gymnase', $manual['locationName']);
        self::assertSame('VENUE', $manual['precision'], 'a hand-pinned gym is venue-precise, never approximated');
        self::assertFalse($manual['approximated']);
        self::assertSame(31, $manual['travelMinutes']);

        $unlocated = $byLabel['Club sans code'];
        self::assertFalse($unlocated['located']);
        self::assertNull($unlocated['opponentOrganismeCode']);
        self::assertNull($unlocated['precision']);
        self::assertNull($unlocated['travelMinutes']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function seedClub(): array
    {
        $uid = uniqid('trav', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club trajet ' . $uid);
        $club->setSlug('club-trajet-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail($uid . '@test.com');
        $user->setFirstName('T');
        $user->setLastName('Rajet');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $user, $season];
    }

    private function awayFixture(Club $club, Season $season, string $code, string $opponentLabel): void
    {
        $fixture = $this->baseFixture($club, $season, $opponentLabel);
        $fixture->setOpponentOrganismeCode($code);
        $this->em->persist($fixture);
        $this->em->flush();
    }

    private function awayFixtureNoCode(Club $club, Season $season, string $opponentLabel): void
    {
        $this->em->persist($this->baseFixture($club, $season, $opponentLabel));
        $this->em->flush();
    }

    private function baseFixture(Club $club, Season $season, string $opponentLabel): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId('11111111-1111-4111-8111-111111111111');
        $fixture->setMatchDate(new DateTimeImmutable('+10 days'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel($opponentLabel);

        return $fixture;
    }

    private function directory(string $code, OpponentLocationPrecision $precision, ?string $venueLabel, ?string $city): void
    {
        $entry = new OpponentDirectoryEntry($code, $city ?? 'Adversaire', $precision);
        $entry->setCity($city)->setVenueLabel($venueLabel)->setLatitude(45.7)->setLongitude(4.85);
        $this->em->persist($entry);
        $this->em->flush();
    }

    private function travel(Club $club, Season $season, string $code, int $minutes, OpponentTravelSource $source, ?string $overrideLabel): void
    {
        $this->scopeGucToClub($club->getId());
        $row = (new OpponentTravel)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setOpponentOrganismeCode($code)
            ->setTravelMinutes($minutes)
            ->setSource($source)
            ->setResolvedAt(new DateTimeImmutable);
        if (null !== $overrideLabel) {
            $row->setOverrideVenueLabel($overrideLabel)->setOverrideLatitude(45.6)->setOverrideLongitude(4.7);
        }
        $this->em->persist($row);
        $this->em->flush();
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }
}
