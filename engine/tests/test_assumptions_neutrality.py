"""P4-96 — NEUTRALITÉ des hypothèses CP-SAT sur un solve FAISABLE.

D1 pose ``add_assumptions`` dès le premier solve : les littérales d'hypothèse sont TOUJOURS vraies,
donc un solve abouti doit rendre EXACTEMENT ce qu'il rendait sans elles (mêmes score et mêmes
créneaux — c'est l'invariant qui protège les goldens, ADR-0001). On le prouve par propriété : pour
un éventail de graines et d'instances faisables PORTANT des contraintes source (fenêtre horaire,
indispo coach), le résultat est identique que ``_assume`` crée des littérales (production) ou renvoie
systématiquement ``None`` (chemin inconditionnel historique, simulé en neutralisant ``_assume`` dans
les trois poseurs qui l'importent)."""

from __future__ import annotations

from typing import Any
from unittest.mock import patch

from hypothesis import HealthCheck, given, settings
from hypothesis import strategies as st

from tests.support.pipeline import make_payload, make_team, make_venue, team_coach


def _feasible_payload(*, seed: int, n_teams: int, with_window: bool) -> dict[str, Any]:
    # Grille LARGE (5 jours × 2 horaires = 10 places) pour rester faisable quelles que soient les
    # fermetures de source ci-dessous ; un coach indisponible le lundi + une fenêtre « pas avant
    # 19:00 » exercent les hypothèses sans jamais rendre l'instance infaisable.
    venue = make_venue("gym", [(day, start) for day in (1, 2, 3, 4, 5) for start in ("18:00", "20:00")])
    teams = [make_team(f"t{i}", sessions_per_week=1) for i in range(n_teams)]
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
    ]
    if with_window:
        constraints.append(
            {
                "id": "tw-0",
                "scope": "TEAM",
                "scopeTargetId": "t0",
                "family": "TIME",
                "ruleType": "HARD",
                "name": "Pas avant 19h",
                "config": {"minStartTime": "19:00"},
                "sortOrder": 0,
                "isActive": True,
            }
        )
    return make_payload(teams=teams, venues=[venue], coaches=[coach], constraints=constraints, seed=seed, timeout=10)


def _signature(result: dict[str, Any]) -> tuple[Any, ...]:
    """L'observable NEUTRE : statut, nombre total de séances placées et le nombre de séances PAR
    ÉQUIPE. C'est la QUALITÉ DE PLACEMENT (phase 1), l'invariant que les hypothèses doivent
    préserver — aucune contrainte silencieusement ajoutée ni relâchée.

    Hors de la signature volontairement : l'affectation EXACTE et le score FINAL. ADR-0001 — la
    phase 2 (chaînage) est au mieux-effort sous un plafond de 10 s ; son incrément de bonus n'est
    PAS prouvé optimal et peut différer de ±1 selon l'incumbent de phase 1 (que la réification des
    hypothèses déplace parmi des optima de placement ÉQUIVALENTS). Les goldens à score EXACT, eux,
    restent verts : sur les vraies fixtures, le chaînage se prouve stable."""
    counts: dict[str, int] = {}
    for slot in result.get("slots", []):
        counts[str(slot["teamId"])] = counts.get(str(slot["teamId"]), 0) + 1
    return (result.get("status"), len(result.get("slots", [])), tuple(sorted(counts.items())))


@settings(max_examples=25, deadline=None, suppress_health_check=[HealthCheck.too_slow])
@given(
    seed=st.integers(min_value=0, max_value=50),
    n_teams=st.integers(min_value=1, max_value=3),
    with_window=st.booleans(),
)
def test_feasible_solve_is_identical_with_and_without_assumptions(seed: int, n_teams: int, with_window: bool) -> None:
    from tests.support.pipeline import solve_payload

    payload = _feasible_payload(seed=seed, n_teams=n_teams, with_window=with_window)

    with_assumptions = solve_payload(payload)
    assert with_assumptions["status"] == "completed"

    # Neutralise `_assume` dans les trois poseurs : fermetures/contraintes redeviennent
    # INCONDITIONNELLES (chemin historique), aucune littérale ni AddAssumption posés.
    with (
        patch("app.solver.constraints.targeting._assume", return_value=None),
        patch("app.solver.constraints.structural._assume", return_value=None),
        patch("app.solver.constraints.travel._assume", return_value=None),
    ):
        without_assumptions = solve_payload(payload)
    assert without_assumptions["status"] == "completed"

    assert _signature(with_assumptions) == _signature(without_assumptions)
