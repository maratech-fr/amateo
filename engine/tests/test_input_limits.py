"""A10: the input schema caps every list so an oversized "generation bomb" payload
is rejected at the boundary (Pydantic ValidationError -> FastAPI 422) before CP-SAT
builds. These bounds are the defense-in-depth last line; the backend pre-checks the
same caps before it ever dispatches to /generate.
"""

from __future__ import annotations

import asyncio

import pytest
from pydantic import ValidationError

from app.main import build_schedule
from app.schemas.input_schema import (
    MAX_CONSTRAINTS_EXPANDED,
    MAX_SLOTS_TOTAL,
    MAX_TEAMS,
    MAX_VENUES,
    ScheduleInputSchema,
)


def _team(i: int) -> dict[str, object]:
    return {
        "id": f"t{i}",
        "sportCategoryId": "sc-1",
        "priorityTierId": 1,
        "name": f"T{i}",
        "sessionsPerWeek": 2,
        "isActive": True,
    }


def _venue(i: int) -> dict[str, object]:
    return {"id": f"v{i}", "name": f"V{i}"}


def _payload(**over: object) -> dict[str, object]:
    base: dict[str, object] = {"clubId": "club", "seasonId": "season", "slotTemplates": []}
    base.update(over)
    return base


class TestInputLimits:
    def test_teams_over_cap_rejected(self) -> None:
        with pytest.raises(ValidationError):
            ScheduleInputSchema.model_validate(_payload(teams=[_team(i) for i in range(MAX_TEAMS + 1)]))

    def test_teams_at_cap_accepted(self) -> None:
        model = ScheduleInputSchema.model_validate(_payload(teams=[_team(i) for i in range(MAX_TEAMS)]))
        assert len(model.teams) == MAX_TEAMS

    def test_venues_over_cap_rejected(self) -> None:
        with pytest.raises(ValidationError):
            ScheduleInputSchema.model_validate(_payload(venues=[_venue(i) for i in range(MAX_VENUES + 1)]))

    def test_total_slots_over_cap_rejected(self) -> None:
        # Per-venue caps alone would let this through; the total-slots model_validator catches it.
        slot = {"dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 90}
        venues = [
            {"id": f"v{i}", "name": f"V{i}", "trainingSlots": [slot] * 300}
            for i in range(11)  # 11 x 300 = 3300 > 3000, each venue's 300 < per-venue cap
        ]
        assert MAX_SLOTS_TOTAL < 11 * 300
        with pytest.raises(ValidationError):
            ScheduleInputSchema.model_validate(_payload(venues=venues))

    def test_large_expanded_constraint_list_within_cap_is_accepted(self) -> None:
        # The backend fans out CLUB-scoped rules into raw(<=500) x teams(<=200) rows, so a per-rule
        # cap would false-block a legit club. A large expanded list (well under the EXPANDED
        # ceiling MAX_CONSTRAINTS_EXPANDED) still validates.
        constraints = [{"id": f"c{i}"} for i in range(10_000)]
        model = ScheduleInputSchema.model_validate(_payload(constraints=constraints))
        assert len(model.constraints) == 10_000

    def test_constraints_over_expanded_cap_rejected(self) -> None:
        # Beyond the EXPANDED ceiling raw(<=500) x teams(<=200), the payload is a bomb: rejected at
        # the boundary before CP-SAT ever builds.
        constraints = [{"id": f"c{i}"} for i in range(MAX_CONSTRAINTS_EXPANDED + 1)]
        with pytest.raises(ValidationError):
            ScheduleInputSchema.model_validate(_payload(constraints=constraints))

    def test_at_cap_payload_still_solves(self) -> None:
        # A max-teams / no-venue payload validates and solves instantly (all unplaced),
        # proving the cap boundary does not break the normal solve path.
        model = ScheduleInputSchema.model_validate(_payload(teams=[_team(i) for i in range(MAX_TEAMS)]))
        result = asyncio.run(build_schedule(model))
        assert result.status == "completed"
        assert len(result.unplaced) == MAX_TEAMS
