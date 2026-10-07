"""P4-96 (repli D4) — le solve NOMINAL ne porte AUCUNE hypothèse CP-SAT.

C'est la garantie qui protège `main` de la régression mesurée : posées dès le premier solve, les
hypothèses privaient le présolve de la fixation des candidats fermés et faisaient stagner la preuve
d'optimalité (614 s vs 8-40 s sur le vrai BCCL ; ~26 s vs ~2 s sur la fixture `bccl_2026_08_15`).
Le repli : le nominal n'instrumente RIEN (byte-identique à `main`), et on ne construit un modèle
INSTRUMENTÉ que pour un SECOND solve diagnostique, seulement après un premier INFEASIBLE.

On prouve ici, STRUCTURELLEMENT (sans chronomètre, donc sans flakiness) :
  1. après un solve nominal d'un payload PORTANT TOUTES les familles de contraintes source qui
     posent une hypothèse — fenêtre horaire, indispo coach, jour IMPOSÉ (``day_forced``), plancher
     « au moins N au gymnase » (``session_floor``, PR-2) ET bloc mutualisé (``shared_block``, PR-2) —
     le modèle ne contient AUCUNE littérale d'hypothèse ni aucune ``AddAssumption`` dans le proto
     CP-SAT ;
  2. le MÊME payload résolu avec ``assumptions_enabled=True`` (le chemin diagnostique) DOIT, lui,
     porter les hypothèses des cinq familles — sans quoi le nommage du noyau (D1) ne reposerait sur
     rien, et la garde du point 1 ne prouverait rien pour les familles PR-2.
La neutralité des hypothèses sur la QUALITÉ d'un solve abouti reste gardée par les goldens à score
exact (`tests/golden/`), qui tournent sur le chemin nominal (donc sans hypothèse) et sont inchangés.
"""

from __future__ import annotations

from typing import Any

from app.main import DIAGNOSTIC_SOLVE_MAX_SECONDS, _solve
from app.schemas.input_schema import ScheduleInputSchema
from tests.support.pipeline import make_payload, make_team, make_venue, team_coach, team_constraint

WEDNESDAY = 3


def _payload_with_source_constraints() -> dict[str, Any]:
    """Un payload FAISABLE portant les CINQ familles de contraintes SOURCE qui posent une hypothèse :
    fenêtre horaire, indispo coach, jour imposé (``day_forced``), plancher venue-minimum
    (``session_floor``, PR-2) et bloc mutualisé (``shared_block``, PR-2) — exactement les règles qui,
    sous l'ancien mécanisme, posaient des hypothèses dès le premier solve.

    Faisabilité : t0 est forcée au mercredi et ≥ 19 h ⇒ mercredi 20 h ; le bloc mutualisé réunit t0
    et t1 sur la MÊME case (gymnase capacité 2) ; le plancher « au moins 1 au gymnase » de t1 est
    trivialement tenu. Le coach de t0 est indisponible le lundi (sans effet sur le mercredi retenu)."""
    venue = make_venue(
        "gym",
        [(day, start) for day in (1, 2, 3, 4, 5) for start in ("18:00", "20:00")],
        capacity=2,
    )
    t0 = make_team("t0", sessions_per_week=1)
    t1 = make_team("t1", sessions_per_week=1)
    coach = {"id": "c0", "firstName": "A", "lastName": "B", "isEmployee": False}
    constraints: list[dict[str, Any]] = [
        team_coach("tc-0", "t0", "c0"),
        {
            "id": "cu-0",
            "scope": "COACH",
            "scopeTargetId": "c0",
            "family": "COACH_AVAILABILITY",
            "ruleType": "HARD",
            "name": "Indispo lundi",
            "config": {"unavailableDays": [1]},
            "sortOrder": 0,
            "isActive": True,
        },
        team_constraint(
            constraint_id="tw-0",
            team_id="t0",
            family="TIME",
            rule_type="HARD",
            config={"minStartTime": "19:00"},
            name="Pas avant 19h",
        ),
        # Jour IMPOSÉ → hypothèse `day_forced` (PR-2) en mode diagnostique.
        team_constraint(
            constraint_id="day-0",
            team_id="t0",
            family="DAY",
            rule_type="HARD",
            config={"forcedDays": [WEDNESDAY]},
            name="Mercredi imposé",
        ),
        # Plancher « au moins N au gymnase » → hypothèse `session_floor` (PR-2).
        team_constraint(
            constraint_id="floor-0",
            team_id="t1",
            family="FACILITY",
            rule_type="HARD",
            config={"minAtVenueId": "gym", "minAtVenueCount": 1},
            name="Au moins 1 au gymnase",
        ),
    ]
    payload = make_payload(teams=[t0, t1], venues=[venue], coaches=[coach], constraints=constraints, timeout=10)
    # Bloc mutualisé → hypothèse `shared_block` (PR-2). t0 et t1 partagent 1 séance commune.
    payload["sharedBlocks"] = [{"id": "b0", "teamIds": ["t0", "t1"], "commonSessions": 1}]
    return payload


def _proto_assumptions(model: Any) -> list[int]:
    return list(model.Proto().assumptions)


def test_nominal_model_carries_no_assumption_literals() -> None:
    payload = _payload_with_source_constraints()
    input_data = ScheduleInputSchema.model_validate(payload)

    _status, _solver, model, _conflicts, _stats = _solve(payload, input_data)

    assert model.assumptions_enabled is False, "le solve nominal ne doit JAMAIS armer les hypothèses"
    assert model.assumption_literals == {}, f"aucune littérale d'hypothèse attendue, obtenu {model.assumption_literals}"
    assert model.assumption_sources == {}, "aucune source d'hypothèse ne doit être enregistrée sur le nominal"
    assert _proto_assumptions(model) == [], "le proto CP-SAT nominal ne doit porter aucune AddAssumption"


def test_diagnostic_model_does_carry_assumption_literals() -> None:
    """Le chemin diagnostique (``assumptions_enabled=True``, budget court) instrumente bien le
    modèle — faute de quoi le nommage du noyau d'infaisabilité ne reposerait sur rien."""
    payload = _payload_with_source_constraints()
    input_data = ScheduleInputSchema.model_validate(payload)

    _status, _solver, model, _conflicts, _stats = _solve(
        payload, input_data, assumptions_enabled=True, budget_override=DIAGNOSTIC_SOLVE_MAX_SECONDS
    )

    assert model.assumptions_enabled is True
    assert model.assumption_literals, "le modèle diagnostique doit porter au moins une littérale d'hypothèse"
    assert _proto_assumptions(model), "le proto diagnostique doit porter des AddAssumption"
    # Les CINQ familles source ont posé leur littérale — y compris les trois kinds dédiés PR-2
    # (`day_forced`, `session_floor`, `shared_block`) : c'est ce qui rend la garde nominale (point 1)
    # NON vide pour ces familles.
    kinds = {src.get("kind") for src in model.assumption_sources.values()}
    expected = {"time_window", "coach_unavailability", "day_forced", "session_floor", "shared_block"}
    assert expected <= kinds, f"familles source attendues dans les hypothèses : {expected - kinds} manquantes (obtenu {kinds})"
