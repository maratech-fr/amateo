<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conflicts;

use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureUnplacedReason;
use App\Service\Conflicts\DayMatches;
use App\Service\MatchDurationProfile;
use App\Service\MatchFootprint;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Promesse : les matchs d'une date portent une seule fenêtre de personne, calculée au même endroit
 * pour le radar et, demain, pour le placement : à domicile sans échauffement, à l'extérieur avec
 * trajet et échauffement, y compris sur une heure estimée, et le terrain neutre comme un extérieur.
 */
#[Group('unit')]
final class DayMatchesTest extends TestCase
{
    private const string FRIDAY = '2026-10-02';

    public function testAHomeMatchHoldsFromKickoffToTheEndOfTheMatch(): void
    {
        $rows = $this->forFriday([$this->view('fx-1', 'team-1', FixtureHomeAway::HOME, self::FRIDAY, '20:30', ['coach-a'])]);

        self::assertCount(1, $rows);
        self::assertSame('fx-1', $rows[0]['fixtureId']);
        self::assertSame('team-1', $rows[0]['teamId']);
        self::assertSame(['coach-a'], $rows[0]['personIds']);
        self::assertSame(20 * 60 + 30, $rows[0]['startMinutes']);
        self::assertSame(20 * 60 + 30 + 105, $rows[0]['endMinutes']);
    }

    public function testAnAwayMatchCountsItsTravelAndWarmUp(): void
    {
        // Aller-retour 60 min : 30 aller + 30 échauffement avant 15:00, 105 + 30 retour après.
        $rows = $this->forFriday([$this->view('fx-1', 'team-1', FixtureHomeAway::AWAY, self::FRIDAY, '15:00', [], 60)]);

        self::assertSame(14 * 60, $rows[0]['startMinutes']);
        self::assertSame(15 * 60 + 105 + 30, $rows[0]['endMinutes']);
    }

    public function testAnEstimatedAwayKickoffKeepsTheSameWindow(): void
    {
        $fixture = $this->fixture('fx-1', 'team-1', FixtureHomeAway::AWAY, self::FRIDAY, null);
        $kickoff = new DateTimeImmutable('16:00');
        $footprint = new MatchFootprint;
        $profile = MatchDurationProfile::fallback();
        $view = $this->assemble(
            $fixture,
            $footprint->occupancyAt($fixture, $kickoff, $profile),
            $footprint->venueOccupancyAt($fixture, $kickoff, $profile),
            $footprint->personConflictOccupancyAt($fixture, $kickoff, $profile),
            [],
            true,
            '16:00',
        );

        $rows = $this->forFriday([$view]);

        self::assertSame(15 * 60 + 30, $rows[0]['startMinutes']);
        self::assertSame(16 * 60 + 105, $rows[0]['endMinutes']);
    }

    public function testANeutralGroundMatchCountsItsWarmUpLikeAnAwayMatch(): void
    {
        $view = $this->view('fx-1', 'team-1', FixtureHomeAway::HOME, self::FRIDAY, '15:00', []);
        $view['fixture']->setUnplacedReason(FixtureUnplacedReason::NEUTRAL_GROUND);

        $rows = $this->forFriday([$view]);

        self::assertSame(14 * 60 + 30, $rows[0]['startMinutes'], 'l\'échauffement compte : l\'équipe n\'est pas chez elle');
        self::assertSame(15 * 60 + 105, $rows[0]['endMinutes']);
    }

    public function testAMatchOfAnotherDateIsNotInTheDay(): void
    {
        $rows = $this->forFriday([
            $this->view('fx-1', 'team-1', FixtureHomeAway::HOME, self::FRIDAY, '20:30', []),
            $this->view('fx-2', 'team-1', FixtureHomeAway::HOME, '2026-10-03', '20:30', []),
        ]);

        self::assertSame(['fx-1'], array_column($rows, 'fixtureId'));
    }

    /**
     * @param list<array<string, mixed>> $views
     *
     * @return list<array<string, mixed>>
     */
    private function forFriday(array $views): array
    {
        return new DayMatches()->forDate(new DateTimeImmutable(self::FRIDAY), $views);
    }

    /**
     * @param list<string> $personIds
     *
     * @return array<string, mixed>
     */
    private function view(string $id, string $teamId, FixtureHomeAway $homeAway, string $date, string $kickoff, array $personIds, int $roundTrip = 0): array
    {
        $fixture = $this->fixture($id, $teamId, $homeAway, $date, $kickoff);
        $footprint = new MatchFootprint;
        $profile = MatchDurationProfile::fallback();

        return $this->assemble(
            $fixture,
            $footprint->occupancy($fixture, $profile, $roundTrip),
            $footprint->venueOccupancy($fixture, $profile),
            $footprint->personConflictOccupancy($fixture, $profile, $roundTrip),
            $personIds,
        );
    }

    /**
     * @param array<string, DateTimeImmutable>|null $window
     * @param array<string, DateTimeImmutable>|null $venueWindow
     * @param array<string, DateTimeImmutable>|null $conflictWindow
     * @param list<string>                          $personIds
     *
     * @return array<string, mixed>
     */
    private function assemble(Fixture $fixture, ?array $window, ?array $venueWindow, ?array $conflictWindow, array $personIds, bool $estimated = false, ?string $estimatedKickoff = null): array
    {
        return [
            'fixture' => $fixture,
            'window' => $window,
            'venueWindow' => $venueWindow,
            'conflictWindow' => $conflictWindow,
            'estimated' => $estimated,
            'estimatedKickoffTime' => $estimatedKickoff,
            'travelOneWayMinutes' => null,
            'matchDurationMinutes' => 105,
            'personIds' => $personIds,
        ];
    }

    private function fixture(string $id, string $teamId, FixtureHomeAway $homeAway, string $date, ?string $kickoff): Fixture
    {
        $fixture = new Fixture;
        new ReflectionProperty($fixture, 'id')->setValue($fixture, $id);
        $fixture->setTeamId($teamId);
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel('Adv');
        $fixture->setKickoffTime(null === $kickoff ? null : (DateTimeImmutable::createFromFormat('!H:i', $kickoff) ?: null));

        return $fixture;
    }
}
