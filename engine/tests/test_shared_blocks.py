"""P2-51 — le bloc `sharedBlocks` est ACCEPTÉ par le contrat (bornes 2..10 équipes,
commonSessions ≥ 1, plafond 50 blocs, défaut liste vide), et un bloc ABSENT/VIDE laisse le
chemin de code byte-identique (patron `teamLinks`).

⚠ PR-3 : le bloc est désormais CONSOMMÉ par le solveur (il place ses séances comme une équipe).
Ce fichier garde l'ACCEPTATION du schéma, ses REJETS de forme, et le fait qu'un bloc VIDE laisse
le solve byte-identique (le seul garde d'inertie encore vrai) — la SÉMANTIQUE (co-placement,
double-comptage, verdict de déplacement-en-bloc) vit dans `tests/semantic/test_shared_block_semantics.py`."""

from __future__ import annotations

from typing import Any

import pytest
from pydantic import ValidationError

from app.schemas.input_schema import MAX_SHARED_TRAINING_BLOCKS, ScheduleInputSchema
from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload


def _block(block_id: str, team_ids: list[str], common_sessions: int = 1) -> dict[str, Any]:
    return {"id": block_id, "teamIds": team_ids, "commonSessions": common_sessions}


def _fixture() -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    teams = [make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)]
    venues = [make_venue("vA", [(1, "18:00"), (2, "18:00")], capacity=2)]
    return teams, venues


class TestSchemaAccepts:
    def test_a_payload_with_shared_blocks_validates(self) -> None:
        teams, venues = _fixture()
        payload = make_payload(teams=teams, venues=venues)
        payload["sharedBlocks"] = [_block("b1", ["t1", "t2"], 1)]
        parsed = ScheduleInputSchema.model_validate(payload)
        assert len(parsed.shared_blocks) == 1
        assert parsed.shared_blocks[0].team_ids == ["t1", "t2"]
        assert parsed.shared_blocks[0].common_sessions == 1

    def test_shared_blocks_defaults_to_empty_list_when_absent(self) -> None:
        teams, venues = _fixture()
        parsed = ScheduleInputSchema.model_validate(make_payload(teams=teams, venues=venues))
        assert parsed.shared_blocks == []

    def test_ten_teams_is_accepted(self) -> None:
        parsed = ScheduleInputSchema.model_validate(
            make_payload(teams=_fixture()[0], venues=_fixture()[1])
            | {"sharedBlocks": [_block("b", [f"t{i}" for i in range(10)], 2)]}
        )
        assert len(parsed.shared_blocks[0].team_ids) == 10

    def test_fifty_blocks_is_accepted(self) -> None:
        blocks = [_block(f"b{i}", ["t1", "t2"], 1) for i in range(MAX_SHARED_TRAINING_BLOCKS)]
        parsed = ScheduleInputSchema.model_validate(
            make_payload(teams=_fixture()[0], venues=_fixture()[1]) | {"sharedBlocks": blocks}
        )
        assert len(parsed.shared_blocks) == MAX_SHARED_TRAINING_BLOCKS


class TestSchemaRejects:
    def test_one_team_is_rejected(self) -> None:
        with pytest.raises(ValidationError):
            ScheduleInputSchema.model_validate(
                make_payload(teams=_fixture()[0], venues=_fixture()[1]) | {"sharedBlocks": [_block("b", ["t1"], 1)]}
            )

    def test_eleven_teams_is_rejected(self) -> None:
        with pytest.raises(ValidationError):
            ScheduleInputSchema.model_validate(
                make_payload(teams=_fixture()[0], venues=_fixture()[1])
                | {"sharedBlocks": [_block("b", [f"t{i}" for i in range(11)], 1)]}
            )

    def test_common_sessions_zero_is_rejected(self) -> None:
        with pytest.raises(ValidationError):
            ScheduleInputSchema.model_validate(
                make_payload(teams=_fixture()[0], venues=_fixture()[1])
                | {"sharedBlocks": [_block("b", ["t1", "t2"], 0)]}
            )

    def test_over_fifty_blocks_is_rejected(self) -> None:
        blocks = [_block(f"b{i}", ["t1", "t2"], 1) for i in range(MAX_SHARED_TRAINING_BLOCKS + 1)]
        with pytest.raises(ValidationError):
            ScheduleInputSchema.model_validate(
                make_payload(teams=_fixture()[0], venues=_fixture()[1]) | {"sharedBlocks": blocks}
            )


class TestEmptyEqualsAbsent:
    def test_empty_shared_blocks_block_matches_no_block(self) -> None:
        # Le SEUL garde d'inertie encore vrai en PR-3 : un bloc VIDE laisse le solve byte-identique
        # (patron `teamLinks`). Un bloc PEUPLÉ, lui, est désormais consommé — sa
        # sémantique est prouvée dans tests/semantic/test_shared_block_semantics.py.
        teams, venues = _fixture()
        without = solve_payload(make_payload(teams=teams, venues=venues))
        with_empty_payload = make_payload(teams=teams, venues=venues)
        with_empty_payload["sharedBlocks"] = []
        with_empty = solve_payload(with_empty_payload)
        assert without["slots"] == with_empty["slots"]
        assert without["score"] == with_empty["score"]


def _hard_lock(team_id: str, venue_id: str, day: int, start: str) -> dict[str, Any]:
    return {
        "id": f"lock-{team_id}",
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": 90,
        "lockLevel": "HARD",
    }


def _cases(output: dict[str, Any], team_id: str) -> set[tuple[str, int, str]]:
    return {
        (str(s["venueId"]), int(s["dayOfWeek"]), str(s["startTime"])[:5])
        for s in output["slots"]
        if str(s["teamId"]) == team_id
    }


class TestPinnedPartnerFreesTheCaseForBlockMembers:
    """P2-51 (comblement) — le relâchement capacité est BLOC-AWARE et scopé aux blocs seuls.

    Le comportement fin (co-présence, refus à un autre début, « à eux seuls ») vit dans
    tests/semantic/test_fill_pinned_block_partner.py ; ici on garde le HEADLINE et son INERTIE :
    un pin sans bloc partagé continue de fermer le candidat libre chevauchant."""

    def test_a_block_whose_partner_is_hard_pinned_still_solves(self) -> None:
        teams = [make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)]
        venues = [make_venue("V", [(1, "19:30")], capacity=1)]
        payload = make_payload(teams=teams, venues=venues, slot_templates=[_hard_lock("t2", "V", 1, "19:30")])
        payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]
        result = solve_payload(payload)
        assert result["status"] == "completed"
        assert _cases(result, "t1") == {("V", 1, "19:30")} == _cases(result, "t2")

    def test_a_pin_without_a_shared_block_still_drops_the_overlapping_free_candidate(self) -> None:
        # INERTIE : sans bloc partagé, le pin de t2 rend l'unique case indisponible à t1 (P4-97 bis).
        # Le relâchement ne doit bénéficier QU'aux partenaires de bloc.
        teams = [make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)]
        venues = [make_venue("V", [(1, "19:30")], capacity=1)]
        payload = make_payload(teams=teams, venues=venues, slot_templates=[_hard_lock("t2", "V", 1, "19:30")])
        result = solve_payload(payload)
        assert result["status"] == "completed"
        assert _cases(result, "t1") == set(), "sans bloc, le candidat libre chevauchant reste fermé"
