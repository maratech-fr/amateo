<?php

declare(strict_types=1);

namespace App\Message;

use App\Enum\TravelComputeScope;
use App\MessageHandler\ComputeTravelTimesHandler;

/**
 * C6 — demande de CALCUL ASYNCHRONE des trajets d'un club+saison, par portée
 * (adversaires ou matrice de gymnases). Enfilée par les contrôleurs (`/opponents/refresh`,
 * `/opponents/travel/resolve`, `/venue-travel-times/autofill`, `PATCH /club/siege`) et
 * jouée par {@see ComputeTravelTimesHandler} dans le worker : le calcul
 * quitte le rail synchrone (soumis au plafond HTTP de 60 s) pour un budget de worker large,
 * la progression étant poussée par Mercure sur `club:{clubId}:travel`.
 *
 * `readonly` — porté par le transport `async` (PhpSerializer natif) ; la portée est un enum
 * sérialisable.
 */
final readonly class ComputeTravelTimesMessage
{
    public function __construct(
        private string $clubId,
        private string $seasonId,
        private TravelComputeScope $scope,
    ) {}

    public function getClubId(): string
    {
        return $this->clubId;
    }

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function getScope(): TravelComputeScope
    {
        return $this->scope;
    }

    /** Clé de routage par club (parité avec {@see GenerateScheduleMessage::getClubRoutingKey}). */
    public function getClubRoutingKey(): string
    {
        return 'club_id:' . $this->clubId;
    }
}
