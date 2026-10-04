<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\AuditAction;
use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * RGPD — écrit le journal d'audit append-only (accountability, art. 5.2).
 *
 * INSERT DBAL direct : pas de flush() d'EntityManager (qui committerait l'état
 * dirty ambiant), pas d'entité managée à vie. BEST-EFFORT LOGUÉ : un échec
 * d'audit ne casse jamais l'opération métier qu'il trace, mais il est signalé
 * (logger error) — un audit silencieusement mort serait pire que pas d'audit.
 *
 * RÈGLE PII : jamais d'email/nom dans details — uniquement des ids et des
 * compteurs. Le rapprochement id→identité se fait via les tables sources tant
 * qu'elles existent (et devient impossible après anonymisation : voulu).
 */
final class AuditTrail
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        // BCK-34 — horloge RÉELLE : l'horodatage du journal d'audit (preuve RGPD,
        // art. 5.2) doit être le temps réel, jamais l'« aujourd'hui » simulé d'un
        // club démo sous lequel l'écriture peut se produire.
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $clock,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** @param array<string, mixed> $details ids/compteurs uniquement — JAMAIS de PII */
    public function record(
        AuditAction $action,
        ?string $actorUserId = null,
        ?string $clubId = null,
        ?string $entityType = null,
        ?string $entityId = null,
        array $details = [],
    ): void {
        $connection = $this->entityManager->getConnection();
        // Best-effort RÉEL sous Postgres : un statement raté à l'intérieur
        // d'une transaction l'avorte (« current transaction is aborted ») et
        // ferait échouer l'opération métier malgré le catch. SAVEPOINT quand
        // une transaction est active → l'échec d'audit se rollback seul.
        // isTransactionActive() ne voit que le compteur DBAL — la transaction
        // de test dama (niveau driver) lui est invisible : on la détecte par
        // le driver pour que le même filet joue en suite phase1 (revue PR-4).
        $inTransaction = $connection->isTransactionActive()
            || $connection->getDriver() instanceof StaticDriver;
        try {
            if ($inTransaction) {
                $connection->executeStatement('SAVEPOINT audit_trail');
            }
            $connection->executeStatement(
                'INSERT INTO audit_log (id, occurred_at, actor_user_id, club_id, action, entity_type, entity_id, details)
                 VALUES (:id, :at, :actor, :club, :action, :etype, :eid, :details)',
                [
                    'id' => Uuid::v4()->toRfc4122(),
                    'at' => $this->clock->now()->format('Y-m-d H:i:s'),
                    'actor' => $actorUserId,
                    'club' => $clubId,
                    'action' => $action->value,
                    'etype' => $entityType,
                    'eid' => $entityId,
                    'details' => json_encode($details, \JSON_THROW_ON_ERROR),
                ],
            );
            if ($inTransaction) {
                $connection->executeStatement('RELEASE SAVEPOINT audit_trail');
            }
        } catch (Throwable $e) {
            if ($inTransaction) {
                try {
                    $connection->executeStatement('ROLLBACK TO SAVEPOINT audit_trail');
                } catch (Throwable) {
                    // savepoint jamais créé (échec avant) — rien à défaire.
                }
            }
            $this->logger?->error('audit_trail_write_failed', [
                'action' => $action->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
