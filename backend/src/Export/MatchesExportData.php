<?php

declare(strict_types=1);

namespace App\Export;

use DateTimeImmutable;

/** Tout ce que le PDF des matchs imprime : `MatchesPdfGenerator::buildHtml` ne lit rien d'autre. */
final readonly class MatchesExportData
{
    /** @param list<MatchesExportTeamSection> $sections */
    public function __construct(
        public string $clubId,
        public string $clubName,
        public ?string $logoDataUri,
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public DateTimeImmutable $editedOn,
        public ?string $teamScopeName,
        public bool $hasTravel,
        public array $sections,
    ) {}
}
