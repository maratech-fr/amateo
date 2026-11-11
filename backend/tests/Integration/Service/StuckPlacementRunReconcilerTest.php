<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\MatchPlacementRun;
use App\Entity\User;
use App\Enum\MatchPlacementRunStatus;
use App\Service\MatchPlacementProgressPublisher;
use App\Service\PlacementQueueInspector;
use App\Service\PlacementQueueUnreadable;
use App\Service\PlacementRunFailureNotifier;
use App\Service\ReconcileMode;
use App\Service\StuckPlacementReconciliation;
use App\Service\StuckPlacementRunReconciler;
use App\Service\TenantConnectionContext;
use App\Tests\BuildsServiceWithDoublesTrait;
use App\Tests\Double\RecordingLogger;
use App\Tests\PlacementLockProbeTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * NR d'axes *generation pipeline* et *tenant isolation* (§7.1) — le rattrapage des placements
 * laissés en vol par un worker mort.
 *
 * Mode « démarrage » (le hook du worker, `PlacementRunStartupReconcilerTest`) : au démarrage aucun
 * autre consommateur n'existe, donc TOUT run RUNNING est orphelin, quel que soit son âge ou son
 * verrou → FAILED « interrompu ». Mode « commande » (worker VIVANT) : un RUNNING n'est déclaré mort
 * que si son verrou a disparu (armé au budget du run, il n'expire qu'au-delà) ET qu'il a démarré
 * il y a plus de 5 min. Dans les deux modes : un PENDING absent de la file Messenger depuis plus de
 * 5 min → FAILED « relancez-le » ; COMPLETED / FAILED jamais touchés ; le verrou jamais touché.
 *
 * Chaque club est traité sous SON GUC (un run d'un autre club n'est visible que si le rattrapage
 * pose le GUC de ce club), jamais par un filtre Doctrine qui fuirait jusqu'au premier message du
 * worker ; un club qui casse est sauté, les autres traités, l'EM rendu utilisable. Une file
 * illisible = bras PENDING entièrement sauté (on ne déclare jamais « interrompu » un placement
 * qu'on n'a pas pu chercher dans la file), le bras RUNNING joue quand même.
 *
 * Doublures : file Messenger (inspecteur simulé — sa lecture réelle : `PlacementQueueInspectorTest`),
 * hub Mercure et mailer espions, horloge réelle figée. Base et Redis réels ; clés de verrou rendues
 * par DEL ciblé (jamais FLUSHALL). Garde structurelle jumelle :
 * `Unit/Documentation/WorkerStartupReconcilerNeverScopesDoctrineFiltersTest`.
 */
#[Group('phase1')]
#[Group('integration')]
final class StuckPlacementRunReconcilerTest extends KernelTestCase
{
    use BuildsServiceWithDoublesTrait;
    use PlacementLockProbeTrait;
    use TenantGucTrait;

    private const string T1 = 'Le placement automatique a été interrompu. Il pourra être relancé une fois le calcul interrompu terminé.';

    private const string T2 = 'Le placement automatique a été interrompu. Relancez-le.';

    private EntityManagerInterface $em;

    /** @var list<Update> */
    private array $published = [];

    /** @var list<Email> */
    private array $sentEmails = [];

    private RecordingLogger $logger;

    public function testAtStartupEveryRunningRunIsInterruptedWhateverItsAgeOrLock(): void
    {
        $fresh = $this->seedRun(MatchPlacementRunStatus::RUNNING, createdAgo: '-40 seconds', startedAgo: '-30 seconds');
        $token = $this->holdPlacementLock($fresh['clubId'], 600);
        $old = $this->seedRun(MatchPlacementRunStatus::RUNNING, createdAgo: '-2 hours', startedAgo: '-2 hours');

        $result = $this->reconcile(ReconcileMode::Startup, queued: []);

        foreach ([$fresh, $old] as $seeded) {
            $run = $this->reload($seeded);
            self::assertSame(MatchPlacementRunStatus::FAILED, $run->getStatus(), 'au démarrage, aucun RUNNING n\'a plus de worker');
            self::assertSame(self::T1, $run->getResultData()['error'] ?? null);
            self::assertNotNull($run->getFinishedAt());
        }
        self::assertEqualsCanonicalizing([$fresh['runId'], $old['runId']], $result->runningRunIds);
        self::assertSame(2, $this->publishedFailedCount(), 'chaque écran concerné est réveillé');
        self::assertSame([$old['email']], $this->recipients(), 'seul le run de plus de deux minutes prévient son demandeur');
        self::assertSame($token, $this->placementLockValue($fresh['clubId']), 'verrou JAMAIS touché : le calcul interrompu tient encore le moteur');
    }

    public function testTheCommandOnlyFailsARunningRunWhoseBudgetHasElapsed(): void
    {
        $alive = $this->seedRun(MatchPlacementRunStatus::RUNNING, createdAgo: '-21 minutes', startedAgo: '-20 minutes');
        $token = $this->holdPlacementLock($alive['clubId'], 1800);
        $dead = $this->seedRun(MatchPlacementRunStatus::RUNNING, createdAgo: '-21 minutes', startedAgo: '-20 minutes');
        $justStarted = $this->seedRun(MatchPlacementRunStatus::RUNNING, createdAgo: '-3 minutes', startedAgo: '-2 minutes');

        $result = $this->reconcile(ReconcileMode::Cli, queued: []);

        self::assertSame(MatchPlacementRunStatus::RUNNING, $this->reload($alive)->getStatus(), 'verrou encore tenu = budget pas écoulé = worker vivant : jamais tué');
        self::assertSame($token, $this->placementLockValue($alive['clubId']));
        $failed = $this->reload($dead);
        self::assertSame(MatchPlacementRunStatus::FAILED, $failed->getStatus(), 'verrou disparu (budget dépassé) et démarré il y a plus de 5 min : mort');
        self::assertSame(self::T1, $failed->getResultData()['error'] ?? null);
        self::assertSame(MatchPlacementRunStatus::RUNNING, $this->reload($justStarted)->getStatus(), 'démarré il y a 2 min : la fenêtre entre la lecture et le réarmement du verrou est protégée');
        self::assertSame([$dead['runId']], $result->runningRunIds);
        self::assertSame(1, $this->publishedFailedCount());
    }

    public function testAPendingRunMissingFromTheQueueIsInterrupted(): void
    {
        $orphan = $this->seedRun(MatchPlacementRunStatus::PENDING, createdAgo: '-10 minutes');
        $queued = $this->seedRun(MatchPlacementRunStatus::PENDING, createdAgo: '-10 minutes');
        $recent = $this->seedRun(MatchPlacementRunStatus::PENDING, createdAgo: '-1 minute');

        $result = $this->reconcile(ReconcileMode::Cli, queued: [$queued['runId']]);

        $failed = $this->reload($orphan);
        self::assertSame(MatchPlacementRunStatus::FAILED, $failed->getStatus(), 'absent de la file depuis plus de 5 min : personne ne le traitera');
        self::assertSame(self::T2, $failed->getResultData()['error'] ?? null);
        self::assertSame(MatchPlacementRunStatus::PENDING, $this->reload($queued)->getStatus(), 'encore en file : il attend son tour');
        self::assertSame(MatchPlacementRunStatus::PENDING, $this->reload($recent)->getStatus(), 'créé il y a 1 min : son message arrive peut-être encore');
        self::assertSame([$orphan['runId']], $result->pendingRunIds);
        self::assertTrue($result->queueInspected);
        self::assertSame(1, $this->publishedFailedCount());
        self::assertSame([$orphan['email']], $this->recipients(), 'l\'orphelin de plus de deux minutes prévient son demandeur');
    }

    public function testFinishedRunsAreNeverTouched(): void
    {
        $completed = $this->seedRun(MatchPlacementRunStatus::COMPLETED, createdAgo: '-3 hours', startedAgo: '-3 hours');
        $failed = $this->seedRun(MatchPlacementRunStatus::FAILED, createdAgo: '-3 hours', startedAgo: '-3 hours');

        $result = $this->reconcile(ReconcileMode::Startup, queued: []);

        self::assertSame(MatchPlacementRunStatus::COMPLETED, $this->reload($completed)->getStatus());
        $stillFailed = $this->reload($failed);
        self::assertSame(MatchPlacementRunStatus::FAILED, $stillFailed->getStatus());
        self::assertSame(['error' => 'motif d\'origine'], $stillFailed->getResultData(), 'le motif d\'origine n\'est pas réécrit');
        self::assertSame(0, $result->count());
        self::assertSame(0, $this->publishedFailedCount());
    }

    public function testAnUnreadableQueueSkipsPendingRunsButStillHandlesRunningOnes(): void
    {
        $pending = $this->seedRun(MatchPlacementRunStatus::PENDING, createdAgo: '-30 minutes');
        $running = $this->seedRun(MatchPlacementRunStatus::RUNNING, createdAgo: '-30 minutes', startedAgo: '-29 minutes');

        $result = $this->reconcile(ReconcileMode::Startup, queued: new PlacementQueueUnreadable('entrée 1-0 indécodable'));

        self::assertFalse($result->queueInspected);
        self::assertSame(MatchPlacementRunStatus::PENDING, $this->reload($pending)->getStatus(), 'file non lue : on ne déclare jamais « interrompu » un placement qu\'on n\'a pas pu chercher');
        self::assertSame([], $result->pendingRunIds);
        self::assertSame(MatchPlacementRunStatus::FAILED, $this->reload($running)->getStatus(), 'le bras RUNNING ne dépend pas de la file');
        $warnings = array_filter($this->logger->records, static fn (array $r): bool => LogLevel::WARNING === $r['level']);
        self::assertNotSame([], $warnings, 'la file non lue se signale dans le journal');
    }

    public function testAClubThatFailsIsSkippedAndTheOthersAreStillReconciled(): void
    {
        // Le club en échec est créé EN PREMIER (parcours des clubs dans l'ordre de la table) : les
        // deux suivants ne passent qu'avec un EM rendu utilisable après l'échec.
        $broken = $this->seedRun(MatchPlacementRunStatus::RUNNING, createdAgo: '-10 minutes', startedAgo: '-10 minutes');
        $after1 = $this->seedRun(MatchPlacementRunStatus::RUNNING, createdAgo: '-10 minutes', startedAgo: '-10 minutes');
        $after2 = $this->seedRun(MatchPlacementRunStatus::RUNNING, createdAgo: '-10 minutes', startedAgo: '-10 minutes');

        $brokenRunId = $broken['runId'];
        $saboteur = new class($brokenRunId) {
            public function __construct(private readonly string $runId) {}

            public function preUpdate(PreUpdateEventArgs $args): void
            {
                $entity = $args->getObject();
                if ($entity instanceof MatchPlacementRun && $entity->getId() === $this->runId) {
                    throw new RuntimeException('écriture impossible pour ce club');
                }
            }
        };
        $this->em->getEventManager()->addEventListener(Events::preUpdate, $saboteur);

        try {
            $result = $this->reconcile(ReconcileMode::Startup, queued: []);
        } finally {
            $this->em->getEventManager()->removeEventListener(Events::preUpdate, $saboteur);
        }

        self::assertSame([$broken['clubId']], $result->skippedClubIds, 'le club en erreur est nommé, pas tu');
        self::assertEqualsCanonicalizing([$after1['runId'], $after2['runId']], $result->runningRunIds);
        self::assertTrue($this->registry()->getManager()->isOpen(), 'l\'EM du worker est rendu utilisable : le premier message après le rattrapage ne doit pas hériter d\'un EM fermé');
        self::assertSame(MatchPlacementRunStatus::FAILED, $this->reload($after1)->getStatus());
        self::assertSame(MatchPlacementRunStatus::FAILED, $this->reload($after2)->getStatus());
        self::assertSame(MatchPlacementRunStatus::RUNNING, $this->reload($broken)->getStatus());
        self::assertEqualsCanonicalizing([$after1['email'], $after2['email']], $this->recipients(), 'les demandeurs des clubs suivants sont prévenus (le notifier passe par l\'EM rendu utilisable)');
        $errors = array_filter($this->logger->records, static fn (array $r): bool => LogLevel::ERROR === $r['level']);
        self::assertNotSame([], $errors, 'l\'erreur du club sauté se lit dans le journal');
    }

    public function testADryRunWritesNothing(): void
    {
        $running = $this->seedRun(MatchPlacementRunStatus::RUNNING, createdAgo: '-30 minutes', startedAgo: '-29 minutes');
        $pending = $this->seedRun(MatchPlacementRunStatus::PENDING, createdAgo: '-30 minutes');

        $result = $this->reconcile(ReconcileMode::Startup, queued: [], dryRun: true);

        self::assertSame([$running['runId']], $result->runningRunIds, 'le run figé est NOMMÉ');
        self::assertSame([$pending['runId']], $result->pendingRunIds);
        self::assertSame(MatchPlacementRunStatus::RUNNING, $this->reload($running)->getStatus(), 'à blanc : rien d\'écrit');
        self::assertSame(MatchPlacementRunStatus::PENDING, $this->reload($pending)->getStatus());
        self::assertSame(0, $this->publishedFailedCount());
        self::assertSame([], $this->recipients());
    }

    /**
     * L'ordre : la FILE est lue AVANT la base. Témoin : pendant la lecture de la file, le worker
     * termine un run que la file ne contient plus. Lue après la file, la base le voit COMPLETED ;
     * lue avant, le rattrapage le croirait encore PENDING et absent — il écraserait un placement
     * abouti par « interrompu ». (L'écriture du témoin ne monte pas la version : une lecture
     * périmée écraserait visiblement au lieu d'échouer en silence.).
     */
    public function testQueueIsReadBeforeTheDatabase(): void
    {
        $finishing = $this->seedRun(MatchPlacementRunStatus::PENDING, createdAgo: '-10 minutes');
        $tenant = self::getContainer()->get(TenantConnectionContext::class);
        $connection = $this->em->getConnection();

        $result = $this->reconcile(ReconcileMode::Cli, queued: static function () use ($finishing, $tenant, $connection): array {
            // Le GUC en cours du rattrapage est RENDU tel quel après l'écriture du témoin : le
            // vider ferait échouer une écriture périmée sous RLS, et le test passerait à tort.
            $previous = (string) $connection->fetchOne('SELECT COALESCE(current_setting(\'app.club_id\', true), \'\')');
            if (!\in_array($previous, ['', $finishing['clubId']], true)) {
                // Lecture de file faite PAR CLUB, pendant un autre club : le témoin attend celle
                // du club concerné (sinon le run serait terminé avant même d'être lu).
                return [];
            }
            $tenant->setClubId($finishing['clubId']);
            $connection->executeStatement(
                'UPDATE match_placement_run SET status = \'COMPLETED\', started_at = now(), finished_at = now() WHERE id = :id',
                ['id' => $finishing['runId']],
            );
            '' === $previous ? $tenant->clear() : $tenant->setClubId($previous);

            return [];
        });

        self::assertSame([], $result->skippedClubIds, 'aucun club en erreur : le témoin ne doit pas passer par un échec d\'écriture');
        self::assertSame(MatchPlacementRunStatus::COMPLETED, $this->reload($finishing)->getStatus(), 'un run terminé pendant la lecture de la file n\'est jamais déclaré interrompu');
        self::assertSame([], $result->pendingRunIds);
    }

    public function testGracePeriodsFollowTheInjectedClock(): void
    {
        $pending = $this->seedRun(MatchPlacementRunStatus::PENDING, createdAgo: '-1 minute');
        $running = $this->seedRun(MatchPlacementRunStatus::RUNNING, createdAgo: '-3 minutes', startedAgo: '-2 minutes');

        // Dix minutes plus tard (horloge réelle injectée) : les deux grâces de 5 min sont passées.
        $result = $this->reconcile(ReconcileMode::Cli, queued: [], clock: new MockClock(new DateTimeImmutable('+10 minutes')));

        self::assertSame(MatchPlacementRunStatus::FAILED, $this->reload($pending)->getStatus(), 'la grâce du PENDING se mesure à l\'horloge injectée');
        self::assertSame(MatchPlacementRunStatus::FAILED, $this->reload($running)->getStatus(), 'la grâce du RUNNING sans verrou aussi');
        self::assertSame(2, $result->count());
    }

    public function testReconciliationLeavesNoTenantFilterNorGucBehind(): void
    {
        $this->seedRun(MatchPlacementRunStatus::RUNNING, createdAgo: '-10 minutes', startedAgo: '-10 minutes');
        $this->seedRun(MatchPlacementRunStatus::RUNNING, createdAgo: '-10 minutes', startedAgo: '-10 minutes');

        $this->reconcile(ReconcileMode::Startup, queued: []);
        $this->assertNoTenantScopeLeft('après un rattrapage nominal');

        $broken = $this->seedRun(MatchPlacementRunStatus::RUNNING, createdAgo: '-10 minutes', startedAgo: '-10 minutes');
        $this->seedRun(MatchPlacementRunStatus::RUNNING, createdAgo: '-10 minutes', startedAgo: '-10 minutes');
        $saboteur = new class($broken['runId']) {
            public function __construct(private readonly string $runId) {}

            public function preUpdate(PreUpdateEventArgs $args): void
            {
                if ($args->getObject() instanceof MatchPlacementRun && $args->getObject()->getId() === $this->runId) {
                    throw new RuntimeException('écriture impossible');
                }
            }
        };
        $this->em->getEventManager()->addEventListener(Events::preUpdate, $saboteur);

        try {
            $this->reconcile(ReconcileMode::Startup, queued: []);
        } finally {
            $this->em->getEventManager()->removeEventListener(Events::preUpdate, $saboteur);
        }
        $this->assertNoTenantScopeLeft('après un rattrapage dont un club a échoué');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->published = [];
        $this->sentEmails = [];
        $this->logger = new RecordingLogger;
    }

    protected function tearDown(): void
    {
        $this->releasePlacementLockKeys();
        parent::tearDown();
    }

    /**
     * @param list<string>|PlacementQueueUnreadable|callable(): list<string> $queued ce que la file contient (ids de run), ou l'échec de sa lecture
     */
    private function reconcile(ReconcileMode $mode, array|PlacementQueueUnreadable|callable $queued, bool $dryRun = false, ?ClockInterface $clock = null): StuckPlacementReconciliation
    {
        $inspector = $this->createMock(PlacementQueueInspector::class);
        if ($queued instanceof PlacementQueueUnreadable) {
            $inspector->method('queuedRunIds')->willThrowException($queued);
        } elseif (\is_array($queued)) {
            $inspector->method('queuedRunIds')->willReturn($queued);
        } else {
            $inspector->method('queuedRunIds')->willReturnCallback($queued);
        }

        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->published[] = $update;

            return 'spy';
        });
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (RawMessage $message): void {
            if ($message instanceof Email) {
                $this->sentEmails[] = $message;
            }
        });

        // Contexte worker : aucun GUC posé quand le rattrapage démarre.
        $this->clearGuc();
        $this->em->clear();

        $reconciler = $this->buildWithDoubles(StuckPlacementRunReconciler::class, [
            PlacementQueueInspector::class => $inspector,
            MatchPlacementProgressPublisher::class => new MatchPlacementProgressPublisher($hub),
            MailerInterface::class => $mailer,
            ClockInterface::class => $clock ?? new MockClock,
            LoggerInterface::class => $this->logger,
        ], [PlacementRunFailureNotifier::class]);

        return $reconciler->reconcile($mode, $dryRun);
    }

    private function assertNoTenantScopeLeft(string $when): void
    {
        $em = $this->registry()->getManager();
        \assert($em instanceof EntityManagerInterface);
        self::assertFalse($em->getFilters()->isEnabled('tenant_filter'), $when . ' : aucun filtre tenant ne survit jusqu\'au premier message du worker');
        $guc = $em->getConnection()->fetchOne('SELECT current_setting(\'app.club_id\', true)');
        self::assertContains($guc, ['', null, false], $when . ' : le GUC du dernier club traité ne survit pas');
    }

    private function registry(): ManagerRegistry
    {
        $registry = self::getContainer()->get('doctrine');
        \assert($registry instanceof ManagerRegistry);

        return $registry;
    }

    /**
     * Un club neuf, son gestionnaire demandeur, et UN run dans l'état voulu (sans saison : le
     * rattrapage ne lit que le run).
     *
     * @return array{clubId: string, runId: string, email: string}
     */
    private function seedRun(MatchPlacementRunStatus $status, string $createdAgo, ?string $startedAgo = null): array
    {
        $this->em = $this->openEm();
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Rattrapage ' . $uid);
        $club->setSlug('rattrapage-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('RT' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);

        $email = 'rattrapage-' . $uid . '@test.com';
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName('Ra');
        $user->setLastName('Trapage');
        $user->setPasswordHash('x');
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $createdAt = new DateTimeImmutable($createdAgo);
        $run = new MatchPlacementRun($club->getId(), null, $user->getId(), $createdAt);
        $run->setStatus($status);
        if (null !== $startedAgo) {
            $run->setStartedAt(new DateTimeImmutable($startedAgo));
        }
        if (\in_array($status, [MatchPlacementRunStatus::COMPLETED, MatchPlacementRunStatus::FAILED], true)) {
            $run->setFinishedAt($createdAt->modify('+1 minute'));
            $run->setResultData(MatchPlacementRunStatus::FAILED === $status ? ['error' => 'motif d\'origine'] : ['placed' => 1]);
        }
        $this->em->persist($run);
        $this->em->flush();
        $this->clearGuc();

        return ['clubId' => $club->getId(), 'runId' => $run->getId(), 'email' => $email];
    }

    /** @param array{clubId: string, runId: string, email: string} $seeded */
    private function reload(array $seeded): MatchPlacementRun
    {
        $em = $this->openEm();
        $em->clear();
        $this->scopeGucToClub($seeded['clubId']);
        $run = $em->getRepository(MatchPlacementRun::class)->find($seeded['runId']);
        $this->clearGuc();
        self::assertInstanceOf(MatchPlacementRun::class, $run);

        return $run;
    }

    private function openEm(): EntityManagerInterface
    {
        $em = $this->registry()->getManager();
        \assert($em instanceof EntityManagerInterface);
        if (!$em->isOpen()) {
            $this->registry()->resetManager();
            $em = $this->registry()->getManager();
            \assert($em instanceof EntityManagerInterface);
        }

        return $em;
    }

    private function publishedFailedCount(): int
    {
        $count = 0;
        foreach ($this->published as $update) {
            $payload = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
            if (\is_array($payload) && 'FAILED' === ($payload['status'] ?? null)) {
                ++$count;
            }
        }

        return $count;
    }

    /** @return list<string> */
    private function recipients(): array
    {
        return array_map(static fn (Email $email): string => $email->getTo()[0]->getAddress(), $this->sentEmails);
    }
}
