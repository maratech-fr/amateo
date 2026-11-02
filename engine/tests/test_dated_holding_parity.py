"""Promesse : le moteur désigne le même tenant que le backend, sur les mêmes cas.

``app/solver/match_placement/holding.py`` est le miroir de ``App\\Service\\Conflicts\\DatedHolding``.
Les cas vivent dans ``backend/tests/Fixtures/datedHolding.parity.json`` : le jumeau PHP les rejoue
dans ``backend/tests/Unit/Service/Conflicts/DatedHoldingTest.php`` et
``backend/tests/CrossStack/DatedHoldingMirrorParityTest.php`` vérifie que les deux côtés lisent ce
même fichier. Changer la règle d'un seul côté rougit ce côté-là.
"""

from __future__ import annotations

import json
import pathlib
from typing import Any

import pytest

from app.solver.match_placement.holding import Need, assign

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
CASES = json.loads((REPO_ROOT / "backend" / "tests" / "Fixtures" / "datedHolding.parity.json").read_text("utf-8"))[
    "cases"
]


def _need(raw: dict[str, Any]) -> Need:
    return Need(
        id=raw["id"],
        kind=raw["kind"],
        team_id=raw["teamId"],
        start_minutes=raw["startMinutes"],
        end_minutes=raw["endMinutes"],
        candidates=list(raw["candidates"]),
        players=list(raw["players"]),
    )


@pytest.mark.parametrize("case", CASES, ids=[c["name"] for c in CASES])
def test_each_need_gets_the_shared_holder_or_nobody(case: dict[str, Any]) -> None:
    assignment = assign([_need(raw) for raw in case["needs"]])

    assert assignment.holders == case["holders"]
    assert set(assignment.unheld) == set(case["unheld"])
    assert assignment.steps < 10_000, "un petit cas reste sous le budget"


@pytest.mark.parametrize("case", CASES, ids=[c["name"] for c in CASES])
def test_input_order_never_changes_who_holds(case: dict[str, Any]) -> None:
    assignment = assign([_need(raw) for raw in reversed(case["needs"])])

    assert assignment.holders == case["holders"]
    assert set(assignment.unheld) == set(case["unheld"])


def test_budget_exhaustion_falls_back_to_the_same_deterministic_greedy_as_the_backend() -> None:
    # Mêmes chiffres que DatedHoldingTest::testBudgetExhaustionFallsBackToADeterministicGreedy.
    coaches = ["a", "b", "c", "d", "e", "f"]
    needs = [
        Need(
            id=f"m-{index}",
            kind="match",
            team_id=f"t-{index}",
            start_minutes=900,
            end_minutes=1005,
            candidates=list(coaches),
            players=[],
        )
        for index in range(10, 40)
    ]

    first = assign(needs)
    second = assign(list(reversed(needs)))

    assert first.steps <= 11_000, "le compteur de pas s'arrête au budget"
    assert first.holders == {"m-10": "a", "m-11": "b", "m-12": "c", "m-13": "d", "m-14": "e", "m-15": "f"}
    assert len(first.unheld) == 24
    assert first.holders == second.holders
