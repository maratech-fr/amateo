<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Club;
use App\Entity\MatchPlacementRun;
use App\Entity\User;
use App\Enum\MatchPlacementRunStatus;
use App\EventListener\PlaceMatchesFailureListener;
use App\Message\GenerateScheduleMessage;
use App\Message\PlaceMatchesMessage;
use App\Service\MatchPlacementProgressPublisher;
use App\Service\PlacementRunFailureNotifier;
use App\Tests\BuildsServiceWithDoublesTrait;
use App\Tests\Double\RecordingLogger;
use App\Tests\PlacementLockProbeTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * NR d'axe *generation pipeline* (§7.1) — un message de placement qui échoue DÉFINITIVEMENT
 * (retries épuisés : base injoignable au démarrage du handler…) ne laisse pas son run en vol.
 *
 * Le run PENDING/RUNNING passe FAILED « n'a pas pu être lancé », l'écran est réveillé, le verrou
 * du message est rendu (le club peut relancer tout de suite). Rien sur un échec qui sera rejoué,
 * rien pour un autre message, rien sur un run déjà terminé. Best-effort : une base encore morte
 * pour le listener se journalise et ne casse pas le worker (le rattrapage du démarrage suivant
 * prend le relais — `StuckPlacementRunReconcilerTest`) ; le GUC du club est toujours vidé.
 */
#[Group('phase1')]
#[Group('integration')]
final class PlaceMatchesFailureListenerTest extends KernelTestCase
{
    use BuildsServiceWithDoublesTrait;
    use PlacementLockProbeTrait;
    use TenantGucTrait;

    private const string T4 = 'Le placement automatique n\'a pas pu être lancé. Réessayez.';

    private EntityManagerInterface $em;

    /** @var list<Update> */
    private array $published = [];

    private RecordingLogger $logger;

    public function testADefinitivelyFailedPlacementMessageFailsItsRunAndReleasesTheLock(): void
    {
        foreach ([MatchPlacementRunStatus::PENDING, MatchPlacementRunStatus::RUNNING] as $status) {
            $this->published = [];
            [$clubId, $runId] = $this->seedRun($status);
            $token = $this->holdPlacementLock($clubId, 600);

            $this->listener()->__invoke($this->failedEvent(new PlaceMatchesMessage($runId, $clubId, null, 1, $token), willRetry: false));
            // Lu AVANT toute relecture du test (qui pose puis vide le GUC elle-même).
            $this->assertGucCleared();

            $run = $this->reload($clubId, $runId);
            self::assertSame(MatchPlacementRunStatus::FAILED, $run->getStatus(), $status->value . ' : un message mort ne laisse pas son run en vol');
            self::assertSame(self::T4, $run->getResultData()['error'] ?? null);
            self::assertCount(1, $this->published, 'l\'écran est réveillé');
            self::assertNull($this->placementLockValue($clubId), 'le verrou du message est rendu : le club peut relancer');
        }
    }

    public function testAFailureThatWillBeRetriedChangesNothing(): void
    {
        [$clubId, $runId] = $this->seedRun(MatchPlacementRunStatus::PENDING);
        $token = $this->holdPlacementLock($clubId, 600);

        $this->listener()->__invoke($this->failedEvent(new PlaceMatchesMessage($runId, $clubId, null, 1, $token), willRetry: true));

        self::assertSame(MatchPlacementRunStatus::PENDING, $this->reload($clubId, $runId)->getStatus(), 'le message sera rejoué : son run attend');
        self::assertCount(0, $this->published);
        self::assertSame($token, $this->placementLockValue($clubId));
    }

    public function testAnotherMessageOrAFinishedRunIsLeftAlone(): void
    {
        [$clubId, $runId] = $this->seedRun(MatchPlacementRunStatus::PENDING);
        $this->listener()->__invoke($this->failedEvent(new GenerateScheduleMessage(scheduleId: $runId, clubId: $clubId), willRetry: false));
        self::assertSame(MatchPlacementRunStatus::PENDING, $this->reload($clubId, $runId)->getStatus(), 'un autre message ne touche pas aux runs de placement');

        [$doneClubId, $doneRunId] = $this->seedRun(MatchPlacementRunStatus::COMPLETED);
        $this->listener()->__invoke($this->failedEvent(new PlaceMatchesMessage($doneRunId, $doneClubId, null, 1, 'jeton'), willRetry: false));
        $done = $this->reload($doneClubId, $doneRunId);
        self::assertSame(MatchPlacementRunStatus::COMPLETED, $done->getStatus(), 'un run déjà terminé n\'est jamais réécrit');
        self::assertSame(['placed' => 1], $done->getResultData());

        self::assertCount(0, $this->published);
    }

    public function testADeadDatabaseIsLoggedNeverThrown(): void
    {
        [$clubId, $runId] = $this->seedRun(MatchPlacementRunStatus::PENDING);
        $deadEm = $this->createMock(EntityManagerInterface::class);
        $deadEm->method('getRepository')->willThrowException(new RuntimeException('connexion perdue'));
        $deadEm->method('find')->willThrowException(new RuntimeException('connexion perdue'));

        // Aucune exception ne doit remonter : le worker continue.
        $this->listener($deadEm)->__invoke($this->failedEvent(new PlaceMatchesMessage($runId, $clubId, null, 1, 'jeton'), willRetry: false));

        $warnings = array_filter($this->logger->records, static fn (array $r): bool => LogLevel::WARNING === $r['level']);
        self::assertNotSame([], $warnings, 'l\'échec du listener se lit dans le journal');
        $this->assertGucCleared();
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->published = [];
        $this->logger = new RecordingLogger;
    }

    protected function tearDown(): void
    {
        $this->releasePlacementLockKeys();
        parent::tearDown();
    }

    private function listener(?EntityManagerInterface $em = null): PlaceMatchesFailureListener
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->published[] = $update;

            return 'spy';
        });
        $doubles = [
            MatchPlacementProgressPublisher::class => new MatchPlacementProgressPublisher($hub),
            MailerInterface::class => $this->createMock(MailerInterface::class),
            LoggerInterface::class => $this->logger,
        ];
        if ($em instanceof EntityManagerInterface) {
            $doubles[EntityManagerInterface::class] = $em;
        }
        // Contexte worker : aucun GUC posé quand le listener joue.
        $this->clearGuc();

        return $this->buildWithDoubles(PlaceMatchesFailureListener::class, $doubles, [PlacementRunFailureNotifier::class]);
    }

    private function failedEvent(object $message, bool $willRetry): WorkerMessageFailedEvent
    {
        $event = new WorkerMessageFailedEvent(new Envelope($message), 'async', new RuntimeException('base injoignable'));
        if ($willRetry) {
            $event->setForRetry();
        }

        return $event;
    }

    private function assertGucCleared(): void
    {
        $guc = $this->em->getConnection()->fetchOne('SELECT current_setting(\'app.club_id\', true)');
        self::assertContains($guc, ['', null, false], 'le GUC du club ne survit pas au listener');
    }

    /** @return array{0: string, 1: string} [clubId, runId] */
    private function seedRun(MatchPlacementRunStatus $status): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Echec ' . $uid);
        $club->setSlug('echec-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('EC' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);
        $user = new User;
        $user->setEmail('echec-' . $uid . '@test.com');
        $user->setFirstName('E');
        $user->setLastName('Chec');
        $user->setPasswordHash('x');
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $run = new MatchPlacementRun($club->getId(), null, $user->getId(), new DateTimeImmutable('-1 minute'));
        $run->setStatus($status);
        if (MatchPlacementRunStatus::PENDING !== $status) {
            $run->setStartedAt(new DateTimeImmutable('-50 seconds'));
        }
        if (MatchPlacementRunStatus::COMPLETED === $status) {
            $run->setFinishedAt(new DateTimeImmutable('-10 seconds'))->setResultData(['placed' => 1]);
        }
        $this->em->persist($run);
        $this->em->flush();
        $this->clearGuc();

        return [$club->getId(), $run->getId()];
    }

    private function reload(string $clubId, string $runId): MatchPlacementRun
    {
        $this->em->clear();
        $this->scopeGucToClub($clubId);
        $run = $this->em->getRepository(MatchPlacementRun::class)->find($runId);
        $this->clearGuc();
        self::assertInstanceOf(MatchPlacementRun::class, $run);

        return $run;
    }
}
