"""Lot 3B PR B — diagnostic à l'échec d'un bloc sans coach de l'union (Q5 volet « à l'échec »).

NR de l'axe §7.1 *constraint semantics*, à travers le VRAI pipeline (``solve_payload``) : quand « un
coach suffit » (PR A) rend malgré tout un bloc infaisable — aucune case où un coach de l'UNION de
ses membres soit disponible — le moteur ne se contente plus du message générique d'infaisabilité :
il NOMME le groupe et porte en ``causes[]`` les indisponibilités de coach en cause (kind existant
``coach_unavailability``, aucun bump de contrat). Le backend compose ensuite leur ORIGINE.

Chaque test rougit si la direction est retirée :
  1. preuve : un bloc dont l'union n'a aucun coach sur la seule case candidate → INFEASIBLE, un
     ``shared_block_not_honored`` NOMME les équipes et cite la contrainte d'indispo en cause ;
  2. témoin : un bloc SERVABLE (coach de l'union dispo) dans un solve par ailleurs INFEASIBLE
     (une équipe hors bloc bloquée) n'est PAS accusé — pas de fausse accusation.
"""

from __future__ import annotations

from typing import Any

from tests.support import (
    coach_availability,
    make_payload,
    make_team,
    make_venue,
    solve_payload,
    team_coach,
)


def _block(block_id: str, team_ids: list[str], common_sessions: int = 1) -> dict[str, Any]:
    return {"id": block_id, "teamIds": team_ids, "commonSessions": common_sessions}


def _shared_block_diagnostics(result: dict[str, Any]) -> list[dict[str, Any]]:
    return [d for d in result.get("diagnostics", []) if d.get("type") == "shared_block_not_honored"]


# ── 1. Preuve : bloc sans coach de l'union → INFEASIBLE nommé avec causes ────────────────────────


def test_block_without_union_coach_is_named_with_coach_unavailability_causes() -> None:
    # 4 équipes en bloc (1 séance commune). Le gymnase n'ouvre QUE le mardi (jour 2) ; les 4 coachs
    # ne sont disponibles QUE le mercredi (jour 3). Aucun coach de l'union sur la seule case candidate
    # → le bloc est fermé (PR A) → INFEASIBLE. Le diagnostic NOMME le groupe et cite les indispos.
    teams = [make_team(f"u{i}", sessions_per_week=1) for i in (1, 2, 3, 4)]
    venue = make_venue("v", [(2, "18:00")], capacity=1)
    constraints = [
        team_coach("tc1", team_id="u1", coach_id="cA"),
        team_coach("tc2", team_id="u2", coach_id="cB"),
        team_coach("tc3", team_id="u3", coach_id="cC"),
        team_coach("tc4", team_id="u4", coach_id="cD"),
        coach_availability("avA", "cA", available_days=[3]),
        coach_availability("avB", "cB", available_days=[3]),
        coach_availability("avC", "cC", available_days=[3]),
        coach_availability("avD", "cD", available_days=[3]),
    ]
    payload = make_payload(teams=teams, venues=[venue], constraints=constraints)
    payload["sharedBlocks"] = [_block("b", ["u1", "u2", "u3", "u4"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "failed", "aucun coach de l'union le seul jour ⇒ le bloc est infaisable"
    diags = _shared_block_diagnostics(result)
    assert diags, "l'échec doit NOMMER le bloc (shared_block_not_honored), pas rester au message générique"
    diag = diags[0]
    # Le groupe est nommé — les 4 équipes citées (ids = noms par défaut des fixtures).
    for team_id in ("u1", "u2", "u3", "u4"):
        assert team_id in diag["message"], f"le message doit nommer {team_id} : {diag['message']}"
    # Les causes portent l'indisponibilité de coach (kind EXISTANT), avec la contrainte nommée.
    causes = diag.get("causes") or []
    assert causes, "le diagnostic doit porter les indisponibilités en cause dans causes[]"
    assert all(c["kind"] == "coach_unavailability" for c in causes), causes
    cited = {c.get("constraintId") for c in causes}
    assert cited & {"avA", "avB", "avC", "avD"}, f"au moins une indispo de coach doit être citée : {cited}"


# ── 2. Témoin : un bloc servable n'est pas accusé (dans un solve par ailleurs infaisable) ─────────


def test_servable_block_is_not_accused_when_infeasibility_comes_from_elsewhere() -> None:
    # Le bloc {u1,u2} est SERVABLE : coachs cA/cB tous deux libres le mardi, gymnase ouvert mardi.
    # Mais une TROISIÈME équipe (solo) se voit IMPOSER une séance le mardi (DAY forcedDays HARD)
    # alors que son unique coach est indisponible ce jour-là : impossible à honorer → le solve est
    # INFEASIBLE à cause d'ELLE (jour imposé souverain), pas du bloc. Le bloc servable ne doit PAS
    # recevoir de shared_block_not_honored (pas de fausse accusation sur INFEASIBLE).
    teams = [
        make_team("u1", sessions_per_week=1),
        make_team("u2", sessions_per_week=1),
        make_team("solo", sessions_per_week=1),
    ]
    venue = make_venue("v", [(2, "18:00")], capacity=3)
    forced_solo_tuesday = {
        "id": "forceSolo",
        "scope": "TEAM",
        "scopeTargetId": "solo",
        "family": "DAY",
        "ruleType": "HARD",
        "name": "solo imposé mardi",
        "config": {"forcedDays": [2]},
        "sortOrder": 0,
        "isActive": True,
    }
    constraints = [
        team_coach("tc1", team_id="u1", coach_id="cA"),
        team_coach("tc2", team_id="u2", coach_id="cB"),
        team_coach("tcS", team_id="solo", coach_id="cS"),
        coach_availability("avA", "cA", available_days=[2]),  # bloc servable mardi
        coach_availability("avB", "cB", available_days=[2]),
        coach_availability("avS", "cS", available_days=[4]),  # solo : coach jamais dispo mardi
        forced_solo_tuesday,
    ]
    payload = make_payload(teams=teams, venues=[venue], constraints=constraints)
    payload["sharedBlocks"] = [_block("b", ["u1", "u2"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "failed", "le jour imposé de solo, coach indisponible, rend le solve infaisable"
    assert not _shared_block_diagnostics(result), (
        "un bloc servable (coach de l'union disponible) ne doit JAMAIS être accusé — "
        f"diagnostics : {result.get('diagnostics')}"
    )
