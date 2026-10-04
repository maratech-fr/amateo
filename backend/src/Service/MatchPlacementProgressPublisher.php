<?php

declare(strict_types=1);

namespace App\Service;

use App\Mercure\ClubTopicUpdate;
use App\Mercure\MercureTopic;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Throwable;

/**
 * Publie la bascule TERMINALE d'un run de placement des matchs sur le topic PRIVÉ
 * `club:{clubId}:placement` (patron {@see TravelProgressPublisher}). Mercure est
 * best-effort — le front dégrade en relecture du GET (le run en base reste la vérité) —
 * donc un échec de publication est journalisé et avalé, jamais propagé : il ne casse
 * jamais un placement déjà persisté.
 *
 * Charge utile MINIMALE — `{runId, status}`, AUCUNE donnée personnelle : le front
 * réveillé relit le run par le GET (scopé tenant) pour le détail.
 */
final class MatchPlacementProgressPublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** Publie l'état terminal (COMPLETED/FAILED) du run — rien avant (pas de progression fine). */
    public function publishTerminal(string $clubId, string $runId, string $status): void
    {
        if ('' === $clubId || '' === $runId) {
            return;
        }
        try {
            $this->hub->publish(ClubTopicUpdate::private(
                MercureTopic::forPlacement($clubId),
                json_encode(['runId' => $runId, 'status' => $status, 'terminal' => true], \JSON_THROW_ON_ERROR),
            ));
        } catch (Throwable $exception) {
            $this->logger?->warning('Mercure placement publish failed (best-effort)', ['clubId' => $clubId, 'exception' => $exception]);
        }
    }
}
