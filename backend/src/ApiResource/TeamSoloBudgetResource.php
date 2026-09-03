<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Service\SoloBudget;
use App\State\Provider\TeamSoloBudgetStateProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Le budget de réservation INDIVIDUELLE d'une équipe, par portée. Lecture seule, scopée club+saison
 * et filtrée par ``schedulePlanId`` (absent/NULL = socle saison ; un UUID = plan de période, deux
 * mondes distincts). Le front s'en sert pour n'offrir en réservation individuelle que ce qui reste
 * réservable : une équipe dont toutes les séances viennent de ses blocs (``residual`` 0 et
 * ``inBlock``) n'apparaît que via ses blocs ; sinon elle porte son ``residual`` en étiquette.
 */
#[ApiResource(shortName: 'TeamSoloBudget', operations: [
    new GetCollection,
], paginationEnabled: false, provider: TeamSoloBudgetStateProvider::class)]
class TeamSoloBudgetResource
{
    /** L'équipe — identifiant de la ligne dans la portée demandée. */
    #[ApiProperty(identifier: true)]
    #[Groups(['read'])]
    public string $teamId = '';

    #[Groups(['read'])]
    public ?string $schedulePlanId = null;

    /** S(T) — séances hebdomadaires effectives (override de période inclus). */
    #[Groups(['read'])]
    public int $effectiveSessions = 0;

    /** B(T) — Σ des séances communes des blocs de la portée contenant l'équipe. */
    #[Groups(['read'])]
    public int $blockSessions = 0;

    /** R(T) = S(T) − B(T) — le nombre maximal de réservations individuelles. */
    #[Groups(['read'])]
    public int $residual = 0;

    /** Réservations individuelles déjà posées (hors cases bloc-complètes). */
    #[Groups(['read'])]
    public int $individualUsed = 0;

    /** L'équipe est membre d'au moins un bloc de la portée. */
    #[Groups(['read'])]
    public bool $inBlock = false;

    public static function from(SoloBudget $budget, ?string $schedulePlanId): self
    {
        $dto = new self;
        $dto->teamId = $budget->teamId;
        $dto->schedulePlanId = $schedulePlanId;
        $dto->effectiveSessions = $budget->effective;
        $dto->blockSessions = $budget->block;
        $dto->residual = $budget->residual;
        $dto->individualUsed = $budget->individualUsed;
        $dto->inBlock = $budget->inBlock;

        return $dto;
    }
}
