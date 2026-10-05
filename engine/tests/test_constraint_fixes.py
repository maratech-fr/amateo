from __future__ import annotations

import asyncio
from collections import Counter

from app.main import build_schedule
from app.schemas.input_schema import ScheduleInputSchema


def make_team(team_id: str, sessions_per_week: int) -> dict[str, object]:
    return {
        "id": team_id,
        "sportCategoryId": "sc-1",
        "priorityTierId": 3,
        "name": team_id.replace("-", " ").title(),
        "sessionsPerWeek": sessions_per_week,
    }


def make_slot(day_of_week: int, start_time: str, duration_minutes: int = 90) -> dict[str, object]:
    return {
        "dayOfWeek": day_of_week,
        "startTime": start_time,
        "durationMinutes": duration_minutes,
        "capacity": 1,
    }


def make_input(
    teams: list[dict[str, object]],
    constraints: list[dict[str, object]],
    slots_per_venue: list[dict[str, object]] | None = None,
) -> ScheduleInputSchema:
    return ScheduleInputSchema.model_validate(
        {
            "clubId": "club-constraint-fixes",
            "seasonId": "season-constraint-fixes",
            "teams": teams,
            "venues": [
                {
                    "id": "venue-1",
                    "name": "Gymnasium",
                    "trainingSlots": slots_per_venue or [],
                }
            ],
            "constraints": constraints,
            "slotTemplates": [],
        }
    )


def day_constraint(team_id: str, rule_type: str, config: dict[str, object]) -> dict[str, object]:
    return {
        "id": f"constraint-{team_id}-{rule_type.lower()}-day",
        "family": "DAY",
        "ruleType": rule_type,
        "scope": "TEAM",
        "scopeTargetId": team_id,
        "isActive": True,
        "config": config,
    }


def time_constraint(team_id: str, config: dict[str, object]) -> dict[str, object]:
    return {
        "id": f"constraint-{team_id}-time",
        "family": "TIME",
        "ruleType": "HARD",
        "scope": "TEAM",
        "scopeTargetId": team_id,
        "isActive": True,
        "config": config,
    }


def team_slots(result, team_id: str):
    return [slot for slot in result.slots if slot.team_id == team_id]


class TestForcedDays:
    def test_forced_single_day_sessions_per_week_1(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 1)],
            constraints=[day_constraint("team-1", "HARD", {"forcedDays": [3]})],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
                make_slot(5, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        assert result.status != "infeasible"
        assert len(slots) == 1
        assert slots[0].day_of_week == 3

    def test_forced_two_days_sessions_per_week_2(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 2)],
            constraints=[day_constraint("team-1", "HARD", {"forcedDays": [1, 3]})],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
                make_slot(5, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        days = Counter(slot.day_of_week for slot in slots)
        assert result.status != "infeasible"
        assert len(slots) == 2
        # forcedDays = "at least one session on the UNION of these days" (engine-only
        # vocabulary — the wizard 'uniquement' emits allowedDays, ENG-16). It does NOT
        # force one session per listed day; asserting days[1]==days[3]==1 rode on the
        # deterministic tie-break, not the model (ENG-19).
        assert days.get(1, 0) + days.get(3, 0) >= 1

    def test_forced_two_days_sessions_per_week_3(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 3)],
            constraints=[day_constraint("team-1", "HARD", {"forcedDays": [1, 3]})],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
                make_slot(5, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        days = Counter(slot.day_of_week for slot in slots)
        assert result.status != "infeasible"
        assert len(slots) == 3
        # Guaranteed property only (ENG-19): ≥1 session on the union {1,3}.
        assert days.get(1, 0) + days.get(3, 0) >= 1

    def test_forced_two_days_sessions_per_week_1_never_uses_free_day(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 1)],
            constraints=[day_constraint("team-1", "HARD", {"forcedDays": [1, 3]})],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
                make_slot(5, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        assert result.status != "infeasible"
        assert len(slots) == 1
        assert slots[0].day_of_week in {1, 3}
        assert slots[0].day_of_week != 5

    def test_forced_two_days_sessions_per_week_4_with_extra_days(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 4)],
            constraints=[day_constraint("team-1", "HARD", {"forcedDays": [1, 3]})],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
                make_slot(4, "18:00"),
                make_slot(5, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        days = Counter(slot.day_of_week for slot in slots)
        assert result.status != "infeasible"
        assert len(slots) == 4
        # Guaranteed property only (ENG-19): ≥1 on {1,3}; the free days 4/5 stay open
        # (this is exactly why forcedDays ≠ "only these days", ENG-16).
        assert days.get(1, 0) + days.get(3, 0) >= 1

    def test_allowed_days_whitelist_confines_a_multi_session_team(self) -> None:
        """allowedDays = the wizard 'uniquement' (ENG-16 fix): a 2-session team is
        confined to the listed days. The old forcedDays mapping only forced ONE
        session onto {1,3} and left day 5 open — silently breaking 'uniquement'."""
        input_data = make_input(
            teams=[make_team("team-1", 2)],
            constraints=[day_constraint("team-1", "HARD", {"allowedDays": [1, 3]})],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
                make_slot(5, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        assert result.status != "infeasible"
        assert len(slots) == 2
        assert all(slot.day_of_week in {1, 3} for slot in slots)


class TestForcedAndForbiddenDays:
    def test_forced_and_forbidden_days_sessions_per_week_2(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 2)],
            constraints=[
                day_constraint("team-1", "HARD", {"forcedDays": [3], "forbiddenDays": [6]}),
            ],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
                make_slot(6, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        days = Counter(slot.day_of_week for slot in slots)
        assert result.status != "infeasible"
        assert len(slots) == 2
        assert days[3] == 1
        assert days[1] == 1
        assert days[6] == 0

    def test_forced_and_forbidden_days_sessions_per_week_3(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 3)],
            constraints=[
                day_constraint("team-1", "HARD", {"forcedDays": [1], "forbiddenDays": [6]}),
            ],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
                make_slot(5, "18:00"),
                make_slot(6, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        days = Counter(slot.day_of_week for slot in slots)
        assert result.status != "infeasible"
        assert len(slots) == 3
        assert days[1] == 1
        assert days[6] == 0
        assert days[3] + days[5] == 2


class TestConflictAndNonRegression:
    def test_day_constraint_conflict_returns_diagnostic_without_infeasible(self) -> None:
        input_data = make_input(
            teams=[make_team("team-conflict", 1), make_team("team-ok", 1)],
            constraints=[
                day_constraint(
                    "team-conflict",
                    "HARD",
                    {"forcedDays": [3], "forbiddenDays": [3]},
                ),
            ],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        conflict_slots = team_slots(result, "team-conflict")
        ok_slots = team_slots(result, "team-ok")
        conflict_diagnostics = [diag for diag in result.diagnostics if diag.type == "day_constraint_conflict"]

        assert result.status != "infeasible"
        assert len(conflict_slots) == 0
        assert len(ok_slots) >= 1
        assert conflict_diagnostics
        assert conflict_diagnostics[0].severity == "ERROR"
        assert conflict_diagnostics[0].team_id == "team-conflict"

    def test_allowed_days_all_forbidden_returns_conflict_diagnostic(self) -> None:
        """ENG-16 review: 'uniquement jour X' (allowedDays) + 'évite jour X'
        (forbiddenDays) zeroes the team — it must surface the EXPLICIT
        day_constraint_conflict diagnostic, not a generic below-minimum error."""
        input_data = make_input(
            teams=[make_team("team-conflict", 1), make_team("team-ok", 1)],
            constraints=[
                day_constraint("team-conflict", "HARD", {"allowedDays": [3]}),
                day_constraint("team-conflict", "HARD", {"forbiddenDays": [3]}),
            ],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        conflict_diagnostics = [diag for diag in result.diagnostics if diag.type == "day_constraint_conflict"]
        assert result.status != "infeasible"
        assert len(team_slots(result, "team-conflict")) == 0
        assert conflict_diagnostics
        assert conflict_diagnostics[0].severity == "ERROR"
        assert conflict_diagnostics[0].team_id == "team-conflict"

    def test_forbidden_days_still_block_forbidden_weekday(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 1)],
            constraints=[day_constraint("team-1", "HARD", {"forbiddenDays": [1]})],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(3, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        assert result.status != "infeasible"
        assert len(slots) == 1
        assert slots[0].day_of_week == 3

    def test_hard_max_start_time_keeps_earlier_slot(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 1)],
            constraints=[time_constraint("team-1", {"maxStartTime": "18:50"})],
            slots_per_venue=[
                make_slot(3, "17:30"),
                make_slot(3, "19:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        assert result.status != "infeasible"
        assert len(slots) == 1
        assert str(slots[0].start_time) == "17:30:00"

    def test_sessions_per_week_caps_team_assignments(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 2)],
            constraints=[],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(2, "18:00"),
                make_slot(3, "18:00"),
                make_slot(4, "18:00"),
                make_slot(5, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        assert result.status != "infeasible"
        assert len(slots) <= 2

    def test_preferred_day_is_still_respected_softly(self) -> None:
        input_data = make_input(
            teams=[make_team("team-1", 1)],
            constraints=[day_constraint("team-1", "PREFERRED", {"preferredDays": [2]})],
            slots_per_venue=[
                make_slot(1, "18:00"),
                make_slot(2, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "team-1")
        assert result.status != "infeasible"
        assert len(slots) == 1
        assert slots[0].day_of_week == 2


class TestMaxEndTime:
    """ALIGN-04: a TIME maxEndTime forbids slots whose END (start + duration)
    falls after the bound — 'finir avant X h'."""

    @staticmethod
    def _time(team_id: str, config: dict[str, object]) -> dict[str, object]:
        return {
            "id": f"c-{team_id}-time",
            "family": "TIME",
            "ruleType": "HARD",
            "scope": "TEAM",
            "scopeTargetId": team_id,
            "isActive": True,
            "config": config,
        }

    def test_max_end_time_forbids_a_session_ending_after_the_bound(self) -> None:
        # 90-min sessions: day 1 @17:30 ends 19:00 (ok) ; day 2 @18:00 ends 19:30 (late).
        input_data = make_input(
            teams=[make_team("t", 1)],
            constraints=[self._time("t", {"maxEndTime": "19:00"})],
            slots_per_venue=[
                make_slot(1, "17:30"),
                make_slot(2, "18:00"),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "t")
        assert result.status != "infeasible"
        assert len(slots) == 1
        assert slots[0].day_of_week == 1  # the only session ending by 19:00

    def test_max_end_time_respects_slot_duration(self) -> None:
        # A 120-min session @18:00 ends 20:00 (late for 19:30) ; a 60-min @18:00
        # ends 19:00 (ok). Only the short one survives.
        input_data = make_input(
            teams=[make_team("t", 1)],
            constraints=[self._time("t", {"maxEndTime": "19:30"})],
            slots_per_venue=[
                make_slot(1, "18:00", duration_minutes=60),
                make_slot(2, "18:00", duration_minutes=120),
            ],
        )

        result = asyncio.run(build_schedule(input_data))

        slots = team_slots(result, "t")
        assert result.status != "infeasible"
        assert len(slots) == 1
        assert slots[0].day_of_week == 1


def _facility_min(team_id: str, venue_id: str, count: int) -> dict[str, object]:
    return {
        "id": f"c-{team_id}-minvenue",
        "family": "FACILITY",
        "ruleType": "HARD",
        "scope": "TEAM",
        "scopeTargetId": team_id,
        "isActive": True,
        "config": {"minAtVenueId": venue_id, "minAtVenueCount": count},
    }


def _two_venue_input(team_sessions: int, constraints: list[dict[str, object]]) -> ScheduleInputSchema:
    return ScheduleInputSchema.model_validate(
        {
            "clubId": "c",
            "seasonId": "s",
            "teams": [make_team("t", team_sessions)],
            "venues": [
                {
                    "id": "venue-A",
                    "name": "A",
                    "trainingSlots": [make_slot(1, "18:00"), make_slot(2, "18:00")],
                },
                {
                    "id": "venue-B",
                    "name": "B",
                    "trainingSlots": [make_slot(3, "18:00"), make_slot(4, "18:00")],
                },
            ],
            "constraints": constraints,
            "slotTemplates": [],
        }
    )


class TestVenueMinimum:
    """ALIGN-05: 'au moins N séances dans tel gymnase' (count, not forced)."""

    def test_at_least_one_session_at_venue(self) -> None:
        result = asyncio.run(build_schedule(_two_venue_input(2, [_facility_min("t", "venue-A", 1)])))
        slots = team_slots(result, "t")
        assert result.status != "infeasible"
        assert sum(1 for s in slots if s.venue_id == "venue-A") >= 1

    def test_other_sessions_stay_free(self) -> None:
        # min 1 at A leaves the 2nd session free to land at B (≠ forcedVenueId).
        result = asyncio.run(build_schedule(_two_venue_input(2, [_facility_min("t", "venue-A", 1)])))
        venues = {s.venue_id for s in team_slots(result, "t")}
        assert "venue-A" in venues  # the guaranteed one

    def test_unreachable_minimum_emits_diagnostic_not_infeasible(self) -> None:
        # venue-A has only 2 slots ; asking for 3 is provably impossible.
        result = asyncio.run(build_schedule(_two_venue_input(2, [_facility_min("t", "venue-A", 3)])))
        diag = [d for d in result.diagnostics if d.type == "venue_minimum_unreachable"]
        assert result.status != "infeasible"
        assert diag and diag[0].team_id == "t"
        assert diag[0].severity == "ERROR"

    def test_same_day_slots_count_as_one_reachable_day(self) -> None:
        # Reachability is DISTINCT DAYS, not raw slots (≤1 session/day). venue-A has
        # 2 slots but BOTH on day 1 → asking for 2 is unreachable (would be a silent
        # INFEASIBLE if the guard counted raw vars). Regression for review C1.
        input_data = ScheduleInputSchema.model_validate(
            {
                "clubId": "c",
                "seasonId": "s",
                "teams": [make_team("t", 2)],
                "venues": [
                    {
                        "id": "venue-A",
                        "name": "A",
                        "trainingSlots": [make_slot(1, "18:00"), make_slot(1, "20:00")],
                    },
                    {
                        "id": "venue-B",
                        "name": "B",
                        "trainingSlots": [make_slot(3, "18:00"), make_slot(4, "18:00")],
                    },
                ],
                "constraints": [_facility_min("t", "venue-A", 2)],
                "slotTemplates": [],
            }
        )
        result = asyncio.run(build_schedule(input_data))
        diag = [d for d in result.diagnostics if d.type == "venue_minimum_unreachable"]
        assert result.status != "infeasible"
        assert diag and diag[0].team_id == "t", "two same-day slots must not pass as 2 reachable sessions"


class TestSpacingPenalty:
    """ALIGN-06: implicit soft nudge — a team prefers non-consecutive training
    days. Never blocks (soft) ; only steers when there's a free choice."""

    def test_prefers_non_consecutive_days_when_possible(self) -> None:
        # Days 1,2,4 available for a 2-session team. {1,2} are consecutive (malus);
        # {1,4} / {2,4} are spaced. The nudge picks a spaced pair.
        input_data = make_input(
            teams=[make_team("t", 2)],
            constraints=[],
            slots_per_venue=[make_slot(1, "18:00"), make_slot(2, "18:00"), make_slot(4, "18:00")],
        )

        result = asyncio.run(build_schedule(input_data))

        days = sorted(s.day_of_week for s in team_slots(result, "t"))
        assert result.status != "infeasible"
        assert len(days) == 2
        assert days[1] - days[0] > 1  # not consecutive

    def test_spacing_never_blocks_when_only_consecutive_slots_exist(self) -> None:
        # Only days 1,2 exist for a 2-session team → must use both (soft, no block).
        input_data = make_input(
            teams=[make_team("t", 2)],
            constraints=[],
            slots_per_venue=[make_slot(1, "18:00"), make_slot(2, "18:00")],
        )

        result = asyncio.run(build_schedule(input_data))

        assert result.status != "infeasible"
        assert len(team_slots(result, "t")) == 2
