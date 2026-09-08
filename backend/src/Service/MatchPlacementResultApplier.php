<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fixture;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Writes the engine's placements back (P1-4 PR D). Concurrency rule: each
 * fixture is RELOADED and only written when the solver is still allowed to —
 * still UNPLACED, or still SOLVER-placed (re-solve). A manual gesture made
 * during the 3-second solve always wins (the optimistic `version` column plus
 * this recheck close the window).
 */
final class MatchPlacementResultApplier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param list<array{matchId: string, venueId: string, kickoff: string}> $placements
     *
     * @return array{applied: int, skipped: int}
     */
    public function apply(array $placements): array
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $applied = 0;
        $skipped = 0;
        foreach ($placements as $placement) {
            $fixture = $this->entityManager->getRepository(Fixture::class)->findOneBy(['id' => $placement['matchId']]);
            if (!$fixture instanceof Fixture || !$this->solverMayWrite($fixture)) {
                ++$skipped;
                continue;
            }
            $fixture->setVenueId($placement['venueId']);
            $fixture->setKickoffTime(new DateTimeImmutable($placement['kickoff']));
            // Placer = traiter (D6) : setStatus pose REVIEWED + horodaté (sauf écart pendant).
            $fixture->setStatus(FixtureStatus::PLACED, $now);
            $fixture->setPlacementSource(FixturePlacementSource::SOLVER);
            ++$applied;
        }
        if ($applied > 0) {
            $this->entityManager->flush();
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    private function solverMayWrite(Fixture $fixture): bool
    {
        if (FixtureStatus::UNPLACED === $fixture->getStatus()) {
            return true;
        }

        return FixtureStatus::PLACED === $fixture->getStatus()
            && FixturePlacementSource::SOLVER === $fixture->getPlacementSource();
    }
}
