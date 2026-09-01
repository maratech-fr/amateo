"""NR-A — le VERDICT honore l'INTENSITÉ des règles implicites (§7.1 constraint semantics).

Rupture de parité corrigée : ``diagnose_candidate_conflicts`` NOMMAIT ``coach_no_rest_day`` comme
violation bloquante INCONDITIONNELLEMENT (« mirror add_coach_rest_day — at most 4 working days »),
alors que le réglage ``coachRestDay`` peut être PREFERRED. En PREFERRED, la GÉNÉRATION place en
payant le malus (−3/−6) et le verdict doit ACCEPTER de même : la concession remonte comme COMPROMIS
(famille ``implicit_rule``), jamais comme violation. En HARD, le refus est inchangé.

``coachRestDay`` est la SEULE des cinq règles implicites réglables (repos coach, distribution
salarié, dos-à-dos, jours consécutifs, âge croissant) à posséder un pré-check nommé dans
``diagnostics.py`` ; les quatre autres n'y miroitent rien, il n'y a donc rien d'autre à régler.

Chaque test rougit si le garde d'intensité est retiré.
"""

from __future__ import annotations

from typing import Any

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.validate_assignments import validate_assignment
from tests.support import make_payload, make_team, make_venue, solve_payload, team_coach


def _coach(coach_id: str) -> dict[str, Any]:
    return {"id": coach_id, "firstName": coach_id, "lastName": "X", "isActive": True, "isEmployee": False}


def _tmpl(team_id: str, venue: str, day: int, start: str, *, duration: int = 60) -> dict[str, Any]:
    return {
        "id": f"s-{team_id}-{day}-{start}",
        "teamId": team_id,
        "venueId": venue,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": duration,
    }


def _cand(team_id: str, venue: str, day: int, start: str, *, duration: int = 60) -> dict[str, Any]:
    return {"teamId": team_id, "venueId": venue, "dayOfWeek": day, "startTime": start, "durationMinutes": duration}


def _verdict(
    *,
    teams: list[dict[str, Any]],
    venues: list[dict[str, Any]],
    coaches: list[dict[str, Any]],
    constraints: list[dict[str, Any]],
    slot_templates: list[dict[str, Any]],
    candidates: list[dict[str, Any]],
    references: list[dict[str, Any]],
    intensity: str,
) -> dict[str, Any]:
    return {
        "clubId": "club",
        "seasonId": "season",
        "venues": venues,
        "teams": teams,
        "coaches": coaches,
        "constraints": constraints,
        "slotTemplates": slot_templates,
        "implicitRules": {"coachRestDay": {"intensity": intensity, "minRestDays": 1}},
        "candidates": candidates,
        "references": references,
        "solverTimeoutSeconds": 10,
    }


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    return validate_assignment(ValidateAssignmentsInputSchema.model_validate(payload), contract_version="2.19")


def _five_day_scene(intensity: str) -> dict[str, Any]:
    """c1 encadre t1..t5 ; t1..t4 GELÉES lun-jeu (coach à 4 jours). On déplace t5 vers le
    VENDREDI → 5ᵉ jour du coach. Rien d'autre ne cloche : le seul « défaut » est le repos coach."""
    teams = [make_team(f"t{d}") for d in range(1, 6)]
    venue = make_venue("v", [(d, "18:00") for d in range(1, 6)], duration_minutes=60, capacity=5)
    constraints = [team_coach(f"tc{d}", f"t{d}", "c1") for d in range(1, 6)]
    slots = [_tmpl(f"t{d}", "v", d, "18:00") for d in range(1, 5)]  # t5 source retirée (déplacée)
    return _verdict(
        teams=teams,
        venues=[venue],
        coaches=[_coach("c1")],
        constraints=constraints,
        slot_templates=slots,
        candidates=[_cand("t5", "v", 5, "18:00")],
        references=[_cand("t5", "v", 4, "18:00")],
        intensity=intensity,
    )


def test_preferred_fifth_day_is_accepted_with_a_named_compromise() -> None:
    """PREFERRED : le 5ᵉ jour du coach est ACCEPTÉ (``valid: True``) et la concession remonte comme
    COMPROMIS ``implicit_rule`` nommant le coach — jamais comme violation. Rougit si le pré-check
    ``coach_no_rest_day`` reste un interdit dur en PREFERRED."""
    result = _run(_five_day_scene("PREFERRED"))
    assert result["valid"] is True
    assert not any(v["rule"] == "coach_no_rest_day" for v in result["violations"])
    rest_compromises = [c for c in result["compromises"] if c["family"] == "implicit_rule" and c["effect"] == "broken"]
    assert rest_compromises, "le 5ᵉ jour concédé doit apparaître en compromis implicit_rule"
    assert any(c.get("coachId") == "c1" for c in rest_compromises)


def test_hard_fifth_day_is_refused_unchanged() -> None:
    """HARD : le refus est INCHANGÉ — le 5ᵉ jour prive le coach de son unique repos, violation
    NOMMÉE ``coach_no_rest_day``."""
    result = _run(_five_day_scene("HARD"))
    assert result["valid"] is False
    rest = [v for v in result["violations"] if v["rule"] == "coach_no_rest_day"]
    assert rest and any(v.get("coach_id") == "c1" for v in rest)


def _genuine_refusal_scene(intensity: str) -> dict[str, Any]:
    """Le déplacement de t5 vers le vendredi donne un 5ᵉ jour au coach c1 ET tombe sur une case
    DÉJÀ PLEINE (tX y est, capacité 1) → solve INFEASIBLE pour une vraie raison (capacité). Le
    diagnostic tourne alors : il doit NOMMER la capacité, mais PAS ``coach_no_rest_day`` en
    PREFERRED (parité), et le NOMMER en HARD."""
    teams = [make_team(f"t{d}") for d in range(1, 6)] + [make_team("tX")]
    venue = make_venue(
        "v",
        [(1, "18:00"), (2, "18:00"), (3, "18:00"), (4, "18:00")],
        duration_minutes=60,
        capacity=5,
    )
    # Le vendredi est une case de CAPACITÉ 1, déjà occupée par tX (gelée).
    friday = make_venue("w", [(5, "18:00")], duration_minutes=60, capacity=1)
    constraints = [team_coach(f"tc{d}", f"t{d}", "c1") for d in range(1, 6)]
    slots = [_tmpl(f"t{d}", "v", d, "18:00") for d in range(1, 5)] + [_tmpl("tX", "w", 5, "18:00")]
    return _verdict(
        teams=teams,
        venues=[venue, friday],
        coaches=[_coach("c1")],
        constraints=constraints,
        slot_templates=slots,
        candidates=[_cand("t5", "w", 5, "18:00")],
        references=[_cand("t5", "v", 4, "18:00")],
        intensity=intensity,
    )


def test_preferred_does_not_pollute_a_genuine_refusal() -> None:
    """LE test du garde. Le solve est légitimement INFEASIBLE (capacité pleine) et le coach gagne
    un 5ᵉ jour : le verdict doit NOMMER la capacité, et SURTOUT PAS ``coach_no_rest_day`` puisque
    la règle est PREFERRED. AVANT le correctif, le pré-check l'ajoutait inconditionnellement →
    ce test ROUGIT (violation parasite plus stricte que la génération)."""
    result = _run(_genuine_refusal_scene("PREFERRED"))
    assert result["valid"] is False
    assert any(v["rule"] == "venue_capacity" for v in result["violations"]), (
        "la vraie cause (capacité) doit être nommée"
    )
    assert not any(v["rule"] == "coach_no_rest_day" for v in result["violations"]), (
        "coach_no_rest_day ne doit JAMAIS apparaître quand coachRestDay est PREFERRED"
    )


def test_hard_still_names_the_rest_day_alongside_the_capacity() -> None:
    """Témoin de parité : le MÊME scénario en HARD NOMME bien ``coach_no_rest_day`` (le pré-check
    fire), ce qui prouve que son absence en PREFERRED vient du garde d'intensité, pas d'un
    pré-check qui ne se déclenche jamais."""
    result = _run(_genuine_refusal_scene("HARD"))
    assert result["valid"] is False
    assert any(v["rule"] == "coach_no_rest_day" for v in result["violations"])


# ── Parité côté GÉNÉRATION : le même état passe en PREFERRED, se durcit en HARD ──────────────────


def _generation_scene(intensity: str) -> dict[str, Any]:
    """c1 encadre 5 équipes, chacune GELÉE (allowedDays) sur son jour lun-ven : les placer toutes
    = coach présent 5 jours. En HARD c'est interdit (au plus 4) ; en PREFERRED c'est concédé."""
    constraints: list[dict[str, Any]] = []
    for d in range(1, 6):
        constraints.append(team_coach(f"tc{d}", f"t{d}", "c1"))
        constraints.append(
            {
                "id": f"day{d}",
                "scope": "TEAM",
                "scopeTargetId": f"t{d}",
                "family": "DAY",
                "ruleType": "HARD",
                "name": "jour imposé",
                "config": {"allowedDays": [d]},
                "sortOrder": 0,
                "isActive": True,
            }
        )
    payload = make_payload(
        teams=[make_team(f"t{d}") for d in range(1, 6)],
        venues=[make_venue("v", [(d, "18:00") for d in range(1, 6)], duration_minutes=60)],
        coaches=[_coach("c1")],
        constraints=constraints,
        timeout=10,
    )
    payload["implicitRules"] = {"coachRestDay": {"intensity": intensity, "minRestDays": 1}}
    return payload


def _coach_weekdays(result: dict[str, Any]) -> set[int]:
    return {int(s["dayOfWeek"]) for s in result["slots"] if s.get("coachId") == "c1" and 1 <= int(s["dayOfWeek"]) <= 5}


def test_generation_parity_preferred_completes_hard_hardens() -> None:
    """Parité génération (le pendant du verdict) : PREFERRED complète en laissant le coach 5 jours,
    HARD le borne à 4 au plus."""
    preferred = solve_payload(_generation_scene("PREFERRED"))
    assert preferred["status"] == "completed"
    assert _coach_weekdays(preferred) == {1, 2, 3, 4, 5}

    hard = solve_payload(_generation_scene("HARD"))
    assert hard["status"] == "completed"
    assert len(_coach_weekdays(hard)) <= 4
