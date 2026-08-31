"""Lot PASSERELLES PR-2 — le VERDICT de déplacement (`/validate-assignments`) honore les passerelles.

Deux miroirs du côté génération (arbitrage n°4 INCLUS) :
  * MANDATORY — un déplacement qui fait CHEVAUCHER deux équipes passerelées obligatoires est
    REFUSÉ, motif NOMMÉ ``team_link_broken`` (patron du miroir mutualisation ``shared_training``) ;
  * PREFERRED — le chevauchement créé par un déplacement ACCEPTÉ apparaît en COMPROMIS nommé
    (famille ``team_link``, effet ``broken``) — le rail P2-32.

Chaque garde est falsifiée dans les deux sens : le déplacement qui NE crée PAS de chevauchement
est accepté sans compromis passerelle.
"""

from __future__ import annotations

from typing import Any

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.validate_assignments import validate_assignment
from tests.support.pipeline import as_validate_payload, make_team, make_venue


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    return validate_assignment(ValidateAssignmentsInputSchema.model_validate(as_validate_payload(payload)))


def _link(link_id: str, team_a: str, team_b: str, intensity: str) -> dict[str, Any]:
    return {"id": link_id, "teamAId": team_a, "teamBId": team_b, "intensity": intensity}


def _template(team_id: str, venue_id: str, day: int, start: str) -> dict[str, Any]:
    return {
        "id": f"tpl-{team_id}-{venue_id}-{day}-{start}",
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": 90,
        "lockLevel": "NONE",
    }


class TestMandatoryMoveMirror:
    def test_move_creating_overlap_is_refused_and_named(self) -> None:
        """t1 (vA lundi) déplacée sur jeudi 20:00 → chevauche t2 (vB jeudi) : REFUS nommé."""
        result = _run(
            {
                "clubId": "c",
                "seasonId": "s",
                "venues": [make_venue("vA", [(1, "20:00"), (4, "20:00")]), make_venue("vB", [(4, "20:00")])],
                "teams": [make_team("t1"), make_team("t2")],
                "constraints": [],
                "teamLinks": [_link("l", "t1", "t2", "MANDATORY")],
                "slotTemplates": [_template("t2", "vB", 4, "20:00")],
                "candidate": {
                    "teamId": "t1",
                    "venueId": "vA",
                    "dayOfWeek": 4,
                    "startTime": "20:00",
                    "durationMinutes": 90,
                },
                "reference": {
                    "teamId": "t1",
                    "venueId": "vA",
                    "dayOfWeek": 1,
                    "startTime": "20:00",
                    "durationMinutes": 90,
                },
            }
        )
        assert result["valid"] is False
        assert result["violations"][0]["rule"] == "team_link_broken"
        assert result["violations"][0]["team_id"] == "t1"

    def test_move_without_overlap_is_accepted(self) -> None:
        """TÉMOIN : la même grille, mais t1 déplacée sur MARDI (pas de chevauchement) → accepté."""
        result = _run(
            {
                "clubId": "c",
                "seasonId": "s",
                "venues": [make_venue("vA", [(1, "20:00"), (2, "20:00")]), make_venue("vB", [(4, "20:00")])],
                "teams": [make_team("t1"), make_team("t2")],
                "constraints": [],
                "teamLinks": [_link("l", "t1", "t2", "MANDATORY")],
                "slotTemplates": [_template("t2", "vB", 4, "20:00")],
                "candidate": {
                    "teamId": "t1",
                    "venueId": "vA",
                    "dayOfWeek": 2,
                    "startTime": "20:00",
                    "durationMinutes": 90,
                },
                "reference": {
                    "teamId": "t1",
                    "venueId": "vA",
                    "dayOfWeek": 1,
                    "startTime": "20:00",
                    "durationMinutes": 90,
                },
            }
        )
        assert result["valid"] is True

    def test_move_onto_declared_shared_case_is_not_refused(self) -> None:
        """EXEMPTION : déplacer t1 sur la case commune déclarée (même gymnase/heure que t2, groupe
        ``sharedTrainings``) n'est PAS refusé — c'est la simultanéité VOLONTAIRE autorisée."""
        result = _run(
            {
                "clubId": "c",
                "seasonId": "s",
                "venues": [make_venue("vS", [(1, "20:00"), (4, "20:00")], capacity=2)],
                "teams": [make_team("t1"), make_team("t2")],
                "constraints": [],
                "teamLinks": [_link("l", "t1", "t2", "MANDATORY")],
                "sharedTrainings": [{"id": "g", "teamIds": ["t1", "t2"], "commonSessions": 1}],
                "slotTemplates": [_template("t2", "vS", 4, "20:00")],
                "candidate": {
                    "teamId": "t1",
                    "venueId": "vS",
                    "dayOfWeek": 4,
                    "startTime": "20:00",
                    "durationMinutes": 90,
                },
                "reference": {
                    "teamId": "t1",
                    "venueId": "vS",
                    "dayOfWeek": 1,
                    "startTime": "20:00",
                    "durationMinutes": 90,
                },
            }
        )
        # La case (vS, jeudi, 20:00) est partagée par les deux équipes déclarées → aucun refus
        # passerelle (le verdict solveur décide alors du reste).
        rules = {v["rule"] for v in result["violations"]}
        assert "team_link_broken" not in rules


class TestPreferredMoveCompromise:
    def test_move_creating_overlap_surfaces_a_named_compromise(self) -> None:
        """PREFERRED : t1 déplacée sur jeudi (chevauche t2) est ACCEPTÉE, avec un compromis
        ``team_link``/``broken`` nommé."""
        result = _run(
            {
                "clubId": "c",
                "seasonId": "s",
                "venues": [make_venue("vA", [(1, "20:00"), (4, "20:00")]), make_venue("vB", [(4, "20:00")])],
                "teams": [make_team("t1"), make_team("t2")],
                "constraints": [],
                "teamLinks": [_link("l", "t1", "t2", "PREFERRED")],
                "slotTemplates": [_template("t2", "vB", 4, "20:00")],
                "candidate": {
                    "teamId": "t1",
                    "venueId": "vA",
                    "dayOfWeek": 4,
                    "startTime": "20:00",
                    "durationMinutes": 90,
                },
                "reference": {
                    "teamId": "t1",
                    "venueId": "vA",
                    "dayOfWeek": 1,
                    "startTime": "20:00",
                    "durationMinutes": 90,
                },
            }
        )
        assert result["valid"] is True
        families = {c["family"]: c for c in result["compromises"]}
        assert "team_link" in families, f"le chevauchement PREFERRED doit être nommé; got {result['compromises']}"
        assert families["team_link"]["effect"] == "broken"

    def test_move_without_overlap_has_no_team_link_compromise(self) -> None:
        """TÉMOIN : le même déplacement mais sur MARDI (pas de chevauchement) → aucun compromis passerelle."""
        result = _run(
            {
                "clubId": "c",
                "seasonId": "s",
                "venues": [make_venue("vA", [(1, "20:00"), (2, "20:00")]), make_venue("vB", [(4, "20:00")])],
                "teams": [make_team("t1"), make_team("t2")],
                "constraints": [],
                "teamLinks": [_link("l", "t1", "t2", "PREFERRED")],
                "slotTemplates": [_template("t2", "vB", 4, "20:00")],
                "candidate": {
                    "teamId": "t1",
                    "venueId": "vA",
                    "dayOfWeek": 2,
                    "startTime": "20:00",
                    "durationMinutes": 90,
                },
                "reference": {
                    "teamId": "t1",
                    "venueId": "vA",
                    "dayOfWeek": 1,
                    "startTime": "20:00",
                    "durationMinutes": 90,
                },
            }
        )
        assert result["valid"] is True
        assert "team_link" not in {c["family"] for c in result["compromises"]}
