<?php

declare(strict_types=1);

namespace App\Tests\Unit\Seed;

use App\Seed\LoadTestMatchPlan;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Le calendrier de championnat fictif : déterministe, ~1 match/WE/équipe,
 * alternance ~50 % pour qui reçoit, extérieur seul sans habitude, phases
 * jeunes (3) vs seniors (2), jours samedi/dimanche, part de fixes ~15-20 %.
 */
#[Group('phase1')]
final class LoadTestMatchPlanTest extends TestCase
{
    private const int WEEKENDS = 18;

    public function testEmptyInputsYieldNoMatches(): void
    {
        self::assertSame([], LoadTestMatchPlan::build([], $this->weekends(), ['Adv']));
        self::assertSame([], LoadTestMatchPlan::build([$this->team('t', true, true)], [], ['Adv']));
        self::assertSame([], LoadTestMatchPlan::build([$this->team('t', true, true)], $this->weekends(), []));
    }

    public function testOneMatchPerWeekendPerTeam(): void
    {
        $teams = [$this->team('youth-home', true, true), $this->team('senior-away', false, false)];
        $matches = LoadTestMatchPlan::build($teams, $this->weekends(), $this->opponents());

        self::assertCount(2 * self::WEEKENDS, $matches);
        foreach (['youth-home', 'senior-away'] as $teamId) {
            self::assertCount(self::WEEKENDS, array_filter($matches, static fn (array $m): bool => $m['teamId'] === $teamId));
        }
    }

    public function testTeamWithoutHabitPlaysAwayOnly(): void
    {
        $matches = LoadTestMatchPlan::build([$this->team('no-habit', true, false)], $this->weekends(), $this->opponents());

        self::assertNotEmpty($matches);
        foreach ($matches as $match) {
            self::assertSame('AWAY', $match['homeAway'], 'une équipe sans habitude ne joue qu\'à l\'extérieur');
            self::assertFalse($match['fixed']);
            self::assertNull($match['venueId']);
        }
    }

    public function testHomeAwayAlternatesAroundFiftyPercentForReceivingTeam(): void
    {
        // Plusieurs équipes : la parité de départ dépend de l'index, on couvre les deux.
        $teams = [];
        for ($i = 0; $i < 6; ++$i) {
            $teams[] = $this->team('home-' . $i, 0 === $i % 2, true);
        }
        $matches = LoadTestMatchPlan::build($teams, $this->weekends(), $this->opponents());

        $home = \count(array_filter($matches, static fn (array $m): bool => 'HOME' === $m['homeAway']));
        $total = \count($matches);
        $ratio = $home / $total;
        self::assertGreaterThanOrEqual(0.40, $ratio, 'les équipes qui reçoivent alternent ~50 % domicile');
        self::assertLessThanOrEqual(0.60, $ratio);
    }

    public function testYouthHasThreePhasesSeniorsTwo(): void
    {
        $matches = LoadTestMatchPlan::build(
            [$this->team('youth', true, true), $this->team('senior', false, true)],
            $this->weekends(),
            $this->opponents(),
        );

        $phasesOf = static function (string $teamId) use ($matches): array {
            $phases = [];
            foreach ($matches as $m) {
                if ($m['teamId'] === $teamId) {
                    $phases[$m['phase']] = true;
                }
            }
            ksort($phases);

            return array_keys($phases);
        };

        self::assertSame([1, 2, 3], $phasesOf('youth'), 'les jeunes jouent 3 phases (aller simple)');
        self::assertSame([1, 2], $phasesOf('senior'), 'les seniors jouent en aller-retour (2 phases)');
    }

    public function testEveryMatchFallsOnSaturdayOrSunday(): void
    {
        $matches = LoadTestMatchPlan::build(
            [$this->team('sat', true, true, 6), $this->team('sun', false, true, 7)],
            $this->weekends(),
            $this->opponents(),
        );

        $seenDays = [];
        foreach ($matches as $match) {
            self::assertContains($match['dayOfWeek'], [6, 7]);
            self::assertSame($match['dayOfWeek'], (int) new DateTimeImmutable($match['date'])->format('N'));
            self::assertNotSame('', $match['date']);
            self::assertContains($match['opponentLabel'], $this->opponents());
            $seenDays[$match['dayOfWeek']] = true;
        }
        self::assertArrayHasKey(6, $seenDays);
        self::assertArrayHasKey(7, $seenDays);
    }

    public function testFixedHomeMatchesAreRoughlyFifteenToTwentyPercent(): void
    {
        // Assez d'équipes qui reçoivent pour que la part des fixes se stabilise.
        $teams = [];
        for ($i = 0; $i < 20; ++$i) {
            $teams[] = $this->team('fx-' . $i, 0 === $i % 2, true);
        }
        $matches = LoadTestMatchPlan::build($teams, $this->weekends(), $this->opponents());

        $home = array_filter($matches, static fn (array $m): bool => 'HOME' === $m['homeAway']);
        $fixed = array_filter($home, static fn (array $m): bool => $m['fixed']);
        self::assertNotEmpty($home);
        $ratio = \count($fixed) / \count($home);
        self::assertGreaterThanOrEqual(0.08, $ratio, 'part des matchs déjà fixés dans la fourchette attendue');
        self::assertLessThanOrEqual(0.28, $ratio);

        // Un match fixé porte un gymnase + un coup d'envoi ; jamais un extérieur.
        foreach ($fixed as $match) {
            self::assertNotNull($match['venueId']);
            self::assertNotNull($match['kickoff']);
            self::assertSame('HOME', $match['homeAway']);
        }
    }

    /**
     * @return array{teamId: string, isYouth: bool, hasHabit: bool, idealDay: int, idealKickoff: string, idealVenueId: string|null}
     */
    private function team(string $id, bool $isYouth, bool $hasHabit, int $idealDay = 6): array
    {
        return [
            'teamId' => $id,
            'isYouth' => $isYouth,
            'hasHabit' => $hasHabit,
            'idealDay' => $idealDay,
            'idealKickoff' => '15:00',
            'idealVenueId' => $hasHabit ? 'venue-' . $id : null,
        ];
    }

    /**
     * @return list<array{saturday: string, sunday: string}>
     */
    private function weekends(): array
    {
        $saturday = new DateTimeImmutable('2026-09-05');
        $weekends = [];
        for ($w = 0; $w < self::WEEKENDS; ++$w) {
            $sat = $saturday->modify(\sprintf('+%d days', 7 * $w));
            $weekends[] = ['saturday' => $sat->format('Y-m-d'), 'sunday' => $sat->modify('+1 day')->format('Y-m-d')];
        }

        return $weekends;
    }

    /**
     * @return list<string>
     */
    private function opponents(): array
    {
        return ['Adv A', 'Adv B', 'Adv C', 'Adv D', 'Adv E'];
    }
}
