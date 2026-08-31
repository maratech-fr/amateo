"""P2-32 PR A — les COMPROMIS nommés d'un candidat ACCEPTÉ.

Une falsification PAR FAMILLE (liste fermée) : chaque scénario prouve que le DELTA « avant » →
« après » repère le confort cassé (``broken``) / gagné (``gained``) et le NOMME. Le cas piège
``chaining`` prouve que sans l'objectif (``Maximize``) le littéral ``chained`` mentirait : il
n'est poussé à vrai QUE par l'objectif (cf. objective.py:921-923), donc un DELTA calculé sans
objectif ne verrait JAMAIS un enchaînement cassé — ce test échouerait sur une telle évaluation.
"""

from __future__ import annotations

from typing import Any

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.validate_assignments import validate_assignment
from tests.support.pipeline import as_validate_payload, make_team, make_venue, team_coach


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    return validate_assignment(ValidateAssignmentsInputSchema.model_validate(as_validate_payload(payload)))


def _families(result: dict[str, Any]) -> dict[str, dict[str, Any]]:
    return {c["family"]: c for c in result["compromises"]}


def _facility_pref(team_id: str, venue_id: str) -> dict[str, Any]:
    return {
        "id": f"pref-{team_id}-{venue_id}",
        "scope": "TEAM",
        "scopeTargetId": team_id,
        "family": "FACILITY",
        "ruleType": "PREFERRED",
        "name": "gymnase préféré",
        "config": {"preferredVenueId": venue_id},
        "sortOrder": 0,
        "isActive": True,
    }


def _day_pref(team_id: str, days: list[int]) -> dict[str, Any]:
    return {
        "id": f"day-{team_id}",
        "scope": "TEAM",
        "scopeTargetId": team_id,
        "family": "DAY",
        "ruleType": "PREFERRED",
        "name": "jours préférés",
        "config": {"preferredDays": days},
        "sortOrder": 0,
        "isActive": True,
    }


def _time_pref(team_id: str, min_start: str) -> dict[str, Any]:
    return {
        "id": f"time-{team_id}",
        "scope": "TEAM",
        "scopeTargetId": team_id,
        "family": "TIME",
        "ruleType": "PREFERRED",
        "name": "horaire préféré",
        "config": {"minStartTime": min_start},
        "sortOrder": 0,
        "isActive": True,
    }


def test_venue_preference_broken_when_moving_out_of_preferred_venue() -> None:
    result = _run(
        {
            "clubId": "c",
            "seasonId": "s",
            "venues": [make_venue("A", [(4, "20:00")]), make_venue("B", [(4, "20:00")])],
            "teams": [make_team("U13")],
            "constraints": [_facility_pref("U13", "A")],
            "slotTemplates": [],
            "candidate": {"teamId": "U13", "venueId": "B", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
            "reference": {"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        }
    )
    assert result["valid"] is True
    fam = _families(result)
    assert fam["venue_preference"]["effect"] == "broken"
    assert fam["venue_preference"]["teamId"] == "U13"
    assert fam["venue_preference"]["venueId"] == "A"


def test_venue_preference_gained_when_moving_into_preferred_venue() -> None:
    result = _run(
        {
            "clubId": "c",
            "seasonId": "s",
            "venues": [make_venue("A", [(4, "20:00")]), make_venue("B", [(4, "20:00")])],
            "teams": [make_team("U13")],
            "constraints": [_facility_pref("U13", "A")],
            "slotTemplates": [],
            "candidate": {"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
            "reference": {"teamId": "U13", "venueId": "B", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        }
    )
    assert result["valid"] is True
    assert _families(result)["venue_preference"]["effect"] == "gained"


def test_day_preference_broken_when_moving_off_a_preferred_day() -> None:
    result = _run(
        {
            "clubId": "c",
            "seasonId": "s",
            "venues": [make_venue("A", [(4, "20:00"), (2, "20:00")])],
            "teams": [make_team("U13")],
            "constraints": [_day_pref("U13", [4])],
            "slotTemplates": [],
            "candidate": {"teamId": "U13", "venueId": "A", "dayOfWeek": 2, "startTime": "20:00", "durationMinutes": 90},
            "reference": {"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        }
    )
    assert result["valid"] is True
    assert _families(result)["day_preference"]["effect"] == "broken"


def test_time_preference_broken_when_moving_before_the_preferred_window() -> None:
    result = _run(
        {
            "clubId": "c",
            "seasonId": "s",
            "venues": [make_venue("A", [(4, "20:00"), (4, "18:00")])],
            "teams": [make_team("U13")],
            "constraints": [_time_pref("U13", "20:00")],
            "slotTemplates": [],
            "candidate": {"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "18:00", "durationMinutes": 90},
            "reference": {"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "20:00", "durationMinutes": 90},
        }
    )
    assert result["valid"] is True
    assert _families(result)["time_preference"]["effect"] == "broken"


def test_match_rest_broken_when_moving_onto_the_rest_day() -> None:
    # matchDay=7 (dimanche) → jour de repos = lundi (1). Déplacer U13 sur lundi casse le repos.
    result = _run(
        {
            "clubId": "c",
            "seasonId": "s",
            "venues": [make_venue("A", [(2, "20:00"), (1, "20:00")])],
            "teams": [make_team("U13", match_day=7)],
            "constraints": [],
            "slotTemplates": [],
            "candidate": {"teamId": "U13", "venueId": "A", "dayOfWeek": 1, "startTime": "20:00", "durationMinutes": 90},
            "reference": {"teamId": "U13", "venueId": "A", "dayOfWeek": 2, "startTime": "20:00", "durationMinutes": 90},
        }
    )
    assert result["valid"] is True
    assert _families(result)["match_rest"]["effect"] == "broken"


def test_spacing_broken_when_a_move_creates_two_consecutive_days() -> None:
    # Baseline U13 mardi (2). Source jeudi... vendredi (5). Déplacer vers mercredi (3) crée 2/3.
    result = _run(
        {
            "clubId": "c",
            "seasonId": "s",
            "venues": [make_venue("A", [(2, "20:00"), (3, "20:00"), (5, "20:00")])],
            "teams": [make_team("U13", sessions_per_week=2)],
            "constraints": [],
            "slotTemplates": [
                {
                    "id": "s2",
                    "teamId": "U13",
                    "venueId": "A",
                    "dayOfWeek": 2,
                    "startTime": "20:00",
                    "durationMinutes": 90,
                },
            ],
            "candidate": {"teamId": "U13", "venueId": "A", "dayOfWeek": 3, "startTime": "20:00", "durationMinutes": 90},
            "reference": {"teamId": "U13", "venueId": "A", "dayOfWeek": 5, "startTime": "20:00", "durationMinutes": 90},
        }
    )
    assert result["valid"] is True
    assert _families(result)["spacing"]["effect"] == "broken"


def test_coach_day_cap_broken_when_placing_a_session_over_the_cap() -> None:
    # Coach C plafonné à 1 jour, encadre U13. Baseline: U13 mardi (1 jour = plafond). PLACER une
    # séance mercredi (aucune référence) → 2 jours travaillés > plafond.
    result = _run(
        {
            "clubId": "c",
            "seasonId": "s",
            "venues": [make_venue("A", [(2, "20:00"), (3, "20:00")])],
            "teams": [make_team("U13", sessions_per_week=2)],
            "coaches": [{"id": "C", "firstName": "Jean", "lastName": "Dupont", "isActive": True, "maxDaysOverride": 1}],
            "constraints": [team_coach("tc13", "U13", "C")],
            "slotTemplates": [
                {
                    "id": "s2",
                    "teamId": "U13",
                    "venueId": "A",
                    "dayOfWeek": 2,
                    "startTime": "20:00",
                    "durationMinutes": 90,
                },
            ],
            "candidate": {"teamId": "U13", "venueId": "A", "dayOfWeek": 3, "startTime": "20:00", "durationMinutes": 90},
        }
    )
    assert result["valid"] is True
    fam = _families(result)
    assert fam["coach_day_cap"]["effect"] == "broken"
    assert fam["coach_day_cap"]["coachId"] == "C"


def test_chaining_broken_requires_the_objective() -> None:
    """Le cas PIÈGE : sans Maximize, ``chained`` resterait à 0 et l'enchaînement cassé
    passerait inaperçu. Ce test l'exige NOMMÉ — il échoue sur une évaluation sans objectif."""
    result = _run(
        {
            "clubId": "c",
            "seasonId": "s",
            "venues": [make_venue("A", [(4, "18:00"), (4, "19:30")]), make_venue("B", [(4, "19:30")])],
            "teams": [make_team("U13"), make_team("U15")],
            "coaches": [{"id": "C", "firstName": "Jean", "lastName": "Dupont", "isActive": True}],
            "constraints": [team_coach("tc13", "U13", "C"), team_coach("tc15", "U15", "C")],
            "slotTemplates": [
                {
                    "id": "s13",
                    "teamId": "U13",
                    "venueId": "A",
                    "dayOfWeek": 4,
                    "startTime": "18:00",
                    "durationMinutes": 90,
                },
            ],
            "candidate": {"teamId": "U15", "venueId": "B", "dayOfWeek": 4, "startTime": "19:30", "durationMinutes": 90},
            "reference": {"teamId": "U15", "venueId": "A", "dayOfWeek": 4, "startTime": "19:30", "durationMinutes": 90},
        }
    )
    assert result["valid"] is True
    fam = _families(result)
    assert fam["chaining"]["effect"] == "broken"
    assert fam["chaining"]["venueId"] == "A"


def test_implicit_rule_broken_when_a_move_breaks_the_age_order() -> None:
    # ageAscending PREFERRED : une équipe plus jeune ne doit pas passer après une plus âgée au
    # même gymnase/jour. Déplacer U13 (13 ans) APRÈS U15 (15 ans) au gymnase A casse la règle.
    result = _run(
        {
            "clubId": "c",
            "seasonId": "s",
            "venues": [make_venue("A", [(4, "18:00"), (4, "19:30")]), make_venue("B", [(4, "19:30")])],
            "teams": [
                {**make_team("U13"), "ageMin": 13},
                {**make_team("U15"), "ageMin": 15},
            ],
            "constraints": [],
            "implicitRules": {"ageAscending": {"intensity": "PREFERRED"}},
            "slotTemplates": [
                {
                    "id": "s15",
                    "teamId": "U15",
                    "venueId": "A",
                    "dayOfWeek": 4,
                    "startTime": "18:00",
                    "durationMinutes": 90,
                },
            ],
            "candidate": {"teamId": "U13", "venueId": "A", "dayOfWeek": 4, "startTime": "19:30", "durationMinutes": 90},
            "reference": {"teamId": "U13", "venueId": "B", "dayOfWeek": 4, "startTime": "19:30", "durationMinutes": 90},
        }
    )
    assert result["valid"] is True
    fam = _families(result)
    assert fam["implicit_rule"]["effect"] == "broken"
    assert fam["implicit_rule"]["venueId"] == "A"
