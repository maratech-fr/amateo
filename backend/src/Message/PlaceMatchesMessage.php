<?php

declare(strict_types=1);

namespace App\Message;

use App\Controller\PlaceMatchesController;
use App\MessageHandler\PlaceMatchesHandler;
use App\Service\MatchPlacementLock;
use App\Service\MatchPlacementPayloadBuilder;
use App\Tests\CrossStack\PlacementLockOutlivesTheSolveTest;

/**
 * Demande de PLACEMENT ASYNCHRONE des matchs d'un club (rail de la génération). Enfilée
 * par {@see PlaceMatchesController} après ses gardes et l'acquisition du
 * verrou anti-double-demande, jouée par {@see PlaceMatchesHandler} dans le worker : le
 * solve quitte le rail synchrone (plafond HTTP) pour le worker, avec un budget de mur
 * dimensionné sur le nombre de semaines ISO à placer.
 *
 * Le verrou {@see MatchPlacementLock} est pris PAR LE CONTRÔLEUR (son TTL
 * couvre tout le run) et RELÂCHÉ par le worker : le `lockToken` voyage donc ici pour que
 * le worker puisse le rendre (compare-and-delete par token). `weeksCount` dimensionne le
 * timeout HTTP worker→engine comme le TTL du verrou (nb de semaines × budget/semaine + marge).
 *
 * `readonly` — porté par le transport `async` (PhpSerializer natif).
 */
final readonly class PlaceMatchesMessage
{
    /**
     * Marge FIXE du budget de mur (aller-retour HTTP, sérialisation du payload, import du
     * résultat) — indépendante du nombre de semaines.
     */
    public const int LOCK_TTL_MARGIN_SECONDS = 60;

    /**
     * Surcoût PAR SEMAINE côté moteur, au-dessus du budget de solve (`WEEK_BUDGET_SECONDS`) :
     * le moteur borne le BUILD de chaque sous-modèle hebdomadaire à `BUILD_BUDGET_SECONDS`
     * (10 s, `engine/app/solver/match_placement.py`). Dans le pire cas une semaine dépense
     * donc build + solve. On réserve 15 s > 10 s pour couvrir ce plafond avec un coussin.
     *
     * ⚠ L'invariant {@see PlacementLockOutlivesTheSolveTest} : le TTL du
     * verrou (= ce budget) survit TOUJOURS au solve — sinon un second placement prendrait le
     * verrou pendant que le worker attend l'engine, deux imports se marcheraient dessus
     * (corruption silencieuse, patron D-05 de la génération). Ce test lit le moteur : baisser
     * ce surcoût sous le plafond de build fait rougir la CI, pas se découvrir en production.
     */
    public const int ENGINE_PER_WEEK_OVERHEAD_SECONDS = 15;

    /**
     * @param array{from: string, to: string}|null $window fenêtre de placement optionnelle (dates Y-m-d incluses)
     */
    public function __construct(
        private string $runId,
        private string $clubId,
        private ?string $seasonId,
        private int $weeksCount,
        private string $lockToken,
        private ?array $window = null,
    ) {}

    /**
     * Le budget de mur du run (secondes) = nb de semaines ISO à placer × budget par semaine
     * (le moteur découpe par semaine, ENG-50) + la marge. Sert AU CONTRÔLEUR pour dimensionner
     * le TTL du verrou à l'acquisition, et AU WORKER pour le timeout HTTP vers l'engine — une
     * seule formule, les deux ne peuvent pas diverger.
     */
    public static function budgetSecondsFor(int $weeksCount): int
    {
        $perWeek = MatchPlacementPayloadBuilder::WEEK_BUDGET_SECONDS + self::ENGINE_PER_WEEK_OVERHEAD_SECONDS;

        return max(1, $weeksCount) * $perWeek + self::LOCK_TTL_MARGIN_SECONDS;
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getClubId(): string
    {
        return $this->clubId;
    }

    public function getSeasonId(): ?string
    {
        return $this->seasonId;
    }

    public function getWeeksCount(): int
    {
        return $this->weeksCount;
    }

    public function getLockToken(): string
    {
        return $this->lockToken;
    }

    /** @return array{from: string, to: string}|null */
    public function getWindow(): ?array
    {
        return $this->window;
    }

    /** Clé de routage par club (parité avec {@see GenerateScheduleMessage::getClubRoutingKey}). */
    public function getClubRoutingKey(): string
    {
        return 'club_id:' . $this->clubId;
    }

    /** Le budget de mur de CE run (timeout HTTP worker→engine). */
    public function budgetSeconds(): int
    {
        return self::budgetSecondsFor($this->weeksCount);
    }
}
