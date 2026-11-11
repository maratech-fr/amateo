<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Club;
use App\Entity\MatchPlacementRun;
use App\Entity\User;
use App\Enum\MatchPlacementRunStatus;
use App\Service\PlacementQueueInspector;
use App\Service\PlacementQueueUnreadable;
use App\Tests\PlacementLockProbeTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Promesse : l'opérateur rattrape à la main, worker VIVANT, les placements figés — sans jamais
 * tuer un placement qui tourne encore. `app:match-placement:reconcile-stuck` (mode commande du
 * rattrapage) déclare FAILED un RUNNING dont le verrou a disparu (budget dépassé) et un PENDING
 * absent de la file au-delà de la grâce (`--pending-grace`, minutes) ; `--dry-run` nomme sans
 * écrire. Ses sorties sont lisibles telles quelles par l'opérateur, et elle rend toujours 0 : un
 * club en erreur ou une file illisible se DISENT, ils n'arrêtent pas la passe.
 *
 * Les règles fines du rattrapage : `StuckPlacementRunReconcilerTest`. Patron :
 * `ReconcileStuckSchedulesTest`. Clés de verrou rendues par DEL ciblé — jamais FLUSHALL.
 */
#[Group('phase1')]
#[Group('integration')]
final class ReconcileStuckMatchPlacementRunsTest extends KernelTestCase
{
    use PlacementLockProbeTrait;
    use TenantGucTrait;

    private const string T1 = 'Le placement automatique a été interrompu. Il pourra être relancé une fois le calcul interrompu terminé.';

    private const string T2 = 'Le placement automatique a été interrompu. Relancez-le.';

    private EntityManagerInterface $em;

    public function testARunningPlacementIsOnlyFailedOnceItsLockIsGone(): void
    {
        [$clubId, $runId] = $this->seedRun(MatchPlacementRunStatus::RUNNING, '-21 minutes', '-20 minutes');
        $token = $this->holdPlacementLock($clubId, 1800);

        $tester = $this->runCommand(['--dry-run' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('0 stuck placement run(s) detected (dry-run).', $this->flat($tester));

        $tester = $this->runCommand([]);
        self::assertStringContainsString('0 stuck placement run(s) marked FAILED.', $this->flat($tester));
        self::assertSame(MatchPlacementRunStatus::RUNNING, $this->reload($clubId, $runId)->getStatus(), 'verrou tenu : le placement tourne encore, la commande n\'y touche pas');
        self::assertSame($token, $this->placementLockValue($clubId));

        // Le verrou expire (budget dépassé, worker mort sans redémarrage) : la passe suivante le ferme.
        $this->placementRedis()->del('match_placement:club:' . $clubId);
        $tester = $this->runCommand([]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 stuck placement run(s) marked FAILED.', $this->flat($tester));
        $run = $this->reload($clubId, $runId);
        self::assertSame(MatchPlacementRunStatus::FAILED, $run->getStatus());
        self::assertSame(self::T1, $run->getResultData()['error'] ?? null);
    }

    public function testThePendingGraceIsAnOption(): void
    {
        [$clubId, $runId] = $this->seedRun(MatchPlacementRunStatus::PENDING, '-30 seconds');

        $this->runCommand([]);
        self::assertSame(MatchPlacementRunStatus::PENDING, $this->reload($clubId, $runId)->getStatus(), 'grâce par défaut (5 min) : un run de 30 s attend encore son message');

        $tester = $this->runCommand(['--pending-grace' => '0']);
        self::assertStringContainsString('1 stuck placement run(s) marked FAILED.', $this->flat($tester));
        $run = $this->reload($clubId, $runId);
        self::assertSame(MatchPlacementRunStatus::FAILED, $run->getStatus(), 'grâce 0 : absent de la file, il est déclaré interrompu');
        self::assertSame(self::T2, $run->getResultData()['error'] ?? null);
    }

    public function testAnUnreadableQueueIsSaidAndPendingRunsAreLeftAlone(): void
    {
        [$clubId, $runId] = $this->seedRun(MatchPlacementRunStatus::PENDING, '-30 minutes');
        $inspector = $this->createMock(PlacementQueueInspector::class);
        $inspector->method('queuedRunIds')->willThrowException(new PlacementQueueUnreadable('Redis injoignable'));
        self::getContainer()->set(PlacementQueueInspector::class, $inspector);

        $tester = $this->runCommand([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('queue not inspected: pending runs skipped', $this->flat($tester));
        self::assertSame(MatchPlacementRunStatus::PENDING, $this->reload($clubId, $runId)->getStatus());
    }

    public function testAClubInErrorIsSaidAndTheCommandStillSucceeds(): void
    {
        [, $runId] = $this->seedRun(MatchPlacementRunStatus::RUNNING, '-30 minutes', '-29 minutes');
        $saboteur = new class($runId) {
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
            $tester = $this->runCommand([]);
        } finally {
            $this->em->getEventManager()->removeEventListener(Events::preUpdate, $saboteur);
        }

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'un club en erreur se dit, il n\'arrête pas la passe');
        self::assertStringContainsString('1 club(s) skipped after an error — see the log.', $this->flat($tester));
    }

    public function testTheHelpSaysWhenARunningRunIsFailed(): void
    {
        $command = new Application(self::$kernel)->find('app:match-placement:reconcile-stuck');

        self::assertStringContainsString(
            'A RUNNING run is only failed when its lock key is gone (budget elapsed) and it started more than 5 minutes ago.',
            preg_replace('/\s+/', ' ', $command->getHelp() . ' ' . $command->getDescription()) ?? '',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->releasePlacementLockKeys();
        parent::tearDown();
    }

    /** @param array<string, mixed> $options */
    private function runCommand(array $options): CommandTester
    {
        $this->clearGuc();
        $this->em->clear();
        $tester = new CommandTester(new Application(self::$kernel)->find('app:match-placement:reconcile-stuck'));
        $tester->execute($options);

        return $tester;
    }

    /** La sortie sur une ligne (SymfonyStyle replie les longues phrases). */
    private function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /** @return array{0: string, 1: string} [clubId, runId] */
    private function seedRun(MatchPlacementRunStatus $status, string $createdAgo, ?string $startedAgo = null): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Commande ' . $uid);
        $club->setSlug('commande-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('CM' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);
        $user = new User;
        $user->setEmail('commande-' . $uid . '@test.com');
        $user->setFirstName('Co');
        $user->setLastName('Mmande');
        $user->setPasswordHash('x');
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $run = new MatchPlacementRun($club->getId(), null, $user->getId(), new DateTimeImmutable($createdAgo));
        $run->setStatus($status);
        if (null !== $startedAgo) {
            $run->setStartedAt(new DateTimeImmutable($startedAgo));
        }
        $this->em->persist($run);
        $this->em->flush();
        $this->clearGuc();
        $this->notePlacementLockKey($club->getId());

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
