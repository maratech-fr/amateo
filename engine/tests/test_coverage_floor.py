"""Garde du cliquet de couverture (P4-166, décision B3).

Le plancher de couverture par zone vit dans un fichier UNIQUE versionné à la racine
du dépôt, `coverage-floor.json` — une seule maison, lue par la CI (`--cov-fail-under`)
et remontée dans la MÊME PR quand une mesure s'améliore. Ce test garde la maison côté
engine : le fichier existe, est du JSON, et la clé `engine` est un entier 0-100 (jamais
`null` : l'engine EST mesuré). Les clés `backend`/`frontend` peuvent rester `null` tant
que leur PR ne les a pas remplies (PR 2 et 3 de P4-166).
"""

from __future__ import annotations

import json
from pathlib import Path

# /app/engine/tests/test_coverage_floor.py → parents[2] == racine du dépôt (/app)
COVERAGE_FLOOR = Path(__file__).resolve().parents[2] / "coverage-floor.json"


def test_coverage_floor_file_exists() -> None:
    assert COVERAGE_FLOOR.is_file(), f"{COVERAGE_FLOOR} manquant (cliquet de couverture, B3)"


def test_coverage_floor_is_json() -> None:
    json.loads(COVERAGE_FLOOR.read_text(encoding="utf-8"))


def test_engine_floor_is_an_integer_percentage() -> None:
    floors = json.loads(COVERAGE_FLOOR.read_text(encoding="utf-8"))
    assert "engine" in floors, "clé `engine` absente de coverage-floor.json"
    engine_floor = floors["engine"]
    assert isinstance(engine_floor, int) and not isinstance(engine_floor, bool), (
        f"le plancher engine doit être un entier, reçu {engine_floor!r}"
    )
    assert 0 <= engine_floor <= 100, f"le plancher engine doit être dans 0-100, reçu {engine_floor}"
