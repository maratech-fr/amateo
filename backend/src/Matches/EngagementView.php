<?php

declare(strict_types=1);

namespace App\Matches;

/**
 * L'engagement d'une équipe dans une compétition, en lecture seule : son libellé
 * FBI, sa poule et ses journées attendues. Rendu par {@see CompetitionRegistry},
 * seule porte vers ces données.
 */
final readonly class EngagementView
{
    /**
     * @param list<string>|null $ffbbPouleOpponents
     */
    public function __construct(
        public string $competitionId,
        public string $teamId,
        public ?string $fbiTeamLabel,
        public ?string $ffbbPouleId,
        public ?string $ffbbPouleName,
        public ?array $ffbbPouleOpponents,
        public ?int $expectedMatchdays,
    ) {}
}
