<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /api/fixtures/place — gardes, application du résultat, et la règle
 * souveraine : une ancre MANUELLE n'est JAMAIS réécrite (P1-4 PR D).
 * Les cas qui solvent vraiment passent par l'engine réel du docker-compose ;
 * s'il est indisponible, ils se skippent (même rituel que le groupe contract).
 */
#[Group('integration')]
final class PlaceMatchesControllerTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testNonManagementMemberIs403(): void
    {
        [, $clubId] = $this->createClub();
        $editorToken = $this->addMember($clubId, 'editor');

        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $editorToken]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testSocleNotChosenIs409(): void
    {
        [$token] = $this->createClub(settleSocle: false);

        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testPlacesTheSaturdayMatchAndNamesTheSundayOne(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '18:00');
        $saturday = $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');
        $sunday = $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-04');

        $this->placeOrSkip($token);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['placed']);
        self::assertCount(1, $data['unplaced']);
        self::assertSame('no_access_window', $data['unplaced'][0]['reason']);

        $this->em->refresh($saturday);
        self::assertSame(FixtureStatus::PLACED, $saturday->getStatus());
        self::assertSame(FixturePlacementSource::SOLVER, $saturday->getPlacementSource());
        self::assertSame($venue->getId(), $saturday->getVenueId());
        $kickoff = $saturday->getKickoffTime()?->format('H:i');
        self::assertNotNull($kickoff);
        self::assertGreaterThanOrEqual('14:30', $kickoff);
        self::assertLessThanOrEqual('16:15', $kickoff);

        $this->em->refresh($sunday);
        self::assertSame(FixtureStatus::UNPLACED, $sunday->getStatus());
    }

    public function testAManualAnchorIsNeverRewritten(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '22:30');
        // Placed BY THE MANAGER at 20:30 — the solver must arrange around it.
        $anchor = $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');
        $anchor->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $anchor->setPlacementSource(FixturePlacementSource::MANUAL);
        $anchor->setVenueId($venue->getId());
        $anchor->setKickoffTime(new DateTimeImmutable('20:30'));
        $other = $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');
        $this->em->flush();

        $this->placeOrSkip($token);

        $this->em->refresh($anchor);
        self::assertSame('20:30', $anchor->getKickoffTime()?->format('H:i'));
        self::assertSame(FixturePlacementSource::MANUAL, $anchor->getPlacementSource());

        // The other match landed clear of the anchor's 20:00-22:15 footprint:
        // its own footprint must END by 20:00 → kickoff ≤ 18:15 (back-to-back
        // contiguity is legal — half-open no-overlap).
        $this->em->refresh($other);
        self::assertSame(FixtureStatus::PLACED, $other->getStatus());
        $kickoff = $other->getKickoffTime()?->format('H:i');
        self::assertNotNull($kickoff);
        self::assertLessThanOrEqual('18:15', $kickoff);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** POST /api/fixtures/place — skip the test when the engine is not up (502). */
    private function placeOrSkip(string $token): void
    {
        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        if (502 === $this->client->getResponse()->getStatusCode()) {
            self::markTestSkipped('Engine not available');
        }
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [adminToken, clubId, seasonId]
     */
    private function createClub(bool $settleSocle = true): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('BC Place ' . $uid);
        $club->setSlug('bc-place-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('place' . $uid . '@test.com');
        $user->setFirstName('Place');
        $user->setLastName('User');
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

        $season = new Season;
        $season->setClubId($club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        if ($settleSocle) {
            $this->settleSeasonPlan($season);
        }

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return [$token, $club->getId(), $season->getId()];
    }

    private function addMember(string $clubId, string $role): string
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid($role, true);
        $user = new User;
        $user->setEmail($role . $uid . '@test.com');
        $user->setFirstName('N');
        $user->setLastName('M');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function createTeam(string $clubId, string $seasonId): Team
    {
        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (null === $sport) {
            $uid = uniqid('', true);
            $sport = new Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new SportCategory;
        $category->setClubId($clubId);
        $category->setSportId($sport->getId());
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($seasonId);
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('SF3');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function createVenue(string $clubId, string $seasonId): Venue
    {
        $venue = new Venue;
        $venue->setClubId($clubId);
        $venue->setSeasonId($seasonId);
        $venue->setName('Mateo');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function createWindow(string $clubId, string $seasonId, string $venueId, int $day, string $start, string $end): void
    {
        $window = new VenueMatchWindow;
        $window->setClubId($clubId);
        $window->setSeasonId($seasonId);
        $window->setVenueId($venueId);
        $window->setDayOfWeek($day);
        $window->setStartTime(new DateTimeImmutable($start));
        $window->setEndTime(new DateTimeImmutable($end));
        $this->em->persist($window);
        $this->em->flush();
    }

    private function createFixture(string $clubId, string $seasonId, string $teamId, string $date): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }
}
