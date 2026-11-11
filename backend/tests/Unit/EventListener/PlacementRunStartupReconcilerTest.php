<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\EventListener\PlacementRunStartupReconciler;
use App\Service\ReconcileMode;
use App\Service\StuckPlacementReconciliation;
use App\Service\StuckPlacementRunReconciler;
use App\Service\TenantConnectionContext;
use App\Tests\Double\RecordingLogger;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Messenger\WorkerMetadata;

/**
 * NR d'axe *generation pipeline* (§7.1) — un worker qui DÉMARRE rattrape d'abord les placements
 * que le précédent a laissés en vol (tué par l'OOM, SIGKILL du déploiement, mort de Redis) : la
 * décision fondateur est un hook au démarrage du worker, pas un cron.
 *
 *  - le worker qui consomme `async` lance le rattrapage en mode « démarrage », une fois ;
 *  - un worker qui ne consomme pas `async` (p. ex. `messenger:failed:retry`) ne rattrape rien ;
 *  - un rattrapage qui casse n'empêche JAMAIS le worker de démarrer : erreur journalisée, EM
 *    remis à neuf (le premier message ne doit pas hériter d'un EM fermé), GUC du tenant vidé.
 *
 * Ce que le rattrapage décide : `StuckPlacementRunReconcilerTest`. L'ordre « hook avant la
 * première lecture de file » est garanti par Messenger (`Worker::run()` émet `WorkerStartedEvent`
 * avant sa boucle) : déclaré, sans test.
 */
#[Group('phase1')]
final class PlacementRunStartupReconcilerTest extends TestCase
{
    private const string CLEAR_GUC = 'SELECT set_config(\'app.club_id\', \'\', false)';

    public function testAWorkerConsumingAsyncReconcilesOnceInStartupMode(): void
    {
        $reconciler = $this->createMock(StuckPlacementRunReconciler::class);
        $reconciler->expects(self::once())->method('reconcile')
            ->with(ReconcileMode::Startup)
            ->willReturn(new StuckPlacementReconciliation([], [], [], true));

        $this->listener($reconciler, $this->registry(resets: 0), $this->tenantContext(clears: 1), new RecordingLogger)
            ->__invoke($this->startedEvent(['failed', 'async']));
    }

    public function testAWorkerThatDoesNotConsumeAsyncReconcilesNothing(): void
    {
        $reconciler = $this->createMock(StuckPlacementRunReconciler::class);
        $reconciler->expects(self::never())->method('reconcile');

        $this->listener($reconciler, $this->registry(resets: 0), $this->tenantContext(clears: null), new RecordingLogger)
            ->__invoke($this->startedEvent(['failed']));
    }

    public function testAFailingReconciliationNeverStopsTheWorker(): void
    {
        $reconciler = $this->createMock(StuckPlacementRunReconciler::class);
        $reconciler->method('reconcile')->willThrowException(new RuntimeException('base injoignable'));
        $logger = new RecordingLogger;

        // Aucune exception ne doit remonter : le worker démarre quand même.
        $this->listener($reconciler, $this->registry(resets: 1), $this->tenantContext(clears: 1), $logger)
            ->__invoke($this->startedEvent(['async']));

        $errors = array_values(array_filter($logger->records, static fn (array $r): bool => LogLevel::ERROR === $r['level']));
        self::assertCount(1, $errors, 'l\'échec du rattrapage se lit dans le journal du conteneur');
        self::assertSame('Placement run reconciliation failed at worker startup', $errors[0]['message']);
    }

    /** @param list<string> $transportNames */
    private function startedEvent(array $transportNames): WorkerStartedEvent
    {
        $worker = $this->createStub(Worker::class);
        $worker->method('getMetadata')->willReturn(new WorkerMetadata(['transportNames' => $transportNames]));

        return new WorkerStartedEvent($worker);
    }

    private function registry(int $resets): ManagerRegistry&MockObject
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::exactly($resets))->method('resetManager');

        return $registry;
    }

    /**
     * `TenantConnectionContext` est final : on observe son `clear()` par la requête qu'il émet.
     * `$clears` null = indifférent.
     */
    private function tenantContext(?int $clears): TenantConnectionContext
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        if (null !== $clears) {
            $connection->expects(self::exactly($clears))->method('executeStatement')->with(self::CLEAR_GUC);
        }

        return new TenantConnectionContext($connection);
    }

    /** Construit le listener par TYPE de dépendance (l'ordre des arguments n'est pas le contrat). */
    private function listener(StuckPlacementRunReconciler $reconciler, ManagerRegistry $registry, TenantConnectionContext $tenant, LoggerInterface $logger): PlacementRunStartupReconciler
    {
        $doubles = [
            StuckPlacementRunReconciler::class => $reconciler,
            ManagerRegistry::class => $registry,
            TenantConnectionContext::class => $tenant,
            LoggerInterface::class => $logger,
        ];
        $arguments = [];
        foreach (new ReflectionClass(PlacementRunStartupReconciler::class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : '';
            self::assertArrayHasKey($name, $doubles, 'dépendance inattendue du hook de démarrage : ' . $name);
            $arguments[] = $doubles[$name];
        }

        return new PlacementRunStartupReconciler(...$arguments);
    }
}
