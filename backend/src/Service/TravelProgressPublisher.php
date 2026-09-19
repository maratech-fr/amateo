<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\TravelComputeScope;
use App\Mercure\ClubTopicUpdate;
use App\Mercure\MercureTopic;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Throwable;

/**
 * C6 — publie l'avancement du CALCUL DES TRAJETS asynchrone sur le topic PRIVÉ
 * `club:{clubId}:travel` (patron {@see ScheduleProgressPublisher}). Mercure est
 * best-effort — le front dégrade en refetch (le GET reste la vérité, `travelStatus`) —
 * donc un échec de publication est journalisé et avalé, jamais propagé : il ne casse
 * jamais un calcul déjà persisté.
 *
 * Événements : `{scope, done, total, terminal, verdict?}`. Le front n'affiche que
 * done/total (jauge) et rafraîchit au terminal ; le `verdict` de la matrice de gymnases
 * (`{filled, unresolved}`) y est joint pour le détail rouvrable de la modale.
 */
final class TravelProgressPublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function publishProgress(string $clubId, TravelComputeScope $scope, int $done, int $total): void
    {
        $this->publishSafely($clubId, ['scope' => $scope->value, 'done' => $done, 'total' => $total, 'terminal' => false]);
    }

    /** @param array<string, mixed>|null $verdict */
    public function publishTerminal(string $clubId, TravelComputeScope $scope, int $done, int $total, ?array $verdict = null): void
    {
        $payload = ['scope' => $scope->value, 'done' => $done, 'total' => $total, 'terminal' => true];
        if (null !== $verdict) {
            $payload['verdict'] = $verdict;
        }
        $this->publishSafely($clubId, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function publishSafely(string $clubId, array $payload): void
    {
        if ('' === $clubId) {
            return;
        }
        try {
            $this->hub->publish(ClubTopicUpdate::private(
                MercureTopic::forTravel($clubId),
                json_encode($payload, \JSON_THROW_ON_ERROR),
            ));
        } catch (Throwable $exception) {
            $this->logger?->warning('Mercure travel publish failed (best-effort)', ['clubId' => $clubId, 'exception' => $exception]);
        }
    }
}
