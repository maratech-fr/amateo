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
use App\Entity\VenueMatchWindow;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\MatchPlacementRunStatus;
use App\Enum\SeasonStatus;
use App\Message\PlaceMatchesMessage;
use App\MessageHandler\PlaceMatchesHandler;
use App\Service\EngineClient;
use App\Service\MatchPlacementProgressPublisher;
use App\Service\OutputCreditLedger;
use App\Service\PlacementRunFailureNotifier;
use App\Service\RequestIdContext;
use App\Service\SeasonResolver;
use App\Tests\BuildsServiceWithDoublesTrait;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\PlacementLockProbeTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

/**
 * NR d'axe *generation pipeline* (§7.1) — le crédit d'un placement de matchs fait partie de son
 * résultat : il se prend DANS la transaction qui pose les placements et le statut « terminé »
 * (avant le lot 2, il se prenait après le commit). Un run n'est jamais « terminé » sans son crédit.
 *
 *  - le REGISTRE de crédits en panne (vraie erreur SQL levée par sa connexion) : run « échoué »,
 *    aucun placement posé, compteur inchangé, jamais annoncé « terminé », message acquitté ;
 *  - un run terminé prend un crédit du club du MESSAGE, jamais d'un voisin.
 *
 * Jumeau sur le rail de la génération : {@see GenerationCreditIsPartOfTheResultTest}. La relecture
 * d'un run terminé qui ne reprend pas de second crédit : {@see RedeliveredPlacementTest}. Harnais
 * (décor d'un domicile à placer, verrou Redis) dupliqué de ce dernier, duplication assumée comme
 * entre les jumeaux existants. Clés Redis rendues par DEL ciblé en tearDown — jamais FLUSHALL.
 */
#[Group('phase1')]
#[Group('integration')]
final class PlacementCreditIsPartOfTheResultTest extends KernelTestCase
{
    use BuildsServiceWithDoublesTrait;
    use CreatesPeopleAndPlacesTrait;
    use PlacementLockProbeTrait;
    use TenantGucTrait;

    private const string GENERIC_FAILURE = 'Le placement automatique a échoué. Réessayez.';

    private EntityManagerInterface $em;

    /** @var list<Update> */
    private array $published = [];

    private int $engineCalls = 0;

    /**
     * La panne du registre au moment de prendre le crédit annule le résultat entier : le run finit
     * « échoué », le match reste à placer, rien n'est décompté, et aucune annonce ne dit « terminé ».
     */
    public function testALedgerFailureFailsTheRunAndPlacesNothing(): void
    {
        [$clubId, $seasonId, $userId, $fixtureId, $venueId] = $this->seed(credits: 3);
        $run = $this->seedPendingRun($clubId, $seasonId, $userId);
        $token = $this->holdPlacementLock($clubId, 600);

        try {
            $this->runHandler($this->message($run, $clubId, $seasonId, $token), $this->placing($fixtureId, $venueId), $this->ledgerWhoseSqlFails());
        } catch (Throwable $escaped) {
            self::fail(\sprintf('le message doit être acquitté (aucune exception ne sort du handler) — reçu %s : %s', $escaped::class, $escaped->getMessage()));
        }

        self::assertSame(1, $this->engineCalls, 'témoin : le moteur a rendu un placement valide, c\'est lui qui est perdu');
        $reloaded = $this->reloadRun($run, $clubId);
        self::assertSame(MatchPlacementRunStatus::FAILED, $reloaded->getStatus(), 'sans son crédit, un run n\'est jamais « terminé »');
        self::assertSame(self::GENERIC_FAILURE, $reloaded->getResultData()['error'] ?? null, 'le motif affiché est le message générique, jamais le détail SQL');

        $this->scopeGucToClub($clubId);
        $fixture = $this->freshEm()->find(Fixture::class, $fixtureId);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::assertSame(FixtureStatus::UNPLACED, $fixture->getStatus(), 'aucun placement posé : il s\'annule avec le statut');
        self::assertSame(3, $this->creditsOf($clubId), 'aucun crédit pris');
        self::assertNotContains('COMPLETED', $this->publishedStatuses(), 'l\'écran n\'est jamais réveillé sur un « terminé »');
    }

    /** Un run terminé prend un crédit du club du message ; le compteur d'un voisin ne bouge pas. */
    public function testACompletedRunTakesOneCreditOfTheMessageClub(): void
    {
        [$clubId, $seasonId, $userId, $fixtureId, $venueId] = $this->seed(credits: 3);
        [$neighbourId] = $this->seed(credits: 3);
        $run = $this->seedPendingRun($clubId, $seasonId, $userId);
        $token = $this->holdPlacementLock($clubId, 600);

        $this->runHandler($this->message($run, $clubId, $seasonId, $token), $this->placing($fixtureId, $venueId));

        self::assertSame(MatchPlacementRunStatus::COMPLETED, $this->reloadRun($run, $clubId)->getStatus(), 'témoin : le run est terminé');
        self::assertSame(4, $this->creditsOf($clubId), 'un run terminé prend un crédit du club du message');
        self::assertSame(3, $this->creditsOf($neighbourId), 'le crédit ne tombe jamais sur un autre club');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->published = [];
        $this->engineCalls = 0;
    }

    protected function tearDown(): void
    {
        $this->releasePlacementLockKeys();
        parent::tearDown();
    }

    /** @param array<string, mixed> $engineBody */
    private function runHandler(PlaceMatchesMessage $message, array $engineBody, ?OutputCreditLedger $ledger = null): void
    {
        $http = new MockHttpClient(function () use ($engineBody): MockResponse {
            ++$this->engineCalls;

            return new MockResponse(json_encode($engineBody, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });
        $spyHub = $this->createMock(HubInterface::class);
        $spyHub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->published[] = $update;

            return 'spy';
        });

        $doubles = [
            EngineClient::class => new EngineClient($http, self::getContainer()->get(RequestIdContext::class)),
            MatchPlacementProgressPublisher::class => new MatchPlacementProgressPublisher($spyHub),
            MailerInterface::class => $this->createMock(MailerInterface::class),
            LoggerInterface::class => new NullLogger,
            EntityManagerInterface::class => $this->em,
        ];
        if ($ledger instanceof OutputCreditLedger) {
            $doubles[OutputCreditLedger::class] = $ledger;
        }

        $handler = $this->buildWithDoubles(PlaceMatchesHandler::class, $doubles, [PlacementRunFailureNotifier::class]);
        $handler($message);
    }

    /**
     * Le registre de crédits réel, construit sur une connexion dont CHAQUE requête lève une vraie
     * erreur SQL de PostgreSQL (`division_by_zero`) : la panne vient du registre, pas d'un `flush`.
     */
    private function ledgerWhoseSqlFails(): OutputCreditLedger
    {
        $real = $this->em->getConnection();
        $fail = static function () use ($real): never {
            $real->executeStatement('SELECT 1 / 0');

            throw new LogicException('inatteignable : la division par zéro a levé');
        };
        $connection = $this->createMock(Connection::class);
        foreach (['executeStatement', 'executeQuery', 'fetchOne', 'fetchAssociative', 'fetchAllAssociative', 'fetchFirstColumn'] as $method) {
            $connection->method($method)->willReturnCallback($fail);
        }

        return $this->buildWithDoubles(OutputCreditLedger::class, [Connection::class => $connection, EntityManagerInterface::class => $this->em]);
    }

    /** @return list<string|null> */
    private function publishedStatuses(): array
    {
        return array_map(static function (Update $update): ?string {
            $payload = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);

            return \is_array($payload) && \is_string($payload['status'] ?? null) ? $payload['status'] : null;
        }, $this->published);
    }

    /** @return array<string, mixed> */
    private function placing(string $fixtureId, string $venueId): array
    {
        return [
            'status' => 'completed',
            'placements' => [['matchId' => $fixtureId, 'venueId' => $venueId, 'kickoff' => '15:00']],
            'unplaced' => [],
            'diagnostics' => [],
        ];
    }

    private function message(MatchPlacementRun $run, string $clubId, string $seasonId, string $token): PlaceMatchesMessage
    {
        return new PlaceMatchesMessage(runId: $run->getId(), clubId: $clubId, seasonId: $seasonId, weeksCount: 1, lockToken: $token);
    }

    /** L'EM du test peut avoir été fermé par une erreur : on relit sur un EM neuf. */
    private function freshEm(): EntityManagerInterface
    {
        if (!$this->em->isOpen()) {
            $registry = self::getContainer()->get('doctrine');
            \assert($registry instanceof ManagerRegistry);
            $registry->resetManager();
            $em = $registry->getManager();
            \assert($em instanceof EntityManagerInterface);
            $this->em = $em;
        }

        return $this->em;
    }

    private function reloadRun(MatchPlacementRun $run, string $clubId): MatchPlacementRun
    {
        $em = $this->freshEm();
        $em->clear();
        $this->scopeGucToClub($clubId);
        $reloaded = $em->getRepository(MatchPlacementRun::class)->find($run->getId());
        self::assertInstanceOf(MatchPlacementRun::class, $reloaded);

        return $reloaded;
    }

    private function creditsOf(string $clubId): int
    {
        return (int) $this->freshEm()->getConnection()->fetchOne('SELECT output_credits_used FROM club WHERE id = :id', ['id' => $clubId]);
    }

    private function seedPendingRun(string $clubId, string $seasonId, string $userId): MatchPlacementRun
    {
        $this->scopeGucToClub($clubId);
        $run = new MatchPlacementRun($clubId, $seasonId, $userId, new DateTimeImmutable('-1 minute'));
        $run->setStatus(MatchPlacementRunStatus::PENDING);
        $this->em->persist($run);
        $this->em->flush();
        $this->clearGuc();

        return $run;
    }

    /**
     * Club NON démo en Découverte (`plan_id` NULL : bridé, un run terminé prend un crédit), une
     * équipe, un gymnase ouvert le samedi, un domicile à placer.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string} [clubId, seasonId, userId, fixtureId, venueId]
     */
    private function seed(int $credits): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('PC ' . $uid);
        $club->setSlug('pc-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setIsDemo(false);
        $club->setOutputCreditsUsed($credits);
        $club->setFfbbClubCode('PC' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('pc-' . $uid . '@test.com');
        $user->setFirstName('Crédit');
        $user->setLastName('Placement');
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
        SeasonPlanOf::id(self::getContainer(), $season->getId());

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

        $venue = $this->newVenue($club->getId(), $season->getId(), 'Mateo');
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
        $this->clearGuc();

        return [$club->getId(), $season->getId(), $user->getId(), $fixture->getId(), $venue->getId()];
    }
}
