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
use App\Message\PlaceMatchesMessage;
use App\MessageHandler\PlaceMatchesHandler;
use App\Service\EngineClient;
use App\Service\MatchPlacementProgressPublisher;
use App\Service\PlacementRunFailureNotifier;
use App\Service\RequestIdContext;
use App\Service\SeasonResolver;
use App\Tests\BuildsServiceWithDoublesTrait;
use App\Tests\PlacementLockProbeTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * NR d'axe *generation pipeline* (§7.1) — le rail de PLACEMENT des matchs face à un worker tué.
 *
 * Messenger RELIT le message d'un worker tué (OOM, SIGKILL du déploiement) : le handler doit
 * alors juger le run sur son statut EN BASE, jamais refaire en aveugle. Jumeau de génération :
 * `RedeliveredGenerationTest` (même promesse sur l'autre rail).
 *
 *  - COMPLETED relu : rien n'est refait (0 appel moteur, 0 crédit, 0 publication), un placement
 *    laissé à la main survit, le verrou est rendu ;
 *  - FAILED relu : acquitté sans solve, verrou INTACT (posé FAILED par le rattrapage, un calcul
 *    orphelin peut encore tourner au moteur : la clé l'atteste jusqu'à son TTL) ;
 *  - RUNNING relu (le rattrapage n'a pas joué) : FAILED « interrompu », publié, verrou intact ;
 *  - PENDING : le verrou est RÉARMÉ au budget complet du run qui démarre (le TTL posé à
 *    l'enfilage a pu fondre dans la file) ; repris s'il a expiré (et c'est le NOUVEAU jeton qui
 *    est rendu à la fin) ; si un autre l'a pris, le run est déclaré « remplacé » sans solve ;
 *  - un perdant (run passé FAILED par ailleurs pendant son solve) n'écrit RIEN.
 *
 * Témoins : l'APPEL moteur (compteur de requêtes HTTP), le DÉGÂT (fixtures, résultat du run), le
 * crédit Découverte (club non démo), la publication Mercure (hub espion), l'e-mail (mailer espion),
 * la clé Redis `match_placement:club:{id}` lue en direct (valeur = jeton, TTL). Harnais dupliqué
 * de `PlaceMatchesHandlerTest` (duplication assumée, comme le jumeau de génération).
 * Clés Redis rendues par compare-and-delete ou DEL ciblé en tearDown — jamais FLUSHALL.
 */
#[Group('phase1')]
#[Group('integration')]
final class RedeliveredPlacementTest extends KernelTestCase
{
    use BuildsServiceWithDoublesTrait;
    use PlacementLockProbeTrait;
    use TenantGucTrait;

    private const string T1 = 'Le placement automatique a été interrompu. Il pourra être relancé une fois le calcul interrompu terminé.';

    private const string T3 = 'Le placement automatique a été remplacé par une demande plus récente.';

    private EntityManagerInterface $em;

    /** @var list<Update> */
    private array $published = [];

    /** @var list<Email> */
    private array $sentEmails = [];

    private int $engineCalls = 0;

    public function testRedeliveredCompletedRunNeverCallsTheEngine(): void
    {
        [$clubId, $seasonId, $userId, $fixtureId, $venueId] = $this->seed();
        $run = $this->seedRun($clubId, $seasonId, $userId, MatchPlacementRunStatus::COMPLETED, ['placed' => 1, 'skipped' => 0, 'unplaced' => []]);
        $token = $this->holdPlacementLock($clubId, 600);

        $this->runHandler($this->message($run, $clubId, $seasonId, $token), $this->placing($fixtureId, $venueId));

        self::assertSame(0, $this->engineCalls, 'un run COMPLETED relu n\'appelle JAMAIS le moteur');
        self::assertSame(0, $this->creditsUsed($clubId), 'un run COMPLETED relu ne consomme aucun crédit de plus');
        self::assertCount(0, $this->published, 'rien à annoncer : le run était déjà terminé');
        self::assertSame(MatchPlacementRunStatus::COMPLETED, $this->reloadRun($run, $clubId)->getStatus());
        self::assertNull($this->placementLockValue($clubId), 'le verrou du run terminé est rendu (jeton du message)');
    }

    public function testRedeliveredCompletedRunPreservesManualAndSolverPlacements(): void
    {
        [$clubId, $seasonId, $userId, $fixtureId, $venueId] = $this->seed();
        $result = ['placed' => 1, 'skipped' => 0, 'unplaced' => [], 'diagnostics' => []];
        $run = $this->seedRun($clubId, $seasonId, $userId, MatchPlacementRunStatus::COMPLETED, $result);
        $finishedAt = $run->getFinishedAt()?->format(\DATE_ATOM);
        // Après le run, le gestionnaire a posé à la main un second match à 17:30, et laissé le
        // premier « à placer » : la relecture du message ne doit défaire ni l'un ni l'autre.
        $manualId = $this->seedManualFixture($clubId, $seasonId, $venueId);
        $token = $this->holdPlacementLock($clubId, 600);

        $this->runHandler($this->message($run, $clubId, $seasonId, $token), $this->placing($fixtureId, $venueId));

        $this->scopeGucToClub($clubId);
        $left = $this->freshEm()->find(Fixture::class, $fixtureId);
        self::assertInstanceOf(Fixture::class, $left);
        self::assertSame(FixtureStatus::UNPLACED, $left->getStatus(), 'le match laissé « à placer » n\'est pas reposé par une relecture');
        $manual = $this->freshEm()->find(Fixture::class, $manualId);
        self::assertInstanceOf(Fixture::class, $manual);
        self::assertSame('17:30', $manual->getKickoffTime()?->format('H:i'), 'le match posé à la main reste à son heure');
        self::assertSame(FixturePlacementSource::MANUAL, $manual->getPlacementSource());

        $reloaded = $this->reloadRun($run, $clubId);
        self::assertSame($result, $reloaded->getResultData(), 'le résultat affiché du run n\'est pas réécrit');
        self::assertSame($finishedAt, $reloaded->getFinishedAt()?->format(\DATE_ATOM));
    }

    public function testRedeliveredFailedRunIsAckedWithoutSolvingAndLeavesTheLock(): void
    {
        [$clubId, $seasonId, $userId, $fixtureId, $venueId] = $this->seed();
        $run = $this->seedRun($clubId, $seasonId, $userId, MatchPlacementRunStatus::FAILED, ['error' => self::T1]);
        $token = $this->holdPlacementLock($clubId, 600);

        $this->runHandler($this->message($run, $clubId, $seasonId, $token), $this->placing($fixtureId, $venueId));

        self::assertSame(0, $this->engineCalls, 'un run FAILED relu est acquitté sans solve');
        self::assertCount(0, $this->published);
        $reloaded = $this->reloadRun($run, $clubId);
        self::assertSame(MatchPlacementRunStatus::FAILED, $reloaded->getStatus());
        self::assertSame(['error' => self::T1], $reloaded->getResultData(), 'le motif d\'échec affiché reste celui posé');
        self::assertSame($token, $this->placementLockValue($clubId), 'verrou INTACT : un calcul orphelin peut encore tourner au moteur, la clé l\'atteste');
        self::assertGreaterThan(590, $this->placementLockTtl($clubId), 'TTL inchangé');
    }

    public function testRedeliveredRunningRunIsFailedAsInterruptedAndKeepsTheLock(): void
    {
        [$clubId, $seasonId, $userId, $fixtureId, $venueId, $userEmail] = $this->seed();
        $run = $this->seedRun($clubId, $seasonId, $userId, MatchPlacementRunStatus::RUNNING, null, new DateTimeImmutable('-11 minutes'));
        $token = $this->holdPlacementLock($clubId, 600);

        $this->runHandler($this->message($run, $clubId, $seasonId, $token), $this->placing($fixtureId, $venueId));

        self::assertSame(0, $this->engineCalls, 'un run RUNNING relu ne relance pas un solve dont l\'original tourne peut-être encore');
        self::assertSame(0, $this->creditsUsed($clubId));
        $reloaded = $this->reloadRun($run, $clubId);
        self::assertSame(MatchPlacementRunStatus::FAILED, $reloaded->getStatus());
        self::assertSame(self::T1, $reloaded->getResultData()['error'] ?? null);
        self::assertNotNull($reloaded->getFinishedAt());
        self::assertCount(1, $this->published, 'l\'écran est réveillé une fois');
        self::assertSame('FAILED', $this->publishedStatus(0));
        self::assertSame($token, $this->placementLockValue($clubId), 'verrou NON rendu : le calcul interrompu tient encore le moteur');
        self::assertCount(1, $this->sentEmails, 'un run de plus de deux minutes prévient le demandeur');
        self::assertSame($userEmail, $this->sentEmails[0]->getTo()[0]->getAddress());
    }

    public function testPendingRunIsSolved(): void
    {
        [$clubId, $seasonId, $userId, $fixtureId, $venueId] = $this->seed();
        $run = $this->seedRun($clubId, $seasonId, $userId, MatchPlacementRunStatus::PENDING);
        $token = $this->holdPlacementLock($clubId, 600);

        $this->runHandler($this->message($run, $clubId, $seasonId, $token), $this->placing($fixtureId, $venueId));

        self::assertSame(1, $this->engineCalls, 'un run PENDING se résout normalement');
        self::assertSame(MatchPlacementRunStatus::COMPLETED, $this->reloadRun($run, $clubId)->getStatus());
        self::assertNull($this->placementLockValue($clubId), 'verrou rendu à la fin');
    }

    public function testPendingRunRearmsItsLockToTheFullBudget(): void
    {
        [$clubId, $seasonId, $userId, $fixtureId, $venueId] = $this->seed();
        $run = $this->seedRun($clubId, $seasonId, $userId, MatchPlacementRunStatus::PENDING);
        // Le TTL posé à l'enfilage a fondu dans la file : il ne reste que 5 s.
        $token = $this->holdPlacementLock($clubId, 5);
        $message = $this->message($run, $clubId, $seasonId, $token);

        $seen = ['value' => null, 'ttl' => null];
        $this->runHandler($message, $this->placing($fixtureId, $venueId), function () use ($clubId, &$seen): void {
            $seen = ['value' => $this->placementLockValue($clubId), 'ttl' => $this->placementLockTtl($clubId)];
        });

        self::assertSame(1, $this->engineCalls);
        self::assertSame($token, $seen['value'], 'pendant le solve, le verrou est toujours celui du message');
        self::assertGreaterThanOrEqual($message->budgetSeconds() - 2, $seen['ttl'], 'pendant le solve, le verrou couvre le budget COMPLET du run');
        self::assertNull($this->placementLockValue($clubId), 'rendu à la fin');
    }

    public function testPendingRunWithExpiredLockReacquiresAndReleasesTheNewToken(): void
    {
        [$clubId, $seasonId, $userId, $fixtureId, $venueId] = $this->seed();
        $run = $this->seedRun($clubId, $seasonId, $userId, MatchPlacementRunStatus::PENDING);
        $this->notePlacementLockKey($clubId);
        // Aucune clé : celle de l'enfilage a expiré derrière un long run d'un autre club.
        $message = $this->message($run, $clubId, $seasonId, 'jeton-expire');

        $seen = null;
        $this->runHandler($message, $this->placing($fixtureId, $venueId), function () use ($clubId, &$seen): void {
            $seen = $this->placementLockValue($clubId);
        });

        self::assertSame(1, $this->engineCalls, 'la clé reprise, le run se résout');
        self::assertIsString($seen, 'pendant le solve, le club est de nouveau verrouillé');
        self::assertNotSame('jeton-expire', $seen, 'sous un NOUVEAU jeton');
        self::assertSame(MatchPlacementRunStatus::COMPLETED, $this->reloadRun($run, $clubId)->getStatus());
        self::assertNull($this->placementLockValue($clubId), 'le `finally` rend le NOUVEAU jeton — sinon le club reste bloqué tout le TTL');
    }

    public function testPendingRunReplacedByANewerOneFailsWithoutSolvingAndLeavesTheLock(): void
    {
        [$clubId, $seasonId, $userId, $fixtureId, $venueId] = $this->seed();
        $run = $this->seedRun($clubId, $seasonId, $userId, MatchPlacementRunStatus::PENDING);
        // La clé de l'enfilage a expiré, une demande plus récente l'a reprise.
        $newer = $this->holdPlacementLock($clubId, 600);

        $this->runHandler($this->message($run, $clubId, $seasonId, 'jeton-expire'), $this->placing($fixtureId, $venueId));

        self::assertSame(0, $this->engineCalls, 'un run remplacé ne solve pas');
        $reloaded = $this->reloadRun($run, $clubId);
        self::assertSame(MatchPlacementRunStatus::FAILED, $reloaded->getStatus());
        self::assertSame(self::T3, $reloaded->getResultData()['error'] ?? null);
        self::assertCount(1, $this->published);
        self::assertSame('FAILED', $this->publishedStatus(0));
        self::assertSame($newer, $this->placementLockValue($clubId), 'le verrou de la demande plus récente n\'est pas touché');
    }

    public function testALoserWhoseRunWasFailedMeanwhileWritesNothing(): void
    {
        [$clubId, $seasonId, $userId, $fixtureId, $venueId] = $this->seed();
        $run = $this->seedRun($clubId, $seasonId, $userId, MatchPlacementRunStatus::PENDING, null, new DateTimeImmutable('-11 minutes'));
        $token = $this->holdPlacementLock($clubId, 600);
        $runId = $run->getId();

        // Pendant l'appel moteur, une AUTRE écriture (rattrapage en mode commande, après
        // expiration du verrou) pose FAILED sur le run : le handler vivant devient un perdant.
        $this->runHandler($this->message($run, $clubId, $seasonId, $token), $this->placing($fixtureId, $venueId), function () use ($runId): void {
            $this->em->getConnection()->executeStatement(
                'UPDATE match_placement_run SET status = \'FAILED\', finished_at = now(), result_data = :error, version = version + 1 WHERE id = :id',
                ['error' => json_encode(['error' => self::T1], \JSON_THROW_ON_ERROR), 'id' => $runId],
            );
        });

        self::assertSame(1, $this->engineCalls);
        $reloaded = $this->reloadRun($run, $clubId);
        self::assertSame(MatchPlacementRunStatus::FAILED, $reloaded->getStatus(), 'le terminal posé par l\'autre écrivain tient');
        self::assertSame(self::T1, $reloaded->getResultData()['error'] ?? null);

        $this->scopeGucToClub($clubId);
        $fixture = $this->freshEm()->find(Fixture::class, $fixtureId);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::assertSame(FixtureStatus::UNPLACED, $fixture->getStatus(), 'le perdant n\'applique aucun placement');
        self::assertSame(0, $this->creditsUsed($clubId), 'le perdant ne consomme aucun crédit');
        self::assertSame([], array_filter(array_map(fn (int $i): ?string => $this->publishedStatus($i), array_keys($this->published)), static fn (?string $s): bool => 'COMPLETED' === $s), 'le perdant n\'annonce jamais COMPLETED');
        self::assertCount(0, $this->sentEmails, 'le perdant ne prévient personne');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->published = [];
        $this->sentEmails = [];
        $this->engineCalls = 0;
    }

    protected function tearDown(): void
    {
        $this->releasePlacementLockKeys();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed>    $engineBody
     * @param (callable(): void)|null $duringEngineCall
     */
    private function runHandler(PlaceMatchesMessage $message, array $engineBody, ?callable $duringEngineCall = null): void
    {
        $http = new MockHttpClient(function () use ($engineBody, $duringEngineCall): MockResponse {
            ++$this->engineCalls;
            if (null !== $duringEngineCall) {
                $duringEngineCall();
            }

            return new MockResponse(json_encode($engineBody, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });

        $spyHub = $this->createMock(HubInterface::class);
        $spyHub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->published[] = $update;

            return 'spy';
        });
        $spyMailer = $this->createMock(MailerInterface::class);
        $spyMailer->method('send')->willReturnCallback(function (RawMessage $email): void {
            if ($email instanceof Email) {
                $this->sentEmails[] = $email;
            }
        });

        $handler = $this->buildWithDoubles(PlaceMatchesHandler::class, [
            EngineClient::class => new EngineClient($http, self::getContainer()->get(RequestIdContext::class)),
            MatchPlacementProgressPublisher::class => new MatchPlacementProgressPublisher($spyHub),
            MailerInterface::class => $spyMailer,
            LoggerInterface::class => new NullLogger,
            EntityManagerInterface::class => $this->em,
        ], [PlacementRunFailureNotifier::class]);

        $handler($message);
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

    private function publishedStatus(int $index): ?string
    {
        $payload = json_decode($this->published[$index]->getData(), true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($payload) && \is_string($payload['status'] ?? null) ? $payload['status'] : null;
    }

    /** L'EM du test peut avoir été fermé par un flush perdant : on relit sur un EM neuf. */
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

    private function creditsUsed(string $clubId): int
    {
        return (int) $this->freshEm()->getConnection()->fetchOne('SELECT output_credits_used FROM club WHERE id = :id', ['id' => $clubId]);
    }

    /** @param array<string, mixed>|null $resultData */
    private function seedRun(string $clubId, string $seasonId, string $userId, MatchPlacementRunStatus $status, ?array $resultData = null, ?DateTimeImmutable $createdAt = null): MatchPlacementRun
    {
        $this->scopeGucToClub($clubId);
        $createdAt ??= new DateTimeImmutable('-1 minute');
        $run = new MatchPlacementRun($clubId, $seasonId, $userId, $createdAt);
        $run->setStatus($status)->setResultData($resultData);
        if (MatchPlacementRunStatus::PENDING !== $status) {
            $run->setStartedAt($createdAt->modify('+5 seconds'));
        }
        if (\in_array($status, [MatchPlacementRunStatus::COMPLETED, MatchPlacementRunStatus::FAILED], true)) {
            $run->setFinishedAt($createdAt->modify('+30 seconds'));
        }
        $this->em->persist($run);
        $this->em->flush();
        // Contexte worker : aucun GUC posé quand le handler démarre.
        $this->clearGuc();

        return $run;
    }

    private function seedManualFixture(string $clubId, string $seasonId, string $venueId): string
    {
        $this->scopeGucToClub($clubId);
        $other = $this->em->getRepository(Fixture::class)->findOneBy(['clubId' => $clubId]);
        self::assertInstanceOf(Fixture::class, $other);
        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId((string) $other->getTeamId());
        $fixture->setCompetitionId($other->getCompetitionId());
        $fixture->setMatchDate(new DateTimeImmutable('2026-10-10'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv manuel');
        $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $fixture->setPlacementSource(FixturePlacementSource::MANUAL);
        $fixture->setVenueId($venueId);
        $fixture->setKickoffTime(new DateTimeImmutable('17:30'));
        $this->em->persist($fixture);
        $this->em->flush();
        $this->clearGuc();

        return $fixture->getId();
    }

    /**
     * Club NON démo (Découverte bridée : un run COMPLETED consommerait un crédit — témoin), une
     * équipe, un gymnase ouvert le samedi, un domicile à placer.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string} [clubId, seasonId, userId, fixtureId, venueId, userEmail]
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('RP ' . $uid);
        $club->setSlug('rp-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setIsDemo(false);
        $club->setFfbbClubCode('RP' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);

        $userEmail = 'rp-' . $uid . '@test.com';
        $user = new User;
        $user->setEmail($userEmail);
        $user->setFirstName('Relu');
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
        $this->clearGuc();

        return [$club->getId(), $season->getId(), $user->getId(), $fixture->getId(), $venue->getId(), $userEmail];
    }
}
