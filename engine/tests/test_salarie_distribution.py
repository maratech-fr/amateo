"""Tests for the salarié distribution hard constraint (TDD: RED -> GREEN).

At least one salarié coach (isEmployee=True) must be present (working)
on each Monday-Friday day. The constraint is skipped if there are fewer
than 2 salarié coaches.
"""

from __future__ import annotations

from ortools.sat.python import cp_model

from app.solver.constraints import (
    AssignmentVariable,
    HardConstraintStats,
    add_level_1_hard_constraints,
)


def _solve(model: cp_model.CpModel) -> int:
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 5
    return solver.Solve(model)


def _days_without_salarie(model: cp_model.CpModel, stats: object) -> set[int]:
    """Résout et rend les jours dont le littéral « aucun salarié » est VRAI (décision 2026-10-31)."""
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 5
    status = solver.Solve(model)
    assert status in (cp_model.FEASIBLE, cp_model.OPTIMAL), f"un jour sans salarié ne bloque plus, obtenu {status}"
    return {
        int(info.day_of_week)
        for info in stats.implicit_soft_info  # type: ignore[attr-defined]
        if info.detail == "salarie" and solver.BooleanValue(info.var)
    }


def _assignment(
    model: cp_model.CpModel,
    name: str,
    *,
    team_id: str = "team-1",
    slot_id: str = "1:18:00",
    venue_id: str = "venue-1",
    coach_id: str | None = "coach-1",
    player_ids: tuple[str, ...] = (),
) -> AssignmentVariable:
    return AssignmentVariable(
        var=model.NewBoolVar(name),
        team_id=team_id,
        slot_id=slot_id,
        venue_id=venue_id,
        coach_id=coach_id,
        player_ids=player_ids,
    )


class TestSalarieDistribution:
    def test_stats_has_salarie_distribution_counter(self) -> None:
        """HardConstraintStats must expose a salarie_distribution counter."""
        stats = HardConstraintStats()
        assert hasattr(stats, "salarie_distribution"), "HardConstraintStats must have a salarie_distribution field"
        assert stats.salarie_distribution == 0, (
            f"salarie_distribution should default to 0, got {stats.salarie_distribution}"
        )

    def test_no_salarie_on_day_stays_feasible_and_lights_the_day(self) -> None:
        """Un jour ouvré sans salarié ne bloque plus, quel que soit le cran : il allume son littéral.

        Setup : 2 salariés sur les jours 1-2, un vacataire sur les jours 1-4. Les jours 3 et 4 ont
        des séances mais aucun salarié.
        """
        model = cp_model.CpModel()
        # Salarié coach-1 works days 1-2 only
        assignments = [
            _assignment(
                model,
                f"salarie_day_{d}",
                slot_id=f"{d}:18:00",
                team_id=f"team-{d}",
                venue_id="venue-A",
                coach_id="coach-1",
            )
            for d in range(1, 3)
        ]
        # Salarié coach-2 works days 1-2 only (different venue/time to avoid conflicts)
        assignments += [
            _assignment(
                model,
                f"salarie2_day_{d}",
                slot_id=f"{d}:19:00",
                team_id=f"team-{d + 5}",
                venue_id="venue-B",
                coach_id="coach-2",
            )
            for d in range(1, 3)
        ]
        # Non-salarié coach-3 works days 1-4 (respects rest day)
        assignments += [
            _assignment(
                model,
                f"freelance_day_{d}",
                slot_id=f"{d}:20:00",
                team_id=f"team-{d + 10}",
                venue_id="venue-C",
                coach_id="coach-3",
            )
            for d in range(1, 5)
        ]

        coaches = [
            {"id": "coach-1", "isEmployee": True},
            {"id": "coach-2", "isEmployee": True},
            {"id": "coach-3", "isEmployee": False},
        ]

        stats = add_level_1_hard_constraints(model, assignments, coaches=coaches)

        # Force all assignments on — days 3-5 have no salarié
        for a in assignments:
            model.Add(a.var == 1)

        assert stats.salarie_distribution > 0, f"Expected salarie_distribution > 0, got {stats.salarie_distribution}"
        lit_days = _days_without_salarie(model, stats)
        assert {3, 4} <= lit_days, f"les jours 3 et 4 n'ont aucun salarié, littéraux vrais : {lit_days}"
        assert not {1, 2} & lit_days, f"les jours 1 et 2 ont un salarié, littéraux vrais : {lit_days}"

    def test_salarie_on_all_days_is_feasible(self) -> None:
        """If at least one salarié works each day Mon-Fri, the model is feasible.

        Coach-1 (salarié) works days 1-4, coach-2 (salarié) works days 2-5.
        Each day has at least one salarié, and each coach has a rest day.
        Different venues avoid room_at_most_one conflicts.
        """
        model = cp_model.CpModel()
        # Salarié coach-1 works days 1-4 (rest on day 5)
        assignments = [
            _assignment(
                model,
                f"salarie_day_{d}",
                slot_id=f"{d}:18:00",
                team_id=f"team-{d}",
                venue_id="venue-A",
                coach_id="coach-1",
            )
            for d in range(1, 5)
        ]
        # Salarié coach-2 works days 2-5 (rest on day 1)
        assignments += [
            _assignment(
                model,
                f"salarie2_day_{d}",
                slot_id=f"{d}:19:00",
                team_id=f"team-{d + 5}",
                venue_id="venue-B",
                coach_id="coach-2",
            )
            for d in range(2, 6)
        ]

        coaches = [
            {"id": "coach-1", "isEmployee": True},
            {"id": "coach-2", "isEmployee": True},
        ]

        add_level_1_hard_constraints(model, assignments, coaches=coaches)

        # Force all assignments on — each day has at least one salarié
        for a in assignments:
            model.Add(a.var == 1)

        status = _solve(model)
        assert status in (cp_model.FEASIBLE, cp_model.OPTIMAL), (
            f"At least one salarié per day should be feasible, got {status}"
        )

    def test_fewer_than_two_salarie_skips_constraint(self) -> None:
        """If there are fewer than 2 salarié coaches, the constraint is skipped."""
        model = cp_model.CpModel()
        # Only 1 salarié coach, working 4 days (satisfies rest day)
        assignments = [
            _assignment(model, f"day_{d}", slot_id=f"{d}:18:00", team_id=f"team-{d}", coach_id="coach-1")
            for d in range(1, 5)
        ]

        coaches = [
            {"id": "coach-1", "isEmployee": True},
            {"id": "coach-2", "isEmployee": False},
        ]

        stats = add_level_1_hard_constraints(model, assignments, coaches=coaches)

        # Force all on — should be feasible since constraint is skipped
        for a in assignments:
            model.Add(a.var == 1)

        status = _solve(model)
        assert stats.salarie_distribution == 0, (
            f"Fewer than 2 salariés => salarie_distribution should be 0, got {stats.salarie_distribution}"
        )
        assert status in (cp_model.FEASIBLE, cp_model.OPTIMAL), (
            f"Skipped constraint should allow any schedule, got {status}"
        )

    def test_zero_salarie_coaches_skips_constraint(self) -> None:
        """If there are 0 salarié coaches, the constraint is skipped."""
        model = cp_model.CpModel()
        assignments = [
            _assignment(model, f"day_{d}", slot_id=f"{d}:18:00", team_id=f"team-{d}", coach_id="coach-1")
            for d in range(1, 5)
        ]

        coaches = [
            {"id": "coach-1", "isEmployee": False},
            {"id": "coach-2", "isEmployee": False},
        ]

        stats = add_level_1_hard_constraints(model, assignments, coaches=coaches)

        for a in assignments:
            model.Add(a.var == 1)

        status = _solve(model)
        assert stats.salarie_distribution == 0, (
            f"0 salariés => salarie_distribution should be 0, got {stats.salarie_distribution}"
        )
        assert status in (cp_model.FEASIBLE, cp_model.OPTIMAL), (
            f"No salarié constraint should allow any schedule, got {status}"
        )

    def test_no_coaches_means_zero_constraints(self) -> None:
        """When no coaches are passed, salarie_distribution must be 0."""
        model = cp_model.CpModel()
        assignments = [_assignment(model, f"day_{d}", slot_id=f"{d}:18:00", team_id=f"team-{d}") for d in range(1, 6)]

        stats = add_level_1_hard_constraints(model, assignments, coaches=[])

        assert stats.salarie_distribution == 0, (
            f"No coaches => salarie_distribution should be 0, got {stats.salarie_distribution}"
        )

    def test_playing_assignments_count_for_salarie(self) -> None:
        """A salarié coach playing (in player_ids) on a day counts as present.

        Coach-1 (salarié) coaches on days 1-3 and plays on day 4.
        Coach-2 (salarié) coaches on days 4-5.
        Without counting player_ids, day 4 would only have coach-2 coaching.
        With counting, coach-1 is also present on day 4 via player_ids.
        Both coaches have rest days (coach-1 rests day 5, coach-2 rests days 1-3).
        """
        model = cp_model.CpModel()
        # Salarié coach-1 coaching on days 1-3
        coaching = [
            _assignment(
                model,
                f"coach_day_{d}",
                slot_id=f"{d}:18:00",
                team_id=f"team-{d}",
                coach_id="coach-1",
            )
            for d in range(1, 4)
        ]
        # Coach-1 playing on day 4 (as player in another team)
        playing = _assignment(
            model,
            "playing_day_4",
            slot_id="4:18:00",
            team_id="team-4",
            coach_id="coach-other",
            player_ids=("coach-1",),
        )
        # Salarié coach-2 coaching on days 4-5
        coach2_assignments = [
            _assignment(
                model,
                f"coach2_day_{d}",
                slot_id=f"{d}:19:00",
                team_id=f"team-{d + 5}",
                venue_id="venue-B",
                coach_id="coach-2",
            )
            for d in range(4, 6)
        ]

        all_assignments = [*coaching, playing, *coach2_assignments]
        coaches = [
            {"id": "coach-1", "isEmployee": True},
            {"id": "coach-2", "isEmployee": True},
            {"id": "coach-other", "isEmployee": False},
        ]

        add_level_1_hard_constraints(model, all_assignments, coaches=coaches)

        for a in all_assignments:
            model.Add(a.var == 1)

        status = _solve(model)
        assert status in (cp_model.FEASIBLE, cp_model.OPTIMAL), (
            f"Salarié coaching + playing should be feasible, got {status}"
        )

    def test_weekend_days_not_constrained(self) -> None:
        """Le samedi ne compte pas : un salarié présent le samedi n'efface pas le vendredi vide.

        Salariés du lundi au jeudi (plus le samedi pour l'un), un vacataire seul le vendredi : le
        vendredi allume son littéral, et aucun terme n'est posé pour le samedi.
        """
        model = cp_model.CpModel()
        # Salarié coach-1 : lundi à mercredi et samedi (quatre jours, il garde son jour de repos).
        assignments = [
            _assignment(
                model,
                f"day_{d}",
                slot_id=f"{d}:18:00",
                team_id=f"team-{d}",
                venue_id="venue-A",
                coach_id="coach-1",
            )
            for d in (1, 2, 3, 6)
        ]
        # Salarié coach-2 works days 1-4 only
        assignments += [
            _assignment(
                model,
                f"day2_{d}",
                slot_id=f"{d}:19:00",
                team_id=f"team-{d + 10}",
                venue_id="venue-B",
                coach_id="coach-2",
            )
            for d in (1, 2, 3, 4)
        ]
        assignments.append(
            _assignment(
                model,
                "friday_freelance",
                slot_id="5:18:00",
                team_id="team-20",
                venue_id="venue-C",
                coach_id="coach-3",
            )
        )

        coaches = [
            {"id": "coach-1", "isEmployee": True},
            {"id": "coach-2", "isEmployee": True},
            {"id": "coach-3", "isEmployee": False},
        ]

        stats = add_level_1_hard_constraints(model, assignments, coaches=coaches)

        # Force all on — day 5 (Friday) has no salarié
        for a in assignments:
            model.Add(a.var == 1)

        assert stats.salarie_distribution > 0, f"Expected salarie_distribution > 0, got {stats.salarie_distribution}"
        assert _days_without_salarie(model, stats) == {5}, "seul le vendredi est sans salarié ; le samedi ne compte pas"

    def test_total_constraints_includes_salarie_distribution(self) -> None:
        """total_constraints_added must include salarie_distribution in the sum."""
        model = cp_model.CpModel()
        assignments = [
            _assignment(
                model,
                f"day_{d}",
                slot_id=f"{d}:18:00",
                team_id=f"team-{d}",
                venue_id="venue-A",
                coach_id="coach-1",
            )
            for d in range(1, 5)
        ]
        assignments += [
            _assignment(
                model,
                f"day2_{d}",
                slot_id=f"{d}:19:00",
                team_id=f"team-{d + 5}",
                venue_id="venue-B",
                coach_id="coach-2",
            )
            for d in range(2, 6)
        ]

        coaches = [
            {"id": "coach-1", "isEmployee": True},
            {"id": "coach-2", "isEmployee": True},
        ]

        stats = add_level_1_hard_constraints(model, assignments, coaches=coaches)

        if stats.salarie_distribution > 0:
            expected_total = (
                stats.room_at_most_one
                + stats.coach_at_most_one
                + stats.coach_player_non_overlap
                + stats.team_no_overlap
                + stats.travel_feasibility_stub
                + stats.fixed_slots
                + stats.forbidden_assignments
                + stats.coach_unavailability
                + stats.required_bridge_stub
                + stats.min_sessions
                + stats.forced_venues
                + stats.one_session_per_day
                + stats.age_ascending
                + stats.coach_rest_day
                + stats.salarie_distribution
            )
            assert stats.total_constraints_added == expected_total, (
                f"total_constraints_added={stats.total_constraints_added} != expected={expected_total}"
            )
