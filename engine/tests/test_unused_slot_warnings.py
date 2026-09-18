"""RED test for unused_slot diagnostics.

Builds a minimal input with 3 available training slots but only 1 team
(sessionsPerWeek=1). The solver places the team in one slot, leaving the
other two available slots unused. The test asserts that an ``unused_slot``
WARNING diagnostic is emitted for each unused slot.
"""

from __future__ import annotations

import asyncio

from app.main import build_schedule
from app.schemas.input_schema import ScheduleInputSchema


def _build_input() -> ScheduleInputSchema:
    return ScheduleInputSchema.model_validate(
        {
            "clubId": "club-unused-slot",
            "seasonId": "season-unused-slot",
            "teams": [
                {
                    "id": "team-1",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 3,
                    "name": "Team 1",
                    "sessionsPerWeek": 1,
                    "isActive": True,
                },
            ],
            "venues": [
                {
                    "id": "venue-1",
                    "name": "Gymnase Test",
                    "isActive": True,
                    "trainingSlots": [
                        {"dayOfWeek": 1, "startTime": "17:30", "durationMinutes": 90, "capacity": 1},
                        {"dayOfWeek": 1, "startTime": "19:00", "durationMinutes": 90, "capacity": 1},
                        {"dayOfWeek": 1, "startTime": "20:30", "durationMinutes": 90, "capacity": 1},
                    ],
                },
            ],
            "slotTemplates": [],
        }
    )


def test_unused_slot_warnings_emitted_for_empty_slots() -> None:
    input_data = _build_input()
    result = asyncio.run(build_schedule(input_data))

    assert result.status != "failed", f"Solver failed unexpectedly; status={result.status}"

    unused_diags = [d for d in result.diagnostics if d.type == "unused_slot"]

    # One team placed in one slot -> two slots remain unused.
    assert len(unused_diags) == 2, (
        f"Expected 2 unused_slot diagnostics, got {len(unused_diags)}: {[d.model_dump() for d in unused_diags]}"
    )

    for diag in unused_diags:
        assert diag.severity == "WARNING", f"Expected WARNING severity, got {diag.severity} for {diag.message}"
        assert diag.venue_id == "venue-1", f"Expected venueId=venue-1, got {diag.venue_id}"
        assert diag.team_id is None, f"Expected teamId=None, got {diag.team_id}"
        assert diag.coach_id is None, f"Expected coachId=None, got {diag.coach_id}"
        assert diag.suggestions == [], f"Expected empty suggestions, got {diag.suggestions}"
        # Le message est possédé par le backend (DiagnosticMessageBuilder le reconstruit
        # inconditionnellement) : l'engine émet une chaîne vide. Les champs structurés
        # (venueId, dayOfWeek, startTime, durationMinutes) portent tout le nécessaire.
        assert diag.message == "", f"Expected engine to emit an empty message, got {diag.message!r}"
        assert diag.venue_id == "venue-1"
        assert diag.duration_minutes == 90

    # The two unused slots should correspond to the two slots not used by the solver.
    used_start_times = {slot.start_time.strftime("%H:%M") for slot in result.slots}
    available_start_times = {"17:30", "19:00", "20:30"}
    unused_start_times = available_start_times - used_start_times
    diag_start_times = {diag.start_time for diag in unused_diags}
    assert diag_start_times == unused_start_times, (
        f"Diagnostic start times {diag_start_times} != expected unused {unused_start_times}"
    )
