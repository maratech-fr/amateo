<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The three kinds of SchedulePlan (ADR-0002). A SchedulePlan is a first-order,
 * named container; its Schedules are its versions. SEASON = the base plan of the
 * whole season (one per season); CLOSURE / HOLIDAY = a bounded secondary plan
 * bound to a CalendarEntry period (its calendarEntryId is set).
 */
enum SchedulePlanType: string
{
    /** Les équipes cochées à la naissance du plan : des vacances reprennent avec l'équipe fanion et les importantes. */
    public function defaultTeamSelection(): PlanTeamDefault
    {
        return self::HOLIDAY === $this ? PlanTeamDefault::FLAGSHIP_AND_IMPORTANT : PlanTeamDefault::ALL;
    }
    case SEASON = 'SEASON';
    case CLOSURE = 'CLOSURE';
    case HOLIDAY = 'HOLIDAY';
}
