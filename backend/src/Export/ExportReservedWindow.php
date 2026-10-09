<?php

declare(strict_types=1);

namespace App\Export;

use DateTimeImmutable;

/**
 * Lot 4bis — un CRÉNEAU LIBRE réservé (réservation sans équipe, `label`), surfacé dans l'export
 * PDF/XLSX comme une case OCCUPÉE nommée — jamais une case « vide » : la place est prise.
 */
final readonly class ExportReservedWindow
{
    public function __construct(
        public string $venueId,
        public int $dayOfWeek,
        public DateTimeImmutable $startTime,
        public int $durationMinutes,
        public string $label,
    ) {}
}
