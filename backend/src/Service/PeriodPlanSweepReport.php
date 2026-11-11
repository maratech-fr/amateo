<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Ce qu'un passage de {@see PeriodPlanAutoValidator::sweepClub} a fait (ou ferait, en `--dry-run`)
 * pour un club : les plans pointés (version retenue, sœurs gardées), ceux dont le 7ᵉ jour est
 * atteint sans version terminée, ceux sautés parce qu'une génération est en vol.
 */
final readonly class PeriodPlanSweepReport
{
    /**
     * @param list<array{planId: string, scheduleId: string, keptVersions: int}> $validated
     * @param list<string>                                                       $startedWithoutPlan
     * @param list<string>                                                       $skippedInFlight
     */
    public function __construct(
        public array $validated = [],
        public array $startedWithoutPlan = [],
        public array $skippedInFlight = [],
    ) {}
}
