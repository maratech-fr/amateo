from __future__ import annotations

import unittest
from typing import Any

from ortools.sat.python import cp_model

from app.schemas.output_schema import ScheduleOutputSchema
from app.solver.model import build_model
from app.solver.result_builder import build_result


class ResultBuilderTest(unittest.TestCase):
    def _solve(self, model: cp_model.CpModel) -> tuple[cp_model.CpSolver, int]:
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 2
        status = solver.Solve(model)
        return solver, status

    def _minimal_data(self) -> dict[str, Any]:
        return {
            "clubId": "club-1",
            "seasonId": "season-1",
            "teams": [{"id": "team-1", "priorityTierId": 3, "sportCategoryId": "sc-1", "name": "Team 1"}],
            "venues": [
                {
                    "id": "venue-1",
                    "name": "Court A",
                    "trainingSlots": [{"dayOfWeek": 1, "startTime": "09:00", "durationMinutes": 60, "capacity": 1}],
                }
            ],
            "coaches": [],
            "slotTemplates": [],
        }

    def test_feasible_solution_produces_slots_and_empty_diagnostics(self):
        data = self._minimal_data()
        model = build_model(data)
        # Force the single available slot to 1 so the solution is feasible.
        for var in model.x.values():
            model.Add(var == 1)

        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status)

        self.assertEqual(result["status"], "completed")
        self.assertIsNotNone(result["score"])
        self.assertGreaterEqual(result["score"], 0)
        self.assertTrue(result["slots"])
        self.assertEqual(result["diagnostics"], [])

        # Validate against the Pydantic schema.
        validated = ScheduleOutputSchema.model_validate(result)
        self.assertEqual(validated.status, "completed")

    def test_metrics_expose_determinism_versions(self):
        from app.solver.objective import SCORE_FORMULA_VERSION

        data = self._minimal_data()
        model = build_model(data)
        for var in model.x.values():
            model.Add(var == 1)

        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status, constraint_version="2.0")

        metrics = result["metrics"]
        self.assertEqual(metrics["score_formula_version"], SCORE_FORMULA_VERSION)
        self.assertEqual(metrics["constraint_version"], "2.0")
        # Must still validate against the (now extended) metrics schema.
        validated = ScheduleOutputSchema.model_validate(result)
        self.assertEqual(validated.metrics.score_formula_version, SCORE_FORMULA_VERSION)
        self.assertEqual(validated.metrics.constraint_version, "2.0")

    def test_metrics_schema_accepts_the_capacity_fields(self):
        # P5-10 — build_result stays lean; the capacity metrics are merged by
        # build_schedule (main.py). Here we only pin that the SHARED schema accepts
        # and round-trips the new optional fields (extra=forbid would reject a typo).
        from app.schemas.output_schema import SolverMetricsSchema

        metrics = SolverMetricsSchema.model_validate(
            {
                "solver_version": "test",
                "nb_variables": 3,
                "nb_constraints": 5,
                "wall_time_ms": 12,
                "total_wall_time_ms": 600123,
                "cpu_time_ms": 1200000,
                "workers": 8,
                "budget_seconds": 600,
                "solver_status_detail": "OPTIMAL",
                "nb_conflicts": 42,
                "peak_rss_mb": 512.5,
                "rss_before_mb": 128.25,
                "engine_wait_ms": 900,
            }
        )
        self.assertEqual(metrics.total_wall_time_ms, 600123)
        self.assertEqual(metrics.workers, 8)
        self.assertEqual(metrics.solver_status_detail, "OPTIMAL")
        self.assertEqual(metrics.nb_conflicts, 42)
        self.assertEqual(metrics.peak_rss_mb, 512.5)
        # camelCase aliases round-trip (the backend reads snake_case; both must work).
        self.assertEqual(metrics.model_dump(by_alias=True)["totalWallTimeMs"], 600123)

    def test_infeasible_solution_returns_failed_status_and_diagnostics(self):
        data = self._minimal_data()
        model = build_model(data)
        # Force two conflicting assignments at the same venue/time.
        keys = list(model.x.keys())
        self.assertGreaterEqual(len(keys), 1)
        first_key = keys[0]
        model.Add(model.x[first_key] == 1)
        # Block the same venue slot so it's impossible.
        model.Add(model.x[first_key] == 0)

        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status)

        self.assertEqual(result["status"], "failed")
        self.assertIsNone(result["score"])
        self.assertTrue(result["diagnostics"])
        diag_types = {d["type"] for d in result["diagnostics"]}
        self.assertIn("conflict", diag_types)

        validated = ScheduleOutputSchema.model_validate(result)
        self.assertEqual(validated.status, "failed")

    def test_hard_locked_slots_are_preserved(self):
        data = self._minimal_data()
        data["slotTemplates"] = [
            {
                "id": "locked-1",
                "teamId": "team-1",
                "venueId": "venue-1",
                "dayOfWeek": 2,
                "startTime": "14:00",
                "durationMinutes": 60,
                "lockLevel": "HARD",
            },
        ]
        model = build_model(data)
        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status)

        hard_slots = [s for s in result["slots"] if s.get("lockLevel") == "HARD"]
        self.assertEqual(len(hard_slots), 1)
        self.assertEqual(hard_slots[0]["teamId"], "team-1")
        self.assertEqual(hard_slots[0]["venueId"], "venue-1")
        self.assertEqual(hard_slots[0]["dayOfWeek"], 2)
        self.assertEqual(hard_slots[0]["startTime"], "14:00")
        self.assertEqual(hard_slots[0]["durationMinutes"], 60)

    def test_all_teams_appear_in_output(self):
        data = self._minimal_data()
        data["teams"].append({"id": "team-2", "priorityTierId": 2, "sportCategoryId": "sc-1", "name": "Team 2"})
        model = build_model(data)
        # Do not force any variable to 1, so team-2 has no slot.
        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status)

        placed_teams = {s["teamId"] for s in result["slots"]}
        unplaced_diags = [d for d in result["diagnostics"] if d["type"] == "unplaced"]
        unplaced_teams = {d["teamId"] for d in unplaced_diags}

        all_teams = {"team-1", "team-2"}
        self.assertTrue(
            all_teams.issubset(placed_teams | unplaced_teams),
            f"Not all teams accounted for: placed={placed_teams}, unplaced={unplaced_teams}",
        )

    def test_soft_lock_moved_diagnostic(self):
        data = self._minimal_data()
        data["slotTemplates"] = [
            {
                "id": "soft-1",
                "teamId": "team-1",
                "venueId": "venue-1",
                "dayOfWeek": 1,
                "startTime": "09:00",
                "durationMinutes": 15,
                "lockLevel": "SOFT",
            },
        ]
        model = build_model(data)
        # Force the variable to 0 so the SOFT slot is "moved" (absent).
        for var in model.x.values():
            model.Add(var == 0)

        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status)

        soft_diags = [d for d in result["diagnostics"] if d["type"] == "soft_lock_moved"]
        self.assertTrue(soft_diags)
        self.assertEqual(soft_diags[0]["teamId"], "team-1")

    def test_coach_overload_diagnostic(self):
        data = self._minimal_data()
        data["coaches"] = [
            {"id": "coach-1", "firstName": "Alice", "lastName": "Smith", "maxDaysOverride": 1},
        ]
        data["slotTemplates"] = [
            {
                "id": "tpl-1",
                "teamId": "team-1",
                "venueId": "venue-1",
                "coachId": "coach-1",
                "dayOfWeek": 1,
                "startTime": "09:00",
                "durationMinutes": 15,
                "lockLevel": "NONE",
            },
            {
                "id": "tpl-2",
                "teamId": "team-1",
                "venueId": "venue-1",
                "coachId": "coach-1",
                "dayOfWeek": 3,
                "startTime": "09:00",
                "durationMinutes": 15,
                "lockLevel": "NONE",
            },
        ]
        # ENG-24: the coach works 2 DISTINCT days (Mon + Wed) > maxDaysOverride=1 → overloaded.
        data["venues"][0]["trainingSlots"] = [
            {"dayOfWeek": 1, "startTime": "09:00", "durationMinutes": 15, "capacity": 1},
            {"dayOfWeek": 3, "startTime": "09:00", "durationMinutes": 15, "capacity": 1},
        ]
        model = build_model(data)
        for var in model.x.values():
            model.Add(var == 1)

        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status)

        overload_diags = [d for d in result["diagnostics"] if d["type"] == "coach_overload"]
        self.assertTrue(overload_diags)
        self.assertEqual(overload_diags[0]["coachId"], "coach-1")
        self.assertIn("2 jours", overload_diags[0]["message"])

    def test_coach_two_sessions_same_day_is_not_overload(self):
        # ENG-24: two sessions on the SAME day = 1 day worked, not 2 — must NOT flag overload
        # for a coach whose maxDaysOverride is 1 (the old 15-min-block count wrongly did).
        data = self._minimal_data()
        data["coaches"] = [{"id": "coach-1", "firstName": "Al", "lastName": "S", "maxDaysOverride": 1}]
        data["slotTemplates"] = [
            {
                "id": "tpl-1",
                "teamId": "team-1",
                "venueId": "venue-1",
                "coachId": "coach-1",
                "dayOfWeek": 1,
                "startTime": "09:00",
                "durationMinutes": 15,
                "lockLevel": "NONE",
            },
            {
                "id": "tpl-2",
                "teamId": "team-1",
                "venueId": "venue-1",
                "coachId": "coach-1",
                "dayOfWeek": 1,
                "startTime": "18:00",
                "durationMinutes": 15,
                "lockLevel": "NONE",
            },
        ]
        data["venues"][0]["trainingSlots"] = [
            {"dayOfWeek": 1, "startTime": "09:00", "durationMinutes": 15, "capacity": 1},
            {"dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 15, "capacity": 1},
        ]
        model = build_model(data)
        for var in model.x.values():
            model.Add(var == 1)
        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status)

        self.assertEqual([d for d in result["diagnostics"] if d["type"] == "coach_overload"], [])

    def test_unknown_status_emits_timeout_diagnostic_not_false_unplaced(self):
        # ENG-22: on UNKNOWN (solver ran out of time), the manager gets an explicit timeout
        # diagnostic — NOT the misleading per-team "all slots were already occupied".
        data = self._minimal_data()
        model = build_model(data)
        solver, _ = self._solve(model)
        result = build_result(data, solver, model, status=cp_model.UNKNOWN)

        self.assertEqual(result["status"], "failed")
        timeout = [d for d in result["diagnostics"] if d.get("id") == "diag-timeout"]
        self.assertTrue(timeout, "expected an explicit timeout diagnostic on UNKNOWN")
        self.assertEqual(
            [d for d in result["diagnostics"] if d["type"] == "unplaced"],
            [],
            "no misleading unplaced diagnostics on timeout",
        )

    def test_empty_diagnostics_when_everything_ok(self):
        data = self._minimal_data()
        data["coaches"] = [
            {"id": "coach-1", "firstName": "Alice", "lastName": "Smith"},
        ]
        data["slotTemplates"] = [
            {
                "id": "tpl-1",
                "teamId": "team-1",
                "venueId": "venue-1",
                "coachId": "coach-1",
                "dayOfWeek": 1,
                "startTime": "09:00",
                "durationMinutes": 15,
                "lockLevel": "NONE",
            },
        ]
        model = build_model(data)
        for var in model.x.values():
            model.Add(var == 1)

        solver, status = self._solve(model)
        result = build_result(data, solver, model, status=status)

        self.assertEqual(result["diagnostics"], [])


class DiagnosticPrecisionTest(unittest.TestCase):
    """S5: diagnostics must answer who / when / why in manager language."""

    def test_venue_over_capacity_names_teams_venue_day_time(self) -> None:
        from app.solver.result_builder import _diagnose_conflicts

        model_data = {
            "teams": [
                {"id": "team-1", "name": "Séniors M1"},
                {"id": "team-2", "name": "U15 F"},
            ],
            "venues": [{"id": "venue-1", "name": "Gymnase Léo Lagrange"}],
            "coaches": [],
        }
        slots = [
            {"venueId": "venue-1", "teamId": "team-1", "dayOfWeek": 2, "startTime": "18:00", "durationMinutes": 90},
            {"venueId": "venue-1", "teamId": "team-2", "dayOfWeek": 2, "startTime": "18:00", "durationMinutes": 90},
        ]
        diags = _diagnose_conflicts(model_data, cp_model.OPTIMAL, slots)
        self.assertEqual(1, len(diags))
        msg = diags[0]["message"]
        self.assertIn("Gymnase Léo Lagrange", msg)  # which venue
        self.assertIn("Séniors M1", msg)  # which teams (by name)
        self.assertIn("U15 F", msg)
        self.assertIn("mardi", msg)  # when (day)
        self.assertIn("18:00", msg)  # when (time)
        self.assertEqual("venue-1", diags[0]["venueId"])
        self.assertEqual(2, diags[0]["dayOfWeek"])

    def test_venue_capacity_two_not_flagged(self) -> None:
        from app.solver.result_builder import _diagnose_conflicts

        model_data = {
            "teams": [{"id": "t1", "name": "A"}, {"id": "t2", "name": "B"}],
            "venues": [{"id": "v1", "name": "Gym"}],
            "coaches": [],
        }
        slots = [
            {"venueId": "v1", "teamId": "t1", "dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 90},
            {"venueId": "v1", "teamId": "t2", "dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 90},
        ]
        caps = {("v1", 1, "18:00"): 2}
        self.assertEqual([], _diagnose_conflicts(model_data, cp_model.OPTIMAL, slots, slot_capacities=caps))

    def test_unplaced_names_team_and_gives_reason(self) -> None:
        from app.solver.result_builder import _diagnose_unplaced

        model_data = {
            "teams": [{"id": "team-9", "name": "U11 Mixte", "sessionsPerWeek": 2}],
            "venues": [
                {
                    "id": "v1",
                    "name": "Gym",
                    "trainingSlots": [{"dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 90}],
                }
            ],
        }
        diags = _diagnose_unplaced(model_data, slots=[])
        self.assertEqual(1, len(diags))
        msg = diags[0]["message"]
        self.assertIn("U11 Mixte", msg)  # who (by name)
        self.assertIn("n'a pas pu être placée", msg)  # why (reason present)
        self.assertEqual("team-9", diags[0]["teamId"])

    def test_unplaced_forced_venue_without_slots_reason(self) -> None:
        from app.solver.result_builder import _diagnose_unplaced

        model_data = {
            "teams": [{"id": "t1", "name": "Séniors", "forcedVenueId": "v-closed"}],
            "venues": [
                {
                    "id": "v-open",
                    "name": "Gym Ouvert",
                    "trainingSlots": [{"dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 90}],
                },
                {"id": "v-closed", "name": "Gym Fermé", "trainingSlots": []},
            ],
        }
        diags = _diagnose_unplaced(model_data, slots=[])
        self.assertEqual(1, len(diags))
        self.assertIn("Gym Fermé", diags[0]["message"])  # names the saturated forced venue

    def test_partial_sessions_warns_even_when_tier_floor_met(self) -> None:
        """Team requested 2 sessions, only 1 placed → WARNING even if tier floor (1) is met."""
        from app.solver.result_builder import _diagnose_session_below_effective_min

        model_data = {
            "teams": [{"id": "t1", "name": "U11 Mixte", "priorityTierId": 4, "sessionsPerWeek": 2}],
            "constraints": [{"type": "PRIORITY_TIER", "metadata": {"id": 4, "defaultMinSessions": 1}}],
        }
        # one placed 15-min unit → placed = 1, requested = 2, floor = 1
        slots = [{"teamId": "t1", "durationMinutes": 15}]
        diags = _diagnose_session_below_effective_min(model_data, slots)
        self.assertEqual(1, len(diags))
        self.assertEqual("WARNING", diags[0]["severity"])  # floor met → warning, not error
        msg = diags[0]["message"]
        self.assertIn("U11 Mixte", msg)
        self.assertIn("2 séance", msg)  # requested
        self.assertIn("1 placée", msg)  # placed

    def test_below_tier_floor_is_error_severity(self) -> None:
        from app.solver.result_builder import _diagnose_session_below_effective_min

        model_data = {
            "teams": [{"id": "t1", "name": "SM1", "priorityTierId": 1, "sessionsPerWeek": 3}],
            "constraints": [{"type": "PRIORITY_TIER", "metadata": {"id": 1, "defaultMinSessions": 2}}],
        }
        diags = _diagnose_session_below_effective_min(model_data, slots=[])  # 0 placed, floor 2
        self.assertEqual(1, len(diags))
        # Severity scale normalised to the backend/frontend enum (ENG-09).
        self.assertEqual("ERROR", diags[0]["severity"])

    def test_infeasible_message_counts_places_not_slots(self) -> None:
        # PR A (2026-08-06) — BCCL: 84 sessions for 82 SLOTS but 87 PLACES (capacity-2
        # slots). The old len(slots) count claimed « capacité insuffisante », a lie.
        from app.solver.result_builder import _infeasible_message

        model_data = {
            "teams": [{"id": "t1", "sessionsPerWeek": 3}],
            "venues": [
                {
                    "id": "v1",
                    "name": "Matéo",
                    "trainingSlots": [
                        {"dayOfWeek": 1, "startTime": "18:00", "capacity": 2},
                        {"dayOfWeek": 2, "startTime": "18:00", "capacity": 1},
                    ],
                }
            ],
        }
        # demand 3 ≤ 3 places (2 slots): no capacity claim.
        self.assertNotIn("capacité est insuffisante", _infeasible_message(model_data))

        model_data["teams"][0]["sessionsPerWeek"] = 4
        message = _infeasible_message(model_data)
        self.assertIn("capacité est insuffisante", message)
        self.assertIn("3 place(s) de créneau", message)

    def test_infeasible_message_dedupes_slot_triplets_like_the_model(self) -> None:
        from app.solver.result_builder import _infeasible_message

        model_data = {
            "teams": [{"id": "t1", "sessionsPerWeek": 2}],
            "venues": [
                {
                    "id": "v1",
                    "trainingSlots": [
                        # Same (venue, day, start) twice — model.slot_capacities overwrites.
                        {"dayOfWeek": 1, "startTime": "18:00", "capacity": 1},
                        {"dayOfWeek": 1, "startTime": "18:00", "capacity": 1},
                    ],
                }
            ],
        }
        message = _infeasible_message(model_data)
        self.assertIn("capacité est insuffisante", message)
        self.assertIn("1 place(s)", message)

    def test_infeasible_message_names_the_saturated_venue(self) -> None:
        # « au moins » minimums need model VARIABLES; a HARD pin blocks its triplet for
        # everyone (model.py) — so 2 minimums against 1 free place is provably infeasible.
        from app.solver.result_builder import _infeasible_message

        model_data = {
            "teams": [
                {"id": "t1", "sessionsPerWeek": 1},
                {"id": "t2", "sessionsPerWeek": 1},
            ],
            "venues": [
                {
                    "id": "v1",
                    "name": "Matéo",
                    "trainingSlots": [
                        {"dayOfWeek": 1, "startTime": "18:00", "capacity": 1},
                        {"dayOfWeek": 2, "startTime": "18:00", "capacity": 1},
                    ],
                },
                {
                    "id": "v2",
                    "name": "Annexe",
                    "trainingSlots": [
                        {"dayOfWeek": 3, "startTime": "18:00", "capacity": 2},
                        {"dayOfWeek": 4, "startTime": "18:00", "capacity": 2},
                    ],
                },
            ],
            "slotTemplates": [
                # Pins v1 Monday: its place is gone for every minimum.
                {"teamId": "t9", "venueId": "v1", "dayOfWeek": 1, "startTime": "18:00", "lockLevel": "HARD"},
            ],
            "constraints": [
                {
                    "family": "FACILITY",
                    "ruleType": "HARD",
                    "scope": "TEAM",
                    "scopeTargetId": "t1",
                    "config": {"minAtVenueId": "v1"},
                },
                {
                    "family": "FACILITY",
                    "ruleType": "HARD",
                    "scope": "TEAM",
                    "scopeTargetId": "t2",
                    "config": {"minAtVenueId": "v1"},
                },
            ],
        }
        message = _infeasible_message(model_data)
        self.assertIn("Matéo", message)
        self.assertIn("réclament 2 place(s)", message)
        self.assertIn("que 1 de libre(s)", message)

    def test_infeasible_message_generic_when_nothing_measurable(self) -> None:
        from app.solver.result_builder import _infeasible_message

        model_data = {
            "teams": [{"id": "t1", "sessionsPerWeek": 1}],
            "venues": [{"id": "v1", "trainingSlots": [{"dayOfWeek": 1, "startTime": "18:00", "capacity": 1}]}],
        }
        message = _infeasible_message(model_data)
        self.assertIn("contraintes dures", message)

    def test_travel_diagnostic_delegates_to_the_shared_geometry_source(self) -> None:
        # ENG-37 — preuve de NON-DUPLICATION. Le diagnostic ne recalcule PLUS gap/barème : il
        # délègue à la SOURCE UNIQUE ``is_travel_too_tight`` (celle-là même que la pose du solveur
        # consomme via ``iter_travel_pairs_from_placements``). On le prouve en NEUTRALISANT la
        # source : si le diagnostic la consomme, forcer « jamais trop serré » fait disparaître le
        # résidu ; le jour où quelqu'un réintroduirait un barème/battement LOCAL dans
        # ``result_builder``, ce patch n'aurait plus d'effet et ce test rougirait.
        from unittest.mock import patch

        from app.solver.result_builder import _diagnose_travel_times

        model_data = {
            "teams": [{"id": "t1", "name": "U11 A"}, {"id": "t2", "name": "U11 B"}],
            "venues": [{"id": "V1", "name": "Gymnase Nord"}, {"id": "V2", "name": "Gymnase Sud"}],
            "coaches": [{"id": "c1", "name": "Léa", "isVehicled": False}],
            "implicitRules": {"travelTime": {"intensity": "MANDATORY"}},
            "venueTravelTimes": [{"venueAId": "V1", "venueBId": "V2", "drivingMinutes": 5, "walkingMinutes": 30}],
        }
        # Deux séances VERROUILLÉES du même coach non véhiculé : V1 finit 19:50 (18:20 + 90),
        # V2 débute 20:00 — battement 10 < barème à pied 30 → résidu MANDATORY nommé.
        slots = [
            {"venueId": "V1", "teamId": "t1", "dayOfWeek": 1, "startTime": "18:20", "durationMinutes": 90},
            {"venueId": "V2", "teamId": "t2", "dayOfWeek": 1, "startTime": "20:00", "durationMinutes": 90},
        ]
        team_coach_map = {"t1": ["c1"], "t2": ["c1"]}

        diags = _diagnose_travel_times(model_data, cp_model.OPTIMAL, slots, team_coach_map)
        self.assertEqual(1, len(diags))
        self.assertEqual("travel_time_infeasible", diags[0]["type"])
        self.assertEqual("c1", diags[0]["coachId"])

        with patch("app.solver.result_builder.is_travel_too_tight", return_value=False):
            self.assertEqual([], _diagnose_travel_times(model_data, cp_model.OPTIMAL, slots, team_coach_map))


if __name__ == "__main__":
    unittest.main()
