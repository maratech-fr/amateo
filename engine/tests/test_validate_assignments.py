"""P2-2 F2a — verdict moteur sur un candidat de deplacement (mono-candidat).

Le SOLVE (baseline figee via add_fixed_slots + candidat epingle) rend le verdict
booleen ; le socle ici garde la faisabilite d'un deplacement legitime, le
determinisme (mono-candidat, 1 worker) et le cas « creneau cible impossible ».
"""

from __future__ import annotations

from typing import Any

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.validate_assignments import validate_assignment
from tests.support.pipeline import as_validate_payload, make_team, make_venue, team_coach


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    return validate_assignment(ValidateAssignmentsInputSchema.model_validate(as_validate_payload(payload)))


def _base_payload(*, candidate: dict[str, Any], slot_templates: list[dict[str, Any]], **over: Any) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "clubId": "club",
        "seasonId": "season",
        "venues": over.get(
            "venues",
            [make_venue("A", [(4, "18:00"), (4, "20:00")]), make_venue("B", [(4, "20:00")])],
        ),
        "teams": over.get("teams", [make_team("U13"), make_team("U15")]),
        "coaches": over.get("coaches", []),
        "constraints": over.get("constraints", []),
        "slotTemplates": slot_templates,
        "candidate": candidate,
    }
    if "reference" in over:
        payload["reference"] = over["reference"]
    return payload


def test_empty_free_slot_is_valid() -> None:
    """U13 vers un creneau vide sans aucun conflit -> valide, zero violation."""
    result = _run(
        _base_payload(
            slot_templates=[],
            candidate={"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        )
    )
    assert result["valid"] is True
    assert result["violations"] == []


def test_verdict_is_deterministic() -> None:
    """Meme entree, meme verdict d'un appel a l'autre (pas de portefeuille)."""
    payload = _base_payload(
        slot_templates=[
            {"id": "s1", "teamId": "U15", "venueId": "B", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        ],
        candidate={"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        constraints=[team_coach("tc13", "U13", "C"), team_coach("tc15", "U15", "C")],
    )
    first = _run(payload)
    second = _run(payload)
    assert first["valid"] == second["valid"]
    assert first["violations"] == second["violations"]


def test_target_slot_that_does_not_exist_is_named() -> None:
    """Un creneau cible inexistant (aucune variable) -> non, motif slot_unavailable."""
    result = _run(
        _base_payload(
            slot_templates=[],
            candidate={"teamId": "U13", "venueId": "A", "dayOfWeek": 3, "startTime": "20:00", "durationMinutes": 90},
        )
    )
    assert result["valid"] is False
    assert [v["rule"] for v in result["violations"]] == ["slot_unavailable"]


def test_full_capacity_slot_is_refused_and_named() -> None:
    """Creneau capacite 1 deja occupe par une autre equipe -> venue_capacity."""
    result = _run(
        _base_payload(
            venues=[make_venue("A", [(4, "20:00")], capacity=1)],
            slot_templates=[
                {
                    "id": "s1",
                    "teamId": "U15",
                    "venueId": "A",
                    "dayOfWeek": 4,
                    "startTime": "20:00",
                    "durationMinutes": 90,
                },
            ],
            candidate={"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        )
    )
    assert result["valid"] is False
    assert "venue_capacity" in {v["rule"] for v in result["violations"]}


# --- P2-32 — compromis nommés (le DELTA de confort d'un candidat ACCEPTÉ) --------------------


def _pref_venue(team_id: str, venue_id: str) -> dict[str, Any]:
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


def _move_out_of_preferred() -> dict[str, Any]:
    """U13 préfère A ; on la déplace de A vers B (reference = A) — un compromis ``broken``."""
    return _base_payload(
        venues=[make_venue("A", [(4, "20:00")]), make_venue("B", [(4, "20:00")])],
        teams=[make_team("U13")],
        constraints=[_pref_venue("U13", "A")],
        slot_templates=[],
        candidate={"teamId": "U13", "venueId": "B", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        reference={"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
    )


def test_accepted_candidate_carries_named_compromises() -> None:
    result = _run(_move_out_of_preferred())
    assert result["valid"] is True
    assert result["compromises"], "un candidat accepté qui casse une préférence doit la nommer"
    first = result["compromises"][0]
    assert first["family"] == "venue_preference"
    assert first["effect"] == "broken"
    assert first["message"]  # phrase prête à afficher


def test_refused_candidate_has_no_compromises() -> None:
    """Chemin REFUS byte-identique : aucune compromis calculée sur un « non »."""
    result = _run(
        _base_payload(
            venues=[make_venue("A", [(4, "20:00")], capacity=1)],
            slot_templates=[
                {
                    "id": "s1",
                    "teamId": "U15",
                    "venueId": "A",
                    "dayOfWeek": 4,
                    "startTime": "20:00",
                    "durationMinutes": 90,
                },
            ],
            candidate={"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        )
    )
    assert result["valid"] is False
    assert result["compromises"] == []


def test_compromises_are_deterministic() -> None:
    payload = _move_out_of_preferred()
    first = _run(payload)
    second = _run(payload)
    assert first["compromises"] == second["compromises"]


def test_without_reference_the_delta_is_against_the_bare_baseline() -> None:
    """Une CRÉATION (pas de reference) : « avant » = baseline nue. Placer U13 dans son gymnase
    préféré A alors qu'elle n'y était pas → un compromis ``gained``."""
    result = _run(
        _base_payload(
            venues=[make_venue("A", [(4, "20:00")])],
            teams=[make_team("U13")],
            constraints=[_pref_venue("U13", "A")],
            slot_templates=[],
            candidate={"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        )
    )
    assert result["valid"] is True
    families = {c["family"]: c for c in result["compromises"]}
    assert families["venue_preference"]["effect"] == "gained"
