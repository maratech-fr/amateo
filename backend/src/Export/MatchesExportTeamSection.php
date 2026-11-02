<?php

declare(strict_types=1);

namespace App\Export;

/** Les matchs d'une équipe sur la plage ; `rows` vide = « Aucun match sur cette période. ». */
final readonly class MatchesExportTeamSection
{
    /** @param list<MatchesExportRow> $rows */
    public function __construct(
        public string $teamName,
        public array $rows,
    ) {}
}
