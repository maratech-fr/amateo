<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\MatchConstraint;
use App\Entity\Team;
use App\Entity\TeamMatchHabit;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\MatchWeek;
use App\Service\ClubRuleCoherenceChecker;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-272 ③ (ajout fondateur) — l'alerte de cohérence règles club ⇄ créneaux idéaux.
 * Le prédicat de collision vit ICI (maison unique) : lecture seule, rien stocké,
 * rien bloqué. On vérifie les deux projections et que HARD comme PREFERRED alertent.
 */
#[Group('phase1')]
final class ClubRuleCoherenceCheckerTest extends TestCase
{
    private ClubRuleCoherenceChecker $checker;

    public function testAHardRuleCollidingWithAnIdealSlotIsReportedBothWays(): void
    {
        // « Pas après 21h » le samedi ; le créneau idéal des U13M (semaine A) est
        // samedi 21:30 → il HEURTE la règle. Les deux projections le signalent.
        $rule = $this->rule('rule-1', ConstraintRuleType::HARD, [6], null, '21:00');
        $team = $this->team('team-1', 'U13M');
        $habit = $this->habit('habit-1', 'team-1', 6, '21:30', MatchWeek::A);

        $result = $this->checker->check([$rule], [$habit], [$team]);

        self::assertSame([
            ['ruleId' => 'rule-1', 'habits' => [
                ['teamId' => 'team-1', 'teamName' => 'U13M', 'week' => 'A', 'dayOfWeek' => 6, 'kickoff' => '21:30'],
            ]],
        ], $result['byRule']);
        self::assertSame([
            ['habitId' => 'habit-1', 'rules' => [
                ['ruleId' => 'rule-1', 'ruleType' => 'HARD', 'daysOfWeek' => [6], 'kickoffMin' => null, 'kickoffMax' => '21:00'],
            ]],
        ], $result['byHabit']);
    }

    public function testAConformingOrOffDayIdealSlotNeverCollides(): void
    {
        $rule = $this->rule('rule-1', ConstraintRuleType::HARD, [6], null, '21:00');
        $team = $this->team('team-1', 'U13M');
        // Conforme (samedi 20:00) ET hors jour couvert (dimanche 21:30) → aucune collision.
        $conforming = $this->habit('habit-1', 'team-1', 6, '20:00', MatchWeek::ALL);
        $offDay = $this->habit('habit-2', 'team-1', 7, '21:30', MatchWeek::ALL);

        $result = $this->checker->check([$rule], [$conforming, $offDay], [$team]);

        self::assertSame([], $result['byRule']);
        self::assertSame([], $result['byHabit']);
    }

    public function testAPreferredRuleAlsoAlerts(): void
    {
        // L'alerte de cohérence vaut pour HARD OU PREFERRED (décision fondateur).
        $rule = $this->rule('rule-1', ConstraintRuleType::PREFERRED, [6], null, '21:00');
        $team = $this->team('team-1', 'U13M');
        $habit = $this->habit('habit-1', 'team-1', 6, '21:30', MatchWeek::ALL);

        $result = $this->checker->check([$rule], [$habit], [$team]);

        self::assertCount(1, $result['byRule']);
        self::assertSame('PREFERRED', $result['byHabit'][0]['rules'][0]['ruleType']);
    }

    protected function setUp(): void
    {
        $this->checker = new ClubRuleCoherenceChecker;
    }

    /**
     * @param list<int> $days
     */
    private function rule(string $id, ConstraintRuleType $type, array $days, ?string $min, ?string $max): MatchConstraint
    {
        $rule = new MatchConstraint;
        $rule->setId($id);
        $rule->setScope(ConstraintScope::CLUB);
        $rule->setRuleType($type);
        $rule->setDaysOfWeek($days);
        $rule->setKickoffMin(null === $min ? null : new DateTimeImmutable($min));
        $rule->setKickoffMax(null === $max ? null : new DateTimeImmutable($max));

        return $rule;
    }

    private function team(string $id, string $name): Team
    {
        $team = new Team;
        $team->setId($id);
        $team->setName($name);

        return $team;
    }

    private function habit(string $id, string $teamId, int $day, string $kickoff, MatchWeek $week): TeamMatchHabit
    {
        $habit = new TeamMatchHabit;
        $habit->setId($id);
        $habit->setTeamId($teamId);
        $habit->setDayOfWeek($day);
        $habit->setKickoffTime(new DateTimeImmutable($kickoff));
        $habit->setWeek($week);

        return $habit;
    }
}
