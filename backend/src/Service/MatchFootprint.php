<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fixture;
use App\Enum\FixtureHomeAway;
use DateTimeImmutable;

/**
 * Time footprint of a fixture on a person's availability timeline (spec
 * gestion-matchs, décision empreinte-temps). This is the atom the conflict
 * engine overlaps across coaches/players.
 *
 * Durations come from a {@see MatchDurationProfile} the CALLER resolves per
 * category (P2-54 RMM-9) — they are no longer fixed constants here:
 * - warm-up before kickoff: profile.warmupMinutes
 * - match itself: profile.matchMinutes
 *
 * ⚠ P2-54 — the after-match SHOWER and BUFFER are GONE (founder decision: the
 * changing room leaves the footprint). Away and home now differ ONLY by the
 * travel leg:
 * - HOME: warm-up before, match after.
 * - AWAY: outbound travel + warm-up before, match + return travel after.
 *
 * The travel leg needs the travel matrix (PR-3); here it is an injected
 * parameter (0 by default), so away footprints are computed with the warm-up +
 * match now and gain the travel span when the matrix lands.
 */
final class MatchFootprint
{
    /**
     * The [start, end] window a person is occupied by this fixture, or null
     * when the kickoff is unknown (an unplaced home fixture / an away fixture
     * with no estimated time yet).
     *
     * @param int $roundTripTravelMinutes total there-and-back travel (away only); 0 until the travel matrix exists
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null
     */
    public function occupancy(Fixture $fixture, MatchDurationProfile $profile, int $roundTripTravelMinutes = 0): ?array
    {
        $kickoff = $this->kickoffMoment($fixture);
        if (!$kickoff instanceof DateTimeImmutable) {
            return null;
        }

        // Half the round-trip is the outbound leg (before warm-up), the rest the
        // return leg (after the match). Home = no travel. See minutesBefore/After.
        return [
            'start' => $kickoff->modify(\sprintf('-%d minutes', $this->minutesBefore($fixture, $profile, $roundTripTravelMinutes))),
            'end' => $kickoff->modify(\sprintf('+%d minutes', $this->minutesAfter($fixture, $profile, $roundTripTravelMinutes))),
        ];
    }

    /**
     * The occupancy window for an EXPLICIT kickoff time — the estimation path
     * (P1-4 PR C): an away fixture without a real hour borrows its team's
     * habitual kickoff. `occupancy()` stays the real-hour path, untouched;
     * nothing is ever written back to the fixture.
     *
     * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
     */
    public function occupancyAt(Fixture $fixture, DateTimeImmutable $kickoffTime, MatchDurationProfile $profile, int $roundTripTravelMinutes = 0): array
    {
        $kickoff = $fixture->getMatchDate()->setTime(
            (int) $kickoffTime->format('H'),
            (int) $kickoffTime->format('i'),
        );

        return [
            'start' => $kickoff->modify(\sprintf('-%d minutes', $this->minutesBefore($fixture, $profile, $roundTripTravelMinutes))),
            'end' => $kickoff->modify(\sprintf('+%d minutes', $this->minutesAfter($fixture, $profile, $roundTripTravelMinutes))),
        ];
    }

    /**
     * Total NOMINAL occupied minutes (before + after the kickoff), or null when
     * the kickoff is unknown. Computed from the profile, NOT from a timestamp
     * delta — a footprint spanning a DST transition must still report its
     * wall-clock duration, not the ±60 min the offset shift would add.
     */
    public function occupancyMinutes(Fixture $fixture, MatchDurationProfile $profile, int $roundTripTravelMinutes = 0): ?int
    {
        if (!$this->kickoffMoment($fixture) instanceof DateTimeImmutable) {
            return null;
        }

        return $this->minutesBefore($fixture, $profile, $roundTripTravelMinutes) + $this->minutesAfter($fixture, $profile, $roundTripTravelMinutes);
    }

    private function minutesBefore(Fixture $fixture, MatchDurationProfile $profile, int $roundTripTravelMinutes): int
    {
        $travelOut = FixtureHomeAway::AWAY === $fixture->getHomeAway() ? intdiv($roundTripTravelMinutes, 2) : 0;

        return $travelOut + $profile->warmupMinutes;
    }

    private function minutesAfter(Fixture $fixture, MatchDurationProfile $profile, int $roundTripTravelMinutes): int
    {
        $travelBack = FixtureHomeAway::AWAY === $fixture->getHomeAway() ? $roundTripTravelMinutes - intdiv($roundTripTravelMinutes, 2) : 0;

        return $profile->matchMinutes + $travelBack;
    }

    /** The kickoff as a full moment (match date + kickoff time), or null if not set. */
    private function kickoffMoment(Fixture $fixture): ?DateTimeImmutable
    {
        $time = $fixture->getKickoffTime();
        if (!$time instanceof DateTimeImmutable) {
            return null;
        }

        return $fixture->getMatchDate()->setTime(
            (int) $time->format('H'),
            (int) $time->format('i'),
        );
    }
}
