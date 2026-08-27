<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\LeagueMatchWindow;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueUnavailability;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\TeamCoachRole;
use App\Enum\TeamLinkType;
use App\Service\AwayKickoffEstimator;
use App\Service\EffectiveScheduleResolver;
use App\Service\MatchConflictDetector;
use App\Service\MatchDurationProfile;
use App\Service\MatchFootprint;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Same-coach conflict detection (spec gestion-matchs PR-2): match↔match,
 * match↔training against the schedule effective on the match date, half-open
 * overlap, away-without-kickoff ignored.
 */
#[Group('unit')]
final class MatchConflictDetectorTest extends TestCase
{
    private const COACH_A = 'coach-a';
    private const COACH_B = 'coach-b';
    private const TEAM_1 = 'team-1';
    private const TEAM_2 = 'team-2';
    private const BASELINE = 'sched-baseline';
    private const OVERLAY = 'sched-overlay';

    public function testTwoMatchesOfSameCoachOverlappingRaiseOneConflict(): void
    {
        // Coach A coaches team-1 AND team-2; both play overlapping windows.
        $fixtures = [
            $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00'), // 15:30–17:45
            $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '16:30'), // 16:00–18:15
        ];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        $conflicts = $this->detect($fixtures, $links);

        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_MATCH', $conflicts[0]['type']);
        self::assertSame(self::COACH_A, $conflicts[0]['coachId']);
    }

    public function testDisjointMatchesRaiseNoConflict(): void
    {
        $fixtures = [
            $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '10:00'), // 09:30–11:45
            $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '16:00'), // 15:30–17:45
        ];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        self::assertSame([], $this->detect($fixtures, $links));
    }

    public function testBackToBackWindowsDoNotConflict(): void
    {
        // fx-1 ends 17:45 ; fx-2 (away, no travel) starts 17:45 → half-open, no clash.
        $fixtures = [
            $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00'), // home 15:30–17:45
            $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '18:15'), // home 17:45–20:00
        ];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        self::assertSame([], $this->detect($fixtures, $links));
    }

    public function testAwayFixtureWithoutKickoffIsIgnored(): void
    {
        $fixtures = [
            $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00'),
            $this->fixture('fx-2', self::TEAM_2, '2026-10-04', null), // no footprint
        ];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        self::assertSame([], $this->detect($fixtures, $links));
    }

    public function testDifferentCoachesNeverConflict(): void
    {
        $fixtures = [
            $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00'),
            $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '16:30'), // overlapping windows
        ];
        // team-1 → coach A, team-2 → coach B: no shared coach.
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_B, self::TEAM_2)];

        self::assertSame([], $this->detect($fixtures, $links));
    }

    public function testMatchOverlappingBaselineTrainingSameWeekdayConflicts(): void
    {
        // 2026-10-04 is a Sunday (ISO 7). Coach A's team-1 trains Sunday 17:00–18:30,
        // the match runs 15:30–17:45 → overlap.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_1, 7, '17:00', 90)];

        $conflicts = $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]);

        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_TRAINING', $conflicts[0]['type']);
        self::assertSame('sl-1', $conflicts[0]['training']['slotTemplateId']);
    }

    public function testTrainingOnDifferentWeekdayDoesNotConflict(): void
    {
        // Slot on Monday (1) but the match is Sunday → projection excludes it.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_1, 1, '17:00', 90)];

        self::assertSame([], $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]));
    }

    public function testTrainingOfAnotherCoachesTeamDoesNotConflict(): void
    {
        // The overlapping Sunday slot belongs to team-2, which coach A does NOT coach.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '17:00', 90)];

        self::assertSame([], $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]));
    }

    public function testOverlayPlanReplacesBaselineOnCoveredDate(): void
    {
        // The match date falls in an active period with an overlay: the overlay
        // slot (overlapping) drives the conflict, the baseline slot is ignored.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $overlayPeriods = [[
            'start' => new DateTimeImmutable('2026-10-01'),
            'end' => new DateTimeImmutable('2026-10-31'),
            'scheduleId' => self::OVERLAY,
        ]];
        $slotsBySchedule = [
            self::BASELINE => [$this->slot('base-sl', self::BASELINE, self::TEAM_1, 7, '17:00', 90)],
            self::OVERLAY => [$this->slot('ovl-sl', self::OVERLAY, self::TEAM_1, 7, '17:00', 90)],
        ];

        $conflicts = $this->detect($fixtures, $links, self::BASELINE, $overlayPeriods, $slotsBySchedule);

        self::assertCount(1, $conflicts);
        self::assertSame('ovl-sl', $conflicts[0]['training']['slotTemplateId']);
    }

    public function testNoBaselineYieldsNoTrainingConflict(): void
    {
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_1, 7, '17:00', 90)];

        // No baseline scheduleId → nothing to resolve → no training conflict.
        self::assertSame([], $this->detect($fixtures, $links, null, [], [self::BASELINE => $slots]));
    }

    public function testActivePeriodWithoutOverlaySuspendsBaselineTraining(): void
    {
        // A closure/holiday recorded as an active period with NO overlay (training
        // suspended, plan not regenerated) captures the date → the baseline slot is
        // NOT checked, so no phantom conflict against a cancelled training.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $activePeriods = [[
            'start' => new DateTimeImmutable('2026-10-01'),
            'end' => new DateTimeImmutable('2026-10-31'),
            'scheduleId' => null, // period active but no overlay generated
        ]];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_1, 7, '17:00', 90)];

        self::assertSame([], $this->detect($fixtures, $links, self::BASELINE, $activePeriods, [self::BASELINE => $slots]));
    }

    public function testFootprintCrossingMidnightChecksNextDaySlots(): void
    {
        // Home match 23:00 on Sunday → footprint 22:30–00:45 (Monday). A Monday
        // 00:00–01:00 training of the coach's team overlaps past midnight and must
        // be caught even though the match date's weekday is Sunday.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '23:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-mon', self::BASELINE, self::TEAM_1, 1, '00:00', 60)]; // Monday 00:00–01:00

        $conflicts = $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]);

        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_TRAINING', $conflicts[0]['type']);
        self::assertSame('sl-mon', $conflicts[0]['training']['slotTemplateId']);
    }

    public function testAssignedSlotCoachDoesNotFlagCoCoaches(): void
    {
        // Team-1 has two coaches A and B; the overlapping Sunday slot is assigned to
        // A only. Only A is double-booked — B (who does not run this slot) must not
        // be flagged.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_B, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_1, 7, '17:00', 90, self::COACH_A)];

        $conflicts = $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]);

        self::assertCount(1, $conflicts);
        self::assertSame(self::COACH_A, $conflicts[0]['coachId']);
    }

    public function testVenueUnavailableFlagsAPlacedFixtureInsideTheRange(): void
    {
        // The real-life case the placement guard cannot catch: placed on
        // 2027-02-14, the closure is posed AFTERWARDS (P1-4 PR B).
        $fixture = $this->fixture('fx-1', self::TEAM_1, '2027-02-14', '15:30');
        $fixture->setVenueId('venue-armand');
        $unavailability = $this->unavailability('venue-armand', '2027-02-04', '2027-02-28', 'travaux');

        $conflicts = $this->detect([$fixture], [], null, [], [], [$unavailability]);

        self::assertCount(1, $conflicts);
        self::assertSame('VENUE_UNAVAILABLE', $conflicts[0]['type']);
        self::assertSame('travaux', $conflicts[0]['label']);
        self::assertSame('fx-1', $conflicts[0]['fixture']['fixtureId']);
    }

    public function testVenueUnavailableBoundsAreInclusiveAndKickoffFree(): void
    {
        // « du 4 au 28 » covers the 28th; a venue-holding fixture without a
        // kickoff (no footprint) is affected too — the DATE suffices.
        $lastDay = $this->fixture('fx-1', self::TEAM_1, '2027-02-28', null);
        $lastDay->setVenueId('venue-armand');
        $dayAfter = $this->fixture('fx-2', self::TEAM_1, '2027-03-01', '15:30');
        $dayAfter->setVenueId('venue-armand');
        $otherVenue = $this->fixture('fx-3', self::TEAM_1, '2027-02-14', '15:30');
        $otherVenue->setVenueId('venue-mateo');
        $unplaced = $this->fixture('fx-4', self::TEAM_1, '2027-02-14', null); // no venue

        $conflicts = $this->detect(
            [$lastDay, $dayAfter, $otherVenue, $unplaced],
            [],
            null,
            [],
            [],
            [$this->unavailability('venue-armand', '2027-02-04', '2027-02-28', null)],
        );

        self::assertCount(1, $conflicts);
        self::assertSame('fx-1', $conflicts[0]['fixture']['fixtureId']);
    }

    // ── Estimation d'heure extérieure + passerelles (P1-4 PR C) ─────────────

    public function testAwayWithoutKickoffBorrowsTheHabitOfItsWeekday(): void
    {
        // 2026-10-04 is a Sunday; SF3's habit = Sunday 17:30. The away match
        // gains an estimated footprint (17:00→20:00 + away extras) and the
        // coach's 18:00 training conflict becomes VISIBLE, flagged estimated.
        $away = $this->awayFixture('fx-1', self::TEAM_1, '2026-10-04', null);
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_1, 7, '18:00', 90, self::COACH_A)];

        $conflicts = $this->detect(
            [$away],
            $links,
            self::BASELINE,
            [],
            [self::BASELINE => $slots],
            [],
            [$this->habit(self::TEAM_1, 7, '17:30')],
        );

        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_TRAINING', $conflicts[0]['type']);
        self::assertTrue($conflicts[0]['fixture']['estimatedKickoff']);
        self::assertNull($conflicts[0]['fixture']['kickoffTime']); // nothing persisted
    }

    public function testAwayWithoutHabitOnThatWeekdayHasNoFootprintButIsNamed(): void
    {
        // NR of the PR-2 contract, amended by PR E2 (dette v): no habit on the
        // match's weekday → no estimation, no footprint, NO time conflict — but
        // the blind spot is now NAMED (AWAY_NO_FOOTPRINT, severity 7 info)
        // instead of being a silence the manager would mistake for health.
        $away = $this->awayFixture('fx-1', self::TEAM_1, '2026-10-04', null); // Sunday
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_1, 7, '18:00', 90, self::COACH_A)];

        $conflicts = $this->detect(
            [$away],
            $links,
            self::BASELINE,
            [],
            [self::BASELINE => $slots],
            [],
            [$this->habit(self::TEAM_1, 6, '15:30')], // Saturday habit only
        );

        self::assertCount(1, $conflicts);
        self::assertSame('AWAY_NO_FOOTPRINT', $conflicts[0]['type']);
        self::assertSame(7, $conflicts[0]['severity']);
        self::assertSame('fx-1', $conflicts[0]['fixture']['fixtureId']);
    }

    // ── Graded diagnostic (P1-4 PR E2, cadrage §8) ───────────────────────────

    public function testVenueOverlapIsTheLoudestFinding(): void
    {
        // Two placed matches, SAME venue, overlapping footprints — the manual
        // loop let it happen (never blocking), the diagnostic screams severity 1.
        $left = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '15:00');
        $left->setVenueId('venue-mateo');
        $right = $this->fixture('fx-2', self::TEAM_2, '2026-10-03', '16:00');
        $right->setVenueId('venue-mateo');
        $elsewhere = $this->fixture('fx-3', self::TEAM_1, '2026-10-03', '15:00');
        $elsewhere->setVenueId('venue-coubertin');

        $conflicts = $this->detect([$left, $right, $elsewhere], []);

        self::assertCount(1, $conflicts);
        self::assertSame('VENUE_OVERLAP', $conflicts[0]['type']);
        self::assertSame(1, $conflicts[0]['severity']);
        self::assertSame('venue-mateo', $conflicts[0]['venueId']);
    }

    public function testLeagueWindowViolationOnlyForMappedTeams(): void
    {
        // Sunday 17:30 vs a Saturday-only envelope → severity 2. The unmapped
        // team placed the same way stays SILENT (tolerant join, PR D decision).
        $mapped = $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '17:30'); // Sunday
        $mapped->setVenueId('venue-mateo');
        $unmapped = $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '17:30');
        $unmapped->setVenueId('venue-mateo');
        $envelope = [self::TEAM_1 => [$this->leagueWindow(6, '14:00', '20:00')], self::TEAM_2 => []];

        $conflicts = $this->detect([$mapped], [], null, [], [], [], [], [], [], $envelope);
        self::assertSame(['LEAGUE_WINDOW_VIOLATION'], array_column($conflicts, 'type'));
        self::assertSame(2, $conflicts[0]['severity']);
        self::assertSame('fx-1', $conflicts[0]['fixture']['fixtureId']);

        // Inside the window → nothing. Unmapped → nothing.
        $inside = $this->fixture('fx-3', self::TEAM_1, '2026-10-03', '15:00'); // Saturday
        $inside->setVenueId('venue-mateo');
        self::assertSame([], $this->detect([$inside, $unmapped], [], null, [], [], [], [], [], [], $envelope));
    }

    public function testAccessWindowLostFollowsThePanelRule(): void
    {
        // Placed Saturday 15:00, then the mairie window moved to 18:00-20:00 →
        // severity 4 (dette ii: the guard could not see a change made AFTER).
        $placed = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '15:00');
        $placed->setVenueId('venue-mateo');

        $moved = [$this->matchWindow('venue-mateo', 6, '18:00', '20:00')];
        $conflicts = $this->detect([$placed], [], null, [], [], [], [], [], $moved);
        self::assertSame(['ACCESS_WINDOW_LOST'], array_column($conflicts, 'type'));
        self::assertSame(4, $conflicts[0]['severity']);

        // Panel parity: kickoff inside (half-open end) → nothing; a club with
        // NO window anywhere has not adopted the data → nothing to enforce.
        $ok = [$this->matchWindow('venue-mateo', 6, '14:00', '18:00')];
        self::assertSame([], $this->detect([$placed], [], null, [], [], [], [], [], $ok));
        self::assertSame([], $this->detect([$placed], [], null, [], [], [], [], [], []));
        $atEnd = [$this->matchWindow('venue-mateo', 6, '13:00', '15:00')];
        self::assertSame(['ACCESS_WINDOW_LOST'], array_column($this->detect([$placed], [], null, [], [], [], [], [], $atEnd), 'type'));
    }

    public function testCoachRoleGradesTheSeverityAndMainWinsAnywhere(): void
    {
        // Same coach on both teams, ASSISTANT everywhere → severity 5; MAIN on
        // ONE side → 3 (the worst engagement counts, cadrage §8).
        $left = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '15:00');
        $right = $this->fixture('fx-2', self::TEAM_2, '2026-10-03', '15:30');

        $assistant = $this->detect([$left, $right], [
            $this->link(self::COACH_A, self::TEAM_1, TeamCoachRole::ASSISTANT),
            $this->link(self::COACH_A, self::TEAM_2, TeamCoachRole::ASSISTANT),
        ]);
        self::assertSame(5, $assistant[0]['severity']);
        self::assertSame('ASSISTANT', $assistant[0]['coachRole']);

        $mixed = $this->detect([$left, $right], [
            $this->link(self::COACH_A, self::TEAM_1, TeamCoachRole::ASSISTANT),
            $this->link(self::COACH_A, self::TEAM_2, TeamCoachRole::MAIN),
        ]);
        self::assertSame(3, $mixed[0]['severity']);
        self::assertSame('MAIN', $mixed[0]['coachRole']);
    }

    public function testAPairedCompetitionShortOfItsExpectationIsNamed(): void
    {
        // P1-4 PR F2 (cadrage §8.6) — severity 6: 22 matchdays frozen at
        // pairing, 1 fixture in base → the manager must not count by hand.
        $competition = $this->competition('comp-1', 22);
        $fx = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '15:00');
        $fx->setCompetitionId('comp-1');

        $items = $this->detect([$fx], [], null, [], [], [], [], [], [], [], [$competition]);

        self::assertSame(['COMPETITION_INCOMPLETE'], array_column($items, 'type'));
        self::assertSame(6, $items[0]['severity']);
        self::assertSame(1, $items[0]['imported']);
        self::assertSame(22, $items[0]['expected']);
    }

    public function testACompetitionWithoutPairingExpectationStaysSilent(): void
    {
        // No expectedMatchdays (never paired) → no way to judge, no noise.
        $competition = $this->competition('comp-1', null);
        self::assertSame([], $this->detect([], [], null, [], [], [], [], [], [], [], [$competition]));
    }

    public function testARealKickoffIsNeverOverriddenByAHabit(): void
    {
        // The away match HAS a real hour (20:30, clear of the training) — the
        // 17:30 habit must not fabricate a phantom conflict.
        $away = $this->awayFixture('fx-1', self::TEAM_1, '2026-10-04', '20:30');
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_1, 7, '14:00', 90, self::COACH_A)];

        $conflicts = $this->detect(
            [$away],
            $links,
            self::BASELINE,
            [],
            [self::BASELINE => $slots],
            [],
            [$this->habit(self::TEAM_1, 7, '17:30')],
        );

        self::assertSame([], $conflicts);
    }

    public function testLinkedTeamsOverlappingRaiseTeamLinkOverlapEvenWithoutCoaches(): void
    {
        // SM1 home 20:30 and SM2 home 21:00 the same evening, NO coach rows —
        // the declared bridge alone raises the finding (players are shared).
        $left = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '20:30');
        $right = $this->fixture('fx-2', 'team-2', '2026-10-03', '21:00');

        $conflicts = $this->detect(
            [$left, $right],
            [],
            null,
            [],
            [],
            [],
            [],
            [$this->teamLink(self::TEAM_1, 'team-2', TeamLinkType::NOT_SIMULTANEOUS)],
        );

        self::assertCount(1, $conflicts);
        self::assertSame('TEAM_LINK_OVERLAP', $conflicts[0]['type']);
        self::assertSame('fx-1', $conflicts[0]['left']['fixtureId']);
        self::assertSame('fx-2', $conflicts[0]['right']['fixtureId']);
    }

    public function testBackToBackLinkRaisesNothingAndBackToBackFixturesDoNotOverlap(): void
    {
        // BACK_TO_BACK is a PR D preference, never a finding; and two chained
        // matches (end == start) don't overlap (half-open) even when linked
        // NOT_SIMULTANEOUS.
        $first = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '18:00'); // window 17:30→19:45
        $chained = $this->fixture('fx-2', 'team-2', '2026-10-03', '20:15'); // window 19:45→22:00

        $viaBackToBack = $this->detect([$first, $chained], [], null, [], [], [], [], [
            $this->teamLink(self::TEAM_1, 'team-2', TeamLinkType::BACK_TO_BACK),
        ]);
        self::assertSame([], $viaBackToBack);

        $viaNotSimultaneous = $this->detect([$first, $chained], [], null, [], [], [], [], [
            $this->teamLink(self::TEAM_1, 'team-2', TeamLinkType::NOT_SIMULTANEOUS),
        ]);
        self::assertSame([], $viaNotSimultaneous);
    }

    public function testPerCategoryProfileDrivesTheFootprint(): void
    {
        // P2-54 RMM-9 (NR) — a U9 profile (75/30) ends the 16:00 match at 17:15,
        // clear of a 17:20 training; the fallback 105/30 ends at 17:45 and clashes.
        // The profile the caller injects per team drives the footprint.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_1, 7, '17:20', 60)]; // Sunday 17:20–18:20

        // No profile → fallback 105/30 → match window ends 17:45 → clash.
        $clash = $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]);
        self::assertCount(1, $clash);
        self::assertSame('MATCH_TRAINING', $clash[0]['type']);

        // U9 profile for team-1 → match window ends 17:15 (half-open) → no clash.
        $clear = $this->detect(
            $fixtures,
            $links,
            self::BASELINE,
            [],
            [self::BASELINE => $slots],
            [],
            [],
            [],
            [],
            [],
            [],
            [self::TEAM_1 => new MatchDurationProfile(75, 30)],
        );
        self::assertSame([], $clear);
    }

    private function competition(string $id, ?int $expectedMatchdays): Competition
    {
        $competition = new Competition;
        $this->setId($competition, $id);
        $competition->setClubId('club');
        $competition->setSeasonId('season');
        $competition->setTeamId(self::TEAM_1);
        $competition->setName('D2');
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $competition->setExpectedMatchdays($expectedMatchdays);

        return $competition;
    }

    private function habit(string $teamId, int $dayOfWeek, string $kickoff): TeamMatchHabit
    {
        $habit = new TeamMatchHabit;
        $habit->setClubId('club');
        $habit->setSeasonId('season');
        $habit->setTeamId($teamId);
        $habit->setDayOfWeek($dayOfWeek);
        $habit->setKickoffTime(DateTimeImmutable::createFromFormat('!H:i', $kickoff) ?: new DateTimeImmutable('00:00'));

        return $habit;
    }

    private function teamLink(string $teamAId, string $teamBId, TeamLinkType $type): TeamLink
    {
        $link = new TeamLink;
        $link->setClubId('club');
        $link->setSeasonId('season');
        $link->setTeamAId($teamAId);
        $link->setTeamBId($teamBId);
        $link->setLinkType($type);

        return $link;
    }

    private function awayFixture(string $id, string $teamId, string $date, ?string $kickoff): Fixture
    {
        $fixture = $this->fixture($id, $teamId, $date, $kickoff);
        $fixture->setHomeAway(FixtureHomeAway::AWAY);

        return $fixture;
    }

    private function unavailability(string $venueId, string $from, string $until, ?string $label): VenueUnavailability
    {
        $unavailability = new VenueUnavailability;
        $unavailability->setClubId('club');
        $unavailability->setSeasonId('season');
        $unavailability->setVenueId($venueId);
        $unavailability->setStartDate(new DateTimeImmutable($from));
        $unavailability->setEndDate(new DateTimeImmutable($until));
        $unavailability->setLabel($label);

        return $unavailability;
    }

    /**
     * @param list<Fixture>                                                                     $fixtures
     * @param list<TeamCoach>                                                                   $links
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string}> $overlayPeriods
     * @param array<string, list<ScheduleSlotTemplate>>                                         $slotsBySchedule
     * @param list<VenueUnavailability>                                                         $unavailabilities
     *
     * @return list<array<string, mixed>>
     */
    private function detect(array $fixtures, array $links, ?string $baselineScheduleId = null, array $overlayPeriods = [], array $slotsBySchedule = [], array $unavailabilities = [], array $habits = [], array $teamLinks = [], array $matchWindows = [], array $envelope = [], array $competitions = [], array $profilesByTeam = []): array
    {
        return new MatchConflictDetector(new MatchFootprint, new EffectiveScheduleResolver, new AwayKickoffEstimator)
            ->detect($fixtures, $links, $baselineScheduleId, $overlayPeriods, $slotsBySchedule, $unavailabilities, $habits, $teamLinks, $matchWindows, $envelope, $competitions, $profilesByTeam);
    }

    private function leagueWindow(int $dayOfWeek, string $min, string $max): LeagueMatchWindow
    {
        $window = new LeagueMatchWindow;
        $window->setLeague('AURA');
        $window->setCategory('U13');
        $window->setLevel('DEPARTEMENTAL');
        $window->setGender(null);
        $window->setDayOfWeek($dayOfWeek);
        $window->setKickoffMin(DateTimeImmutable::createFromFormat('!H:i', $min) ?: new DateTimeImmutable('00:00'));
        $window->setKickoffMax(DateTimeImmutable::createFromFormat('!H:i', $max) ?: new DateTimeImmutable('00:00'));

        return $window;
    }

    private function matchWindow(string $venueId, int $dayOfWeek, string $start, string $end): VenueMatchWindow
    {
        $window = new VenueMatchWindow;
        $window->setClubId('club');
        $window->setSeasonId('season');
        $window->setVenueId($venueId);
        $window->setDayOfWeek($dayOfWeek);
        $window->setStartTime(DateTimeImmutable::createFromFormat('!H:i', $start) ?: new DateTimeImmutable('00:00'));
        $window->setEndTime(DateTimeImmutable::createFromFormat('!H:i', $end) ?: new DateTimeImmutable('00:00'));

        return $window;
    }

    private function fixture(string $id, string $teamId, string $date, ?string $kickoff): Fixture
    {
        $fixture = new Fixture;
        $this->setId($fixture, $id);
        $fixture->setTeamId($teamId);
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $fixture->setKickoffTime(null === $kickoff ? null : (DateTimeImmutable::createFromFormat('!H:i', $kickoff) ?: null));

        return $fixture;
    }

    private function link(string $coachId, string $teamId, TeamCoachRole $role = TeamCoachRole::MAIN): TeamCoach
    {
        $link = new TeamCoach;
        $link->setClubId('club');
        $link->setSeasonId('season');
        $link->setTeamId($teamId);
        $link->setCoachId($coachId);
        $link->setRole($role);

        return $link;
    }

    private function slot(string $id, string $scheduleId, string $teamId, int $dayOfWeek, string $start, int $durationMinutes, ?string $coachId = null): ScheduleSlotTemplate
    {
        $slot = new ScheduleSlotTemplate;
        $this->setId($slot, $id);
        $slot->setScheduleId($scheduleId);
        $slot->setTeamId($teamId);
        $slot->setVenueId('venue');
        $slot->setCoachId($coachId);
        $slot->setDayOfWeek($dayOfWeek);
        $slot->setStartTime(DateTimeImmutable::createFromFormat('!H:i', $start) ?: new DateTimeImmutable('00:00'));
        $slot->setDurationMinutes($durationMinutes);

        return $slot;
    }

    /** Ids are DB-generated (no setter) — set the private field for pure-unit assertions. */
    private function setId(object $entity, string $id): void
    {
        $ref = new ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }
}
