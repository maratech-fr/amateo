"""NR-D — le moteur honore l'INTERRUPTEUR du lien coach-joueur (§7.1 constraint semantics).

Le backend émet une ligne ``COACH_PLAYER_UNAVAILABILITY`` avec ``severity: HARD`` quand le lien
est ACTIF, ``SOFT`` quand il est désactivé (``getIsActive()`` →
``ScheduleConstraintBuilder::serializeCoachPlayerMembershipConstraints``), et redouble le signal
dans ``metadata.isActive``. Le parseur ``parse_v2_constraints`` versait TOUTE ligne dans
``team_player_map`` sans regarder ce cran : le bouton existait, le moteur l'ignorait.

Un lien INACTIF ne doit plus entrer dans le web dur. Comme le MÊME parseur sert la génération ET
le verdict, la parité est automatique — on prouve les deux rails ici. Un lien ACTIF reste
byte-identique (les goldens portant des liens actifs ne bougent pas).
"""

from __future__ import annotations

from typing import Any

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.validate_assignments import validate_assignment
from tests.support import make_payload, make_team, make_venue, solve_payload, team_coach


def _coach(coach_id: str) -> dict[str, Any]:
    return {"id": coach_id, "firstName": coach_id, "lastName": "X", "isActive": True, "isEmployee": False}


def _forced_venue(cid: str, team_id: str, venue_id: str) -> dict[str, Any]:
    return {
        "id": cid,
        "scope": "TEAM",
        "scopeTargetId": team_id,
        "family": "FACILITY",
        "ruleType": "HARD",
        "name": "gymnase imposé",
        "config": {"forcedVenueId": venue_id},
        "sortOrder": 0,
        "isActive": True,
    }


def _coach_player(team_id: str, coach_id: str, *, active: bool) -> dict[str, Any]:
    """Miroir EXACT de ``serializeCoachPlayerMembershipConstraints`` : ``severity`` HARD/SOFT ET
    ``metadata.isActive`` portent tous deux l'interrupteur."""
    return {
        "id": f"coach-player-unavailability:{team_id}-{coach_id}",
        "teamId": team_id,
        "type": "COACH_PLAYER_UNAVAILABILITY",
        "severity": "HARD" if active else "SOFT",
        "value": coach_id,
        "metadata": {"coachId": coach_id, "teamId": team_id, "position": "PIVOT", "isActive": active},
    }


def _venues() -> list[dict[str, Any]]:
    # X et Y, chacun UNE case lun 18:00 (cap 1) : A forcée en X, B forcée en Y → si le coach joue B,
    # il coache A et joue B en même temps dans deux gymnases (chevauchement dur cross-gymnase).
    return [
        make_venue("X", [(1, "18:00")], capacity=1, duration_minutes=90),
        make_venue("Y", [(1, "18:00")], capacity=1, duration_minutes=90),
    ]


def _generation_payload(*, active: bool) -> dict[str, Any]:
    return make_payload(
        teams=[make_team("A"), make_team("B")],
        venues=_venues(),
        coaches=[_coach("c1")],
        constraints=[
            team_coach("tcA", "A", "c1"),  # c1 coache A (ressource dure)
            _coach_player("B", "c1", active=active),  # c1 joue dans B (interrupteur)
            _forced_venue("fA", "A", "X"),
            _forced_venue("fB", "B", "Y"),
        ],
        timeout=10,
    )


def test_generation_inactive_link_allows_the_overlap() -> None:
    """Lien INACTIF : c1 n'est PAS joueur de B → aucun chevauchement dur → les DEUX équipes sont
    placées sur leurs cases superposées, ``completed``. Rougit avant le fix (la ligne SOFT entrait
    quand même dans ``team_player_map`` → B non plaçable)."""
    result = solve_payload(_generation_payload(active=False))
    assert result["status"] == "completed"
    placed = {s["teamId"] for s in result["slots"]}
    assert {"A", "B"} <= placed, f"lien inactif : les deux équipes doivent tenir, or placed={placed}"


def test_generation_active_link_forbids_the_overlap() -> None:
    """Lien ACTIF : c1 coache A et joue B → le chevauchement cross-gymnase est INTERDIT ; les cases
    étant les seules disponibles, B ne peut pas être placée. La contrainte est honorée (byte-identique
    au comportement historique)."""
    result = solve_payload(_generation_payload(active=True))
    placed = {s["teamId"] for s in result["slots"]}
    assert not ({"A", "B"} <= placed), "lien actif : le chevauchement coach-joueur doit rester interdit"


def _verdict_payload(*, active: bool) -> dict[str, Any]:
    payload = make_payload(
        teams=[make_team("A"), make_team("B")],
        venues=_venues(),
        coaches=[_coach("c1")],
        constraints=[team_coach("tcA", "A", "c1"), _coach_player("B", "c1", active=active)],
        timeout=10,
    )
    payload["slotTemplates"] = [
        {"id": "sA", "teamId": "A", "venueId": "X", "dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 90}
    ]
    payload["candidates"] = [
        {"teamId": "B", "venueId": "Y", "dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 90}
    ]
    payload["references"] = [
        {"teamId": "B", "venueId": "Y", "dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 90}
    ]
    return payload


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    return validate_assignment(ValidateAssignmentsInputSchema.model_validate(payload), contract_version="2.19")


def test_verdict_inactive_link_accepts_the_overlapping_move() -> None:
    """Verdict, lien INACTIF : déplacer B sur la case superposée à A est ACCEPTÉ (c1 n'est pas joueur
    de B). Rougit avant le fix (valid:false coach_player_no_overlap)."""
    result = _run(_verdict_payload(active=False))
    assert result["valid"] is True
    assert not any(v["rule"] == "coach_player_no_overlap" for v in result["violations"])


def test_verdict_active_link_refuses_the_overlapping_move() -> None:
    """Verdict, lien ACTIF : même déplacement REFUSÉ, ``coach_player_no_overlap`` (parité avec la
    génération, même parseur)."""
    result = _run(_verdict_payload(active=True))
    assert result["valid"] is False
    assert any(v["rule"] == "coach_player_no_overlap" for v in result["violations"])
