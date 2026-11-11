<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Club;
use App\Entity\MatchPlacementRun;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\MatchPlacementRunStatus;
use App\Message\PlaceMatchesMessage;
use App\Service\EngineClient;
use App\Service\MatchPlacementLock;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\MatchPlacementProgressPublisher;
use App\Service\MatchPlacementResultApplier;
use App\Service\OutputCreditLedger;
use App\Service\PlacementRunEmailBuilder;
use App\Service\PlacementRunFailureNotifier;
use App\Service\PlanEntitlements;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Throwable;

/**
 * Le PLACEMENT ASYNCHRONE des matchs (patron {@see GenerateScheduleHandler}). Le solve
 * quitte le rail synchrone (plafond HTTP) pour le worker, sous un budget dimensionné sur
 * le nombre de semaines ISO à placer. Garanties :
 *
 *  - **RLS** : aucune requête HTTP dans le worker → aucun GUC posé par le listener. On scope
 *    la connexion au club du message AVANT toute requête, on clear en `finally`.
 *  - **Relecture jugée sur le statut EN BASE** : Messenger relit le message d'un worker tué.
 *    COMPLETED → rien n'est refait, verrou rendu ; FAILED → acquitté, verrou intact (un calcul
 *    orphelin peut encore tenir le moteur, la clé l'atteste jusqu'à son TTL) ; RUNNING (le
 *    rattrapage du démarrage n'a pas joué) → FAILED « interrompu », verrou intact ; PENDING →
 *    nominal.
 *  - **Verrou réarmé au départ** : le TTL posé à l'enfilage a pu fondre dans la file. Le run qui
 *    démarre réarme la clé au budget COMPLET ; expirée, il la reprend (et c'est ce NOUVEAU jeton
 *    qui est rendu en `finally`) ; tenue par un autre jeton, le run est « remplacé », sans solve.
 *    RUNNING n'est posé qu'APRÈS : un run visible RUNNING a toujours eu sa clé armée au budget
 *    complet de CE run (le rattrapage en mode commande s'en sert).
 *  - **Transitions terminales conditionnelles** : COMPLETED/FAILED seulement depuis RUNNING
 *    (relecture fraîche, puis `@Version` au flush) — un handler dont le run a été déclaré échoué
 *    pendant son solve est un PERDANT : il n'écrit rien, ne décompte rien, ne publie rien. Les
 *    placements et le COMPLETED s'écrivent dans UNE transaction ; crédit et Mercure après commit.
 *    Invariant : un seul consommateur du transport `async` à la fois.
 *
 * L'exception est ACQUITTÉE (jamais rejouée) : un run FAILED est un fait d'exploitation, pas
 * un message à re-solver — relancer réécrirait un run déjà terminé.
 */
#[AsMessageHandler]
final class PlaceMatchesHandler
{
    /** T1 — run RUNNING sans worker (relu ici, ou rattrapé au démarrage / par la commande). */
    public const string ERROR_INTERRUPTED = 'Le placement automatique a été interrompu. Il pourra être relancé une fois le calcul interrompu terminé.';

    /** T2 — run PENDING dont le message n'est plus dans la file. */
    public const string ERROR_ORPHANED = 'Le placement automatique a été interrompu. Relancez-le.';

    /** T3 — verrou du club repris par une demande plus récente avant le départ du run. */
    public const string ERROR_REPLACED = 'Le placement automatique a été remplacé par une demande plus récente.';

    /** T4 — message en échec définitif, ou enfilage raté. */
    public const string ERROR_NOT_STARTED = 'Le placement automatique n\'a pas pu être lancé. Réessayez.';

    public function __construct(
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly MatchPlacementLock $lock,
        private readonly MatchPlacementPayloadBuilder $payloadBuilder,
        private readonly EngineClient $engineClient,
        private readonly MatchPlacementResultApplier $applier,
        private readonly MatchPlacementProgressPublisher $publisher,
        private readonly OutputCreditLedger $creditLedger,
        private readonly PlanEntitlements $planEntitlements,
        private readonly ClockInterface $clock,
        private readonly MailerInterface $mailer,
        private readonly PlacementRunEmailBuilder $emailBuilder,
        private readonly PlacementRunFailureNotifier $failureNotifier,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(PlaceMatchesMessage $message): void
    {
        $this->tenantConnectionContext->setClubId($message->getClubId());

        try {
            $this->handle($message);
        } finally {
            $this->tenantConnectionContext->clear();
        }
    }

    private function handle(PlaceMatchesMessage $message): void
    {
        $clubId = $message->getClubId();
        $run = $this->entityManager->getRepository(MatchPlacementRun::class)->find($message->getRunId());
        if (!$run instanceof MatchPlacementRun) {
            // Run invisible (autre club sous RLS) ou supprimé : rien à nommer, on rend le
            // verrou et on sort. (Le contrôleur vient de l'écrire sur CETTE connexion scopée
            // au même club — ce cas ne devrait pas se produire en pratique.)
            $this->lock->release($clubId, $message->getLockToken());

            return;
        }
        // Relecture : le message peut être relu après la mort d'un worker — on juge sur la base.
        $this->entityManager->refresh($run);

        switch ($run->getStatus()) {
            case MatchPlacementRunStatus::COMPLETED:
                $this->logger?->info('Redelivered match placement already completed — acked without solving', ['clubId' => $clubId, 'runId' => $run->getId()]);
                $this->lock->release($clubId, $message->getLockToken());

                return;
            case MatchPlacementRunStatus::FAILED:
                $this->logger?->info('Redelivered match placement already failed — acked without solving', ['clubId' => $clubId, 'runId' => $run->getId()]);

                return;
            case MatchPlacementRunStatus::RUNNING:
                $this->logger?->warning('Redelivered match placement found RUNNING — failed as interrupted', ['clubId' => $clubId, 'runId' => $run->getId()]);
                $this->fail($run, self::ERROR_INTERRUPTED);

                return;
            case MatchPlacementRunStatus::PENDING:
                break;
        }

        // Verrou réarmé au budget complet du run qui démarre (D2).
        $lockToken = $message->getLockToken();
        if (!$this->lock->extend($clubId, $lockToken, $message->budgetSeconds())) {
            $newToken = $this->lock->acquire($clubId, $message->budgetSeconds());
            if (null === $newToken) {
                $this->logger?->warning('Match placement lock taken by a newer request — run replaced without solving', ['clubId' => $clubId, 'runId' => $run->getId()]);
                $this->fail($run, self::ERROR_REPLACED, MatchPlacementRunStatus::PENDING);

                return;
            }
            $lockToken = $newToken;
        }

        try {
            $run->setStatus(MatchPlacementRunStatus::RUNNING)->setStartedAt($this->now());
            $this->entityManager->flush();

            $built = $this->payloadBuilder->build($this->clubOf($run), $message->getSeasonId(), $message->getWindow());

            if (0 === $built['toPlaceCount']) {
                $this->complete($run, [], static fn (array $outcome): array => [
                    'placed' => 0,
                    'skipped' => 0,
                    'unplaced' => [],
                    'diagnostics' => $built['infoDiagnostics'],
                    'message' => 'Aucun match à placer.',
                ]);

                return;
            }

            try {
                $result = $this->engineClient->placeMatches($built['payload'], $message->budgetSeconds());
            } catch (HttpClientExceptionInterface $exception) {
                // Timeout/transport/4xx-5xx : rien n'a été écrit (l'applier ne tourne qu'au
                // succès). FAILED, aucun crédit, message métier identique à l'ancien 502.
                $this->logger?->error('Match placement engine call failed', ['clubId' => $clubId, 'runId' => $run->getId(), 'exception' => $exception]);
                $this->fail($run, 'Le solveur n\'a pas répondu — réessayez.');

                return;
            }

            /** @var list<array{matchId: string, venueId: string, kickoff: string}> $placements */
            $placements = $result['placements'] ?? [];
            /** @var list<array{matchId: string, reason: string, message: string}> $unplaced */
            $unplaced = $result['unplaced'] ?? [];

            $this->complete($run, $placements, static fn (array $outcome): array => [
                'placed' => $outcome['applied'],
                'skipped' => $outcome['skipped'],
                'unplaced' => $unplaced,
                'diagnostics' => array_merge($built['infoDiagnostics'], $result['diagnostics'] ?? []),
                'metrics' => $result['metrics'] ?? null,
            ]);
        } catch (Throwable $exception) {
            // BCK-01 : toute erreur non rattrapée (build, applier, flush) doit laisser un
            // statut terminal — un run figé en RUNNING est le pire mode d'échec. Message
            // générique (jamais de détail brut d'exception vers le client).
            $this->logger?->error('Match placement failed unexpectedly', ['clubId' => $clubId, 'runId' => $run->getId(), 'exception' => $exception]);
            $this->failDefensively($message->getRunId(), 'Le placement automatique a échoué. Réessayez.');
        } finally {
            $this->lock->release($clubId, $lockToken);
        }
    }

    /**
     * Applique les placements ET pose COMPLETED dans UNE transaction (jamais des matchs posés par
     * un run que l'écran dira échoué), seulement si le run est encore RUNNING en base. Puis, après
     * commit : crédit Découverte, bascule Mercure, e-mail de fin. Un perdant (run passé terminal
     * par un autre écrivain pendant le solve) n'écrit rien et ne dit rien.
     *
     * @param list<array{matchId: string, venueId: string, kickoff: string}>    $placements
     * @param callable(array{applied: int, skipped: int}): array<string, mixed> $resultOf
     */
    private function complete(MatchPlacementRun $run, array $placements, callable $resultOf): void
    {
        if (!$this->isStill($run, MatchPlacementRunStatus::RUNNING)) {
            return;
        }

        // Transaction tenue sur la connexion (pas `wrapInTransaction`, qui fermerait l'EM sur toute
        // erreur — y compris une erreur de l'applier AVANT écriture, que le FAILED défensif doit
        // encore pouvoir poser sur un EM ouvert).
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $outcome = [] === $placements ? ['applied' => 0, 'skipped' => 0] : $this->applier->apply($placements);
            $result = $resultOf($outcome);
            $run->setStatus(MatchPlacementRunStatus::COMPLETED)->setFinishedAt($this->now())->setResultData($result);
            $this->entityManager->flush();
            $connection->commit();
        } catch (OptimisticLockException $exception) {
            $this->rollBack();
            $this->logger?->warning('Match placement run was finished by another writer meanwhile — nothing written', ['runId' => $run->getId(), 'exception' => $exception]);

            return;
        } catch (Throwable $exception) {
            $this->rollBack();

            throw $exception;
        }

        $this->consumeCreditIfRestricted($run);
        $this->publisher->publishTerminal((string) $run->getClubId(), $run->getId(), MatchPlacementRunStatus::COMPLETED->value);
        $this->emailManagerIfRunWasLong($run, $result);
    }

    private function rollBack(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }

    /**
     * Prévient par e-mail le gestionnaire qui a lancé le run quand celui-ci a duré plus de deux
     * minutes (il a eu le temps de partir de l'écran). Best-effort : un échec d'envoi n'annule
     * jamais un run COMPLETED déjà persisté (l'e-mail part de toute façon par le bus).
     *
     * @param array<string, mixed> $result
     */
    private function emailManagerIfRunWasLong(MatchPlacementRun $run, array $result): void
    {
        if (!PlacementRunFailureNotifier::exceededEmailThreshold($run)) {
            return;
        }

        $user = $this->entityManager->getRepository(User::class)->find($run->getRequestedByUserId());
        if (!$user instanceof User) {
            return;
        }

        $placed = \is_int($result['placed'] ?? null) ? $result['placed'] : 0;
        $toTreat = \is_array($result['unplaced'] ?? null) ? \count($result['unplaced']) : 0;

        // Identité du club (logo + nom court) sur la carte d'e-mail (D1). Nullable best-effort :
        // un club introuvable ne doit pas faire échouer l'e-mail de fin de run.
        $club = $this->entityManager->getRepository(Club::class)->find((string) $run->getClubId());

        try {
            $this->mailer->send($this->emailBuilder->build($user->getEmail(), $placed, $toTreat, $club));
        } catch (Throwable $exception) {
            $this->logger?->warning('Match placement completion e-mail failed (best-effort)', ['runId' => $run->getId(), 'exception' => $exception]);
        }
    }

    /**
     * Pose FAILED + le motif, seulement si le run est encore dans l'état `$from` en base (un perdant
     * n'écrase jamais un terminal posé par un autre écrivain), puis publie et prévient le demandeur
     * d'un run long (best-effort, décision fondateur 2026-10-03 : sinon il croit que le moteur
     * travaille encore et ne revient jamais).
     */
    private function fail(MatchPlacementRun $run, string $message, MatchPlacementRunStatus $from = MatchPlacementRunStatus::RUNNING): void
    {
        if (!$this->isStill($run, $from)) {
            return;
        }

        $run->setStatus(MatchPlacementRunStatus::FAILED)->setFinishedAt($this->now())->setResultData(['error' => $message]);

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException $exception) {
            $this->logger?->warning('Match placement run was finished by another writer meanwhile — failure not written', ['runId' => $run->getId(), 'exception' => $exception]);

            return;
        }

        $this->publisher->publishTerminal((string) $run->getClubId(), $run->getId(), MatchPlacementRunStatus::FAILED->value);
        $this->failureNotifier->notify($run);
    }

    /** Relecture FRAÎCHE du statut en base (hors identity map) : le run est-il encore `$status` ? */
    private function isStill(MatchPlacementRun $run, MatchPlacementRunStatus $status): bool
    {
        $current = $this->entityManager->getConnection()->fetchOne(
            'SELECT status FROM match_placement_run WHERE id = :id',
            ['id' => $run->getId()],
        );
        if ($status->value === $current) {
            return true;
        }

        $this->logger?->warning('Match placement run changed status meanwhile — nothing written', ['runId' => $run->getId(), 'expected' => $status->value, 'actual' => $current]);

        return false;
    }

    /**
     * Échec terminal défensif pour une erreur par ailleurs non rattrapée : l'EntityManager
     * a pu être fermé par l'erreur d'origine. On re-cherche le run sur un EM propre et on
     * pose FAILED s'il est encore en vol — jamais par-dessus un terminal ; si l'EM est mort, le
     * run reste RUNNING : le rattrapage du prochain démarrage du worker le fermera.
     */
    private function failDefensively(string $runId, string $message): void
    {
        if (!$this->entityManager->isOpen()) {
            return;
        }

        try {
            $this->entityManager->clear();
            $run = $this->entityManager->getRepository(MatchPlacementRun::class)->find($runId);
            if (!$run instanceof MatchPlacementRun
                || !\in_array($run->getStatus(), [MatchPlacementRunStatus::PENDING, MatchPlacementRunStatus::RUNNING], true)) {
                return;
            }
            $this->fail($run, $message, $run->getStatus());
        } catch (Throwable) {
            // Impossible d'enregistrer l'échec (connexion perdue) : rien de plus à faire.
        }
    }

    private function consumeCreditIfRestricted(MatchPlacementRun $run): void
    {
        $club = $this->clubOf($run);
        $seasonId = $run->getSeasonId();
        $season = null !== $seasonId ? $this->entityManager->getRepository(Season::class)->find($seasonId) : null;
        if (!$season instanceof Season) {
            return;
        }
        if ($this->planEntitlements->outputBudget($club, $season)['restricted']) {
            $this->creditLedger->consume((string) $run->getClubId());
        }
    }

    private function clubOf(MatchPlacementRun $run): Club
    {
        $club = $this->entityManager->getRepository(Club::class)->find((string) $run->getClubId());
        if (!$club instanceof Club) {
            // Sous RLS, le club du run est toujours visible sur la connexion scopée.
            throw new RuntimeException(\sprintf('Club %s introuvable pour le run de placement %s.', (string) $run->getClubId(), $run->getId()));
        }

        return $club;
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
