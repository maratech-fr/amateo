<?php

declare(strict_types=1);

namespace App\Export;

/** Une semaine ISO (lundi → dimanche) du regroupement par jour : une page imprimée. */
final readonly class MatchesExportWeekSection
{
    /** @param list<MatchesExportDaySection> $days */
    public function __construct(
        /** « 2026-W46 » (`o-\WW`). */
        public string $isoWeek,
        public array $days,
    ) {}
}
