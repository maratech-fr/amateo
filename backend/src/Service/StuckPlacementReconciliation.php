<?php

declare(strict_types=1);

namespace App\Service;

use Countable;

/** Ce qu'une passe du rattrapage des runs de placement figés a trouvé (ou fait, hors dry-run). */
final readonly class StuckPlacementReconciliation implements Countable
{
    /**
     * @param list<string> $runningRunIds  runs RUNNING déclarés (ou à déclarer) « interrompus »
     * @param list<string> $pendingRunIds  runs PENDING absents de la file, déclarés (ou à déclarer) « interrompus »
     * @param list<string> $skippedClubIds clubs sautés après une erreur (journalisée)
     * @param bool         $queueInspected la file a-t-elle été lue (sinon le bras PENDING a été sauté)
     */
    public function __construct(
        public array $runningRunIds,
        public array $pendingRunIds,
        public array $skippedClubIds,
        public bool $queueInspected,
    ) {}

    public function count(): int
    {
        return \count($this->runningRunIds) + \count($this->pendingRunIds);
    }
}
