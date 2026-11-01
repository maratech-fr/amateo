<?php

declare(strict_types=1);

namespace App\Export;

use DateTimeImmutable;

/** Une ligne du document : ce qu'un parent lit d'un match, sans statut ni conflit. */
final readonly class MatchesExportRow
{
    public function __construct(
        public DateTimeImmutable $date,
        /** « H:i », null quand l'heure n'est pas connue. */
        public ?string $kickoff,
        public bool $toConfirm,
        public string $opponent,
        public bool $friendly,
        /** @var 'home'|'away'|'neutral' */
        public string $where,
        public ?MatchesExportPlace $place,
    ) {}
}
