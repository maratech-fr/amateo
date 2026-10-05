"""NR — lot « règles implicites réglables » (§7.1 constraint semantics).

Les 4 règles implicites « bien-être » deviennent réglables par club : intensité HARD /
PREFERRED (2 crans), 2 seuils paramétrables, et une violation TOUJOURS diagnostiquée
post-solve quel que soit le cran. On vérifie ici, sur le VRAI pipeline (``solve_payload``),
par règle × cran :

- HARD honorée (seuils inclus) : le solveur ne place jamais en violation ;
- PREFERRED oriente sans bloquer + émet ``implicit_rule_not_honored`` si la règle n'est pas
  tenue ;
- parité pose ⇄ détection (S2) : un scénario violé est détecté ssi la pose HARD l'aurait
  interdit, au MÊME grain ;
- « PREFERRED ne supprime jamais une séance » : l'arbitre du poids −6 ;
- HARD contournée par un verrou → diagnostiquée (avec le ton « le solveur n'a pas pu »).
"""

from __future__ import annotations

from typing import Any

from tests.support import make_payload, make_venue, solve_payload, team_coach


def _team(team_id: str, *, sessions: int = 1, tier: int = 1, age_min: int | None = None) -> dict[str, Any]:
    team: dict[str, Any] = {
        "id": team_id,
        "sportCategoryId": "cat",
        "priorityTierId": tier,
        "name": team_id,
        "sessionsPerWeek": sessions,
    }
    if age_min is not None:
        team["ageMin"] = age_min
    return team


def _coach(coach_id: str, *, employee: bool = False) -> dict[str, Any]:
    return {
        "id": coach_id,
        "firstName": coach_id,
        "lastName": "X",
        "isEmployee": employee,
    }


def _allowed_day(constraint_id: str, team_id: str, day: int) -> dict[str, Any]:
    return {
        "id": constraint_id,
        "scope": "TEAM",
        "scopeTargetId": team_id,
        "family": "DAY",
        "ruleType": "HARD",
        "name": "jour imposé",
        "config": {"allowedDays": [day]},
        "sortOrder": 0,
        "isActive": True,
    }


def _min_start(constraint_id: str, team_id: str, hhmm: str) -> dict[str, Any]:
    return {
        "id": constraint_id,
        "scope": "TEAM",
        "scopeTargetId": team_id,
        "family": "TIME",
        "ruleType": "HARD",
        "name": "pas avant",
        "config": {"minStartTime": hhmm},
        "sortOrder": 0,
        "isActive": True,
    }


def _implicit_warnings(result: dict[str, Any], rule_key: str) -> list[dict[str, Any]]:
    return [
        d
        for d in result.get("diagnostics", [])
        if d.get("type") == "implicit_rule_not_honored" and d.get("ruleKey") == rule_key
    ]


# ===========================================================================
# 3b — coach rest day (intensité + seuil minRestDays)
# ===========================================================================


def _rest_day_payload(*, intensity: str | None, min_rest_days: int | None) -> dict[str, Any]:
    """1 coach encadre 5 équipes, chacune verrouillée sur son jour lun-ven : les placer
    toutes = coach présent les 5 jours."""
    constraints: list[dict[str, Any]] = []
    for d in range(1, 6):
        constraints.append(team_coach(f"tc-{d}", f"t-{d}", "c1"))
        constraints.append(_allowed_day(f"day-{d}", f"t-{d}", d))
    payload = make_payload(
        teams=[_team(f"t-{d}") for d in range(1, 6)],
        venues=[make_venue("v", [(d, "18:00") for d in range(1, 6)], duration_minutes=60)],
        coaches=[_coach("c1")],
        constraints=constraints,
        timeout=10,
    )
    block: dict[str, Any] = {}
    if intensity is not None:
        block["intensity"] = intensity
    if min_rest_days is not None:
        block["minRestDays"] = min_rest_days
    if block:
        payload["implicitRules"] = {"coachRestDay": block}
    return payload


def _coach_weekdays(result: dict[str, Any], coach_id: str) -> set[int]:
    return {
        int(s["dayOfWeek"])
        for s in result["slots"]
        if s.get("coachId") == coach_id and s.get("dayOfWeek") is not None and 1 <= int(s["dayOfWeek"]) <= 5
    }


def test_rest_day_hard_default_honored() -> None:
    result = solve_payload(_rest_day_payload(intensity=None, min_rest_days=None))
    assert result["status"] == "completed"
    assert len(_coach_weekdays(result, "c1")) <= 4


def test_rest_day_hard_min_rest_days_two_is_stricter() -> None:
    """Seuil : minRestDays=2 durcit — au plus 3 jours travaillés lun-ven."""
    result = solve_payload(_rest_day_payload(intensity="HARD", min_rest_days=2))
    assert result["status"] == "completed"
    assert len(_coach_weekdays(result, "c1")) <= 3


def test_rest_day_preferred_steers_and_warns() -> None:
    result = solve_payload(_rest_day_payload(intensity="PREFERRED", min_rest_days=None))
    assert result["status"] == "completed"
    assert _coach_weekdays(result, "c1") == {1, 2, 3, 4, 5}
    warnings = _implicit_warnings(result, "coachRestDay")
    assert any(w.get("coachId") == "c1" for w in warnings)
    assert all("assouplie par vous" in w["message"] for w in warnings)


# ===========================================================================
# 3d — max consecutive sessions (intensité + seuil maxConsecutive)
# ===========================================================================


def _chain_payload(*, intensity: str | None, max_consecutive: int | None, n_slots: int = 3) -> dict[str, Any]:
    """1 coach encadre ``n_slots`` équipes, toutes le LUNDI sur des créneaux dos-à-dos
    (17:00, 18:30, 20:00, …) : les placer toutes = chaîne de longueur ``n_slots``."""
    starts = ["17:00", "18:30", "20:00", "21:30"][:n_slots]
    constraints: list[dict[str, Any]] = []
    for i in range(n_slots):
        constraints.append(team_coach(f"tc-{i}", f"t-{i}", "c1"))
        constraints.append(_allowed_day(f"day-{i}", f"t-{i}", 1))
    payload = make_payload(
        teams=[_team(f"t-{i}") for i in range(n_slots)],
        venues=[make_venue("v", [(1, s) for s in starts], duration_minutes=90)],
        coaches=[_coach("c1")],
        constraints=constraints,
        timeout=10,
    )
    block: dict[str, Any] = {}
    if intensity is not None:
        block["intensity"] = intensity
    if max_consecutive is not None:
        block["maxConsecutive"] = max_consecutive
    if block:
        payload["implicitRules"] = {"maxConsecutiveSessions": block}
    return payload


def _coach_monday_count(result: dict[str, Any]) -> int:
    return sum(1 for s in result["slots"] if s.get("coachId") == "c1" and int(s["dayOfWeek"]) == 1)


def test_chain_hard_default_forbids_the_triple() -> None:
    """Défaut maxConsecutive=3 : jamais 3 dos-à-dos — au plus 2 des 3 pour le coach ce jour."""
    result = solve_payload(_chain_payload(intensity=None, max_consecutive=None, n_slots=3))
    assert result["status"] == "completed"
    assert _coach_monday_count(result) <= 2


def test_chain_hard_max_four_allows_the_triple() -> None:
    """Seuil maxConsecutive=4 : le triple redevient permis (seul le quadruple est interdit)."""
    result = solve_payload(_chain_payload(intensity="HARD", max_consecutive=4, n_slots=3))
    assert result["status"] == "completed"
    assert _coach_monday_count(result) == 3


def test_chain_hard_max_four_still_forbids_the_quadruple() -> None:
    result = solve_payload(_chain_payload(intensity="HARD", max_consecutive=4, n_slots=4))
    assert result["status"] == "completed"
    assert _coach_monday_count(result) <= 3


def test_chain_preferred_steers_and_warns() -> None:
    result = solve_payload(_chain_payload(intensity="PREFERRED", max_consecutive=None, n_slots=3))
    assert result["status"] == "completed"
    assert _coach_monday_count(result) == 3
    warnings = _implicit_warnings(result, "maxConsecutiveSessions")
    assert any(w.get("coachId") == "c1" for w in warnings)


def test_chain_parity_pose_vs_detection() -> None:
    """Parité S2 : maxConsecutive=4 HARD autorise le triple (pas de warning), et maxConsecutive=3
    PREFERRED sur le MÊME triple le détecte — détecté ssi la pose HARD l'aurait interdit."""
    permitted = solve_payload(_chain_payload(intensity="HARD", max_consecutive=4, n_slots=3))
    # HARD max=4 : le triple est placé et NON interdit → aucun warning de chaîne.
    assert _implicit_warnings(permitted, "maxConsecutiveSessions") == []

    detected = solve_payload(_chain_payload(intensity="PREFERRED", max_consecutive=3, n_slots=3))
    assert _coach_monday_count(detected) == 3
    assert _implicit_warnings(detected, "maxConsecutiveSessions")


# ===========================================================================
# 3c — salarié distribution (intensité)
# ===========================================================================


def _salarie_payload(*, intensity: str | None, friday_salarie: bool) -> dict[str, Any]:
    """2 salariés (règle active), 5 équipes lun-ven. Le vendredi est encadré par un salarié
    (``friday_salarie=True``) ou par un vacataire (violation potentielle)."""
    coaches = [_coach("emp-1", employee=True), _coach("emp-2", employee=True), _coach("vol-1")]
    constraints: list[dict[str, Any]] = []
    for d in range(1, 5):  # lun-jeu encadrés par emp-1
        constraints.append(team_coach(f"tc-{d}", f"t-{d}", "emp-1"))
        constraints.append(_allowed_day(f"day-{d}", f"t-{d}", d))
    friday_coach = "emp-2" if friday_salarie else "vol-1"
    constraints.append(team_coach("tc-5", "t-5", friday_coach))
    constraints.append(_allowed_day("day-5", "t-5", 5))
    payload = make_payload(
        teams=[_team(f"t-{d}") for d in range(1, 6)],
        venues=[make_venue("v", [(d, "18:00") for d in range(1, 6)], duration_minutes=60)],
        coaches=coaches,
        constraints=constraints,
        timeout=10,
    )
    if intensity is not None:
        payload["implicitRules"] = {"salarieDistribution": {"intensity": intensity}}
    return payload


def test_salarie_hard_honored_when_every_day_has_a_salarie() -> None:
    result = solve_payload(_salarie_payload(intensity="HARD", friday_salarie=True))
    assert result["status"] == "completed"
    assert _implicit_warnings(result, "salarieDistribution") == []


def test_salarie_preferred_steers_and_warns() -> None:
    result = solve_payload(_salarie_payload(intensity="PREFERRED", friday_salarie=False))
    assert result["status"] == "completed"
    warnings = _implicit_warnings(result, "salarieDistribution")
    assert warnings, "vendredi sans salarié : un warning doit sortir"
    assert "vendredi" in warnings[0]["message"]


def test_salarie_parity_hard_infeasible_where_preferred_warns() -> None:
    """Parité S2 : le MÊME scénario (vendredi sans salarié) est INFEASIBLE en HARD (la pose
    l'interdit) et complété-avec-warning en PREFERRED."""
    hard = solve_payload(_salarie_payload(intensity="HARD", friday_salarie=False))
    assert hard["status"] == "failed"
    preferred = solve_payload(_salarie_payload(intensity="PREFERRED", friday_salarie=False))
    assert preferred["status"] == "completed"
    assert _implicit_warnings(preferred, "salarieDistribution")


# ===========================================================================
# 12 — age ascending (intensité)
# ===========================================================================


def _age_payload(*, intensity: str | None) -> dict[str, Any]:
    """2 équipes d'âges différents, MÊME gymnase MÊME jour, 2 créneaux (17:00, 19:00, cap 1).
    L'équipe jeune est forcée après 18:00 (donc 19:00) ; l'ancienne prend 17:00 → inversion."""
    constraints = [_min_start("ms-young", "young", "18:00")]
    payload = make_payload(
        teams=[_team("young", age_min=10), _team("old", age_min=15)],
        venues=[make_venue("v", [(1, "17:00"), (1, "19:00")], duration_minutes=90)],
        constraints=constraints,
        timeout=10,
    )
    if intensity is not None:
        payload["implicitRules"] = {"ageAscending": {"intensity": intensity}}
    return payload


def _age_starts(result: dict[str, Any]) -> dict[str, str]:
    return {s["teamId"]: str(s["startTime"])[:5] for s in result["slots"]}


def test_age_hard_never_places_in_inversion() -> None:
    """HARD : jamais l'équipe jeune après l'ancienne (même gymnase/jour). Le solveur préfère
    laisser une équipe non placée plutôt que d'inverser."""
    result = solve_payload(_age_payload(intensity="HARD"))
    assert result["status"] == "completed"
    starts = _age_starts(result)
    if "young" in starts and "old" in starts:
        assert starts["young"] < starts["old"], "inversion d'âge posée alors que la règle est HARD"


def test_age_preferred_steers_and_warns() -> None:
    result = solve_payload(_age_payload(intensity="PREFERRED"))
    assert result["status"] == "completed"
    starts = _age_starts(result)
    assert starts.get("young") == "19:00" and starts.get("old") == "17:00", (
        "en PREFERRED l'inversion doit être tolérée (les 2 équipes placées)"
    )
    warnings = _implicit_warnings(result, "ageAscending")
    assert warnings and "plus jeunes" in warnings[0]["message"]


# ===========================================================================
# « PREFERRED ne supprime jamais une séance » — l'arbitre du poids −6
# ===========================================================================


def test_preferred_never_drops_a_session_worst_case() -> None:
    """Fixture pire-cas : une équipe tier D encadrée par 2 coach-personnes, avec violation
    repos ET chaîne simultanées, toutes en PREFERRED. Le malus −6 ne doit JAMAIS rendre la
    suppression d'une séance rentable (3×6 = 18 < 21) : toutes les séances demandées sont
    placées."""
    # c1 encadre 5 équipes mono-jour (violation repos, 5 jours) ; lundi t-1 et t-6 sont
    # dos-à-dos (violation chaîne pour maxConsecutive=2). c2 double c1 sur ces 2 équipes
    # (2 coach-personnes → k=2). Tier D partout.
    constraints: list[dict[str, Any]] = []
    for d in range(1, 6):
        constraints.append(team_coach(f"tc-{d}", f"t-{d}", "c1"))
        constraints.append(_allowed_day(f"day-{d}", f"t-{d}", d))
    # t-6 : deuxième équipe du lundi, dos-à-dos avec t-1, encadrée par c1 ET c2.
    constraints.append(team_coach("tc-6a", "t-6", "c1"))
    constraints.append(team_coach("tc-6b", "t-6", "c2"))
    constraints.append(team_coach("tc-1b", "t-1", "c2"))
    constraints.append(_allowed_day("day-6", "t-6", 1))

    teams = [_team(f"t-{d}", tier=5) for d in range(1, 6)] + [_team("t-6", tier=5)]
    payload = make_payload(
        teams=teams,
        venues=[
            make_venue(
                "v",
                [(1, "17:00"), (1, "18:30"), (2, "18:00"), (3, "18:00"), (4, "18:00"), (5, "18:00")],
                duration_minutes=90,
            )
        ],
        coaches=[_coach("c1"), _coach("c2")],
        constraints=constraints,
        timeout=15,
    )
    payload["implicitRules"] = {
        "coachRestDay": {"intensity": "PREFERRED"},
        "maxConsecutiveSessions": {"intensity": "PREFERRED", "maxConsecutive": 2},
    }
    result = solve_payload(payload)
    assert result["status"] == "completed"
    placed_teams = {s["teamId"] for s in result["slots"]}
    assert placed_teams == {f"t-{d}" for d in range(1, 6)} | {"t-6"}, (
        f"PREFERRED a supprimé une séance : {placed_teams}"
    )
    # Et la preuve que les violations existaient bien (sinon le test ne prouve rien).
    assert _implicit_warnings(result, "coachRestDay") or _implicit_warnings(result, "maxConsecutiveSessions")


# ===========================================================================
# HARD contournée par un verrou → diagnostiquée (ton « le solveur n'a pas pu »)
# ===========================================================================


def test_hard_rest_day_bypassed_by_locks_is_diagnosed() -> None:
    """Le repos coach est HARD, mais 5 réservations HARD placent le coach les 5 jours hors du
    solveur. La règle est contournée par les verrous — elle DOIT être diagnostiquée, avec le
    ton « le solveur n'a pas pu honorer »."""
    constraints = [team_coach(f"tc-{d}", f"t-{d}", "c1") for d in range(1, 6)]
    locks = [
        {
            "id": f"lock-{d}",
            "teamId": f"t-{d}",
            "venueId": "v",
            "coachId": "c1",
            "dayOfWeek": d,
            "startTime": "18:00",
            "durationMinutes": 60,
            "lockLevel": "HARD",
        }
        for d in range(1, 6)
    ]
    payload = make_payload(
        teams=[_team(f"t-{d}") for d in range(1, 6)],
        venues=[make_venue("v", [(d, "18:00") for d in range(1, 6)], duration_minutes=60)],
        coaches=[_coach("c1")],
        constraints=constraints,
        slot_templates=locks,
        timeout=10,
    )
    # Bloc absent = HARD par défaut : c'est bien le cas « HARD contournée ».
    result = solve_payload(payload)
    assert result["status"] == "completed"
    warnings = _implicit_warnings(result, "coachRestDay")
    assert any(w.get("coachId") == "c1" for w in warnings), "un verrou contournant la 3b HARD doit être diagnostiqué"
    assert all("n'a pas pu honorer" in w["message"] for w in warnings), (
        "le ton HARD-contournée doit être « le solveur n'a pas pu honorer », pas « assouplie par vous »"
    )
