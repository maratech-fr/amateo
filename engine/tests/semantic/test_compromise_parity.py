"""P2-32 — PARITÉ génération ⇄ évaluation des compromis (axe *constraint semantics*).

Le confort que la GÉNÉRATION honore est EXACTEMENT celui que le verdict d'un déplacement
NOMME quand on le casse. Sans cette parité, le moteur optimiserait une définition du confort
et en nommerait une autre — un gestionnaire verrait « rien de cassé » sur un geste qui casse
pourtant ce que la génération avait soigné.

Falsifié dans les DEUX sens : avec la préférence, la génération pose la séance dans le gymnase
préféré ET le déplacement hors de ce gymnase est nommé ``broken`` ; sans la préférence, la
génération est libre ET le même déplacement ne nomme AUCUN compromis de gymnase.
"""

from __future__ import annotations

from typing import Any

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.validate_assignments import validate_assignment
from tests.support.pipeline import (
    as_validate_payload,
    make_payload,
    make_team,
    make_venue,
    read_contract_version,
    solve_payload,
)


def _preferred_venue_constraint(team_id: str, venue_id: str) -> dict[str, Any]:
    return {
        "id": f"pref-{team_id}",
        "scope": "TEAM",
        "scopeTargetId": team_id,
        "family": "FACILITY",
        "ruleType": "PREFERRED",
        "name": "gymnase préféré",
        "config": {"preferredVenueId": venue_id},
        "sortOrder": 0,
        "isActive": True,
    }


def _generate(*, with_preference: bool) -> dict[str, Any]:
    payload = make_payload(
        teams=[make_team("U13", sessions_per_week=1)],
        venues=[make_venue("A", [(4, "20:00")]), make_venue("B", [(4, "20:00")])],
        constraints=[_preferred_venue_constraint("U13", "A")] if with_preference else [],
        timeout=10,
    )
    result = solve_payload(payload)
    assert result["status"] == "completed"
    return result


def _validate_move_out_of_a(*, with_preference: bool) -> dict[str, Any]:
    """Déplace U13 du gymnase A vers B (reference = A), au réglage donné."""
    payload: dict[str, Any] = {
        "version": read_contract_version(),
        "clubId": "test-club",
        "seasonId": "test-season",
        "solverTimeoutSeconds": 2,
        "venues": [make_venue("A", [(4, "20:00")]), make_venue("B", [(4, "20:00")])],
        "teams": [make_team("U13", sessions_per_week=1)],
        "coaches": [],
        "constraints": [_preferred_venue_constraint("U13", "A")] if with_preference else [],
        "slotTemplates": [],
        "candidate": {"teamId": "U13", "venueId": "B", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        "reference": {"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
    }
    return validate_assignment(ValidateAssignmentsInputSchema.model_validate(as_validate_payload(payload)))


def test_generation_honors_the_preference_and_the_move_out_names_it() -> None:
    generated = _generate(with_preference=True)
    placed = [s for s in generated["slots"] if s["teamId"] == "U13"]
    assert placed, "la génération doit placer U13"
    # La génération a HONORÉ la préférence : U13 est au gymnase A.
    assert placed[0]["venueId"] == "A", "la génération place la séance dans le gymnase préféré"

    verdict = _validate_move_out_of_a(with_preference=True)
    assert verdict["valid"] is True
    families = {c["family"]: c for c in verdict["compromises"]}
    assert "venue_preference" in families, "déplacer HORS du gymnase préféré doit être nommé"
    assert families["venue_preference"]["effect"] == "broken"


def test_without_the_preference_the_same_move_names_nothing() -> None:
    verdict = _validate_move_out_of_a(with_preference=False)
    assert verdict["valid"] is True
    families = {c["family"] for c in verdict["compromises"]}
    assert "venue_preference" not in families, "sans préférence, aucun compromis de gymnase"


# --- PR 1a — l'écart de 2 h : la génération l'évite, le geste qui le casse le nomme -------------
# Exception écrite (plan 1a.2) : le geste ne reçoit pas les références de saison, il nomme donc
# aussi l'écart d'une paire de saison que la génération ne facture pas.

_GAP_VENUES = [make_venue("A", [(1, "09:00"), (1, "17:00"), (1, "18:30")])]


def _gap_team() -> dict[str, Any]:
    return {**make_team("U13", sessions_per_week=2), "maxSessionsPerDay": 2}


def test_generation_avoids_glued_sessions_and_the_gluing_move_names_it() -> None:
    """Parité : la génération espace les deux séances du lundi, le geste qui les colle nomme l'écart."""
    generated = solve_payload(make_payload(teams=[_gap_team()], venues=_GAP_VENUES, timeout=10))
    starts = sorted(str(s["startTime"])[:5] for s in generated["slots"] if s["teamId"] == "U13")
    assert len(starts) == 2 and "09:00" in starts, starts

    payload: dict[str, Any] = {
        "version": read_contract_version(),
        "clubId": "test-club",
        "seasonId": "test-season",
        "solverTimeoutSeconds": 2,
        "venues": _GAP_VENUES,
        "teams": [_gap_team()],
        "coaches": [],
        "constraints": [],
        "slotTemplates": [
            {"id": "s", "teamId": "U13", "venueId": "A", "dayOfWeek": 1, "startTime": "17:00", "durationMinutes": 90}
        ],
        "candidate": {"teamId": "U13", "venueId": "A", "dayOfWeek": 1, "startTime": "18:30", "durationMinutes": 90},
        "reference": {"teamId": "U13", "venueId": "A", "dayOfWeek": 1, "startTime": "09:00", "durationMinutes": 90},
    }
    verdict = validate_assignment(ValidateAssignmentsInputSchema.model_validate(as_validate_payload(payload)))
    assert verdict["valid"] is True
    named = {(c["family"], c["effect"]) for c in verdict["compromises"]}
    assert ("same_day", "broken") in named, verdict["compromises"]
