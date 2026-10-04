<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Club;
use App\Entity\MatchPlacementRun;
use App\Entity\Season;
use App\Enum\MatchPlacementRunStatus;
use App\Message\PlaceMatchesMessage;
use App\Service\EngineClient;
use App\Service\MatchPlacementLock;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\MatchPlacementProgressPublisher;
use App\Service\MatchPlacementResultApplier;
use App\Service\OutputCreditLedger;
use App\Service\PlanEntitlements;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Throwable;

/**
 * Le PLACEMENT ASYNCHRONE des matchs (patron {@see GenerateScheduleHandler}). Le solve
 * quitte le rail synchrone (plafond HTTP) pour le worker, sous un budget dimensionné sur
 * le nombre de semaines ISO à placer. Trois garanties du patron :
 *
 *  - **RLS** : aucune requête HTTP dans le worker → aucun GUC posé par le listener. On scope
 *    la connexion au club du message AVANT toute requête, on clear en `finally`.
 *  - **Statut terminal TOUJOURS posé** : succès → COMPLETED + résultat appliqué + crédit
 *    décompté (Découverte) + publication Mercure ; échec moteur → FAILED, aucun crédit,
 *    publication FAILED ; sur exception inattendue, FAILED défensif — jamais un run bloqué
 *    en RUNNING.
 *  - **Verrou pris par le contrôleur, rendu ici** : le `MatchPlacementLock` tenu depuis
 *    l'enfilage (anti-double-demande) est relâché par ce worker (compare-and-delete par
 *    token porté par le message), dans le `finally`.
 *
 * L'exception est ACQUITTÉE (jamais rejouée) : un run FAILED est un fait d'exploitation, pas
 * un message à re-solver — relancer réécrirait un run déjà terminé.
 */
#[AsMessageHandler]
final class PlaceMatchesHandler
{
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

        try {
            $run->setStatus(MatchPlacementRunStatus::RUNNING)->setStartedAt($this->now());
            $this->entityManager->flush();

            $built = $this->payloadBuilder->build($this->clubOf($run), $message->getSeasonId(), $message->getWindow());

            if (0 === $built['toPlaceCount']) {
                $this->complete($run, [
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
            $outcome = $this->applier->apply($placements);

            $this->complete($run, [
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
            $this->lock->release($clubId, $message->getLockToken());
        }
    }

    /**
     * Pose COMPLETED + le résultat, décompte le crédit Découverte (sortie produite), publie
     * la bascule Mercure. Le crédit se décompte APRÈS la persistance du run (une sortie
     * aboutie), jamais sur un run FAILED.
     *
     * @param array<string, mixed> $result
     */
    private function complete(MatchPlacementRun $run, array $result): void
    {
        $run->setStatus(MatchPlacementRunStatus::COMPLETED)->setFinishedAt($this->now())->setResultData($result);
        $this->entityManager->flush();

        $this->consumeCreditIfRestricted($run);
        $this->publisher->publishTerminal((string) $run->getClubId(), $run->getId(), MatchPlacementRunStatus::COMPLETED->value);
    }

    private function fail(MatchPlacementRun $run, string $message): void
    {
        $run->setStatus(MatchPlacementRunStatus::FAILED)->setFinishedAt($this->now())->setResultData(['error' => $message]);
        $this->entityManager->flush();

        $this->publisher->publishTerminal((string) $run->getClubId(), $run->getId(), MatchPlacementRunStatus::FAILED->value);
    }

    /**
     * Échec terminal défensif pour une erreur par ailleurs non rattrapée : l'EntityManager
     * a pu être fermé par l'erreur d'origine. On re-cherche le run sur un EM propre et on
     * pose FAILED ; si l'EM est mort, le run reste RUNNING (résidu assumé, comme la
     * génération — pas de watchdog de placement à ce stade).
     */
    private function failDefensively(string $runId, string $message): void
    {
        if (!$this->entityManager->isOpen()) {
            return;
        }

        try {
            $this->entityManager->clear();
            $run = $this->entityManager->getRepository(MatchPlacementRun::class)->find($runId);
            if (!$run instanceof MatchPlacementRun) {
                return;
            }
            $this->fail($run, $message);
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
