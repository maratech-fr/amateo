<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fixture;
use App\Entity\TeamMatchHabit;
use App\Enum\FixtureHomeAway;
use DateTimeImmutable;

/**
 * THE rule « which kickoff does an hour-less AWAY fixture borrow » (P1-4 PR C),
 * extracted (PR D) because it now has TWO consumers — the conflict radar and
 * the match-placement payload — and two copies of an estimation rule
 * inevitably diverge.
 *
 * An AWAY fixture without a real hour borrows its team's HABITUAL kickoff when
 * a habit exists on the match's weekday; otherwise it stays footprint-less
 * (the residual blind spot, named in PR E's graded diagnostic). A real hour is
 * never overridden; unplaced HOME fixtures are never estimated.
 */
final class AwayKickoffEstimator
{
    /**
     * @param array<string, array<int, TeamMatchHabit>> $habitByTeamDay teamId → ISO day → habit
     */
    public function estimate(Fixture $fixture, array $habitByTeamDay): ?DateTimeImmutable
    {
        if (FixtureHomeAway::AWAY !== $fixture->getHomeAway() || $fixture->getKickoffTime() instanceof DateTimeImmutable) {
            return null;
        }
        $habit = $habitByTeamDay[$fixture->getTeamId()][(int) $fixture->getMatchDate()->format('N')] ?? null;

        return $habit?->getKickoffTime();
    }

    /**
     * @param list<TeamMatchHabit> $habits
     *
     * @return array<string, array<int, TeamMatchHabit>>
     */
    public function indexHabits(array $habits): array
    {
        $byTeamDay = [];
        foreach ($habits as $habit) {
            $byTeamDay[$habit->getTeamId()][$habit->getDayOfWeek()] = $habit;
        }

        return $byTeamDay;
    }
}
