"""P2-53 RMM-8 PR-2 — pose et lecture de la règle implicite `travelTime` (niveau modèle).

Tient les briques : la matrice de trajet et le barème par statut de coach (voiture/à pied) et
d'office à pied pour une passerelle, le défaut 20 pour un couple non arbitré, le palier du
départage, l'exemption même-gymnase par construction, et la pose byte-identique quand la règle
est absente. La SÉMANTIQUE (pipeline réel : MANDATORY force le battement, le départage choisit le
gymnase le plus proche, aucune violation dure) vit dans
``tests/semantic/test_travel_time_semantics.py``.
"""

from __future__ import annotations

from typing import Any

from ortools.sat.python import cp_model

from app.solver.constraints import (
    AssignmentVariable,
    add_travel_departage_penalty,
    add_travel_time_hard_constraints,
    add_travel_time_penalty,
    build_travel_matrix,
    resolve_implicit_rules,
)
from app.solver.constraints.travel import _barometer, _departage_bucket


def _matrix_row(a: str, b: str, driving: int | None, walking: int | None) -> dict[str, Any]:
    return {"venueAId": a, "venueBId": b, "drivingMinutes": driving, "walkingMinutes": walking}


def _assign(model: Any, name: str, team: str, venue: str, day: int, start: int, end: int) -> AssignmentVariable:
    return AssignmentVariable(
        var=model.NewBoolVar(name),
        team_id=team,
        venue_id=venue,
        slot_id=f"{day}:{start}",
        start=start,
        end=end,
    )


class TestMatrixAndBarometer:
    def test_matrix_is_symmetric_and_keeps_nulls(self) -> None:
        matrix = build_travel_matrix([_matrix_row("A", "B", 12, 40)])
        assert matrix[frozenset({"A", "B"})] == (12, 40)
        assert matrix[frozenset({"B", "A"})] == (12, 40)  # symétrique par frozenset

    def test_vehicled_coach_reads_the_driving_column(self) -> None:
        matrix = build_travel_matrix([_matrix_row("A", "B", 12, 40)])
        assert _barometer(matrix, "A", "B", driving=True, default_minutes=20) == 12

    def test_non_vehicled_coach_reads_the_walking_column(self) -> None:
        matrix = build_travel_matrix([_matrix_row("A", "B", 12, 40)])
        assert _barometer(matrix, "A", "B", driving=False, default_minutes=20) == 40

    def test_null_column_falls_back_to_default_20(self) -> None:
        matrix = build_travel_matrix([_matrix_row("A", "B", None, 40)])
        assert _barometer(matrix, "A", "B", driving=True, default_minutes=20) == 20

    def test_absent_pair_falls_back_to_default(self) -> None:
        matrix = build_travel_matrix([_matrix_row("A", "B", 12, 40)])
        assert _barometer(matrix, "A", "C", driving=False, default_minutes=20) == 20


class TestDepartageBucket:
    def test_short_medium_long_paliers(self) -> None:
        assert _departage_bucket(5) == 1
        assert _departage_bucket(15) == 1
        assert _departage_bucket(16) == 2
        assert _departage_bucket(40) == 2
        assert _departage_bucket(41) == 3
        assert _departage_bucket(120) == 3


class TestResolveTravelRule:
    def test_absent_block_leaves_the_rule_inactive(self) -> None:
        rules = resolve_implicit_rules({"coachRestDay": {"intensity": "PREFERRED"}})
        assert rules.travel_time_active is False

    def test_present_block_activates_with_defaults(self) -> None:
        rules = resolve_implicit_rules({"travelTime": {"intensity": "PREFERRED"}})
        assert rules.travel_time_active is True
        assert rules.travel_time_intensity == "PREFERRED"
        assert rules.travel_time_default_minutes == 20

    def test_mandatory_and_custom_default(self) -> None:
        rules = resolve_implicit_rules({"travelTime": {"intensity": "MANDATORY", "defaultMinutes": 35}})
        assert rules.travel_time_intensity == "MANDATORY"
        assert rules.travel_time_default_minutes == 35


class TestHardPose:
    def test_empty_matrix_adds_nothing(self) -> None:
        model = cp_model.CpModel()
        a = _assign(model, "a", "t1", "V1", 1, 1080, 1170)
        b = _assign(model, "b", "t2", "V2", 1, 1200, 1290)
        added = add_travel_time_hard_constraints(
            model,
            [a, b],
            coaches=[{"id": "c1", "isVehicled": False}],
            team_coach_map={"t1": ["c1"], "t2": ["c1"]},
            venue_travel_times=[],
        )
        assert added == 0

    def test_tight_cross_venue_pair_posts_exclusion(self) -> None:
        # V1 finit 19:50 (1190), V2 débute 20:00 (1200) : écart 10 < barème à pied 30 → interdit.
        model = cp_model.CpModel()
        a = _assign(model, "a", "t1", "V1", 1, 1100, 1190)
        b = _assign(model, "b", "t2", "V2", 1, 1200, 1290)
        before = len(model.Proto().constraints)
        added = add_travel_time_hard_constraints(
            model,
            [a, b],
            coaches=[{"id": "c1", "isVehicled": False}],
            team_coach_map={"t1": ["c1"], "t2": ["c1"]},
            venue_travel_times=[_matrix_row("V1", "V2", 5, 30)],
        )
        assert added == 1
        assert len(model.Proto().constraints) == before + 1

    def test_same_venue_pair_is_never_constrained(self) -> None:
        # Même gymnase : jamais concerné (exemption D-14 intacte par construction).
        model = cp_model.CpModel()
        a = _assign(model, "a", "t1", "V1", 1, 1100, 1190)
        b = _assign(model, "b", "t2", "V1", 1, 1200, 1290)
        added = add_travel_time_hard_constraints(
            model,
            [a, b],
            coaches=[{"id": "c1", "isVehicled": False}],
            team_coach_map={"t1": ["c1"], "t2": ["c1"]},
            venue_travel_times=[_matrix_row("V1", "V2", 5, 30)],
        )
        assert added == 0

    def test_comfortable_gap_is_not_constrained(self) -> None:
        # Écart 70 ≥ barème 30 → aucune contrainte.
        model = cp_model.CpModel()
        a = _assign(model, "a", "t1", "V1", 1, 1000, 1130)
        b = _assign(model, "b", "t2", "V2", 1, 1200, 1290)
        added = add_travel_time_hard_constraints(
            model,
            [a, b],
            coaches=[{"id": "c1", "isVehicled": False}],
            team_coach_map={"t1": ["c1"], "t2": ["c1"]},
            venue_travel_times=[_matrix_row("V1", "V2", 5, 30)],
        )
        assert added == 0

    def test_vehicled_coach_uses_the_shorter_driving_gap(self) -> None:
        # Écart 20 : ≥ barème voiture 12 (OK) mais < barème à pied 40. Véhiculé ⇒ aucune contrainte.
        model = cp_model.CpModel()
        a = _assign(model, "a", "t1", "V1", 1, 1090, 1180)
        b = _assign(model, "b", "t2", "V2", 1, 1200, 1290)
        added = add_travel_time_hard_constraints(
            model,
            [a, b],
            coaches=[{"id": "c1", "isVehicled": True}],
            team_coach_map={"t1": ["c1"], "t2": ["c1"]},
            venue_travel_times=[_matrix_row("V1", "V2", 12, 40)],
        )
        assert added == 0

    def test_bridge_uses_walking_even_between_vehicled_teams(self) -> None:
        # Passerelle : barème À PIED d'office, écart 20 < 40 → interdit (les coachs sont véhiculés).
        model = cp_model.CpModel()
        a = _assign(model, "a", "tA", "V1", 1, 1090, 1180)
        b = _assign(model, "b", "tB", "V2", 1, 1200, 1290)
        added = add_travel_time_hard_constraints(
            model,
            [a, b],
            coaches=[{"id": "c1", "isVehicled": True}],
            team_coach_map={},  # aucun coach commun : c'est la passerelle qui relie
            team_links=[{"id": "l", "teamAId": "tA", "teamBId": "tB", "intensity": "MANDATORY"}],
            venue_travel_times=[_matrix_row("V1", "V2", 12, 40)],
        )
        assert added == 1


class TestSoftPose:
    def test_preferred_tight_pair_yields_minus_six_term(self) -> None:
        model = cp_model.CpModel()
        a = _assign(model, "a", "t1", "V1", 1, 1100, 1190)
        b = _assign(model, "b", "t2", "V2", 1, 1200, 1290)
        terms = add_travel_time_penalty(
            model,
            [a, b],
            coaches=[{"id": "c1", "isVehicled": False}],
            team_coach_map={"t1": ["c1"], "t2": ["c1"]},
            venue_travel_times=[_matrix_row("V1", "V2", 5, 30)],
        )
        assert len(terms) == 1
        assert terms[0][1] == -6

    def test_comfortable_pair_yields_no_soft_term(self) -> None:
        model = cp_model.CpModel()
        a = _assign(model, "a", "t1", "V1", 1, 1000, 1130)
        b = _assign(model, "b", "t2", "V2", 1, 1200, 1290)
        terms = add_travel_time_penalty(
            model,
            [a, b],
            coaches=[{"id": "c1", "isVehicled": False}],
            team_coach_map={"t1": ["c1"], "t2": ["c1"]},
            venue_travel_times=[_matrix_row("V1", "V2", 5, 30)],
        )
        assert terms == []


class TestDepartagePose:
    def test_empty_matrix_yields_no_terms(self) -> None:
        model = cp_model.CpModel()
        a = _assign(model, "a", "t1", "V1", 1, 1000, 1130)
        b = _assign(model, "b", "t2", "V2", 1, 1200, 1290)
        assert add_travel_departage_penalty(model, [a, b], team_coach_map={"t1": ["c1"]}, venue_travel_times=[]) == []

    def test_departage_malus_scales_with_the_barometer_palier(self) -> None:
        # Un enchaînement confortable (écart 70) MAIS long à pied (35 → palier 2) porte tout de
        # même son malus de départage : le départage juge le TRAJET, pas le battement.
        model = cp_model.CpModel()
        a = _assign(model, "a", "t1", "V1", 1, 1000, 1130)
        b = _assign(model, "b", "t2", "V2", 1, 1200, 1290)
        terms = add_travel_departage_penalty(
            model,
            [a, b],
            coaches=[{"id": "c1", "isVehicled": False}],
            team_coach_map={"t1": ["c1"], "t2": ["c1"]},
            venue_travel_times=[_matrix_row("V1", "V2", 5, 35)],
        )
        assert len(terms) == 1
        assert terms[0][1] == -2  # palier 2 (16..40 min) × poids 1

    def test_same_venue_pair_never_departaged(self) -> None:
        model = cp_model.CpModel()
        a = _assign(model, "a", "t1", "V1", 1, 1000, 1130)
        b = _assign(model, "b", "t2", "V1", 1, 1200, 1290)
        assert (
            add_travel_departage_penalty(
                model,
                [a, b],
                coaches=[{"id": "c1", "isVehicled": False}],
                team_coach_map={"t1": ["c1"], "t2": ["c1"]},
                venue_travel_times=[_matrix_row("V1", "V2", 5, 35)],
            )
            == []
        )
