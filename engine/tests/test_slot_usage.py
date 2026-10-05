"""Tests for slot usage — all teams should be placed when enough slots exist.

Verifies that the unplaced penalty in the objective forces the solver to
place every team (including low-tier D teams) when there are enough slots.
"""

from __future__ import annotations

import asyncio

from app.main import build_schedule
from app.schemas.input_schema import ScheduleInputSchema


def _build_input_with_three_tiers() -> ScheduleInputSchema:
    """Build minimal input: 3 teams (S, C, D) and 1 venue with 5 slots."""
    return ScheduleInputSchema.model_validate(
        {
            "clubId": "club-slot-usage",
            "seasonId": "season-slot-usage",
            "teams": [
                {
                    "id": "team-s",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 1,  # S tier (weight=10000)
                    "name": "Team S",
                    "sessionsPerWeek": 1,
                },
                {
                    "id": "team-c",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 4,  # C tier (weight=10)
                    "name": "Team C",
                    "sessionsPerWeek": 1,
                },
                {
                    "id": "team-d",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 5,  # D tier (weight=1)
                    "name": "Team D",
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
                        {"dayOfWeek": 3, "startTime": "18:00", "durationMinutes": 90, "capacity": 1},
                        {"dayOfWeek": 4, "startTime": "18:00", "durationMinutes": 90, "capacity": 1},
                        {"dayOfWeek": 5, "startTime": "18:00", "durationMinutes": 90, "capacity": 1},
                    ],
                },
            ],
            "slotTemplates": [],
        }
    )


def test_all_teams_placed_when_enough_slots() -> None:
    """All 3 teams (S, C, D tiers) must be placed when 5 slots exist."""
    input_data = _build_input_with_three_tiers()

    result = asyncio.run(build_schedule(input_data))

    assert result.status != "failed", f"Solver failed with 3 teams and 5 slots; status={result.status}"
    assert result.unplaced == [], f"Expected no unplaced teams, got unplaced={result.unplaced}"
    assert len(result.slots) >= 3, f"Expected at least 3 placed slots (one per team), got {len(result.slots)} slots"


def test_low_tier_d_team_placed_when_slots_available() -> None:
    """The D-tier team (lowest weight=1) must still be placed when slots exist."""
    input_data = _build_input_with_three_tiers()

    result = asyncio.run(build_schedule(input_data))

    assert result.status != "failed", f"Solver failed; status={result.status}"
    placed_team_ids = {slot.team_id for slot in result.slots}
    assert "team-d" in placed_team_ids, f"D-tier team must be placed when slots are available; placed={placed_team_ids}"
