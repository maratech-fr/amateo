<?php

declare(strict_types=1);

namespace App\Service;

use App\Plans\SoclePlan;
use App\Repository\CoachRepository;
use App\Repository\ConstraintRepository;
use App\Repository\TeamRepository;
use App\Repository\VenueRepository;
use App\Repository\VenueTrainingSlotRepository;

/**
 * A10 (DoS "generation bomb" guard): before a generation is queued, bound the size of
 * the club/season problem so a mid-size-but-explosive payload can never monopolise the
 * club's single generation slot for the whole solver timeout. Caps are generous (~10x a
 * large FFBB club) — they trip only on a genuine bomb. Counts are aligned with what the
 * engine payload actually carries (`ScheduleConstraintBuilder`): constraints are counted
 * PERMANENT-only (calendarEntryId IS NULL — the base-plan set), never the dated overlay
 * rows the solver never receives.
 *
 * Dimensions the engine also bounds (slot_templates, priority_tiers, per-team tags) are
 * left to the engine's `max_length`: those reject INSTANTLY at Pydantic validation (before
 * any solve, so the generation lock is released in milliseconds — not a DoS), and adding
 * raw-row counts for them here would risk falsely blocking legitimate clubs.
 */
final class GenerationComplexityGuard
{
    public const MAX_TEAMS = 200;
    public const MAX_VENUES = 50;
    public const MAX_COACHES = 200;
    public const MAX_AVAILABILITY_SLOTS = 3000;
    public const MAX_CONSTRAINTS = 500;
    public const MAX_TEAM_VENUE_PRODUCT = 2000;

    public function __construct(
        private readonly TeamRepository $teamRepository,
        private readonly VenueRepository $venueRepository,
        private readonly CoachRepository $coachRepository,
        private readonly VenueTrainingSlotRepository $venueTrainingSlotRepository,
        private readonly ConstraintRepository $constraintRepository,
        private readonly SoclePlan $soclePlan,
    ) {}

    /**
     * Pure cap evaluation over the counted dimensions — the first breached cap, or null.
     *
     * @return array{cap: string, count: int, limit: int}|null
     */
    public static function evaluate(int $teams, int $venues, int $coaches, int $slots, int $constraints): ?array
    {
        if ($teams > self::MAX_TEAMS) {
            return ['cap' => 'teams', 'count' => $teams, 'limit' => self::MAX_TEAMS];
        }
        if ($venues > self::MAX_VENUES) {
            return ['cap' => 'venues', 'count' => $venues, 'limit' => self::MAX_VENUES];
        }
        if ($coaches > self::MAX_COACHES) {
            return ['cap' => 'coaches', 'count' => $coaches, 'limit' => self::MAX_COACHES];
        }
        if ($slots > self::MAX_AVAILABILITY_SLOTS) {
            return ['cap' => 'availability_slots', 'count' => $slots, 'limit' => self::MAX_AVAILABILITY_SLOTS];
        }
        if ($constraints > self::MAX_CONSTRAINTS) {
            return ['cap' => 'constraints', 'count' => $constraints, 'limit' => self::MAX_CONSTRAINTS];
        }

        // The dominant driver of CP-SAT model size: every team can be placed in every venue.
        $product = $teams * $venues;
        if ($product > self::MAX_TEAM_VENUE_PRODUCT) {
            return ['cap' => 'teams_x_venues', 'count' => $product, 'limit' => self::MAX_TEAM_VENUE_PRODUCT];
        }

        return null;
    }

    /**
     * @return array{cap: string, count: int, limit: int}|null null when within limits;
     *                                                         otherwise the first breached cap
     */
    public function firstViolation(string $clubId, string $seasonId): ?array
    {
        $scope = ['clubId' => $clubId, 'seasonId' => $seasonId];

        return self::evaluate(
            $this->teamRepository->count($scope),
            $this->venueRepository->count($scope),
            $this->coachRepository->count($scope),
            // Base plan uses the season plan's slots only — period slots must not
            // inflate the base availability_slots cap.
            $this->venueTrainingSlotRepository->count($scope + ['schedulePlanId' => $this->soclePlan->idOf($seasonId)]),
            // Permanent constraints only — the exact set the base-plan payload carries.
            // ⚠️ Ancre DIFFÉRENTE de la ligne au-dessus, et c'est voulu : les contraintes
            // DATÉES restent sur la CalendarEntry (le FAIT), le radar de conflits les lit
            // par l'entrée — cf. ADR-0002 inv. 5, correction du 2026-07-17.
            $this->constraintRepository->count($scope + ['calendarEntryId' => null]),
        );
    }
}
