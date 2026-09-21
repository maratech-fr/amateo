<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\CoachPlayerMembership;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\LeagueMatchWindow;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\TeamCoach;
use App\Entity\TeamMatchHabit;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueUnavailability;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\TeamCoachRole;
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
        // 2026-10-04 is a Sunday (ISO 7). Coach A coaches team-1 (the match) AND its
        // SISTER team-2, which trains Sunday 17:00–18:30; the match runs 15:30–17:45
        // → the coach is double-booked (D1: a training of the match's OWN team would
        // be silent — the players who play don't also train — so the clash lives on
        // the sister team's session).
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '17:00', 90)];

        $conflicts = $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]);

        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_TRAINING', $conflicts[0]['type']);
        self::assertSame('sl-1', $conflicts[0]['training']['slotTemplateId']);
    }

    public function testTrainingOnDifferentWeekdayDoesNotConflict(): void
    {
        // Sister team-2's slot on Monday (1) but the match is Sunday → projection
        // excludes it (the weekday, not the same-team rule, is what silences it).
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 1, '17:00', 90)];

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
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $overlayPeriods = [[
            'start' => new DateTimeImmutable('2026-10-01'),
            'end' => new DateTimeImmutable('2026-10-31'),
            'scheduleId' => self::OVERLAY,
        ]];
        $slotsBySchedule = [
            self::BASELINE => [$this->slot('base-sl', self::BASELINE, self::TEAM_2, 7, '17:00', 90)],
            self::OVERLAY => [$this->slot('ovl-sl', self::OVERLAY, self::TEAM_2, 7, '17:00', 90)],
        ];

        $conflicts = $this->detect($fixtures, $links, self::BASELINE, $overlayPeriods, $slotsBySchedule);

        self::assertCount(1, $conflicts);
        self::assertSame('ovl-sl', $conflicts[0]['training']['slotTemplateId']);
    }

    public function testNoBaselineYieldsNoTrainingConflict(): void
    {
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '17:00', 90)];

        // No baseline scheduleId → nothing to resolve → no training conflict.
        self::assertSame([], $this->detect($fixtures, $links, null, [], [self::BASELINE => $slots]));
    }

    public function testActivePeriodWithoutOverlaySuspendsBaselineTraining(): void
    {
        // A closure/holiday recorded as an active period with NO overlay (training
        // suspended, plan not regenerated) captures the date → the baseline slot is
        // NOT checked, so no phantom conflict against a cancelled training.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $activePeriods = [[
            'start' => new DateTimeImmutable('2026-10-01'),
            'end' => new DateTimeImmutable('2026-10-31'),
            'scheduleId' => null, // period active but no overlay generated
        ]];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '17:00', 90)];

        self::assertSame([], $this->detect($fixtures, $links, self::BASELINE, $activePeriods, [self::BASELINE => $slots]));
    }

    public function testNarrowPointedChildBeatsABroaderUnpointedRootForTraining(): void
    {
        // P4-188 NR — the root period (large, no plan → scheduleId null) is
        // listed BEFORE its narrow « début » child pointing at a version that
        // carries the Sunday slot. The narrowest period wins, so the child's
        // training is checked and the clash surfaces. A « first covering period
        // wins » rule would have let the root's null suspend everything and
        // hidden the MATCH_TRAINING (the founder's faux négatif).
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')]; // Sunday, 15:30–17:45
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $root = [
            'start' => new DateTimeImmutable('2026-10-01'),
            'end' => new DateTimeImmutable('2026-10-31'),
            'scheduleId' => null, // root closure, no plan
        ];
        $child = [
            'start' => new DateTimeImmutable('2026-10-01'),
            'end' => new DateTimeImmutable('2026-10-07'),
            'scheduleId' => self::OVERLAY,
        ];
        $slots = [self::OVERLAY => [$this->slot('sl-1', self::OVERLAY, self::TEAM_2, 7, '17:00', 90)]];

        // Root listed first (the id-order hazard): the narrow child still wins.
        $conflicts = $this->detect($fixtures, $links, self::BASELINE, [$root, $child], $slots);

        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_TRAINING', $conflicts[0]['type']);
        self::assertSame('sl-1', $conflicts[0]['training']['slotTemplateId']);
    }

    public function testOccupancyBoundsAreWallClockWithoutOffset(): void
    {
        // P4-191 — start/end/windowStart/windowEnd carry the club's WALL-CLOCK
        // time WITHOUT a timezone offset (`2026-10-04T17:00:00`, never
        // `…+02:00`), so a browser in another zone re-formats the very « 17:00 »
        // it was handed. A 16:00 home match (15:30–17:45) clashing with a Sunday
        // 17:00 training (–18:30) exposes every bound.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '17:00', 90)];

        $conflicts = $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]);

        self::assertCount(1, $conflicts);
        $conflict = $conflicts[0];
        self::assertSame('MATCH_TRAINING', $conflict['type']);
        // Overlap segment 17:00 → 17:45.
        self::assertSame('2026-10-04T17:00:00', $conflict['start']);
        self::assertSame('2026-10-04T17:45:00', $conflict['end']);
        // The fixture footprint bounds.
        self::assertSame('2026-10-04T15:30:00', $conflict['fixture']['windowStart']);
        self::assertSame('2026-10-04T17:45:00', $conflict['fixture']['windowEnd']);
        // The training window bounds.
        self::assertSame('2026-10-04T17:00:00', $conflict['training']['windowStart']);
        self::assertSame('2026-10-04T18:30:00', $conflict['training']['windowEnd']);
        // Falsification: no offset rides along on ANY bound.
        self::assertStringNotContainsString('+', $conflict['start']);
        self::assertStringNotContainsString('+', $conflict['training']['windowStart']);
    }

    public function testFootprintCrossingMidnightChecksNextDaySlots(): void
    {
        // Home match 23:00 on Sunday → footprint 22:30–00:45 (Monday). A Monday
        // 00:00–01:00 training of the coach's team overlaps past midnight and must
        // be caught even though the match date's weekday is Sunday.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '23:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $slots = [$this->slot('sl-mon', self::BASELINE, self::TEAM_2, 1, '00:00', 60)]; // Monday 00:00–01:00

        $conflicts = $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]);

        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_TRAINING', $conflicts[0]['type']);
        self::assertSame('sl-mon', $conflicts[0]['training']['slotTemplateId']);
    }

    public function testAssignedSlotCoachDoesNotFlagCoCoaches(): void
    {
        // Team-1 (the match) has two coaches A and B; the overlapping Sunday slot of
        // its SISTER team-2 is assigned to A only. Only A is double-booked — B (who
        // does not run this slot) must not be flagged.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_B, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '17:00', 90, self::COACH_A)];

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
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '18:00', 90, self::COACH_A)];

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
        // P2-54 side details — the estimated hour « HH:MM » is carried (= the habit).
        self::assertSame('17:30', $conflicts[0]['fixture']['estimatedKickoffTime']);
    }

    public function testFixtureViewCarriesDurationProfileOpponentLabelAndNoTravelOnHome(): void
    {
        // Two overlapping HOME matches sharing coach A (no venue → no VENUE_OVERLAP,
        // only MATCH_MATCH). team-1 carries a 90-min profile; team-2 falls back.
        $fx1 = $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00');
        $fx1->setOpponentLabel('ASVEL - 2');
        $fx2 = $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '16:30');
        $fx2->setOpponentLabel('VAULX - 1');
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $profiles = [self::TEAM_1 => new MatchDurationProfile(90, 20)];

        $conflicts = $this->detect([$fx1, $fx2], $links, null, [], [], [], [], [], [], [], [], $profiles);

        self::assertCount(1, $conflicts);
        $byTeam = $this->sidesByTeam($conflicts[0]);
        self::assertSame(90, $byTeam[self::TEAM_1]['matchDurationMinutes']);
        self::assertSame(105, $byTeam[self::TEAM_2]['matchDurationMinutes']); // fallback profil
        self::assertSame('ASVEL - 2', $byTeam[self::TEAM_1]['opponentLabel']);
        self::assertSame('VAULX - 1', $byTeam[self::TEAM_2]['opponentLabel']);
        // HOME → jamais de trajet ; pas d'estimation → heure estimée nulle.
        self::assertNull($byTeam[self::TEAM_1]['travelOneWayMinutes']);
        self::assertNull($byTeam[self::TEAM_2]['travelOneWayMinutes']);
        self::assertNull($byTeam[self::TEAM_1]['estimatedKickoffTime']);
    }

    public function testTravelOneWayIsHalfTheRoundTripWhenModelledNullWhenAbsent(): void
    {
        // Coach A on both teams; fx-1 AWAY (round trip modelled), fx-2 HOME (never).
        $away = $this->awayFixture('fx-1', self::TEAM_1, '2026-10-04', '16:00');
        $home = $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '16:30');
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        // 170-min round trip on the away fixture ONLY.
        $conflicts = $this->detect([$away, $home], $links, null, [], [], [], [], [], [], [], [], [], null, [], ['fx-1' => 170]);

        self::assertCount(1, $conflicts);
        $byTeam = $this->sidesByTeam($conflicts[0]);
        self::assertSame(85, $byTeam[self::TEAM_1]['travelOneWayMinutes']); // 170 / 2, away
        self::assertNull($byTeam[self::TEAM_2]['travelOneWayMinutes']); // home, never
    }

    public function testTravelOneWayNullForAwayWithoutAModelledRoundTrip(): void
    {
        // AWAY fixture but NO row in the round-trip map → « trajet inconnu » (null),
        // never 0 (the footprint still gets 0, but the side field distinguishes).
        $away = $this->awayFixture('fx-1', self::TEAM_1, '2026-10-04', '16:00');
        $home = $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '16:30');
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        $conflicts = $this->detect([$away, $home], $links);

        self::assertCount(1, $conflicts);
        $byTeam = $this->sidesByTeam($conflicts[0]);
        self::assertNull($byTeam[self::TEAM_1]['travelOneWayMinutes']);
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

    public function testUnplacedFixturesWithAVenueAndKickoffStillOverlap(): void
    {
        // P4-187a NR — un domicile IMPORTÉ reste UNPLACED mais porte un venueId
        // (rattaché par alias) : la collision de gymnase le voit quand même. Le
        // détecteur ne regarde que le venueId, jamais le statut de placement — c'est
        // l'invariant sur lequel repose « rendre visible sans placer ».
        $left = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '15:00');
        $left->setStatus(FixtureStatus::UNPLACED, new DateTimeImmutable);
        $left->setVenueId('venue-mateo');
        $right = $this->fixture('fx-2', self::TEAM_2, '2026-10-03', '16:00');
        $right->setStatus(FixtureStatus::UNPLACED, new DateTimeImmutable);
        $right->setVenueId('venue-mateo');

        $conflicts = $this->detect([$left, $right], []);

        self::assertSame(['VENUE_OVERLAP'], array_column($conflicts, 'type'));
        self::assertSame('venue-mateo', $conflicts[0]['venueId']);
    }

    public function testUnplacedFixtureWithAVenueStillSeesAClosure(): void
    {
        // P4-187a NR — même chose pour la fermeture : un domicile UNPLACED rattaché
        // par alias à un gymnase fermé à la date du match alerte VENUE_UNAVAILABLE
        // (la DATE dans la plage suffit, statut et coup d'envoi indifférents).
        $fixture = $this->fixture('fx-1', self::TEAM_1, '2027-02-14', null);
        $fixture->setStatus(FixtureStatus::UNPLACED, new DateTimeImmutable);
        $fixture->setVenueId('venue-armand');

        $conflicts = $this->detect([$fixture], [], null, [], [], [$this->unavailability('venue-armand', '2027-02-04', '2027-02-28', 'travaux')]);

        self::assertSame(['VENUE_UNAVAILABLE'], array_column($conflicts, 'type'));
        self::assertSame('fx-1', $conflicts[0]['fixture']['fixtureId']);
    }

    public function testLeagueWindowViolationOnlyForMappedTeams(): void
    {
        // Sunday 17:30 vs a Saturday-only envelope → severity 2. The unmapped
        // team placed the same way stays SILENT (tolerant join, PR D decision).
        $mapped = $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '17:30'); // Sunday
        $mapped->setVenueId('venue-mateo');
        $mapped->setCompetitionId('comp-1'); // real competition → the league envelope applies (only friendlies are exempt, P4-190)
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

    public function testAFriendlyIsNeverALeagueWindowViolation(): void
    {
        // P4-190 (fondateur, 2026-09-08): a friendly (competitionId null) answers
        // to no FFBB league envelope. The real case: SM1 (paired PNM, Sat/Sun
        // windows) with a HOME amical placed outside every window must NOT scream
        // « Hors fenêtre autorisée par la ligue ».
        $friendly = $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '17:30'); // Sunday, competitionId null → amical
        $friendly->setVenueId('venue-mateo');
        $envelope = [self::TEAM_1 => [$this->leagueWindow(6, '14:00', '20:00')]]; // Saturday only

        self::assertSame([], $this->detect([$friendly], [], null, [], [], [], [], [], [], $envelope));
    }

    public function testAFriendlyInAVenueCollisionStillScreamsButNeverForTheLeague(): void
    {
        // The amical is lifted from LEAGUE_WINDOW_VIOLATION only (P4-190) — every
        // other family still applies: a gym clash still screams VENUE_OVERLAP.
        $left = $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '17:30'); // Sunday amical, outside the Saturday window
        $left->setVenueId('venue-mateo');
        $right = $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '17:30');
        $right->setVenueId('venue-mateo');
        $envelope = [self::TEAM_1 => [$this->leagueWindow(6, '14:00', '20:00')]]; // Saturday only

        $types = array_column($this->detect([$left, $right], [], null, [], [], [], [], [], [], $envelope), 'type');
        self::assertContains('VENUE_OVERLAP', $types);
        self::assertNotContains('LEAGUE_WINDOW_VIOLATION', $types);
    }

    // ── Amical sur un créneau de match (P4-193, alerte jamais bloquante) ─────

    public function testFriendlyOnAMatchWindowRaisesTheWindowReason(): void
    {
        // Un amical (competitionId null) placé un samedi 15:00, gymnase mateo, dont
        // l'empreinte (13:15–16:45) chevauche la fenêtre d'accès match 14:00–18:00 :
        // FRIENDLY_ON_MATCH_SLOT, severity 5, raison MATCH_SLOT_WINDOW, venueId porté.
        $friendly = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '15:00'); // Saturday, amical
        $friendly->setVenueId('venue-mateo');
        $windows = [$this->matchWindow('venue-mateo', 6, '14:00', '18:00')];

        $conflicts = array_values(array_filter(
            $this->detect([$friendly], [], null, [], [], [], [], [], $windows),
            static fn (array $c): bool => 'FRIENDLY_ON_MATCH_SLOT' === $c['type'],
        ));

        self::assertCount(1, $conflicts);
        self::assertSame(5, $conflicts[0]['severity']);
        self::assertSame(['MATCH_SLOT_WINDOW'], $conflicts[0]['reasons']);
        self::assertSame('venue-mateo', $conflicts[0]['venueId']);
        self::assertSame('fx-1', $conflicts[0]['fixture']['fixtureId']);
    }

    public function testFriendlyOnAMatchWeekendRaisesTheWeekendReason(): void
    {
        // Amical placé le DIMANCHE d'un week-end où une équipe joue en compétition
        // à l'EXTÉRIEUR le samedi → raison MATCH_WEEKEND (clé = date du samedi).
        // Aucune fenêtre → pas de raison fenêtre, donc pas de venueId.
        $friendly = $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '15:00'); // Sunday, amical, placed
        $friendly->setVenueId('venue-mateo');
        $away = $this->awayFixture('fx-2', self::TEAM_2, '2026-10-03', '18:00'); // Saturday, competition
        $away->setCompetitionId('comp-1');

        $conflicts = array_values(array_filter(
            $this->detect([$friendly, $away], []),
            static fn (array $c): bool => 'FRIENDLY_ON_MATCH_SLOT' === $c['type'],
        ));

        self::assertCount(1, $conflicts);
        self::assertSame(['MATCH_WEEKEND'], $conflicts[0]['reasons']);
        self::assertArrayNotHasKey('venueId', $conflicts[0]);
        self::assertSame('fx-1', $conflicts[0]['fixture']['fixtureId']);
    }

    public function testFriendlyOnAWeekdayHolidayStaysSilent(): void
    {
        // Amical un JEUDI (hors week-end), aucune fenêtre : ni fenêtre ni week-end
        // → aucune alerte. Le jeudi ne déclenche jamais la raison week-end.
        $friendly = $this->fixture('fx-1', self::TEAM_1, '2026-10-01', '18:00'); // Thursday, amical, placed
        $friendly->setVenueId('venue-mateo');

        self::assertSame([], $this->detect([$friendly], []));
    }

    public function testFriendlyOutsideEveryWindowOnAMatchlessWeekendStaysSilent(): void
    {
        // Amical placé un samedi mais son empreinte (16:15–19:45) NE chevauche PAS
        // la fenêtre du gymnase (08:00–10:00), et aucune rencontre NON amicale ne se
        // joue ce week-end → aucune alerte FRIENDLY_ON_MATCH_SLOT (la branche fenêtre
        // exige un CHEVAUCHEMENT, la branche week-end un match non amical).
        $friendly = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '18:00'); // Saturday, amical, placed
        $friendly->setVenueId('venue-mateo');
        $windows = [$this->matchWindow('venue-mateo', 6, '08:00', '10:00')];

        $types = array_column($this->detect([$friendly], [], null, [], [], [], [], [], $windows), 'type');
        self::assertNotContains('FRIENDLY_ON_MATCH_SLOT', $types);
    }

    public function testAChampionshipFixtureNeverRaisesFriendlyOnMatchSlot(): void
    {
        // Un match de CHAMPIONNAT dans une fenêtre de match n'est PAS un amical sur
        // un créneau : FRIENDLY_ON_MATCH_SLOT ne vise que les competitionId null.
        $championship = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '15:00'); // Saturday
        $championship->setVenueId('venue-mateo');
        $championship->setCompetitionId('comp-1');
        $windows = [$this->matchWindow('venue-mateo', 6, '14:00', '18:00')];

        $types = array_column($this->detect([$championship], [], null, [], [], [], [], [], $windows), 'type');
        self::assertNotContains('FRIENDLY_ON_MATCH_SLOT', $types);
    }

    public function testAccessWindowLostFollowsThePanelRule(): void
    {
        // Placed Saturday 15:00, then the mairie window moved to 18:00-20:00 →
        // severity 4 (dette ii: the guard could not see a change made AFTER). A
        // competition match (competitionId set) isolates ACCESS_WINDOW_LOST: a
        // friendly on a slot would also raise FRIENDLY_ON_MATCH_SLOT (P4-193).
        $placed = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '15:00');
        $placed->setVenueId('venue-mateo');
        $placed->setCompetitionId('comp-1');

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

    public function testAccessWindowLostCarriesTheVenueWindowsMatchDayFirst(): void
    {
        // Placé le samedi 15:00, hors des accès match de son gymnase → ACCESS_WINDOW_LOST.
        // Le conflit porte les accès match DE SON GYMNASE, jour du match (samedi) d'abord —
        // de quoi dire à l'écran « placé hors des accès (samedi 16:00–18:00, …) ».
        $placed = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '15:00');
        $placed->setVenueId('venue-mateo');
        $placed->setCompetitionId('comp-1');

        $windows = [
            $this->matchWindow('venue-mateo', 3, '18:00', '20:00'), // mercredi
            $this->matchWindow('venue-mateo', 6, '16:00', '18:00'), // samedi (jour du match)
            $this->matchWindow('venue-autre', 6, '14:00', '18:00'), // autre gymnase → exclu
        ];
        $conflicts = $this->detect([$placed], [], null, [], [], [], [], [], $windows);

        self::assertSame(['ACCESS_WINDOW_LOST'], array_column($conflicts, 'type'));
        self::assertSame(
            [
                ['dayOfWeek' => 6, 'startTime' => '16:00', 'endTime' => '18:00'],
                ['dayOfWeek' => 3, 'startTime' => '18:00', 'endTime' => '20:00'],
            ],
            $conflicts[0]['windows'],
            'les accès du gymnase de la fixture, jour du match (samedi) en premier ; l\'autre gymnase est exclu',
        );
    }

    public function testCoachRoleIsMainOnlyWhenMainOnEveryInvolvedTeam(): void
    {
        // P4-189 — the per-PAIR role is MAIN only when the coach is MAIN on BOTH
        // teams (severity 3). A single ASSISTANT engagement on either side
        // softens the finding to ASSISTANT (severity 5): a helper can hold that
        // side, so it is less acute than a double head-coach booking.
        $left = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '15:00');
        $right = $this->fixture('fx-2', self::TEAM_2, '2026-10-03', '15:30');

        // MAIN on both → severity 3, MAIN.
        $mainMain = $this->detect([$left, $right], [
            $this->link(self::COACH_A, self::TEAM_1, TeamCoachRole::MAIN),
            $this->link(self::COACH_A, self::TEAM_2, TeamCoachRole::MAIN),
        ]);
        self::assertSame(3, $mainMain[0]['severity']);
        self::assertSame('MAIN', $mainMain[0]['coachRole']);

        // ASSISTANT on one side, MAIN on the other → ASSISTANT, severity 5. This
        // is the case P4-189 REVERSES: MAIN no longer wins « anywhere ».
        $assistantMain = $this->detect([$left, $right], [
            $this->link(self::COACH_A, self::TEAM_1, TeamCoachRole::ASSISTANT),
            $this->link(self::COACH_A, self::TEAM_2, TeamCoachRole::MAIN),
        ]);
        self::assertSame(5, $assistantMain[0]['severity']);
        self::assertSame('ASSISTANT', $assistantMain[0]['coachRole']);

        // ASSISTANT everywhere → ASSISTANT, severity 5.
        $assistant = $this->detect([$left, $right], [
            $this->link(self::COACH_A, self::TEAM_1, TeamCoachRole::ASSISTANT),
            $this->link(self::COACH_A, self::TEAM_2, TeamCoachRole::ASSISTANT),
        ]);
        self::assertSame(5, $assistant[0]['severity']);
        self::assertSame('ASSISTANT', $assistant[0]['coachRole']);
    }

    public function testMainAndAssistantOnTheSameTeamStillCountsMain(): void
    {
        // The within-team « worst engagement wins » (rolesByTeam) is UNCHANGED
        // by P4-189: a coach who is BOTH assistant and head on team-1, and head
        // on team-2, is MAIN on each side → severity 3. Only the per-PAIR
        // combination flipped, not the per-team resolution.
        $left = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '15:00');
        $right = $this->fixture('fx-2', self::TEAM_2, '2026-10-03', '15:30');

        $conflicts = $this->detect([$left, $right], [
            $this->link(self::COACH_A, self::TEAM_1, TeamCoachRole::ASSISTANT),
            $this->link(self::COACH_A, self::TEAM_1, TeamCoachRole::MAIN),
            $this->link(self::COACH_A, self::TEAM_2, TeamCoachRole::MAIN),
        ]);

        self::assertCount(1, $conflicts);
        self::assertSame(3, $conflicts[0]['severity']);
        self::assertSame('MAIN', $conflicts[0]['coachRole']);
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

    public function testACupWithoutExpectedMatchdaysNeverCriesIncompleteCalendar(): void
    {
        // P4-195 — une coupe porte expectedMatchdays null (pas de « 2×(N−1) »).
        // MÊME avec une rencontre importée (la vraie Coupe du Rhône : 1 rencontre),
        // le radar ne DOIT PAS crier « calendrier incomplet ». Le détecteur est déjà
        // muet quand expected est null (MatchConflictDetector.php:371-374) — ce test
        // le garde après le changement de l'appariement (journées CUP = null).
        $cup = new Competition;
        $this->setId($cup, 'cup-1');
        $cup->setClubId('club');
        $cup->setSeasonId('season');
        $cup->setTeamId(self::TEAM_1);
        $cup->setName('U18 MASCULIN COUPE DU RHONE');
        $cup->setCompetitionType(CompetitionType::CUP);
        $cup->setExpectedMatchdays(null);

        $fx = $this->fixture('fx-cup', self::TEAM_1, '2026-10-03', '17:00');
        $fx->setCompetitionId('cup-1');

        $incompletes = array_values(array_filter(
            $this->detect([$fx], [], null, [], [], [], [], [], [], [], [$cup]),
            static fn (array $item): bool => 'COMPETITION_INCOMPLETE' === $item['type'],
        ));
        self::assertSame([], $incompletes, 'a cup with null expected matchdays never triggers COMPETITION_INCOMPLETE');
    }

    public function testARealKickoffIsNeverOverriddenByAHabit(): void
    {
        // The away match HAS a real hour (20:30, clear of the training) — the
        // 17:30 habit must not fabricate a phantom conflict.
        $away = $this->awayFixture('fx-1', self::TEAM_1, '2026-10-04', '20:30');
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '14:00', 90, self::COACH_A)];

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

    public function testTeamLinksNoLongerRaiseAnyConflict(): void
    {
        // Lot M — the TEAM_LINK family LEFT the radar (founder decision : « pour
        // l'instant ça fait plus de bruit qu'autre chose »). Two home matches of two
        // teams that WOULD have been a declared bridge, overlapping and coach-less,
        // now raise nothing at all (no coach, no shared player, no venue collision).
        // The placement solver keeps its soft preference on the link — the radar is
        // simply mute. `$teamLinks` is no longer even a parameter of the detector.
        $left = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '20:30');
        $right = $this->fixture('fx-2', 'team-2', '2026-10-03', '21:00');

        self::assertSame([], $this->detect([$left, $right], []));
    }

    public function testPerCategoryProfileDrivesTheFootprint(): void
    {
        // P2-54 RMM-9 (NR) — a U9 profile (75/30) ends the 16:00 match at 17:15,
        // clear of a 17:20 training; the fallback 105/30 ends at 17:45 and clashes.
        // The profile the caller injects per team drives the footprint.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '17:20', 60)]; // Sunday 17:20–18:20

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

    // ── D1 règle 2 : match sur SON PROPRE entraînement (2026-09-13) ──────────

    public function testMatchOnItsOwnTeamTrainingStaysSilent(): void
    {
        // D1 rule 2 — the match of team-1 overlaps a training of team-1 itself.
        // The players who play the match do not ALSO train it: no conflict, whatever
        // the gym. Coach A runs both.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-own', self::BASELINE, self::TEAM_1, 7, '17:00', 90, self::COACH_A)];

        self::assertSame([], $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]));
    }

    public function testMatchOnTheCoachsOtherTeamTrainingConflictsWhileTheOwnTeamIsSilent(): void
    {
        // The real case (Dionnet SM1 + U18M1): coach A holds team-1's match AND
        // team-2's overlapping training. His OWN team's (team-1) session is silent,
        // the SISTER team's (team-2) is the double-booking. Both slots overlap the
        // match window; exactly ONE conflict comes out, on the sister slot.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $slots = [
            $this->slot('sl-own', self::BASELINE, self::TEAM_1, 7, '17:00', 90, self::COACH_A),
            $this->slot('sl-sister', self::BASELINE, self::TEAM_2, 7, '17:00', 90, self::COACH_A),
        ];

        $conflicts = $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots]);

        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_TRAINING', $conflicts[0]['type']);
        self::assertSame('sl-sister', $conflicts[0]['training']['slotTemplateId']);
    }

    // ── D1 règle 1 : collision de gymnase sur la fenêtre SALLE (2026-09-13) ──

    public function testTwoMatchesChainedTwoHoursApartInTheSameGymDoNotOverlap(): void
    {
        // The founder's case: SF1 18:45 then SM1 20:45 in the same gym, senior 105
        // min. VENUE windows 18:45→20:30 and 20:45→22:30 do NOT touch → no
        // VENUE_OVERLAP. Their PERSON footprints (warm-up inflated) DID overlap
        // 20:15→20:30 — the old rule false-alarmed here.
        $sf1 = $this->fixture('fx-sf1', self::TEAM_1, '2026-10-03', '18:45');
        $sf1->setVenueId('venue-mateo');
        $sm1 = $this->fixture('fx-sm1', self::TEAM_2, '2026-10-03', '20:45');
        $sm1->setVenueId('venue-mateo');

        $types = array_column($this->detect([$sf1, $sm1], []), 'type');
        self::assertNotContains('VENUE_OVERLAP', $types);
    }

    public function testTwoMatchesTooCloseInTheSameGymOverlapOnTheVenueWindow(): void
    {
        // SM1 pulled forward to 20:15: its VENUE window 20:15→22:00 now bites into
        // SF1's 18:45→20:30 (overlap 20:15→20:30) → VENUE_OVERLAP.
        $sf1 = $this->fixture('fx-sf1', self::TEAM_1, '2026-10-03', '18:45');
        $sf1->setVenueId('venue-mateo');
        $sm1 = $this->fixture('fx-sm1', self::TEAM_2, '2026-10-03', '20:15');
        $sm1->setVenueId('venue-mateo');

        $conflicts = array_values(array_filter(
            $this->detect([$sf1, $sm1], []),
            static fn (array $c): bool => 'VENUE_OVERLAP' === $c['type'],
        ));
        self::assertCount(1, $conflicts);
        self::assertSame('venue-mateo', $conflicts[0]['venueId']);
    }

    public function testVenueOverlapBoundsAreTheVenueWindowIntersection(): void
    {
        // Two matches 15:00 and 16:00 (senior 105) in the same gym: VENUE windows
        // 15:00→16:45 and 16:00→17:45, intersection 16:00→16:45 — the served
        // start/end are the SALLE windows, wall-clock, no offset.
        $left = $this->fixture('fx-1', self::TEAM_1, '2026-10-03', '15:00');
        $left->setVenueId('venue-mateo');
        $right = $this->fixture('fx-2', self::TEAM_2, '2026-10-03', '16:00');
        $right->setVenueId('venue-mateo');

        $conflicts = array_values(array_filter(
            $this->detect([$left, $right], []),
            static fn (array $c): bool => 'VENUE_OVERLAP' === $c['type'],
        ));
        self::assertCount(1, $conflicts);
        self::assertSame('2026-10-03T16:00:00', $conflicts[0]['start']);
        self::assertSame('2026-10-03T16:45:00', $conflicts[0]['end']);
    }

    public function testFriendlyWhoseVenueWindowClearsTheAccessWindowRaisesNoSlotReason(): void
    {
        // D1 rule 1 for FRIENDLY_ON_MATCH_SLOT: a friendly kicking off 14:30 in a gym
        // whose match access window is 12:00–14:15. Its VENUE window 14:30→16:15
        // sits CLEAR of the window (only the old warm-up 14:00 would have touched it)
        // → no MATCH_SLOT_WINDOW reason. A 14:00 kickoff (venue window 14:00→15:45)
        // still bites the window → the reason fires. (ACCESS_WINDOW_LOST is a
        // separate, kickoff-point family and is not asserted here.)
        $clears = $this->fixture('fx-clear', self::TEAM_1, '2026-10-03', '14:30'); // Saturday, amical
        $clears->setVenueId('venue-mateo');
        $windows = [$this->matchWindow('venue-mateo', 6, '12:00', '14:15')];

        $clearFriendly = array_values(array_filter(
            $this->detect([$clears], [], null, [], [], [], [], [], $windows),
            static fn (array $c): bool => 'FRIENDLY_ON_MATCH_SLOT' === $c['type'],
        ));
        self::assertSame([], $clearFriendly);

        $bites = $this->fixture('fx-bite', self::TEAM_1, '2026-10-03', '14:00');
        $bites->setVenueId('venue-mateo');
        $biteFriendly = array_values(array_filter(
            $this->detect([$bites], [], null, [], [], [], [], [], $windows),
            static fn (array $c): bool => 'FRIENDLY_ON_MATCH_SLOT' === $c['type'],
        ));
        self::assertCount(1, $biteFriendly);
        self::assertSame(['MATCH_SLOT_WINDOW'], $biteFriendly[0]['reasons']);
    }

    // ── D1 règle 3 : un match passé ne porte ni ne reçoit de conflit ─────────

    public function testPastMatchesAreDroppedFromEveryFamilyButCompetitionIncomplete(): void
    {
        // clubToday = 2026-10-10; a same-gym overlapping pair AND a league violation,
        // all dated 2026-10-04 (already played), are silenced. Only the completeness
        // item survives, its count built on the FULL list.
        $clubToday = new DateTimeImmutable('2026-10-10');
        $left = $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '17:30'); // Sunday, outside a Saturday envelope
        $left->setVenueId('venue-mateo');
        $left->setCompetitionId('comp-1');
        $right = $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '17:30');
        $right->setVenueId('venue-mateo');
        $envelope = [self::TEAM_1 => [$this->leagueWindow(6, '14:00', '20:00')]];
        $competition = $this->competition('comp-1', 22);

        $conflicts = $this->detect([$left, $right], [], null, [], [], [], [], [], [], $envelope, [$competition], [], $clubToday);

        self::assertSame(['COMPETITION_INCOMPLETE'], array_column($conflicts, 'type'));
    }

    public function testAMatchOnClubTodayIsKeptWhileYesterdayIsDropped(): void
    {
        // Boundary of the >= filter: a same-day (== clubToday) overlapping pair still
        // screams; the previous day's pair (elsewhere) is dropped. Proves the filter
        // is « strictly before today », not « today too ».
        $clubToday = new DateTimeImmutable('2026-10-04');
        $todayLeft = $this->fixture('fx-t1', self::TEAM_1, '2026-10-04', '16:00');
        $todayLeft->setVenueId('venue-mateo');
        $todayRight = $this->fixture('fx-t2', self::TEAM_2, '2026-10-04', '16:30');
        $todayRight->setVenueId('venue-mateo');
        $yesterdayLeft = $this->fixture('fx-y1', self::TEAM_1, '2026-10-03', '16:00');
        $yesterdayLeft->setVenueId('venue-coubertin');
        $yesterdayRight = $this->fixture('fx-y2', self::TEAM_2, '2026-10-03', '16:30');
        $yesterdayRight->setVenueId('venue-coubertin');

        $overlaps = array_values(array_filter(
            $this->detect([$todayLeft, $todayRight, $yesterdayLeft, $yesterdayRight], [], null, [], [], [], [], [], [], [], [], [], $clubToday),
            static fn (array $c): bool => 'VENUE_OVERLAP' === $c['type'],
        ));
        self::assertCount(1, $overlaps);
        self::assertSame('venue-mateo', $overlaps[0]['venueId']);
    }

    public function testNullClubTodayKeepsEvenLongPastMatches(): void
    {
        // The pure test path (no clubToday) never filters by date: a pair dated years
        // ago still screams. This is why every other unit test can use fixed dates.
        $left = $this->fixture('fx-1', self::TEAM_1, '2020-01-04', '15:00');
        $left->setVenueId('venue-mateo');
        $right = $this->fixture('fx-2', self::TEAM_2, '2020-01-04', '16:00');
        $right->setVenueId('venue-mateo');

        self::assertSame(['VENUE_OVERLAP'], array_column($this->detect([$left, $right], []), 'type'));
    }

    // ── Une personne = ses équipes coachées + ses équipes où elle joue ───────

    public function testACoachWhoAlsoPlaysElsewhereIsDoubleBookedWithPerSideRolesAndChronologicalOrder(): void
    {
        // The founder case (Mara): she is the MAIN coach of team-1 and a PLAYER of
        // team-2, both playing overlapping matches → a MATCH_MATCH. Severity 3
        // (MAIN×PLAYER is a hard clash), coachRole PLAYER (not all MAIN, no
        // assistant). Sides are chronological: the earlier window is on the left,
        // whatever the input order — team-1's fixture is passed LAST here.
        $early = $this->fixture('fx-early', self::TEAM_1, '2026-10-04', '16:00'); // 15:30–17:45
        $late = $this->fixture('fx-late', self::TEAM_2, '2026-10-04', '16:30'); // 16:00–18:15

        $conflicts = $this->detect(
            [$late, $early],
            [$this->link(self::COACH_A, self::TEAM_1, TeamCoachRole::MAIN)],
            playerMemberships: [$this->membership(self::COACH_A, self::TEAM_2)],
        );

        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_MATCH', $conflicts[0]['type']);
        self::assertSame(self::COACH_A, $conflicts[0]['coachId']);
        self::assertSame(3, $conflicts[0]['severity']);
        self::assertSame('PLAYER', $conflicts[0]['coachRole']);
        // Chronological: team-1 (15:30) on the left even though it was passed last.
        self::assertSame('fx-early', $conflicts[0]['left']['fixtureId']);
        self::assertSame('MAIN', $conflicts[0]['left']['role']);
        self::assertSame('fx-late', $conflicts[0]['right']['fixtureId']);
        self::assertSame('PLAYER', $conflicts[0]['right']['role']);
    }

    public function testTwoTeamsAPlayerPlaysBothClashAtSeverityThree(): void
    {
        // A pure player of two teams with overlapping matches → MATCH_MATCH,
        // severity 3, both sides PLAYER, coachRole PLAYER. No coach anywhere.
        $left = $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00');
        $right = $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '16:30');

        $conflicts = $this->detect(
            [$left, $right],
            [],
            playerMemberships: [$this->membership(self::COACH_A, self::TEAM_1), $this->membership(self::COACH_A, self::TEAM_2)],
        );

        self::assertCount(1, $conflicts);
        self::assertSame(3, $conflicts[0]['severity']);
        self::assertSame('PLAYER', $conflicts[0]['coachRole']);
        self::assertSame('PLAYER', $conflicts[0]['left']['role']);
        self::assertSame('PLAYER', $conflicts[0]['right']['role']);
    }

    public function testAnAssistantEngagementSoftensAPlayerClashToFive(): void
    {
        // ASSISTANT on one side, PLAYER on the other → severity 5, coachRole
        // ASSISTANT (a helper can hold the assistant side).
        $left = $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00');
        $right = $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '16:30');

        $conflicts = $this->detect(
            [$left, $right],
            [$this->link(self::COACH_A, self::TEAM_1, TeamCoachRole::ASSISTANT)],
            playerMemberships: [$this->membership(self::COACH_A, self::TEAM_2)],
        );

        self::assertCount(1, $conflicts);
        self::assertSame(5, $conflicts[0]['severity']);
        self::assertSame('ASSISTANT', $conflicts[0]['coachRole']);
    }

    public function testAnInactiveMembershipIsIgnored(): void
    {
        // She coaches team-1 but her team-2 membership is INACTIVE: she is not a
        // player of team-2, so the two overlapping matches raise nothing.
        $left = $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00');
        $right = $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '16:30');

        self::assertSame([], $this->detect(
            [$left, $right],
            [$this->link(self::COACH_A, self::TEAM_1, TeamCoachRole::MAIN)],
            playerMemberships: [$this->membership(self::COACH_A, self::TEAM_2, false)],
        ));
    }

    public function testMatchPlayedAgainstACoachedTrainingIsHard(): void
    {
        // She PLAYS team-1's match and COACHES (MAIN) team-2's overlapping Sunday
        // training. Direction « match joué × entraînement coaché » → severity 3.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')]; // Sunday 15:30–17:45
        $links = [$this->link(self::COACH_A, self::TEAM_2, TeamCoachRole::MAIN)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '17:00', 90, self::COACH_A)];

        $conflicts = $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots], playerMemberships: [$this->membership(self::COACH_A, self::TEAM_1)]);

        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_TRAINING', $conflicts[0]['type']);
        self::assertSame(3, $conflicts[0]['severity']);
        self::assertSame('PLAYER', $conflicts[0]['coachRole']);
        self::assertSame('PLAYER', $conflicts[0]['fixture']['role']);
        self::assertSame('MAIN', $conflicts[0]['training']['role']);
    }

    public function testMatchCoachedAgainstAPlayedTrainingIsSofter(): void
    {
        // She COACHES (MAIN) team-1's match and only PLAYS team-2, whose training
        // overlaps (no assigned coach → the slot is held by its players too).
        // Direction « match coaché × entraînement où elle joue » → severity 5.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_A, self::TEAM_1, TeamCoachRole::MAIN)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '17:00', 90)]; // no assigned coach

        $conflicts = $this->detect($fixtures, $links, self::BASELINE, [], [self::BASELINE => $slots], playerMemberships: [$this->membership(self::COACH_A, self::TEAM_2)]);

        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_TRAINING', $conflicts[0]['type']);
        self::assertSame(5, $conflicts[0]['severity']);
        self::assertSame('MAIN', $conflicts[0]['fixture']['role']);
        self::assertSame('PLAYER', $conflicts[0]['training']['role']);
    }

    public function testAPlayerMatchOnHerOwnTeamTrainingStaysSilent(): void
    {
        // D1 rule 2, EXTENDED to players: she plays team-1's match while team-1
        // itself trains — the players who play do not also train, so no conflict.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $slots = [$this->slot('sl-own', self::BASELINE, self::TEAM_1, 7, '17:00', 90)];

        self::assertSame([], $this->detect($fixtures, [], self::BASELINE, [], [self::BASELINE => $slots], playerMemberships: [$this->membership(self::COACH_A, self::TEAM_1)]));
    }

    public function testAnAssignedSlotCoachNeverEvictsThePlayers(): void
    {
        // The slot of team-2 is assigned to coach B (who replaces the OTHER
        // coaches). A PLAYER of team-2 (coach A) also plays team-1's overlapping
        // match: the assigned coach must not shadow her — she is still flagged.
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')];
        $links = [$this->link(self::COACH_B, self::TEAM_2, TeamCoachRole::MAIN)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '17:00', 90, self::COACH_B)];

        $conflicts = $this->detect(
            $fixtures,
            $links,
            self::BASELINE,
            [],
            [self::BASELINE => $slots],
            playerMemberships: [$this->membership(self::COACH_A, self::TEAM_1), $this->membership(self::COACH_A, self::TEAM_2)],
        );

        self::assertCount(1, $conflicts);
        self::assertSame('MATCH_TRAINING', $conflicts[0]['type']);
        self::assertSame(self::COACH_A, $conflicts[0]['coachId']);
        self::assertSame('PLAYER', $conflicts[0]['fixture']['role']);
        self::assertSame('PLAYER', $conflicts[0]['training']['role']);
    }

    public function testACoachWhoAlsoPlaysTheSameTeamCountsMain(): void
    {
        // Coach role wins over player on the SAME team: MAIN on team-1 AND a player
        // of team-1, MAIN on team-2 → each side is MAIN, severity 3, coachRole MAIN.
        $left = $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00');
        $right = $this->fixture('fx-2', self::TEAM_2, '2026-10-04', '16:30');

        $conflicts = $this->detect(
            [$left, $right],
            [$this->link(self::COACH_A, self::TEAM_1, TeamCoachRole::MAIN), $this->link(self::COACH_A, self::TEAM_2, TeamCoachRole::MAIN)],
            playerMemberships: [$this->membership(self::COACH_A, self::TEAM_1)],
        );

        self::assertCount(1, $conflicts);
        self::assertSame(3, $conflicts[0]['severity']);
        self::assertSame('MAIN', $conflicts[0]['coachRole']);
        self::assertSame('MAIN', $conflicts[0]['left']['role']);
    }

    // ── Lot M : l'échauffement sort de l'empreinte des conflits de PERSONNE ──
    // La règle générale (l'échauffement ne compte plus, quel que soit le gymnase)
    // SUBSUME l'exception « même gymnase à domicile » de septembre : les cas
    // same-gym ci-dessous restent verts SANS modification (preuve de redondance) ;
    // les cas NON couverts par l'ancienne exception (autre gymnase, sans gymnase,
    // extérieur) encodaient la règle inverse et sont INVERSÉS.

    public function testTwoHomeMatchesSameGymTheLaterDropsWarmupNoConflict(): void
    {
        // Founder case (2026-09-17): SM2 home 18:30 (115 min → 20:25) and SM1 home
        // 20:45 (warm-up 30 → person window from 20:15), SAME gym, shared person.
        // SM1 kicks off later → its warm-up drops (effective window 20:45→22:30);
        // SM2 keeps its person window (…→20:25). No overlap → NO MATCH_MATCH (the
        // old rule false-alarmed on the inflated 20:15→20:25 person overlap).
        $sm2 = $this->fixture('fx-sm2', self::TEAM_1, '2026-10-03', '18:30');
        $sm2->setVenueId('jdr');
        $sm1 = $this->fixture('fx-sm1', self::TEAM_2, '2026-10-03', '20:45');
        $sm1->setVenueId('jdr');
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        $matchMatch = array_values(array_filter(
            $this->detect([$sm2, $sm1], $links, profilesByTeam: [self::TEAM_1 => new MatchDurationProfile(115, 30)]),
            static fn (array $c): bool => 'MATCH_MATCH' === $c['type'],
        ));
        self::assertSame([], $matchMatch);
    }

    public function testTwoHomeMatchesSameGymRealOverlapStartsAtTheSecondKickoff(): void
    {
        // SM1 pulled to 20:00: its effective venue window 20:00→21:45 now bites SM2's
        // 18:00→20:25 person window → MATCH_MATCH, start = the SECOND kickoff (20:00),
        // end = the intersection 20:25 (SM2's person end). Bornes = fenêtres EFFECTIVES.
        $sm2 = $this->fixture('fx-sm2', self::TEAM_1, '2026-10-03', '18:30');
        $sm2->setVenueId('jdr');
        $sm1 = $this->fixture('fx-sm1', self::TEAM_2, '2026-10-03', '20:00');
        $sm1->setVenueId('jdr');
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        $matchMatch = array_values(array_filter(
            $this->detect([$sm2, $sm1], $links, profilesByTeam: [self::TEAM_1 => new MatchDurationProfile(115, 30)]),
            static fn (array $c): bool => 'MATCH_MATCH' === $c['type'],
        ));
        self::assertCount(1, $matchMatch);
        self::assertSame('2026-10-03T20:00:00', $matchMatch[0]['start']);
        self::assertSame('2026-10-03T20:25:00', $matchMatch[0]['end']);
    }

    public function testTwoHomeMatchesDifferentGymsNoLongerConflictOnWarmupOnly(): void
    {
        // INVERSÉ (lot M) — same times as the founder case but DIFFERENT gyms. Under
        // the OLD rule the same-gym exception did not apply, so the full person
        // windows overlapped 20:15→20:25 (warm-up only) and a conflict stood. Now the
        // warm-up drops WHATEVER the gym: SM2 [18:30→20:25], SM1 [20:45→22:30] — the
        // shared person reaches SM1 by its kickoff, so NO conflict.
        $sm2 = $this->fixture('fx-sm2', self::TEAM_1, '2026-10-03', '18:30');
        $sm2->setVenueId('jdr');
        $sm1 = $this->fixture('fx-sm1', self::TEAM_2, '2026-10-03', '20:45');
        $sm1->setVenueId('other');
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        $matchMatch = array_values(array_filter(
            $this->detect([$sm2, $sm1], $links, profilesByTeam: [self::TEAM_1 => new MatchDurationProfile(115, 30)]),
            static fn (array $c): bool => 'MATCH_MATCH' === $c['type'],
        ));
        self::assertSame([], $matchMatch);
    }

    public function testTwoHomeMatchesOneWithoutVenueNoLongerConflictOnWarmupOnly(): void
    {
        // INVERSÉ (lot M) — SM1 has NO venue (unplaced). The old same-gym exception
        // needed both venues non-null, so the full windows overlapped 20:15→20:25 and
        // a conflict stood. The warm-up now drops regardless of the venue → no overlap.
        $sm2 = $this->fixture('fx-sm2', self::TEAM_1, '2026-10-03', '18:30');
        $sm2->setVenueId('jdr');
        $sm1 = $this->fixture('fx-sm1', self::TEAM_2, '2026-10-03', '20:45'); // no venue
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        $matchMatch = array_values(array_filter(
            $this->detect([$sm2, $sm1], $links, profilesByTeam: [self::TEAM_1 => new MatchDurationProfile(115, 30)]),
            static fn (array $c): bool => 'MATCH_MATCH' === $c['type'],
        ));
        self::assertSame([], $matchMatch);
    }

    public function testTwoMatchesSameGymButOneAwayNoLongerConflictOnWarmupOnly(): void
    {
        // INVERSÉ (lot M) — same gym, same times, but SM1 is AWAY. The old rule
        // required both sides HOME, so the full windows overlapped 20:15→20:25 and a
        // conflict stood. The warm-up now drops for the away side too (travel kept,
        // here zero) → SM1 [20:45→22:30], no overlap with SM2 [18:30→20:25].
        $sm2 = $this->fixture('fx-sm2', self::TEAM_1, '2026-10-03', '18:30');
        $sm2->setVenueId('jdr');
        $sm1 = $this->awayFixture('fx-sm1', self::TEAM_2, '2026-10-03', '20:45');
        $sm1->setVenueId('jdr');
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        $matchMatch = array_values(array_filter(
            $this->detect([$sm2, $sm1], $links, profilesByTeam: [self::TEAM_1 => new MatchDurationProfile(115, 30)]),
            static fn (array $c): bool => 'MATCH_MATCH' === $c['type'],
        ));
        self::assertSame([], $matchMatch);
    }

    public function testTwoHomeMatchesRealOverlapStillConflictAcrossGyms(): void
    {
        // Contre-exemple à recouvrement RÉEL (lot M) — SM1 pulled to 20:00 (before SM2
        // ends 20:25), DIFFERENT gyms. Even with the warm-up dropped everywhere, the
        // shared person cannot be at SM1's 20:00 kickoff while SM2 runs until 20:25 →
        // the conflict STANDS, its bounds are the conflict-window intersection.
        $sm2 = $this->fixture('fx-sm2', self::TEAM_1, '2026-10-03', '18:30');
        $sm2->setVenueId('jdr');
        $sm1 = $this->fixture('fx-sm1', self::TEAM_2, '2026-10-03', '20:00');
        $sm1->setVenueId('other');
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        $matchMatch = array_values(array_filter(
            $this->detect([$sm2, $sm1], $links, profilesByTeam: [self::TEAM_1 => new MatchDurationProfile(115, 30)]),
            static fn (array $c): bool => 'MATCH_MATCH' === $c['type'],
        ));
        self::assertCount(1, $matchMatch);
        self::assertSame('2026-10-03T20:00:00', $matchMatch[0]['start']);
        self::assertSame('2026-10-03T20:25:00', $matchMatch[0]['end']);
    }

    public function testTwoHomeMatchesSameGymEqualKickoffsKeepTheFullOverlap(): void
    {
        // Same gym, EQUAL kickoffs 18:30: neither is « the later », the rule keeps
        // both full person windows → MATCH_MATCH conserved (current behaviour).
        $a = $this->fixture('fx-a', self::TEAM_1, '2026-10-03', '18:30');
        $a->setVenueId('jdr');
        $b = $this->fixture('fx-b', self::TEAM_2, '2026-10-03', '18:30');
        $b->setVenueId('jdr');
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];

        $matchMatch = array_values(array_filter(
            $this->detect([$a, $b], $links),
            static fn (array $c): bool => 'MATCH_MATCH' === $c['type'],
        ));
        self::assertCount(1, $matchMatch);
    }

    public function testHomeMatchSecondSameGymAsTrainingWarmupOnlyOverlapIsSilent(): void
    {
        // 2026-10-04 Sunday. Sister team-2 trains 19:00→20:30 in the slot's gym
        // 'venue'; the team-1 HOME match kicks off 20:45 in the SAME gym (person
        // window from 20:15 via the 30-min warm-up). The match is « second » (20:45
        // > 19:00): the coach is already on site, its warm-up drops → effective
        // window 20:45→… clears the 20:30 session → NO MATCH_TRAINING (the old
        // warm-up overlap 20:15→20:30 false-alarmed).
        $fixture = $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '20:45');
        $fixture->setVenueId('venue');
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '19:00', 90)]; // 19:00→20:30

        $training = array_values(array_filter(
            $this->detect([$fixture], $links, self::BASELINE, [], [self::BASELINE => $slots]),
            static fn (array $c): bool => 'MATCH_TRAINING' === $c['type'],
        ));
        self::assertSame([], $training);
    }

    public function testHomeMatchSecondSameGymRealOverlapStartsAtKickoff(): void
    {
        // Same as above but the session runs to 21:00: the match's effective window
        // 20:45→22:30 now bites it → MATCH_TRAINING, start = the kickoff (20:45).
        $fixture = $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '20:45');
        $fixture->setVenueId('venue');
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '19:00', 120)]; // 19:00→21:00

        $training = array_values(array_filter(
            $this->detect([$fixture], $links, self::BASELINE, [], [self::BASELINE => $slots]),
            static fn (array $c): bool => 'MATCH_TRAINING' === $c['type'],
        ));
        self::assertCount(1, $training);
        self::assertSame('2026-10-04T20:45:00', $training[0]['start']);
    }

    public function testHomeMatchDifferentGymFromTrainingNoLongerConflictsOnWarmupOnly(): void
    {
        // INVERSÉ (lot M) — match in gym 'other', training in the slot's gym 'venue':
        // different gyms. Under the OLD rule the same-gym special case did not apply,
        // so the full window (from 20:15, warm-up) overlapped the 19:00→20:30 session
        // and a conflict stood. The warm-up now drops WHATEVER the gym: the match's
        // conflict window 20:45→22:30 clears the session → no MATCH_TRAINING.
        $fixture = $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '20:45');
        $fixture->setVenueId('other');
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '19:00', 90)]; // 19:00→20:30

        $training = array_values(array_filter(
            $this->detect([$fixture], $links, self::BASELINE, [], [self::BASELINE => $slots]),
            static fn (array $c): bool => 'MATCH_TRAINING' === $c['type'],
        ));
        self::assertSame([], $training);
    }

    // ── Lot M : le cas Inès, arrivée exactement au coup d'envoi (demi-ouvert) ──

    public function testInesArrivalExactlyAtTheSecondKickoffIsNoConflict(): void
    {
        // Le cas terrain qui fonde la règle : Inès coache U13F2 à l'EXTÉRIEUR (coup
        // d'envoi 18:00) et JOUE SF2 à DOMICILE (coup d'envoi 19:30). L'échauffement de
        // SF2 sort de l'empreinte de personne → son côté de conflit commence au coup
        // d'envoi 19:30. On fige l'arithmétique pour que U13F2 finisse EXACTEMENT à
        // 19:30 : match 70 min + retour 20 min (aller-retour 40) après 18:00 → fin
        // 19:30. La fin du premier engagement TOUCHE l'arrivée du second (19:30 = 19:30)
        // → chevauchement DEMI-OUVERT, aucun conflit. Verdict fondateur : « pas de
        // conflit du tout, et même règle pour le coach » (ici coach d'un côté, joueuse
        // de l'autre : la règle ne distingue pas les rôles).
        $u13f2 = $this->awayFixture('fx-u13f2', self::TEAM_1, '2026-10-10', '18:00');
        $sf2 = $this->fixture('fx-sf2', self::TEAM_2, '2026-10-10', '19:30');
        $sf2->setVenueId('gym-sf2');
        // Inès : coach de U13F2 (MAIN), joueuse de SF2.
        $links = [$this->link(self::COACH_A, self::TEAM_1, TeamCoachRole::MAIN)];
        $memberships = [$this->membership(self::COACH_A, self::TEAM_2)];
        // U13F2 : match 70 min ; aller-retour 40 min (retour 20) → fin = 18:00 + 70 + 20 = 19:30.
        $profiles = [self::TEAM_1 => new MatchDurationProfile(70, 20)];

        $conflicts = $this->detect([$u13f2, $sf2], $links, null, [], [], [], [], [], [], [], [], $profiles, null, $memberships, ['fx-u13f2' => 40]);

        self::assertSame([], $conflicts, 'arrivée pile au coup d\'envoi = pas de conflit (demi-ouvert, même règle coach/joueuse)');
    }

    public function testInesArrivalOneMinutePastKickoffIsAConflict(): void
    {
        // Le contre-témoin du demi-ouvert : si U13F2 finit UNE minute APRÈS le coup
        // d'envoi de SF2 (retour à 19:31 pour un coup d'envoi 19:30), Inès dépasse le
        // coup d'envoi → conflit RÉEL. Retour = 18:00 + 70 + 21 = 19:31.
        $u13f2 = $this->awayFixture('fx-u13f2', self::TEAM_1, '2026-10-10', '18:00');
        $sf2 = $this->fixture('fx-sf2', self::TEAM_2, '2026-10-10', '19:30');
        $sf2->setVenueId('gym-sf2');
        $links = [$this->link(self::COACH_A, self::TEAM_1, TeamCoachRole::MAIN)];
        $memberships = [$this->membership(self::COACH_A, self::TEAM_2)];
        // aller-retour 42 → retour 21 → fin U13F2 = 18:00 + 70 + 21 = 19:31 > 19:30.
        $profiles = [self::TEAM_1 => new MatchDurationProfile(70, 20)];

        $conflicts = array_values(array_filter(
            $this->detect([$u13f2, $sf2], $links, null, [], [], [], [], [], [], [], [], $profiles, null, $memberships, ['fx-u13f2' => 42]),
            static fn (array $c): bool => 'MATCH_MATCH' === $c['type'],
        ));

        self::assertCount(1, $conflicts, 'un dépassement réel du coup d\'envoi (retour 19:31 > 19:30) reste un conflit');
    }

    public function testTrainingStartingAfterTheKickoffKeepsTheConflict(): void
    {
        // The training is « second » — it starts (17:00) AFTER the 16:00 kickoff — so
        // there is no warm-up to strip: the match keeps its full person window and the
        // tail overlap 17:00→17:45 stands → MATCH_TRAINING conserved, even same-gym.
        $fixture = $this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00'); // 15:30→17:45
        $fixture->setVenueId('venue');
        $links = [$this->link(self::COACH_A, self::TEAM_1), $this->link(self::COACH_A, self::TEAM_2)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_2, 7, '17:00', 90)]; // 17:00→18:30

        $training = array_values(array_filter(
            $this->detect([$fixture], $links, self::BASELINE, [], [self::BASELINE => $slots]),
            static fn (array $c): bool => 'MATCH_TRAINING' === $c['type'],
        ));
        self::assertCount(1, $training);
        self::assertSame('2026-10-04T17:00:00', $training[0]['start']);
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
     * @param list<Fixture>                                                                          $fixtures
     * @param list<TeamCoach>                                                                        $links
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}> $overlayPeriods
     * @param array<string, list<ScheduleSlotTemplate>>                                              $slotsBySchedule
     * @param list<VenueUnavailability>                                                              $unavailabilities
     *
     * @return list<array<string, mixed>>
     */
    /**
     * @param list<Fixture>                                                                          $fixtures
     * @param list<TeamCoach>                                                                        $links
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}> $overlayPeriods
     * @param array<string, list<ScheduleSlotTemplate>>                                              $slotsBySchedule
     * @param list<VenueUnavailability>                                                              $unavailabilities
     * @param list<CoachPlayerMembership>                                                            $playerMemberships
     *
     * @return list<array<string, mixed>>
     */
    private function detect(array $fixtures, array $links, ?string $baselineScheduleId = null, array $overlayPeriods = [], array $slotsBySchedule = [], array $unavailabilities = [], array $habits = [], array $teamLinks = [], array $matchWindows = [], array $envelope = [], array $competitions = [], array $profilesByTeam = [], ?DateTimeImmutable $clubToday = null, array $playerMemberships = [], array $roundTripByFixtureId = []): array
    {
        // Lot M — the TEAM_LINK family left the radar; the detector no longer takes
        // team links. `$teamLinks` is kept in THIS helper's positional shape only so
        // the many callers that pass later args positionally do not all shift; it is
        // never forwarded.
        unset($teamLinks);

        return new MatchConflictDetector(new MatchFootprint, new EffectiveScheduleResolver, new AwayKickoffEstimator)
            ->detect($fixtures, $links, $baselineScheduleId, $overlayPeriods, $slotsBySchedule, $unavailabilities, $habits, $matchWindows, $envelope, $competitions, $profilesByTeam, $roundTripByFixtureId, $clubToday, $playerMemberships);
    }

    private function membership(string $coachId, string $teamId, bool $active = true): CoachPlayerMembership
    {
        $membership = new CoachPlayerMembership;
        $membership->setClubId('club');
        $membership->setSeasonId('season');
        $membership->setCoachId($coachId);
        $membership->setTeamId($teamId);
        $membership->setIsActive($active);

        return $membership;
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

    /**
     * The two sides of a MATCH_MATCH conflict, keyed by their teamId (the view
     * order is chronological, so this makes assertions independent of it).
     *
     * @param array<string, mixed> $conflict
     *
     * @return array<string, array<string, mixed>>
     */
    private function sidesByTeam(array $conflict): array
    {
        $out = [];
        foreach (['left', 'right'] as $key) {
            /** @var array<string, mixed> $side */
            $side = $conflict[$key];
            /** @var string $teamId */
            $teamId = $side['teamId'];
            $out[$teamId] = $side;
        }

        return $out;
    }

    /** Ids are DB-generated (no setter) — set the private field for pure-unit assertions. */
    private function setId(object $entity, string $id): void
    {
        $ref = new ReflectionProperty($entity, 'id');
        $ref->setValue($entity, $id);
    }
}
