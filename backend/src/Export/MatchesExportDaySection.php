<?php

declare(strict_types=1);

namespace App\Export;

use DateTimeImmutable;

/** Les matchs d'un jour, regroupement par jour : un en-tête daté puis ses lignes. */
final readonly class MatchesExportDaySection
{
    /** @param list<MatchesExportRow> $rows */
    public function __construct(
        public DateTimeImmutable $date,
        public array $rows,
    ) {}
}
