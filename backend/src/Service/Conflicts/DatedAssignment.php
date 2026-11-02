<?php

declare(strict_types=1);

namespace App\Service\Conflicts;

/**
 * Le résultat de {@see DatedHolding::assign()} : qui tient quoi ce jour-là, ce qui reste sans
 * tenant, et le nombre de pas de la recherche.
 */
final readonly class DatedAssignment
{
    /**
     * @param array<string, string> $holders besoin → coach qui le tient
     * @param array<string, true>   $unheld  besoins sans tenant
     */
    public function __construct(
        public array $holders,
        public array $unheld,
        public int $steps,
    ) {}
}
