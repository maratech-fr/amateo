import unittest

from ortools.sat.python import cp_model

from app.solver.constraints import (
    AssignmentVariable,
    add_coach_unavailability_constraints,
    add_level_1_hard_constraints,
    add_team_no_overlap,
    parse_v2_constraints,
)


class LevelOneHardConstraintsTest(unittest.TestCase):
    def solve(self, model: cp_model.CpModel) -> int:
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 2
        return solver.Solve(model)

    def assignment(
        self,
        model: cp_model.CpModel,
        name: str,
        *,
        team_id: str = "team-1",
        slot_id: str = "slot-1",
        venue_id: str = "venue-1",
        coach_id: str = "coach-1",
        **kwargs,
    ) -> AssignmentVariable:
        return AssignmentVariable(
            var=model.NewBoolVar(name),
            team_id=team_id,
            slot_id=slot_id,
            venue_id=venue_id,
            coach_id=coach_id,
            **kwargs,
        )

    def test_room_double_booking_is_impossible(self):
        model = cp_model.CpModel()
        first = self.assignment(model, "first", team_id="team-1", coach_id="coach-1")
        second = self.assignment(model, "second", team_id="team-2", coach_id="coach-2")

        stats = add_level_1_hard_constraints(model, [first, second])
        model.Add(first.var == 1)
        model.Add(second.var == 1)

        self.assertEqual(stats.room_at_most_one, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))

    def test_t22_model_x_keys_use_team_venue_day_and_start_order(self):
        model = cp_model.CpModel()
        first = model.NewBoolVar("first")
        second = model.NewBoolVar("second")
        assignments = {
            ("team-1", "venue-1", 1, "09:00"): first,
            ("team-2", "venue-1", 1, "09:00"): second,
        }

        stats = add_level_1_hard_constraints(model, assignments)
        model.Add(first == 1)
        model.Add(second == 1)

        self.assertEqual(stats.room_at_most_one, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))

    def test_coach_on_two_venues_at_same_time_is_impossible(self):
        model = cp_model.CpModel()
        first = self.assignment(model, "first", team_id="team-1", venue_id="venue-1")
        second = self.assignment(model, "second", team_id="team-2", venue_id="venue-2")

        stats = add_level_1_hard_constraints(model, [first, second])
        model.Add(first.var == 1)
        model.Add(second.var == 1)

        self.assertEqual(stats.coach_at_most_one, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))

    def test_coach_player_overlap_is_impossible(self):
        model = cp_model.CpModel()
        coaching = self.assignment(model, "coaching", team_id="team-1", venue_id="venue-1", coach_id="person-1")
        playing = self.assignment(
            model,
            "playing",
            team_id="team-2",
            venue_id="venue-2",
            coach_id="coach-2",
            player_ids=("person-1",),
        )

        stats = add_level_1_hard_constraints(model, [coaching, playing])
        model.Add(coaching.var == 1)
        model.Add(playing.var == 1)

        self.assertEqual(stats.coach_player_non_overlap, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))

    def test_team_no_overlap_prevents_double_booking(self):
        model = cp_model.CpModel()
        first = self.assignment(model, "first", team_id="team-1", slot_id="slot-1", venue_id="venue-1")
        second = self.assignment(model, "second", team_id="team-1", slot_id="slot-1", venue_id="venue-2")

        stats = add_level_1_hard_constraints(model, [first, second])
        model.Add(first.var == 1)
        model.Add(second.var == 1)

        self.assertEqual(stats.team_no_overlap, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))

    def test_team_no_overlap_allows_different_slots(self):
        model = cp_model.CpModel()
        first = self.assignment(model, "first", team_id="team-1", slot_id="slot-1", venue_id="venue-1")
        second = self.assignment(model, "second", team_id="team-1", slot_id="slot-2", venue_id="venue-1")

        stats = add_level_1_hard_constraints(
            model,
            [first, second],
            teams=[{"id": "team-1", "sessionsPerWeek": 2}],
        )
        model.Add(first.var == 1)
        model.Add(second.var == 1)

        self.assertEqual(stats.team_no_overlap, 0)
        self.assertIn(self.solve(model), (cp_model.FEASIBLE, cp_model.OPTIMAL))

    def test_team_no_overlap_direct_call(self):
        model = cp_model.CpModel()
        first = self.assignment(model, "first", team_id="team-1", slot_id="slot-1")
        second = self.assignment(model, "second", team_id="team-1", slot_id="slot-1")

        added = add_team_no_overlap(model, [first, second])
        model.Add(first.var == 1)
        model.Add(second.var == 1)

        self.assertEqual(added, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))

    def test_fixed_slot_is_forced_to_one(self):
        model = cp_model.CpModel()
        fixed = self.assignment(model, "fixed", fixed=True)

        stats = add_level_1_hard_constraints(model, [fixed])
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 2
        status = solver.Solve(model)

        self.assertEqual(stats.fixed_slots, 1)
        self.assertIn(status, (cp_model.FEASIBLE, cp_model.OPTIMAL))
        self.assertEqual(1, solver.Value(fixed.var))

    def test_forbidden_assignment_is_forced_to_zero(self):
        model = cp_model.CpModel()
        forbidden = self.assignment(model, "forbidden", forbidden=True)

        stats = add_level_1_hard_constraints(model, [forbidden])
        model.Add(forbidden.var == 1)

        self.assertEqual(stats.forbidden_assignments, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))

    def test_coach_unavailable_assignment_is_forced_to_zero(self):
        model = cp_model.CpModel()
        unavailable = self.assignment(model, "unavailable", coach_unavailable=True)

        stats = add_level_1_hard_constraints(model, [unavailable])
        model.Add(unavailable.var == 1)

        self.assertEqual(stats.coach_unavailability, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))

    # (ENG-15) The dead `add_venue_closure_constraints` path was removed: venue
    # closures are honored upstream (backend expands them to FACILITY
    # forbiddenVenueId → forbidden_assignments), covered by the forbidden tests.

    def test_min_sessions_effectif_is_guaranteed(self):
        model = cp_model.CpModel()
        assignments = [
            self.assignment(model, "first", team_id="team-1", slot_id="slot-1", venue_id="venue-1"),
            self.assignment(model, "second", team_id="team-1", slot_id="slot-2", venue_id="venue-1"),
            self.assignment(model, "third", team_id="team-1", slot_id="slot-3", venue_id="venue-1"),
        ]

        stats = add_level_1_hard_constraints(
            model,
            assignments,
            teams=[{"id": "team-1", "sessionsPerWeek": 3}],
            min_sessions_by_team={"team-1": 2},
        )
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 2
        status = solver.Solve(model)

        self.assertEqual(stats.min_sessions, 1)
        self.assertIn(status, (cp_model.FEASIBLE, cp_model.OPTIMAL))
        self.assertGreaterEqual(sum(solver.Value(item.var) for item in assignments), 2)

    def test_other_venues_are_forced_to_zero_when_venue_is_forced(self):
        model = cp_model.CpModel()
        wanted = self.assignment(model, "wanted", team_id="team-1", session_id="session-1", venue_id="venue-1")
        other = self.assignment(model, "other", team_id="team-1", session_id="session-1", venue_id="venue-2")

        stats = add_level_1_hard_constraints(model, [wanted, other], forced_venues={("team-1", "session-1"): "venue-1"})
        model.Add(other.var == 1)

        self.assertEqual(stats.forced_venues, 1)
        self.assertEqual(cp_model.INFEASIBLE, self.solve(model))


class ParseV2ConstraintsTest(unittest.TestCase):
    def test_empty_constraints_returns_defaults(self):
        result = parse_v2_constraints([])
        assert result["forbidden_assignments"] == []
        assert result["coach_unavailability"] == {}
        assert result["forced_venues"] == {}
        assert result["time_windows"] == []

    def test_inactive_constraints_are_skipped(self):
        constraints = [
            {"id": "c1", "isActive": False, "ruleType": "LOCK"},
        ]
        result = parse_v2_constraints(constraints)
        assert result["forbidden_assignments"] == []

    def test_lock_time_day_routes_to_time_windows(self):
        # LOCK on a TIME/DAY rule is enforced as HARD (routed to time_windows).
        # AUD-ENG-31 : la clé `fixed_slots` a disparu du parseur — le chemin UUID qui
        # l'alimentait avait été supprimé (il ne matchait jamais), et la clé restait
        # câblée au solveur en annonçant un mécanisme que le payload n'a pas.
        constraints = [
            {
                "id": "c1",
                "isActive": True,
                "ruleType": "LOCK",
                "family": "DAY",
                "scopeTargetId": "team-1",
                "config": {"forbiddenDays": [2]},
            },
        ]
        result = parse_v2_constraints(constraints)
        assert len(result["time_windows"]) == 1

    def test_coach_availability_family(self):
        # Days are weekday ints; a day-only config → whole-day (0..1440) intervals.
        constraints = [
            {
                "id": "c1",
                "isActive": True,
                "family": "COACH_AVAILABILITY",
                "scopeTargetId": "coach-1",
                "config": {"unavailableDays": [1, 3]},
            }
        ]
        result = parse_v2_constraints(constraints)
        assert result["coach_unavailability"] == {"coach-1": {(1, 0, 1440), (3, 0, 1440)}}

    def test_coach_availability_time_window(self):
        # Lot C: an unavailable day with a time window → a bounded interval.
        constraints = [
            {
                "id": "c1",
                "isActive": True,
                "family": "COACH_AVAILABILITY",
                "scopeTargetId": "coach-1",
                "config": {"unavailableDays": [2], "fromTime": "20:00"},
            }
        ]
        result = parse_v2_constraints(constraints)
        # from 20:00 → blocked [1200, 1440); no untilTime → end of day.
        assert result["coach_unavailability"] == {"coach-1": {(2, 1200, 1440)}}

    def test_coach_availability_inverted_window_falls_back_to_whole_day(self):
        # Lot C review: an inverted window (from >= until, e.g. an overnight
        # "20:00–08:00" the flat model can't wrap) reaches the solver ungated on
        # the write/generate paths → must HONOR the unavailability by blocking the
        # whole day, never build a from>=to interval that matches no slot.
        constraints = [
            {
                "id": "c1",
                "isActive": True,
                "family": "COACH_AVAILABILITY",
                "scopeTargetId": "coach-1",
                "config": {"unavailableDays": [2], "fromTime": "20:00", "untilTime": "08:00"},
            }
        ]
        result = parse_v2_constraints(constraints)
        assert result["coach_unavailability"] == {"coach-1": {(2, 0, 1440)}}

    def test_coach_availability_malformed_time_does_not_crash_and_blocks_whole_day(self):
        # Lot C review: a malformed HH:MM (bypassing / predating the backend
        # check) must not raise out of parse_v2_constraints and abort the whole
        # solve; it degrades to a whole-day block for that unavailable day.
        constraints = [
            {
                "id": "c1",
                "isActive": True,
                "family": "COACH_AVAILABILITY",
                "scopeTargetId": "coach-1",
                "config": {"unavailableDays": [2], "fromTime": "9h"},
            }
        ]
        result = parse_v2_constraints(constraints)
        assert result["coach_unavailability"] == {"coach-1": {(2, 0, 1440)}}

    def test_coach_unavailability_apply_blocks_only_the_window(self):
        # Lot C semantic: "coach c1 unavailable Tue from 20:00" blocks a Tue 20:30
        # slot but NOT a Tue 18:00 slot nor a Wed 20:30 slot.
        class _Model:
            def Add(self, *_args):
                return None

        rules = {"c1": {(2, 1200, 1440)}}  # Tuesday, 20:00 → end of day
        avs = [
            AssignmentVariable(var=1, team_id="t", coach_id="c1", slot_id="2:20:30"),  # blocked
            AssignmentVariable(var=1, team_id="t", coach_id="c1", slot_id="2:18:00"),  # free (before 20:00)
            AssignmentVariable(var=1, team_id="t", coach_id="c1", slot_id="3:20:30"),  # free (Wednesday)
        ]
        assert add_coach_unavailability_constraints(_Model(), avs, rules) == 1

    def test_facility_with_date_range_is_ignored(self):
        # venue_closures (dateStart) is removed — a dated closure has no meaning
        # on a week-template schedule without occurrences (ENG-02).
        constraints = [
            {
                "id": "c1",
                "isActive": True,
                "family": "FACILITY",
                "scopeTargetId": "venue-1",
                "config": {"dateStart": "2026-01-01", "dateEnd": "2026-01-05"},
            }
        ]
        result = parse_v2_constraints(constraints)
        assert "venue_closures" not in result

    def test_facility_with_preferred_venue_produces_forced_venue(self):
        constraints = [
            {
                "id": "c1",
                "isActive": True,
                "family": "FACILITY",
                "scope": "TEAM",
                "scopeTargetId": "team-1",
                "ruleType": "HARD",
                "config": {"preferredVenueId": "venue-1"},
            }
        ]
        result = parse_v2_constraints(constraints)
        assert result["forced_venues"] == {"team-1": "venue-1"}

    def test_facility_with_forbidden_venue_produces_forbidden_assignment(self):
        constraints = [
            {
                "id": "c1",
                "isActive": True,
                "family": "FACILITY",
                "scopeTargetId": "team-1",
                "config": {"forbiddenVenueId": "venue-2"},
            }
        ]
        result = parse_v2_constraints(constraints)
        assert len(result["forbidden_assignments"]) == 1
        assert result["forbidden_assignments"][0]["scope_target_id"] == "team-1"
        assert result["forbidden_assignments"][0]["venue_id"] == "venue-2"

    def test_time_family_produces_time_window(self):
        constraints = [
            {
                "id": "c1",
                "isActive": True,
                "family": "TIME",
                "scopeTargetId": "team-1",
                "config": {"preferredStart": "18:00"},
            }
        ]
        result = parse_v2_constraints(constraints)
        assert len(result["time_windows"]) == 1

    def test_day_family_produces_time_window(self):
        constraints = [
            {
                "id": "c1",
                "isActive": True,
                "family": "DAY",
                "scopeTargetId": "team-1",
                "config": {"preferredDays": [1, 3]},
            }
        ]
        result = parse_v2_constraints(constraints)
        assert len(result["time_windows"]) == 1

    def test_snake_case_aliases(self):
        constraints = [
            {
                "id": "c1",
                "isActive": True,
                "rule_type": "LOCK",
                "family": "TIME",
                "scope_target_id": "team-1",
                "config": {"maxStartTime": "19:00"},
            }
        ]
        result = parse_v2_constraints(constraints)
        assert len(result["time_windows"]) == 1

    def test_scope_target_id_snake_case(self):
        constraints = [
            {
                "id": "c1",
                "isActive": True,
                "family": "COACH_AVAILABILITY",
                "scope_target_id": "coach-2",
                "config": {"unavailableDays": [5]},
            }
        ]
        result = parse_v2_constraints(constraints)
        assert result["coach_unavailability"] == {"coach-2": {(5, 0, 1440)}}

    def test_unknown_family_and_type_surfaces_a_not_honored_diagnostic(self):
        # A constraint whose family AND type are both unrecognised was dropped with
        # only a server-side log — invisible to the manager. It must now ride the same
        # diagnostics channel as the other target-less drops (constraint_not_honored).
        constraints = [
            {
                "id": "c1",
                "name": "Ma règle exotique",
                "isActive": True,
                "family": "UNKNOWN_FAMILY",
                "type": "UNKNOWN_TYPE",
                "scopeTargetId": "team-1",
                "config": {},
            }
        ]
        result = parse_v2_constraints(constraints)
        warnings = [w for w in result["parse_warnings"] if w["type"] == "constraint_not_honored"]
        assert len(warnings) == 1, f"Expected one not-honored diagnostic, got {result['parse_warnings']}"
        message = warnings[0]["message"]
        assert "UNKNOWN_FAMILY" in message, message
        assert "UNKNOWN_TYPE" in message, message


if __name__ == "__main__":
    unittest.main()
