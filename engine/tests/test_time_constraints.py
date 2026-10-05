from __future__ import annotations

import asyncio

from app.main import build_schedule
from app.schemas.input_schema import ScheduleInputSchema


def test_time_hard_min_start_time_blocks_early_slot() -> None:
    input_data = ScheduleInputSchema.model_validate(
        {
            "clubId": "club-time-min-start",
            "seasonId": "season-time-min-start",
            "teams": [
                {
                    "id": "team-senior",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 3,
                    "name": "Team Senior",
                    "sessionsPerWeek": 1,
                },
            ],
            "venues": [
                {
                    "id": "venue-1",
                    "name": "Gymnasium",
                    "trainingSlots": [
                        {"dayOfWeek": 3, "startTime": "17:30", "durationMinutes": 90, "capacity": 1},
                        {"dayOfWeek": 3, "startTime": "19:00", "durationMinutes": 90, "capacity": 1},
                    ],
                },
            ],
            "constraints": [
                {
                    "id": "constraint-time-min-start",
                    "ruleType": "HARD",
                    "family": "TIME",
                    "scope": "TEAM",
                    "scopeTargetId": "team-senior",
                    "isActive": True,
                    "config": {"minStartTime": "18:50"},
                },
            ],
            "slotTemplates": [],
        }
    )

    result = asyncio.run(build_schedule(input_data))

    assert result.status != "failed"
    assert not any(slot.team_id == "team-senior" and slot.start_time == "17:30" for slot in result.slots)


def test_time_hard_max_start_time_blocks_late_slot() -> None:
    input_data = ScheduleInputSchema.model_validate(
        {
            "clubId": "club-time-max-start",
            "seasonId": "season-time-max-start",
            "teams": [
                {
                    "id": "team-jeune",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 3,
                    "name": "Team Jeune",
                    "sessionsPerWeek": 1,
                },
            ],
            "venues": [
                {
                    "id": "venue-1",
                    "name": "Gymnasium",
                    "trainingSlots": [
                        {"dayOfWeek": 3, "startTime": "19:00", "durationMinutes": 90, "capacity": 1},
                        {"dayOfWeek": 3, "startTime": "20:30", "durationMinutes": 90, "capacity": 1},
                    ],
                },
            ],
            "constraints": [
                {
                    "id": "constraint-time-max-start",
                    "ruleType": "HARD",
                    "family": "TIME",
                    "scope": "TEAM",
                    "scopeTargetId": "team-jeune",
                    "isActive": True,
                    "config": {"maxStartTime": "19:50"},
                },
            ],
            "slotTemplates": [],
        }
    )

    result = asyncio.run(build_schedule(input_data))

    assert result.status != "failed"
    assert not any(slot.team_id == "team-jeune" and slot.start_time == "20:30" for slot in result.slots)


def test_day_hard_forbidden_days_blocks_forbidden_weekday() -> None:
    input_data = ScheduleInputSchema.model_validate(
        {
            "clubId": "club-day-forbidden",
            "seasonId": "season-day-forbidden",
            "teams": [
                {
                    "id": "team-forbidden",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 3,
                    "name": "Team Forbidden",
                    "sessionsPerWeek": 1,
                },
            ],
            "venues": [
                {
                    "id": "venue-1",
                    "name": "Gymnasium",
                    "trainingSlots": [
                        {"dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 90, "capacity": 1},
                        {"dayOfWeek": 2, "startTime": "18:00", "durationMinutes": 90, "capacity": 1},
                    ],
                },
            ],
            "constraints": [
                {
                    "id": "constraint-day-forbidden",
                    "ruleType": "HARD",
                    "family": "DAY",
                    "scope": "TEAM",
                    "scopeTargetId": "team-forbidden",
                    "isActive": True,
                    "config": {"forbiddenDays": [1]},
                },
            ],
            "slotTemplates": [],
        }
    )

    result = asyncio.run(build_schedule(input_data))

    assert result.status != "failed"
    assert not any(slot.team_id == "team-forbidden" and slot.day_of_week == 1 for slot in result.slots)


def test_day_preferred_day_places_team_on_preferred_weekday() -> None:
    input_data = ScheduleInputSchema.model_validate(
        {
            "clubId": "club-day-preferred",
            "seasonId": "season-day-preferred",
            "teams": [
                {
                    "id": "team-preferred",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 3,
                    "name": "Team Preferred",
                    "sessionsPerWeek": 1,
                },
            ],
            "venues": [
                {
                    "id": "venue-1",
                    "name": "Gymnasium",
                    "trainingSlots": [
                        {"dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 90, "capacity": 1},
                        {"dayOfWeek": 2, "startTime": "18:00", "durationMinutes": 90, "capacity": 1},
                    ],
                },
            ],
            "constraints": [
                {
                    "id": "constraint-day-preferred",
                    "ruleType": "PREFERRED",
                    "family": "DAY",
                    "scope": "TEAM",
                    "scopeTargetId": "team-preferred",
                    "isActive": True,
                    "config": {"preferredDays": [2]},
                },
            ],
            "slotTemplates": [],
        }
    )

    result = asyncio.run(build_schedule(input_data))

    assert result.status != "failed"
    assert any(slot.team_id == "team-preferred" and slot.day_of_week == 2 for slot in result.slots)
