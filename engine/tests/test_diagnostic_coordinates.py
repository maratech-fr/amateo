"""P4-95 lot 8 — les diagnostics à coordonnées PARTIELLES portent désormais l'instant cliquable.

Trois familles avaient leurs coordonnées enfermées dans la seule phrase française : le clic
« corriger » du panneau ne ciblait rien. On assertie ici sur les CHAMPS structurés (jamais une
regex sur ``message``), et chaque test nomme sa falsification.

  * ``diag-locked-person-*`` — une personne dans deux gymnases : ``startTime`` = début du
    CHEVAUCHEMENT (``max(a_start, b_start)``), pas le début de la 1ʳᵉ séance ;
  * ``diag-locked-team-day-*`` — deux séances le même jour : DÉCISION de NE PAS porter d'heure
    (« toutes les séances de cette équipe ce jour »), épinglée par l'absence du champ ;
  * ``diag-implicit-age-*`` — inversion d'âge : gymnase + jour + ruleKey structurés.
"""

from typing import Any

from app.solver.constraints import ResolvedImplicitRules
from app.solver.result_builder import (
    _diagnose_age_violations,
    _diagnose_locked_structural_conflicts,
)


def _slot(team: str, venue: str, start: str, *, day: int = 2, duration: int = 90, hard: bool = True) -> dict[str, Any]:
    return {
        "teamId": team,
        "venueId": venue,
        "startTime": start,
        "durationMinutes": duration,
        "dayOfWeek": day,
        "lockLevel": "HARD" if hard else "NONE",
    }


# --- 1. locked-person : startTime = début du CHEVAUCHEMENT -------------------------------------
def test_locked_person_emits_the_overlap_start_not_the_first_session_start() -> None:
    """Débuts DÉCALÉS, deux gymnases, une personne partagée : l'instant émis doit tomber dans les
    DEUX séances — c'est ``max(a_start, b_start)``, jamais ``a_start`` (qui n'appartient qu'à une).

    Falsification : émettre ``a_start`` (18:00) → l'assert ``"19:00"`` rougit ; côté front, une
    seule case matcherait et le panneau OUVRIRAIT, en violation de « deux gymnases : on surligne
    les deux et on n'ouvre rien »."""
    slots = [
        _slot("teamA", "gym-nord", "18:00"),  # 18:00-19:30
        _slot("teamB", "gym-sud", "19:00"),  # 19:00-20:30
    ]
    diagnostics = _diagnose_locked_structural_conflicts({}, slots, {"teamA": ["coach-x"], "teamB": ["coach-x"]}, {})
    person = [d for d in diagnostics if str(d.get("id", "")).startswith("diag-locked-person-")]

    assert len(person) == 1
    assert person[0]["startTime"] == "19:00"
    assert person[0]["dayOfWeek"] == 2
    # L'id garde le début de la 1ʳᵉ séance (stable) — il ne suit PAS le champ.
    assert person[0]["id"].endswith("-1080")


# --- 2. locked-team-day : startTime volontairement ABSENT --------------------------------------
def test_locked_team_day_carries_no_start_time_by_design() -> None:
    """Deux séances HARD, même équipe, même jour : le diagnostic désigne « toutes les séances de
    CETTE équipe CE jour » — porter une heure restreindrait à tort à une seule case.

    Falsification : émettre l'heure de la première séance → ``"startTime" not in`` rougit."""
    slots = [
        _slot("teamA", "gym-nord", "18:00"),
        _slot("teamA", "gym-nord", "20:00"),
    ]
    diagnostics = _diagnose_locked_structural_conflicts({}, slots, {}, {})
    team_day = [d for d in diagnostics if str(d.get("id", "")).startswith("diag-locked-team-day-")]

    assert len(team_day) == 1
    assert team_day[0]["teamId"] == "teamA"
    assert isinstance(team_day[0]["dayOfWeek"], int)
    assert "startTime" not in team_day[0]


# --- 3. implicit-age : gymnase + jour + ruleKey structurés ------------------------------------
def test_implicit_age_carries_venue_day_and_rule_key() -> None:
    """Inversion d'âge (plus jeunes APRÈS plus vieux, même gymnase même jour) : le diagnostic porte
    ``venueId`` + ``dayOfWeek`` entier + ``ruleKey == 'ageAscending'``.

    Falsification : ``dayOfWeek: None`` → l'assert ``isinstance(..., int)`` rougit."""
    model_data = {"teams": [{"id": "u11", "ageMin": 11}, {"id": "u15", "ageMin": 15}]}
    slots = [
        _slot("u15", "gym-a", "18:00", day=3, hard=False),  # plus vieux, plus tôt
        _slot("u11", "gym-a", "20:00", day=3, hard=False),  # plus jeune, plus tard = inversion
    ]
    diagnostics = _diagnose_age_violations(model_data, slots, ResolvedImplicitRules(), {}, {})
    age = [d for d in diagnostics if str(d.get("id", "")).startswith("diag-implicit-age-")]

    assert len(age) == 1
    assert age[0]["venueId"] == "gym-a"
    assert isinstance(age[0]["dayOfWeek"], int)
    assert age[0]["dayOfWeek"] == 3
    assert age[0]["ruleKey"] == "ageAscending"
