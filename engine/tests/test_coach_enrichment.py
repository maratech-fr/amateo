"""Integration tests verifying coach constraint handling from TEAM_COACH
and COACH_PLAYER_UNAVAILABILITY constraints.

These tests confirm that when the solver receives constraints with coach data,
the constraint functions use team_coach_map and team_player_map to look up
coach/player info — no AssignmentVariable duplication per coach.
"""

from __future__ import annotations

from ortools.sat.python import cp_model

from app.solver.constraints import (
    AssignmentVariable,
    add_level_1_hard_constraints,
    parse_v2_constraints,
)


def _solve(model: cp_model.CpModel) -> int:
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 5
    return solver.Solve(model)


class TestParseV2CoachConstraints:
    def test_team_coach_constraint_parsed(self) -> None:
        constraints = [
            {
                "id": "tc-1",
                "type": "TEAM_COACH",
                "teamId": "team-sm1",
                "severity": "HARD",
                "value": "coach-maxime",
                "metadata": {"coachId": "coach-maxime", "role": "head_coach"},
            }
        ]
        result = parse_v2_constraints(constraints)
        assert "team_coach_map" in result
        assert result["team_coach_map"]["team-sm1"] == ["coach-maxime"]

    def test_team_coach_multiple_coaches(self) -> None:
        constraints = [
            {
                "id": "tc-1",
                "type": "TEAM_COACH",
                "teamId": "team-sm1",
                "severity": "HARD",
                "value": "coach-maxime",
                "metadata": {"coachId": "coach-maxime"},
            },
            {
                "id": "tc-2",
                "type": "TEAM_COACH",
                "teamId": "team-sm1",
                "severity": "HARD",
                "value": "coach-thomas",
                "metadata": {"coachId": "coach-thomas"},
            },
        ]
        result = parse_v2_constraints(constraints)
        assert result["team_coach_map"]["team-sm1"] == ["coach-maxime", "coach-thomas"]

    def test_assistant_coach_excluded_from_hard_map(self) -> None:
        """ASSISTANT coach is optional — never a HARD no-overlap resource.

        Only the MAIN (head) coach blocks placement; an assistant busy
        elsewhere must not prevent the team from being scheduled.
        """
        constraints = [
            {
                "id": "tc-main",
                "type": "TEAM_COACH",
                "teamId": "team-sm1",
                "severity": "HARD",
                "value": "coach-maxime",
                "metadata": {"coachId": "coach-maxime", "role": "MAIN"},
            },
            {
                "id": "tc-asst",
                "type": "TEAM_COACH",
                "teamId": "team-sm1",
                "severity": "SOFT",
                "value": "coach-thomas",
                "metadata": {"coachId": "coach-thomas", "role": "ASSISTANT"},
            },
        ]
        result = parse_v2_constraints(constraints)
        assert result["team_coach_map"]["team-sm1"] == ["coach-maxime"]

    def test_coach_player_unavailability_parsed(self) -> None:
        constraints = [
            {
                "id": "cpu-1",
                "type": "COACH_PLAYER_UNAVAILABILITY",
                "teamId": "team-sm2",
                "severity": "HARD",
                "value": "coach-emeric",
                "metadata": {"coachId": "coach-emeric", "teamId": "team-sm2"},
            }
        ]
        result = parse_v2_constraints(constraints)
        assert "team_player_map" in result
        assert result["team_player_map"]["team-sm2"] == ["coach-emeric"]

    def test_inactive_team_coach_skipped(self) -> None:
        constraints = [
            {
                "id": "tc-1",
                "type": "TEAM_COACH",
                "teamId": "team-sm1",
                "severity": "HARD",
                "value": "coach-maxime",
                "isActive": False,
                "metadata": {"coachId": "coach-maxime"},
            }
        ]
        result = parse_v2_constraints(constraints)
        assert result["team_coach_map"] == {}

    def test_empty_constraints_returns_defaults(self) -> None:
        result = parse_v2_constraints([])
        assert result["team_coach_map"] == {}
        assert result["team_player_map"] == {}


class TestCoachAtMostOneWithMap:
    def test_coach_at_most_one_fires_with_team_coach_map(self) -> None:
        """When two teams share a coach (via team_coach_map) and both are assigned
        to the same time slot, the coach_at_most_one constraint must make it infeasible."""
        model = cp_model.CpModel()
        var_a = model.NewBoolVar("team_a_venue1_day1_18")
        var_b = model.NewBoolVar("team_b_venue1_day1_18")

        assignments = [
            AssignmentVariable(
                var=var_a,
                team_id="team-a",
                venue_id="venue-1",
                slot_id="1:18:00",
            ),
            AssignmentVariable(
                # D-14 : gymnase DIFFÉRENT — c'est ce qui rend le cas interdit. Le même
                # gymnase est désormais AUTORISÉ (cf. les deux tests SAME_venue plus bas),
                # donc ce test ne prouverait plus rien avec deux fois "venue-1".
                var=var_b,
                team_id="team-b",
                venue_id="venue-2",
                slot_id="1:18:00",
            ),
        ]

        team_coach_map = {"team-a": ["coach-1"], "team-b": ["coach-1"]}

        stats = add_level_1_hard_constraints(
            model,
            assignments,
            coaches=[{"id": "coach-1"}],
            team_coach_map=team_coach_map,
        )

        model.Add(var_a == 1)
        model.Add(var_b == 1)

        status = _solve(model)
        assert stats.coach_at_most_one > 0, f"Expected coach_at_most_one > 0, got {stats.coach_at_most_one}"
        assert status == cp_model.INFEASIBLE, f"Same coach in same slot should be INFEASIBLE, got {status}"

    def test_same_coach_two_teams_SAME_venue_is_allowed(self) -> None:
        """D-14 — Matthieu coache les SM1 et les SM2 au même moment, au même endroit.

        Arbitrage fondateur (2026-08-09) : « fonctionnellement il pourrait avoir le droit de
        faire ça, ce n'est pas une erreur. C'est rare mais c'est possible dans les petites
        structures. » Le coach est présent UNE fois et surveille deux groupes.

        Le backend (`CoachDoubleBookingDetector`) et la modale du wizard
        (`coachDoubleBooking.ts`) offraient déjà ce geste ; le moteur était le seul des trois
        à le refuser, et rendait donc INFEASIBLE ce que l'UI proposait.
        """
        model = cp_model.CpModel()
        # Le créneau doit accepter DEUX équipes — c'est la contrainte 1 (capacité du
        # gymnase), pas la règle coach, qui borne le nombre d'équipes au même endroit. Sans
        # cela le modèle reste INFEASIBLE pour une raison étrangère à Matthieu, et le test
        # croirait éprouver la règle coach en éprouvant la capacité.
        model.slot_capacities = {("gymnase-unique", 1, "18:00"): 2}
        var_a = model.NewBoolVar("a")
        var_b = model.NewBoolVar("b")
        assignments = [
            AssignmentVariable(var=var_a, team_id="sm1", venue_id="gymnase-unique", slot_id="1:18:00"),
            AssignmentVariable(var=var_b, team_id="sm2", venue_id="gymnase-unique", slot_id="1:18:00"),
        ]

        stats = add_level_1_hard_constraints(
            model,
            assignments,
            coaches=[{"id": "matthieu"}],
            team_coach_map={"sm1": ["matthieu"], "sm2": ["matthieu"]},
        )
        model.Add(var_a == 1)
        model.Add(var_b == 1)

        assert stats.coach_at_most_one == 0
        assert _solve(model) != cp_model.INFEASIBLE

    def test_overlapping_intervals_SAME_venue_is_allowed(self) -> None:
        """La même règle par le chemin des INTERVALLES, pas celui de la clé de temps.

        Des débuts différents (17h00-18h30 / 17h30-19h00) tombent dans deux clés de temps
        distinctes : seul `_add_interval_at_most_one` peut les opposer. Ce test isole donc
        l'exemption — sans lui, la retirer ne changeait RIEN à la suite, et la falsification
        restait verte.
        """
        model = cp_model.CpModel()
        model.slot_capacities = {("gymnase-unique", 2, "17:00"): 2, ("gymnase-unique", 2, "17:30"): 2}
        var_a = model.NewBoolVar("a")
        var_b = model.NewBoolVar("b")
        assignments = [
            AssignmentVariable(
                var=var_a, team_id="sm1", venue_id="gymnase-unique", slot_id="2:17:00", start=1020, end=1110
            ),
            AssignmentVariable(
                var=var_b, team_id="sm2", venue_id="gymnase-unique", slot_id="2:17:30", start=1050, end=1140
            ),
        ]

        stats = add_level_1_hard_constraints(
            model,
            assignments,
            coaches=[{"id": "matthieu"}],
            team_coach_map={"sm1": ["matthieu"], "sm2": ["matthieu"]},
        )
        model.Add(var_a == 1)
        model.Add(var_b == 1)

        assert stats.coach_at_most_one == 0
        assert _solve(model) != cp_model.INFEASIBLE

    def test_overlapping_intervals_DIFFERENT_venues_stays_impossible(self) -> None:
        """Le pendant obligatoire : deux gymnases restent une impossibilité physique.

        Les deux vont par paire. Relâcher le même gymnase sans garder celui-ci ouvrirait la
        porte à un coach téléporté — et c'est exactement l'erreur qu'a commise la première
        version du correctif (le gymnase mis dans la CLÉ de groupe séparait les variables
        au lieu de les opposer).
        """
        model = cp_model.CpModel()
        var_a = model.NewBoolVar("a")
        var_b = model.NewBoolVar("b")
        assignments = [
            AssignmentVariable(
                var=var_a, team_id="sm1", venue_id="gymnase-nord", slot_id="2:17:00", start=1020, end=1110
            ),
            AssignmentVariable(
                var=var_b, team_id="sm2", venue_id="gymnase-sud", slot_id="2:17:30", start=1050, end=1140
            ),
        ]

        stats = add_level_1_hard_constraints(
            model,
            assignments,
            coaches=[{"id": "matthieu"}],
            team_coach_map={"sm1": ["matthieu"], "sm2": ["matthieu"]},
        )
        model.Add(var_a == 1)
        model.Add(var_b == 1)

        assert stats.coach_at_most_one > 0
        assert _solve(model) == cp_model.INFEASIBLE

    def test_coaching_and_playing_in_the_SAME_venue_stays_impossible(self) -> None:
        """La BORNE de l'exemption : elle vaut coach-coach, jamais coach-JOUEUR.

        Matthieu peut surveiller deux groupes côte à côte ; il ne peut pas coacher les SM1
        et jouer en SM2 en même temps, fussent-elles dans le même gymnase — ce sont deux
        RÔLES, pas deux groupes. Sans ce test, remplacer la condition de rôle par un `True`
        constant laissait TOUTE la suite verte (falsification F3).
        """
        model = cp_model.CpModel()
        model.slot_capacities = {("gymnase-unique", 2, "17:00"): 2, ("gymnase-unique", 2, "17:30"): 2}
        var_a = model.NewBoolVar("a")
        var_b = model.NewBoolVar("b")
        assignments = [
            AssignmentVariable(
                var=var_a, team_id="sm1", venue_id="gymnase-unique", slot_id="2:17:00", start=1020, end=1110
            ),
            AssignmentVariable(
                var=var_b, team_id="sm2", venue_id="gymnase-unique", slot_id="2:17:30", start=1050, end=1140
            ),
        ]

        stats = add_level_1_hard_constraints(
            model,
            assignments,
            coaches=[{"id": "matthieu"}],
            team_coach_map={"sm1": ["matthieu"], "sm2": ["julie"]},
            team_player_map={"sm2": ["matthieu"]},
        )
        model.Add(var_a == 1)
        model.Add(var_b == 1)

        assert stats.coach_player_non_overlap > 0
        assert _solve(model) == cp_model.INFEASIBLE

    def test_coach_at_most_one_allows_different_slots(self) -> None:
        """When a coach coaches two teams in different time slots, it must be feasible."""
        model = cp_model.CpModel()
        var_a = model.NewBoolVar("team_a_venue1_day1_18")
        var_b = model.NewBoolVar("team_b_venue1_day1_19")

        assignments = [
            AssignmentVariable(
                var=var_a,
                team_id="team-a",
                venue_id="venue-1",
                slot_id="1:18:00",
            ),
            AssignmentVariable(
                var=var_b,
                team_id="team-b",
                venue_id="venue-1",
                slot_id="1:19:00",
            ),
        ]

        team_coach_map = {"team-a": ["coach-1"], "team-b": ["coach-1"]}

        add_level_1_hard_constraints(
            model,
            assignments,
            coaches=[{"id": "coach-1"}],
            team_coach_map=team_coach_map,
        )

        model.Add(var_a == 1)
        model.Add(var_b == 1)

        status = _solve(model)
        assert status in (cp_model.FEASIBLE, cp_model.OPTIMAL), (
            f"Coach in different slots should be feasible, got {status}"
        )

    def test_coach_at_most_one_fallback_to_assignment_coach_id(self) -> None:
        """When team_coach_map is None, fall back to coach_id on the assignment."""
        model = cp_model.CpModel()
        var_a = model.NewBoolVar("team_a_venue1_day1_18")
        var_b = model.NewBoolVar("team_b_venue1_day1_18")

        assignments = [
            AssignmentVariable(
                var=var_a,
                team_id="team-a",
                venue_id="venue-1",
                slot_id="1:18:00",
                coach_id="coach-1",
            ),
            AssignmentVariable(
                var=var_b,
                team_id="team-b",
                venue_id="venue-2",
                slot_id="1:18:00",
                coach_id="coach-1",
            ),
        ]

        stats = add_level_1_hard_constraints(model, assignments, coaches=[{"id": "coach-1"}])

        model.Add(var_a == 1)
        model.Add(var_b == 1)

        status = _solve(model)
        assert stats.coach_at_most_one > 0
        assert status == cp_model.INFEASIBLE


class TestCoachRestDayWithMap:
    def test_coach_rest_day_fires_with_team_coach_map(self) -> None:
        """When a coach is assigned to all 5 days via team_coach_map,
        the coach_rest_day constraint must make it infeasible."""
        model = cp_model.CpModel()
        assignments = [
            AssignmentVariable(
                var=model.NewBoolVar(f"team_a_day{d}"),
                team_id="team-a",
                venue_id="venue-1",
                slot_id=f"{d}:18:00",
            )
            for d in range(1, 6)
        ]

        team_coach_map = {"team-a": ["coach-1"]}

        stats = add_level_1_hard_constraints(
            model,
            assignments,
            coaches=[{"id": "coach-1"}],
            team_coach_map=team_coach_map,
        )

        for a in assignments:
            model.Add(a.var == 1)

        status = _solve(model)
        assert stats.coach_rest_day > 0, f"Expected coach_rest_day > 0, got {stats.coach_rest_day}"
        assert status == cp_model.INFEASIBLE, f"Coach working 5 days should be INFEASIBLE, got {status}"


class TestSalarieDistributionWithMap:
    def test_salarie_distribution_fires_with_team_coach_map(self) -> None:
        """Les salariés lus dans team_coach_map comptent : un jour où aucun d'eux n'a de séance
        reste faisable et allume le littéral « aucun salarié » (décision 2026-10-31)."""
        model = cp_model.CpModel()
        assignments = [
            AssignmentVariable(
                var=model.NewBoolVar(f"salarie_day{d}"),
                team_id=f"team-{d}",
                venue_id="venue-A",
                slot_id=f"{d}:18:00",
            )
            for d in range(1, 3)
        ]
        assignments += [
            AssignmentVariable(
                var=model.NewBoolVar(f"salarie2_day{d}"),
                team_id=f"team-{d + 5}",
                venue_id="venue-B",
                slot_id=f"{d}:19:00",
            )
            for d in range(1, 3)
        ]
        # Le mercredi a une séance, sans aucun salarié.
        assignments.append(
            AssignmentVariable(
                var=model.NewBoolVar("freelance_day3"),
                team_id="team-20",
                venue_id="venue-C",
                slot_id="3:18:00",
            )
        )

        coaches = [
            {"id": "coach-1", "isEmployee": True},
            {"id": "coach-2", "isEmployee": True},
        ]

        team_coach_map = {
            "team-1": ["coach-1"],
            "team-2": ["coach-1"],
            "team-6": ["coach-2"],
            "team-7": ["coach-2"],
            "team-20": ["coach-3"],
        }

        stats = add_level_1_hard_constraints(
            model,
            assignments,
            coaches=coaches,
            team_coach_map=team_coach_map,
        )

        for a in assignments:
            model.Add(a.var == 1)

        assert stats.salarie_distribution > 0, f"Expected salarie_distribution > 0, got {stats.salarie_distribution}"
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 5
        status = solver.Solve(model)
        assert status in (cp_model.FEASIBLE, cp_model.OPTIMAL), f"un jour sans salarié ne bloque plus, obtenu {status}"
        lit_days = {
            info.day_of_week
            for info in stats.implicit_soft_info
            if info.detail == "salarie" and solver.BooleanValue(info.var)
        }
        assert 3 in lit_days, f"le mercredi n'a aucun salarié, littéraux vrais : {lit_days}"
        assert not {1, 2} & lit_days, f"lundi et mardi ont un salarié, littéraux vrais : {lit_days}"


class TestMaxConsecutiveWithMap:
    def test_max_consecutive_fires_with_team_coach_map(self) -> None:
        """When team_coach_map maps a coach to teams in 3 consecutive slots,
        the max_consecutive_sessions constraint must make it infeasible."""
        model = cp_model.CpModel()
        a = AssignmentVariable(
            var=model.NewBoolVar("slot_a"),
            team_id="team-1",
            venue_id="venue-1",
            slot_id="1:18:00",
            start=18 * 60,
            end=19 * 60 + 30,
        )
        b = AssignmentVariable(
            var=model.NewBoolVar("slot_b"),
            team_id="team-2",
            venue_id="venue-1",
            slot_id="1:19:30",
            start=19 * 60 + 30,
            end=21 * 60,
        )
        c = AssignmentVariable(
            var=model.NewBoolVar("slot_c"),
            team_id="team-3",
            venue_id="venue-1",
            slot_id="1:21:00",
            start=21 * 60,
            end=22 * 60 + 30,
        )

        team_coach_map = {
            "team-1": ["coach-1"],
            "team-2": ["coach-1"],
            "team-3": ["coach-1"],
        }

        stats = add_level_1_hard_constraints(
            model,
            [a, b, c],
            coaches=[{"id": "coach-1"}],
            team_coach_map=team_coach_map,
        )

        model.Add(a.var == 1)
        model.Add(b.var == 1)
        model.Add(c.var == 1)

        status = _solve(model)
        assert stats.max_consecutive_sessions > 0, (
            f"Expected max_consecutive_sessions > 0, got {stats.max_consecutive_sessions}"
        )
        assert status == cp_model.INFEASIBLE, f"Coach in 3 consecutive slots should be INFEASIBLE, got {status}"


class TestCoachPlayerNonOverlapWithMap:
    def test_coach_player_non_overlap_with_team_player_map(self) -> None:
        """When a coach is coaching team A and also listed as a player on team B
        (via team_coach_map and team_player_map) at the same time, the
        coach_player_non_overlap constraint must prevent it."""
        model = cp_model.CpModel()
        coaching = AssignmentVariable(
            var=model.NewBoolVar("coaching"),
            team_id="team-a",
            venue_id="venue-1",
            slot_id="1:18:00",
        )
        playing = AssignmentVariable(
            var=model.NewBoolVar("playing"),
            team_id="team-b",
            venue_id="venue-2",
            slot_id="1:18:00",
        )

        team_coach_map = {"team-a": ["coach-1"]}
        team_player_map = {"team-b": ["coach-1"]}

        stats = add_level_1_hard_constraints(
            model,
            [coaching, playing],
            coaches=[{"id": "coach-1"}, {"id": "coach-2"}],
            team_coach_map=team_coach_map,
            team_player_map=team_player_map,
        )

        model.Add(coaching.var == 1)
        model.Add(playing.var == 1)

        status = _solve(model)
        assert stats.coach_player_non_overlap > 0, (
            f"Expected coach_player_non_overlap > 0, got {stats.coach_player_non_overlap}"
        )
        assert status == cp_model.INFEASIBLE, (
            f"Coach coaching + playing at same time should be INFEASIBLE, got {status}"
        )


class TestMultiCoachTeamWithMap:
    def test_multi_coach_team_constrains_both_coaches(self) -> None:
        """When a team has 2 coaches (via team_coach_map), both should be
        constrained by coach_at_most_one — no duplicate AssignmentVariable needed."""
        model = cp_model.CpModel()
        var_sm1 = model.NewBoolVar("sm1_venue1_day1_18")
        var_other = model.NewBoolVar("other_venue1_day1_18")

        # Each (team, venue, day, slot) appears exactly ONCE
        assignments = [
            AssignmentVariable(
                var=var_sm1,
                team_id="sm1",
                venue_id="venue-1",
                slot_id="1:18:00",
            ),
            AssignmentVariable(
                var=var_other,
                team_id="other-team",
                venue_id="venue-2",
                slot_id="1:18:00",
            ),
        ]

        team_coach_map = {
            "sm1": ["coach-maxime", "coach-thomas"],
            "other-team": ["coach-maxime"],
        }

        stats = add_level_1_hard_constraints(
            model,
            assignments,
            coaches=[{"id": "coach-maxime"}, {"id": "coach-thomas"}],
            team_coach_map=team_coach_map,
        )

        model.Add(var_sm1 == 1)
        model.Add(var_other == 1)

        status = _solve(model)
        assert stats.coach_at_most_one > 0, f"Expected coach_at_most_one > 0, got {stats.coach_at_most_one}"
        assert status == cp_model.INFEASIBLE, (
            f"Coach-maxime coaching two teams at same time should be INFEASIBLE, got {status}"
        )

    def test_no_venue_double_booking_with_multi_coach(self) -> None:
        """With multi-coach teams using maps, venue double-booking must still be
        prevented — each (team, venue, day, slot) appears exactly once."""
        model = cp_model.CpModel()
        var_sm1 = model.NewBoolVar("sm1_venue1_day1_18")
        var_sm2 = model.NewBoolVar("sm2_venue1_day1_18")

        assignments = [
            AssignmentVariable(
                var=var_sm1,
                team_id="sm1",
                venue_id="venue-1",
                slot_id="1:18:00",
            ),
            AssignmentVariable(
                var=var_sm2,
                team_id="sm2",
                venue_id="venue-1",
                slot_id="1:18:00",
            ),
        ]

        team_coach_map = {
            "sm1": ["coach-maxime", "coach-thomas"],
            "sm2": ["coach-emeric"],
        }

        stats = add_level_1_hard_constraints(
            model,
            assignments,
            coaches=[{"id": "coach-maxime"}, {"id": "coach-thomas"}, {"id": "coach-emeric"}],
            team_coach_map=team_coach_map,
        )

        # Both teams at same venue/slot should be infeasible (room_at_most_one)
        model.Add(var_sm1 == 1)
        model.Add(var_sm2 == 1)

        status = _solve(model)
        assert stats.room_at_most_one > 0, f"Expected room_at_most_one > 0, got {stats.room_at_most_one}"
        assert status == cp_model.INFEASIBLE, f"Two teams at same venue/slot should be INFEASIBLE, got {status}"
