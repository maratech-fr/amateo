<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\MatchPlacementRun;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\SubscriptionPlan;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Enum\MatchPlacementRunStatus;
use App\Enum\SeasonStatus;
use App\Message\PlaceMatchesMessage;
use App\MessageHandler\PlaceMatchesHandler;
use App\Service\MatchPlacementLock;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * POST /api/fixtures/place — le rail est désormais ASYNCHRONE : le contrôleur garde ses
 * gardes, enfile un {@see PlaceMatchesMessage} et répond 202 + id du run. Les cas qui solvent
 * vraiment tirent le message de la file et jouent le VRAI handler (engine réel du
 * docker-compose) ; s'il est indisponible, ils se skippent. La règle souveraine tient : une
 * ancre MANUELLE n'est JAMAIS réécrite (P1-4 PR D).
 *
 * Plus GET /api/fixtures/placement-run (lecture du dernier run, scopé tenant).
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

    public function testInvalidWindowIs422(): void
    {
        [$token] = $this->createClub();

        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'], '{"from":"pas-une-date","to":"2026-10-04"}');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Semaine invalide', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'], '{"from":"2026-10-10","to":"2026-10-04"}');
        self::assertResponseStatusCodeSame(422);
    }

    public function testEnqueuesAndReturnsAPendingRun(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '18:00');
        $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');

        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(202);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('PENDING', $data['status'] ?? null);
        self::assertNotEmpty($data['runId'] ?? null);

        // Le run existe en base, PENDING ; un message a été enfilé.
        $this->scopeGucToClub($clubId);
        $run = $this->em->getRepository(MatchPlacementRun::class)->find($data['runId']);
        self::assertInstanceOf(MatchPlacementRun::class, $run);
        self::assertSame(MatchPlacementRunStatus::PENDING, $run->getStatus());
        self::assertNotNull($this->lastQueuedMessage());
    }

    public function testASecondDemandWhileARunIsOpenIs409(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '18:00');
        $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');

        // Le verrou tenu (par une 1ʳᵉ demande, simulé ici en l'acquérant) → la 2ᵉ est refusée.
        $lock = self::getContainer()->get(MatchPlacementLock::class);
        $token2 = $lock->acquire($clubId, 120);
        self::assertNotNull($token2);

        try {
            $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
            self::assertResponseStatusCodeSame(409);
            // Le JSON échappe l'unicode (é → é) : on vise une sous-chaîne ASCII du message.
            self::assertStringContainsString('en cours', (string) $this->client->getResponse()->getContent());
        } finally {
            $lock->release($clubId, $token2);
        }
    }

    public function testCreditExhaustedRefusesBeforeEnqueuing(): void
    {
        // Club NON démo (Découverte bridée) dont le pool est à sec → 403 au kernel.request,
        // AVANT le contrôleur : aucun run créé, aucun message enfilé.
        $max = $this->decouverteMaxGenerations();
        [$token, $clubId, $seasonId] = $this->createClub(isDemo: false, credits: $max);
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '18:00');
        $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');

        $before = $this->queuedCount();
        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'], '{"from":"2026-10-03","to":"2026-10-04"}');
        self::assertResponseStatusCodeSame(403);

        $this->scopeGucToClub($clubId);
        self::assertSame(0, $this->em->getRepository(MatchPlacementRun::class)->count([]), 'aucun run ne doit être créé');
        self::assertSame($before, $this->queuedCount(), 'rien ne doit être enfilé');
    }

    public function testAnotherClubNeverSeesTheRun(): void
    {
        [, $clubA, $seasonA, $userA] = $this->createClub();
        [$tokenB, $clubB] = $this->createClub();

        // Un run du club A.
        $this->scopeGucToClub($clubA);
        $run = new MatchPlacementRun($clubA, $seasonA, $userA, new DateTimeImmutable);
        $this->em->persist($run);
        $this->em->flush();

        // Le club B lit le dernier run : il ne voit JAMAIS celui de A → {run: null}.
        $this->client->request('GET', '/api/fixtures/placement-run', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokenB]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('run', $data);
        self::assertNull($data['run'], 'un autre club ne voit pas le run (frontière tenant)');
        self::assertNotSame($clubA, $clubB);
    }

    public function testPlacesTheSaturdayMatchAndNamesTheSundayOne(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '18:00');
        $saturdayId = $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03')->getId();
        $sundayId = $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-04')->getId();

        // placeAndRunOrSkip vide l'EM (le worker clôt en nettoyant le GUC puis on re-scope) :
        // on RELIT les fixtures par id plutôt que refresh sur une entité détachée.
        $run = $this->placeAndRunOrSkip($token, $clubId);
        $result = (array) $run->getResultData();

        self::assertSame(1, $result['placed'] ?? null);
        self::assertCount(1, $result['unplaced'] ?? []);
        self::assertSame('no_access_window', $result['unplaced'][0]['reason'] ?? null);

        $saturday = $this->em->find(Fixture::class, $saturdayId);
        self::assertInstanceOf(Fixture::class, $saturday);
        self::assertSame(FixtureStatus::PLACED, $saturday->getStatus());
        self::assertSame(FixturePlacementSource::SOLVER, $saturday->getPlacementSource());
        self::assertSame($venue->getId(), $saturday->getVenueId());
        $kickoff = $saturday->getKickoffTime()?->format('H:i');
        self::assertNotNull($kickoff);
        self::assertGreaterThanOrEqual('14:00', $kickoff);
        self::assertLessThanOrEqual('16:30', $kickoff);

        $sunday = $this->em->find(Fixture::class, $sundayId);
        self::assertInstanceOf(Fixture::class, $sunday);
        self::assertSame(FixtureStatus::UNPLACED, $sunday->getStatus());
    }

    public function testAManualAnchorIsNeverRewritten(): void
    {
        [$token, $clubId, $seasonId] = $this->createClub();
        $this->scopeGucToClub($clubId);
        $team = $this->createTeam($clubId, $seasonId);
        $venue = $this->createVenue($clubId, $seasonId);
        $this->createWindow($clubId, $seasonId, $venue->getId(), 6, '14:00', '22:30');
        $anchor = $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');
        $anchor->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $anchor->setPlacementSource(FixturePlacementSource::MANUAL);
        $anchor->setVenueId($venue->getId());
        $anchor->setKickoffTime(new DateTimeImmutable('20:30'));
        $other = $this->createFixture($clubId, $seasonId, $team->getId(), '2026-10-03');
        $this->em->flush();
        [$anchorId, $otherId] = [$anchor->getId(), $other->getId()];

        $this->placeAndRunOrSkip($token, $clubId);

        // L'EM a été vidé par le rail : on relit par id (find réattache depuis la base).
        $anchor = $this->em->find(Fixture::class, $anchorId);
        self::assertInstanceOf(Fixture::class, $anchor);
        self::assertSame('20:30', $anchor->getKickoffTime()?->format('H:i'));
        self::assertSame(FixturePlacementSource::MANUAL, $anchor->getPlacementSource());

        $other = $this->em->find(Fixture::class, $otherId);
        self::assertInstanceOf(Fixture::class, $other);
        self::assertSame(FixtureStatus::PLACED, $other->getStatus());
        $kickoff = $other->getKickoffTime()?->format('H:i');
        self::assertNotNull($kickoff);
        self::assertLessThanOrEqual('19:00', $kickoff);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * POST (202) puis joue le VRAI handler sur le message enfilé (engine réel). Skip si
     * l'engine est indisponible (le run revient FAILED « n'a pas répondu »).
     */
    private function placeAndRunOrSkip(string $token, string $clubId): MatchPlacementRun
    {
        $this->client->request('POST', '/api/fixtures/place', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(202);
        $runId = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['runId'];

        $message = $this->lastQueuedMessage();
        self::assertInstanceOf(PlaceMatchesMessage::class, $message);

        self::getContainer()->get(PlaceMatchesHandler::class)->__invoke($message);

        $this->em->clear();
        $this->scopeGucToClub($clubId);
        $run = $this->em->getRepository(MatchPlacementRun::class)->find($runId);
        self::assertInstanceOf(MatchPlacementRun::class, $run);
        if (MatchPlacementRunStatus::FAILED === $run->getStatus()
            && str_contains((string) ($run->getResultData()['error'] ?? ''), 'n\'a pas répondu')) {
            self::markTestSkipped('Engine not available');
        }

        return $run;
    }

    private function lastQueuedMessage(): ?PlaceMatchesMessage
    {
        $transport = self::getContainer()->get('messenger.transport.placement_in_memory');
        \assert($transport instanceof InMemoryTransport);
        $sent = $transport->getSent();
        if ([] === $sent) {
            return null;
        }
        $message = end($sent)->getMessage();

        return $message instanceof PlaceMatchesMessage ? $message : null;
    }

    private function queuedCount(): int
    {
        $transport = self::getContainer()->get('messenger.transport.placement_in_memory');
        \assert($transport instanceof InMemoryTransport);

        return \count($transport->getSent());
    }

    private function decouverteMaxGenerations(): int
    {
        $plan = $this->em->getRepository(SubscriptionPlan::class)->findOneBy(['code' => 'decouverte']);
        \assert($plan instanceof SubscriptionPlan);

        return (int) $plan->getMaxGenerations();
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string} [adminToken, clubId, seasonId, userId]
     */
    private function createClub(bool $settleSocle = true, bool $isDemo = true, int $credits = 0): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('BC Place ' . $uid);
        $club->setSlug('bc-place-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setIsDemo($isDemo);
        $club->setOutputCreditsUsed($credits);
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

        return [$token, $club->getId(), $season->getId(), $user->getId()];
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
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setTeamId($teamId);
        $competition->setName('D2-' . uniqid('', true));
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->em->persist($competition);
        $this->em->flush();

        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId($competition->getId());
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }
}
