<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Fixture;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\VenueUnavailability;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Service\EffectiveScheduleResolver;
use App\Service\VenueUnavailabilityImpact;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * NR de la surface planning (axe §7.1, lecture seule) — the impact card must
 * count trainings with the SAME ADR-0002 rules as the match radar: an active
 * period CAPTURES its dates (overlay null = no training → no phantom alert),
 * outside any period the season baseline applies.
 */
#[Group('unit')]
final class VenueUnavailabilityImpactTest extends TestCase
{
    private const BASELINE = 'schedule-baseline';

    private const VENUE = 'venue-armand';

    public function testCountsBaselineOccurrencesAndAffectedFixtures(): void
    {
        // Range 2027-02-04 (Thu) → 2027-02-28 (Sun) holds 4 Saturdays; the
        // baseline has ONE Saturday slot at Armand + one at another venue.
        $slots = [
            $this->slot('sl-1', self::BASELINE, self::VENUE, 6),
            $this->slot('sl-2', self::BASELINE, 'venue-mateo', 6),
        ];
        $fixtures = [
            $this->fixture('fx-in', self::VENUE, '2027-02-14'),
            $this->fixture('fx-out', self::VENUE, '2027-03-06'),
            $this->fixture('fx-other', 'venue-mateo', '2027-02-14'),
        ];

        $items = $this->impact()->build(
            [$this->unavailability('2027-02-04', '2027-02-28', 'travaux')],
            $fixtures,
            self::BASELINE,
            [],
            [self::BASELINE => $slots],
        );

        self::assertCount(1, $items);
        self::assertSame('travaux', $items[0]['label']);
        self::assertSame(4, $items[0]['trainingOccurrences']);
        self::assertSame(1, $items[0]['trainingSlotCount']);
        self::assertSame(['fx-in'], array_column($items[0]['affectedFixtures'], 'fixtureId'));
    }

    public function testAnActivePeriodWithoutOverlayCapturesItsDatesNoPhantomAlert(): void
    {
        // Period 2027-02-12 → 2027-02-21 captures the Saturdays 13 & 20 with NO
        // overlay (plan not validated → no training at all): only the Saturdays
        // 6 and 27 still train on the baseline. A phantom count would cry wolf.
        $slots = [$this->slot('sl-1', self::BASELINE, self::VENUE, 6)];
        $period = [
            'start' => new DateTimeImmutable('2027-02-12'),
            'end' => new DateTimeImmutable('2027-02-21'),
            'scheduleId' => null,
        ];

        $items = $this->impact()->build(
            [$this->unavailability('2027-02-04', '2027-02-28', null)],
            [],
            self::BASELINE,
            [$period],
            [self::BASELINE => $slots],
        );

        self::assertSame(2, $items[0]['trainingOccurrences']);
    }

    public function testAPeriodOverlayCountsItsOwnSlotsNotTheBaselines(): void
    {
        // Same period but WITH an overlay carrying 2 Saturday slots at the
        // venue: captured Saturdays count the overlay's slots, not the base's.
        $overlayId = 'schedule-overlay';
        $slotsBySchedule = [
            self::BASELINE => [$this->slot('sl-1', self::BASELINE, self::VENUE, 6)],
            $overlayId => [
                $this->slot('sl-2', $overlayId, self::VENUE, 6),
                $this->slot('sl-3', $overlayId, self::VENUE, 6),
            ],
        ];
        $period = [
            'start' => new DateTimeImmutable('2027-02-12'),
            'end' => new DateTimeImmutable('2027-02-21'),
            'scheduleId' => $overlayId,
        ];

        $items = $this->impact()->build(
            [$this->unavailability('2027-02-04', '2027-02-28', null)],
            [],
            self::BASELINE,
            [$period],
            $slotsBySchedule,
        );

        // Saturdays 6 & 27 → 1 baseline slot each (2) ; 13 & 20 → 2 overlay
        // slots each (4).
        self::assertSame(6, $items[0]['trainingOccurrences']);
        self::assertSame(3, $items[0]['trainingSlotCount']);
    }

    public function testNestedPeriodsCountTheNarrowestChildNotTheBroaderRoot(): void
    {
        // P4-188 — a root closure (wide, NO plan → null) split into a narrower
        // child pointing at an overlay. On the Saturdays the child covers, the
        // NARROWEST period wins: the overlay's slots count, the root's null does
        // not suspend them. On the Saturdays only the root covers, nothing trains.
        $overlayId = 'schedule-overlay';
        $slotsBySchedule = [
            self::BASELINE => [$this->slot('sl-1', self::BASELINE, self::VENUE, 6)],
            $overlayId => [$this->slot('sl-2', $overlayId, self::VENUE, 6)],
        ];
        $root = [
            'start' => new DateTimeImmutable('2027-02-01'),
            'end' => new DateTimeImmutable('2027-02-28'),
            'scheduleId' => null, // root closure, no plan
        ];
        $child = [
            'start' => new DateTimeImmutable('2027-02-12'),
            'end' => new DateTimeImmutable('2027-02-21'),
            'scheduleId' => $overlayId, // captures Saturdays 13 & 20
        ];

        $items = $this->impact()->build(
            [$this->unavailability('2027-02-04', '2027-02-28', null)],
            [],
            self::BASELINE,
            [$root, $child], // root listed first: the narrow child must still win
            $slotsBySchedule,
        );

        // Saturdays 6 & 27 → root only (null → no training). Saturdays 13 & 20 →
        // narrowest child → 1 overlay slot each = 2 occurrences, 1 distinct slot.
        self::assertSame(2, $items[0]['trainingOccurrences']);
        self::assertSame(1, $items[0]['trainingSlotCount']);
    }

    private function impact(): VenueUnavailabilityImpact
    {
        return new VenueUnavailabilityImpact(new EffectiveScheduleResolver);
    }

    private function unavailability(string $from, string $until, ?string $label): VenueUnavailability
    {
        $unavailability = new VenueUnavailability;
        $unavailability->setClubId('club');
        $unavailability->setSeasonId('season');
        $unavailability->setVenueId(self::VENUE);
        $unavailability->setStartDate(new DateTimeImmutable($from));
        $unavailability->setEndDate(new DateTimeImmutable($until));
        $unavailability->setLabel($label);

        return $unavailability;
    }

    private function fixture(string $id, string $venueId, string $date): Fixture
    {
        $fixture = new Fixture;
        $this->setId($fixture, $id);
        $fixture->setTeamId('team');
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $fixture->setVenueId($venueId);
        $fixture->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);

        return $fixture;
    }

    private function slot(string $id, string $scheduleId, string $venueId, int $dayOfWeek): ScheduleSlotTemplate
    {
        $slot = new ScheduleSlotTemplate;
        $this->setId($slot, $id);
        $slot->setScheduleId($scheduleId);
        $slot->setTeamId('team');
        $slot->setVenueId($venueId);
        $slot->setDayOfWeek($dayOfWeek);
        $slot->setStartTime(new DateTimeImmutable('17:00'));
        $slot->setDurationMinutes(90);

        return $slot;
    }

    private function setId(object $entity, string $id): void
    {
        $property = new ReflectionProperty($entity::class, 'id');
        $property->setValue($entity, $id);
    }
}
