<?php

declare(strict_types=1);

namespace App\Service;

/**
 * La demande composée pour une version : `snapshot` = l'entrée figée (ce que porte
 * `Schedule::snapshotData` et ce que hache `snapshotHash`), `input` = ce qui part au moteur
 * (le snapshot plus les greffes de convergence, qui ajoutent des clés sans en modifier une).
 */
final readonly class ComposedEngineRequest
{
    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $input
     */
    public function __construct(
        public array $snapshot,
        public array $input,
        public bool $isSeasonPlan,
    ) {}
}
