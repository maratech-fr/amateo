"""Lot PASSERELLES — le bloc `teamLinks` est ACCEPTÉ par le contrat, et un bloc ABSENT/VIDE
laisse le chemin de code byte-identique (patron `previousAssignments`).

⚠ Depuis PR-2 (sémantique moteur) un bloc PEUPLÉ n'est PLUS inerte : une passerelle MANDATORY
sépare deux équipes, une PREFERRED les pénalise si elles se chevauchent — c'est le sujet de
`test_team_link_constraints.py` et des tests sémantiques. Ce fichier ne garde donc que
l'ACCEPTATION du schéma et l'inertie du bloc VIDE (toujours vraie)."""

from __future__ import annotations

from typing import Any

from app.schemas.input_schema import ScheduleInputSchema
from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload


def _link(link_id: str, team_a: str, team_b: str, intensity: str) -> dict[str, Any]:
    return {"id": link_id, "teamAId": team_a, "teamBId": team_b, "intensity": intensity}


def _fixture() -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    teams = [make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)]
    venues = [make_venue("vA", [(1, "18:00"), (2, "18:00")], capacity=2)]
    return teams, venues


class TestSchemaAccepts:
    def test_a_payload_with_team_links_validates(self) -> None:
        teams, venues = _fixture()
        payload = make_payload(teams=teams, venues=venues)
        payload["teamLinks"] = [
            _link("l1", "t1", "t2", "PREFERRED"),
            _link("l2", "t1", "t2", "MANDATORY"),
        ]
        parsed = ScheduleInputSchema.model_validate(payload)
        assert len(parsed.team_links) == 2
        assert parsed.team_links[0].intensity == "PREFERRED"
        assert parsed.team_links[1].intensity == "MANDATORY"

    def test_intensity_defaults_to_preferred_when_absent(self) -> None:
        parsed = ScheduleInputSchema.model_validate(
            make_payload(teams=_fixture()[0], venues=_fixture()[1])
            | {"teamLinks": [{"id": "l", "teamAId": "t1", "teamBId": "t2"}]}
        )
        assert parsed.team_links[0].intensity == "PREFERRED"


class TestInertness:
    def test_empty_team_links_block_matches_no_block(self) -> None:
        teams, venues = _fixture()
        without = solve_payload(make_payload(teams=teams, venues=venues))
        with_empty_payload = make_payload(teams=teams, venues=venues)
        with_empty_payload["teamLinks"] = []
        with_empty = solve_payload(with_empty_payload)
        assert without["slots"] == with_empty["slots"]
        assert without["score"] == with_empty["score"]
