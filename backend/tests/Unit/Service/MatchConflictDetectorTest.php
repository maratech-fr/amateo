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
use App\Enum\FixtureStatus;
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

    public function testNarrowPointedChildBeatsABroaderUnpointedRootForTraining(): void
    {
        // P4-188 NR — the root period (large, no plan → scheduleId null) is
        // listed BEFORE its narrow « début » child pointing at a version that
        // carries the Sunday slot. The narrowest period wins, so the child's
        // training is checked and the clash surfaces. A « first covering period
        // wins » rule would have let the root's null suspend everything and
        // hidden the MATCH_TRAINING (the founder's faux négatif).
        $fixtures = [$this->fixture('fx-1', self::TEAM_1, '2026-10-04', '16:00')]; // Sunday, 15:30–17:45
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
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
        $slots = [self::OVERLAY => [$this->slot('sl-1', self::OVERLAY, self::TEAM_1, 7, '17:00', 90)]];

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
        $links = [$this->link(self::COACH_A, self::TEAM_1)];
        $slots = [$this->slot('sl-1', self::BASELINE, self::TEAM_1, 7, '17:00', 90)];

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
     * @param list<Fixture>                                                                          $fixtures
     * @param list<TeamCoach>                                                                        $links
     * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, scheduleId: string|null}> $overlayPeriods
     * @param array<string, list<ScheduleSlotTemplate>>                                              $slotsBySchedule
     * @param list<VenueUnavailability>                                                              $unavailabilities
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
