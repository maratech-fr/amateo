"""Tests for VenueTrainingSlot capacity > 1 behavior.

Verifies that:
- capacity=2 allows two teams to share the same training slot.
- capacity=1 allows at most one team per training slot.
"""

from __future__ import annotations

import asyncio

from pydantic import ValidationError

from app.main import build_schedule
from app.schemas.input_schema import ScheduleInputSchema


def test_capacity_2_slot_allows_two_teams() -> None:
    """Two teams can share a slot with capacity=2."""
    input_data = ScheduleInputSchema.model_validate(
        {
            "clubId": "club-capacity-2",
            "seasonId": "season-capacity-2",
            "teams": [
                {
                    "id": "team-a",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 3,
                    "name": "Team A",
                    "sessionsPerWeek": 1,
                },
                {
                    "id": "team-b",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 3,
                    "name": "Team B",
                    "sessionsPerWeek": 1,
                },
            ],
            "venues": [
                {
                    "id": "venue-1",
                    "name": "Gymnasium",
                    "trainingSlots": [
                        {"dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 90, "capacity": 2},
                    ],
                },
            ],
            "slotTemplates": [],
        }
    )

    result = asyncio.run(build_schedule(input_data))

    assert result.status != "failed", f"Solver failed with capacity=2 and 2 teams; status={result.status}"
    placed_team_ids = {slot.team_id for slot in result.slots}
    assert "team-a" in placed_team_ids, "team-a should be placed with capacity=2"
    assert "team-b" in placed_team_ids, "team-b should be placed with capacity=2"


def test_capacity_1_slot_allows_only_one_team() -> None:
    """Two teams cannot both occupy the same slot when capacity=1."""
    input_data = ScheduleInputSchema.model_validate(
        {
            "clubId": "club-capacity-1",
            "seasonId": "season-capacity-1",
            "teams": [
                {
                    "id": "team-a",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 3,
                    "name": "Team A",
                    "sessionsPerWeek": 1,
                },
                {
                    "id": "team-b",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 3,
                    "name": "Team B",
                    "sessionsPerWeek": 1,
                },
            ],
            "venues": [
                {
                    "id": "venue-1",
                    "name": "Gymnasium",
                    "trainingSlots": [
                        {"dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 90, "capacity": 1},
                    ],
                },
            ],
            "slotTemplates": [],
        }
    )

    result = asyncio.run(build_schedule(input_data))

    assert result.status != "failed", f"Solver failed unexpectedly; status={result.status}"
    placed_team_ids = {slot.team_id for slot in result.slots}
    # With capacity=1, at most one team can occupy the slot
    assert len(placed_team_ids) <= 1, f"Expected at most 1 team placed with capacity=1, got {placed_team_ids}"
    # At least one team should be in the unplaced list
    assert len(result.unplaced) >= 1, (
        f"Expected at least 1 unplaced team with capacity=1, got unplaced={result.unplaced}"
    )


def test_capacity_0_slot_is_rejected_by_validation() -> None:
    try:
        ScheduleInputSchema.model_validate(
            {
                "clubId": "club-capacity-0",
                "seasonId": "season-capacity-0",
                "teams": [
                    {
                        "id": "team-a",
                        "sportCategoryId": "sc-1",
                        "priorityTierId": 3,
                        "name": "Team A",
                        "sessionsPerWeek": 1,
                    },
                ],
                "venues": [
                    {
                        "id": "venue-1",
                        "name": "Gymnasium",
                        "trainingSlots": [
                            {"dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 90, "capacity": 0},
                        ],
                    },
                ],
                "slotTemplates": [],
            }
        )
        raise AssertionError("Expected ValidationError for capacity=0")
    except ValidationError as exc:
        assert "greater than or equal to 1" in str(exc)
