<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\MatchPlacementRun;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
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
use App\Mercure\MercureTopic;
use App\Message\PlaceMatchesMessage;
use App\MessageHandler\PlaceMatchesHandler;
use App\Service\EngineClient;
use App\Service\MatchPlacementLock;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\MatchPlacementProgressPublisher;
use App\Service\MatchPlacementResultApplier;
use App\Service\OutputCreditLedger;
use App\Service\PlacementRunEmailBuilder;
use App\Service\PlanEntitlements;
use App\Service\RequestIdContext;
use App\Service\SeasonResolver;
use App\Service\TenantConnectionContext;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * NR du worker de PLACEMENT asynchrone (axes §7.1 : generation pipeline, tenant). Le handler
 * tourne SANS requête HTTP : il pose lui-même le GUC tenant (RLS). On vérifie le statut
 * terminal TOUJOURS posé, l'application du résultat, le décompte du crédit au SUCCÈS SEULEMENT,
 * et la publication Mercure terminale.
 */
#[Group('integration')]
final class PlaceMatchesHandlerTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    /** @var list<Update> */
    private array $published = [];

    /** @var list<Email> */
    private array $sentEmails = [];

    public function testSuccessCompletesAppliesTheResultDecrementsTheCreditAndPublishes(): void
    {
        [$clubId, $seasonId, $userId, $fixtureId, $venueId] = $this->seed(isDemo: false);
        $run = $this->seedRun($clubId, $seasonId, $userId);

        $engine = $this->engineReturning([
            'status' => 'completed',
            'placements' => [['matchId' => $fixtureId, 'venueId' => $venueId, 'kickoff' => '15:00']],
            'unplaced' => [],
            'diagnostics' => [],
            'metrics' => ['budget_seconds' => 35],
        ]);

        $this->handler($engine)->__invoke($this->message($run, $clubId, $seasonId));

        $this->em->clear();
        $this->scopeGucToClub($clubId);
        $reloaded = $this->em->getRepository(MatchPlacementRun::class)->find($run->getId());
        self::assertInstanceOf(MatchPlacementRun::class, $reloaded);
        self::assertSame(MatchPlacementRunStatus::COMPLETED, $reloaded->getStatus());
        self::assertNotNull($reloaded->getFinishedAt());
        self::assertSame(1, $reloaded->getResultData()['placed'] ?? null);

        $fixture = $this->em->getRepository(Fixture::class)->find($fixtureId);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::assertSame(FixtureStatus::PLACED, $fixture->getStatus());
        self::assertSame(FixturePlacementSource::SOLVER, $fixture->getPlacementSource());

        // Crédit décompté AU SUCCÈS (club non démo → Découverte bridée).
        self::assertSame(1, $this->creditsUsed($clubId), 'un run COMPLETED consomme 1 crédit');

        // Publication Mercure TERMINALE sur le topic placement du club, statut COMPLETED.
        self::assertCount(1, $this->published);
        self::assertSame([MercureTopic::forPlacement($clubId)], $this->published[0]->getTopics());
        self::assertTrue($this->published[0]->isPrivate());
        $payload = json_decode($this->published[0]->getData(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('COMPLETED', $payload['status'] ?? null);
        self::assertSame($run->getId(), $payload['runId'] ?? null);
    }

    public function testAnEngineErrorFailsTheRunConsumesNoCreditAndPublishesFailed(): void
    {
        [$clubId, $seasonId, $userId] = $this->seed(isDemo: false);
        $run = $this->seedRun($clubId, $seasonId, $userId);

        $engine = $this->engineThrowing();

        $this->handler($engine)->__invoke($this->message($run, $clubId, $seasonId));

        $this->em->clear();
        $this->scopeGucToClub($clubId);
        $reloaded = $this->em->getRepository(MatchPlacementRun::class)->find($run->getId());
        self::assertInstanceOf(MatchPlacementRun::class, $reloaded);
        self::assertSame(MatchPlacementRunStatus::FAILED, $reloaded->getStatus());
        self::assertNotNull($reloaded->getFinishedAt());
        self::assertArrayHasKey('error', (array) $reloaded->getResultData());

        self::assertSame(0, $this->creditsUsed($clubId), 'un run FAILED ne consomme AUCUN crédit');

        self::assertCount(1, $this->published);
        $payload = json_decode($this->published[0]->getData(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('FAILED', $payload['status'] ?? null);
    }

    public function testAnUnexpectedErrorStillLeavesATerminalStatus(): void
    {
        [$clubId, $seasonId, $userId, $fixtureId, $venueId] = $this->seed(isDemo: true);
        $run = $this->seedRun($clubId, $seasonId, $userId);

        // Un coup d'envoi illisible fait lever l'applier APRÈS le passage en RUNNING : le
        // handler doit tout de même poser un statut terminal (jamais un run figé en RUNNING).
        $engine = $this->engineReturning([
            'status' => 'completed',
            'placements' => [['matchId' => $fixtureId, 'venueId' => $venueId, 'kickoff' => 'pas-une-heure']],
            'unplaced' => [],
        ]);

        $this->handler($engine)->__invoke($this->message($run, $clubId, $seasonId));

        $this->em->clear();
        $this->scopeGucToClub($clubId);
        $reloaded = $this->em->getRepository(MatchPlacementRun::class)->find($run->getId());
        self::assertInstanceOf(MatchPlacementRun::class, $reloaded);
        self::assertSame(MatchPlacementRunStatus::FAILED, $reloaded->getStatus());
    }

    public function testALongRunEmailsTheRequestingManagerWithTheCounts(): void
    {
        [$clubId, $seasonId, $userId, $fixtureId, $venueId, $userEmail] = $this->seed(isDemo: true);
        // Le run a été créé il y a plus de 2 min : la fin (horloge réelle) dépasse le seuil.
        $run = $this->seedRun($clubId, $seasonId, $userId, new DateTimeImmutable('-3 minutes'));

        $engine = $this->engineReturning([
            'status' => 'completed',
            'placements' => [['matchId' => $fixtureId, 'venueId' => $venueId, 'kickoff' => '15:00']],
            'unplaced' => [['matchId' => 'x', 'reason' => 'venue_full', 'message' => 'm']],
        ]);

        $this->handler($engine)->__invoke($this->message($run, $clubId, $seasonId));

        self::assertCount(1, $this->sentEmails, 'un run de plus de 2 min prévient le demandeur');
        $email = $this->sentEmails[0];
        self::assertSame($userEmail, $email->getTo()[0]->getAddress(), 'le destinataire est le gestionnaire qui a cliqué');
        $body = $email->getTextBody();
        self::assertStringContainsString('1 match placé', $body);
        self::assertStringContainsString('1 match restant à traiter', $body);
        // Aucun identifiant interne (id de run) dans le texte.
        self::assertStringNotContainsString($run->getId(), $body);
    }

    public function testAShortRunDoesNotEmail(): void
    {
        [$clubId, $seasonId, $userId, $fixtureId, $venueId] = $this->seed(isDemo: true);
        $run = $this->seedRun($clubId, $seasonId, $userId); // createdAt = maintenant → run instantané

        $engine = $this->engineReturning([
            'status' => 'completed',
            'placements' => [['matchId' => $fixtureId, 'venueId' => $venueId, 'kickoff' => '15:00']],
            'unplaced' => [],
        ]);

        $this->handler($engine)->__invoke($this->message($run, $clubId, $seasonId));

        self::assertCount(0, $this->sentEmails, 'un run court ne prévient personne');
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->published = [];
        $this->sentEmails = [];
    }

    private function message(MatchPlacementRun $run, string $clubId, string $seasonId): PlaceMatchesMessage
    {
        return new PlaceMatchesMessage(
            runId: $run->getId(),
            clubId: $clubId,
            seasonId: $seasonId,
            weeksCount: 1,
            lockToken: 'test-token',
        );
    }

    private function handler(EngineClient $engine): PlaceMatchesHandler
    {
        $container = self::getContainer();

        // Hub espion : la publication Mercure est best-effort (avalée) — on l'observe via un
        // faux hub injecté dans notre propre publisher.
        $spyHub = $this->createMock(HubInterface::class);
        $spyHub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->published[] = $update;

            return 'spy';
        });

        // Mailer espion : on collecte les e-mails envoyés (l'e-mail de fin part par le bus).
        $spyMailer = $this->createMock(MailerInterface::class);
        $spyMailer->method('send')->willReturnCallback(function (RawMessage $message): void {
            if ($message instanceof Email) {
                $this->sentEmails[] = $message;
            }
        });

        return new PlaceMatchesHandler(
            $container->get(TenantConnectionContext::class),
            $this->em,
            $container->get(MatchPlacementLock::class),
            $container->get(MatchPlacementPayloadBuilder::class),
            $engine,
            $container->get(MatchPlacementResultApplier::class),
            new MatchPlacementProgressPublisher($spyHub),
            $container->get(OutputCreditLedger::class),
            $container->get(PlanEntitlements::class),
            $container->get(ClockInterface::class),
            $spyMailer,
            $container->get(PlacementRunEmailBuilder::class),
            new NullLogger,
        );
    }

    /** @param array<string, mixed> $body */
    private function engineReturning(array $body): EngineClient
    {
        $http = new MockHttpClient(new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]));

        return new EngineClient($http, self::getContainer()->get(RequestIdContext::class));
    }

    private function engineThrowing(): EngineClient
    {
        $http = new MockHttpClient(static function (): MockResponse {
            // Un timeout de transport : l'exception HttpClient remonte comme en prod.
            return new MockResponse('', ['error' => 'Connection timed out']);
        });

        return new EngineClient($http, self::getContainer()->get(RequestIdContext::class));
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string} [clubId, seasonId, userId, fixtureId, venueId, userEmail]
     */
    private function seed(bool $isDemo): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('PH ' . $uid);
        $club->setSlug('ph-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setIsDemo($isDemo);
        $club->setFfbbClubCode('PH' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);

        $userEmail = 'ph-' . $uid . '@test.com';
        $user = new User;
        $user->setEmail($userEmail);
        $user->setFirstName('Place');
        $user->setLastName('Handler');
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

        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (null === $sport) {
            $sport = new Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U13-' . $uid);
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('SF3');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);

        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName('Mateo');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        $window = new VenueMatchWindow;
        $window->setClubId($club->getId());
        $window->setSeasonId($season->getId());
        $window->setVenueId($venue->getId());
        $window->setDayOfWeek(6);
        $window->setStartTime(new DateTimeImmutable('14:00'));
        $window->setEndTime(new DateTimeImmutable('20:00'));
        $this->em->persist($window);

        $competition = new Competition;
        $competition->setClubId($club->getId());
        $competition->setSeasonId($season->getId());
        $competition->setTeamId($team->getId());
        $competition->setName('D2-' . $uid);
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->em->persist($competition);
        $this->em->flush();

        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($team->getId());
        $fixture->setCompetitionId($competition->getId());
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-03'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $this->em->persist($fixture);
        $this->em->flush();

        return [$club->getId(), $season->getId(), $user->getId(), $fixture->getId(), $venue->getId(), $userEmail];
    }

    private function seedRun(string $clubId, string $seasonId, string $userId, ?DateTimeImmutable $createdAt = null): MatchPlacementRun
    {
        $this->scopeGucToClub($clubId);
        $run = new MatchPlacementRun($clubId, $seasonId, $userId, $createdAt ?? new DateTimeImmutable);
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function creditsUsed(string $clubId): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT output_credits_used FROM club WHERE id = :id', ['id' => $clubId]);
    }
}
