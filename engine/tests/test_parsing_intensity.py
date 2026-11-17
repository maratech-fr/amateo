"""Le cran d'une règle du club se lit sans surprise : « OFF » éteint, l'absence vaut Obligatoire.

PR 1b (contrat 1.11) — une règle historique ignorée part au moteur en ``OFF`` explicite, parce que
son absence vaut HARD. Une valeur inconnue retombe aussi sur HARD : jamais une règle éteinte par
erreur de saisie. L'effet sur la pose et les signalements : ``semantic/test_implicit_rules_adjustable.py``.
"""

from __future__ import annotations

from typing import Any

import pytest

from app.solver.constraints.parsing import _intensity


@pytest.mark.parametrize(
    ("block", "expected"),
    [
        ({"intensity": "OFF"}, "OFF"),
        (None, "HARD"),
        ({}, "HARD"),
        ({"intensity": "PREFERRED"}, "PREFERRED"),
        ({"intensity": "SOFT"}, "HARD"),
    ],
    ids=["off-eteint", "bloc-absent", "cran-absent", "souhaitee", "inconnu"],
)
def test_the_stored_setting_reads_as_the_intended_intensity(block: dict[str, Any] | None, expected: str) -> None:
    assert _intensity(block) == expected
