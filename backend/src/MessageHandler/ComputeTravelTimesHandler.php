<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\TravelComputeScope;
use App\Message\ComputeTravelTimesMessage;
use App\Service\Geo\IgnRoutingClient;
use App\Service\Geo\OpponentTravelResolver;
use App\Service\Geo\VenueTravelTimeAutofillService;
use App\Service\TenantConnectionContext;
use App\Service\TravelComputeLock;
use App\Service\TravelProgressPublisher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Throwable;

/**
 * C6 — le CALCUL ASYNCHRONE des trajets (patron {@see GenerateScheduleHandler}). Le calcul
 * quitte le rail synchrone (plafond HTTP 60 s) pour le worker, avec un budget large ; la
 * progression est poussée par Mercure sur `club:{clubId}:travel`.
 *
 * Trois garanties du patron :
 *  - **RLS** : aucune requête HTTP dans le worker → aucun GUC posé par le listener. On scope
 *    la connexion au club du message AVANT toute requête, on clear en `finally`.
 *  - **Anti-double-calcul** : un {@see TravelComputeLock} par club (TTL > budget). Verrou déjà
 *    tenu ⇒ {@see RecoverableMessageHandlingException} (le message repart en file, pas d'erreur).
 *    Le même verrou fait « calcul en cours » pour `travelStatus: pending` (C5).
 *  - **Terminal toujours publié** : même sur exception, un événement terminal part pour que le
 *    front cesse d'attendre (Mercure best-effort, le GET reste la vérité).
 */
#[AsMessageHandler]
final class ComputeTravelTimesHandler
{
    /** Budget de mur du worker : large (pas de plafond HTTP), ~1 req/s IGN ⇒ ~180 trajets/run. */
    private const int WORKER_BUDGET_SECONDS = 180;

    /**
     * Marge du TTL du verrou AU-DESSUS du budget (l'aller-retour IGN + les écritures). Le pire
     * cas PAR PAIRE est borné : le `Retry-After` d'un 429 est plafonné à 5 s
     * ({@see IgnRoutingClient} MAX_RETRY_AFTER_SECONDS) — un `Retry-After`
     * abusif (3600 s) abandonne la paire au lieu d'endormir le worker, donc au pire quelques
     * dizaines de secondes par paire (pacing 1 s + réessais bornés + timeout 5 s), jamais l'heure.
     */
    private const int LOCK_TTL_MARGIN_SECONDS = 60;

    /** On publie la progression par paliers de 5 trajets (le front n'a pas besoin de plus fin). */
    private const int PROGRESS_STEP = 5;

    public function __construct(
        private readonly TenantConnectionContext $tenantConnectionContext,
        private readonly TravelComputeLock $lock,
        private readonly TravelProgressPublisher $publisher,
        private readonly OpponentTravelResolver $opponentResolver,
        private readonly VenueTravelTimeAutofillService $venueAutofill,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(ComputeTravelTimesMessage $message): void
    {
        $this->tenantConnectionContext->setClubId($message->getClubId());

        try {
            $this->handle($message);
        } finally {
            $this->tenantConnectionContext->clear();
        }
    }

    private function handle(ComputeTravelTimesMessage $message): void
    {
        $clubId = $message->getClubId();
        $token = $this->lock->acquire($clubId, self::WORKER_BUDGET_SECONDS + self::LOCK_TTL_MARGIN_SECONDS);
        if (null === $token) {
            // Un calcul tourne déjà pour ce club : le message repart en file (pas d'erreur).
            throw new RecoverableMessageHandlingException(\sprintf('Travel computation already running for club %s.', $clubId));
        }

        $scope = $message->getScope();
        $lastDone = 0;
        $lastTotal = 0;
        $lastPublished = 0;
        $onProgress = function (int $done, int $total) use (&$lastDone, &$lastTotal, &$lastPublished, $clubId, $scope): void {
            $lastDone = $done;
            $lastTotal = $total;
            if ($done - $lastPublished >= self::PROGRESS_STEP) {
                $lastPublished = $done;
                $this->publisher->publishProgress($clubId, $scope, $done, $total);
            }
        };

        try {
            $verdict = null;
            if (TravelComputeScope::VENUE_MATRIX === $scope) {
                $result = $this->venueAutofill->autofill($clubId, $message->getSeasonId(), $onProgress, (float) self::WORKER_BUDGET_SECONDS);
                $verdict = ['filled' => $result['filled'], 'unresolved' => $result['unresolved']];
            } else {
                $this->opponentResolver->resolve($clubId, $message->getSeasonId(), (float) self::WORKER_BUDGET_SECONDS, $onProgress);
            }
            $this->publisher->publishTerminal($clubId, $scope, $lastDone, $lastTotal, $verdict);
        } catch (Throwable $exception) {
            // Best-effort : le calcul a pu écrire partiellement ; on publie un terminal pour
            // que le front cesse d'attendre. L'erreur est journalisée, jamais propagée (sinon
            // le message re-jouerait et re-calculerait ce qui est déjà en cache).
            $this->logger?->warning('Travel computation failed', ['clubId' => $clubId, 'scope' => $scope->value, 'exception' => $exception]);
            $this->publisher->publishTerminal($clubId, $scope, $lastDone, $lastTotal, null);
        } finally {
            $this->lock->release($clubId, $token);
        }
    }
}
