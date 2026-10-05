"""ENG-54 — chaque fixture franchit la validation Pydantic RÉELLE **et** la garde de version
MAJOR de l'API.

Les tests golden chargent le JSON et appellent le solveur en direct (``solve_payload``), ce qui
SAUTE la garde de version de ``main.generate_schedule`` : une fixture portant une version MAJOR
étrangère (jadis ``2.0`` / ``2.7``) passait donc inaperçue. Ce test rejoue la garde ET la
validation ``extra="forbid"`` sur CHAQUE fixture : un champ mort retiré du contrat (ENG-53 : les
flags entité ``isActive``/``tags``/``minSessionsOverride``, la PII coach ``email``/``phone``, le
``priorityTiers`` de tête) ou une version incompatible échoue ICI, plus en silence."""

from __future__ import annotations

import json
import pathlib

import pytest

from app.main import read_contract_version
from app.schemas.input_schema import ScheduleInputSchema
from app.schemas.match_input_schema import MatchPlacementInputSchema

FIXTURES_DIR = pathlib.Path(__file__).resolve().parent / "fixtures"
FIXTURES = sorted(FIXTURES_DIR.glob("*.json"))


def test_fixtures_dir_is_not_empty() -> None:
    # Garde-fou : un glob vide ferait passer la paramétrisation ci-dessous pour « tout vert ».
    assert FIXTURES, "aucune fixture trouvée — le glob a-t-il changé de dossier ?"


@pytest.mark.parametrize("path", FIXTURES, ids=lambda p: p.name)
def test_fixture_passes_major_guard_and_pydantic(path: pathlib.Path) -> None:
    data = json.loads(path.read_text(encoding="utf-8"))

    # Garde MAJOR de l'API (main.generate_schedule) — rejouée ici, le harnais golden la saute.
    contract = read_contract_version()
    assert data["version"].split(".")[0] == contract.split(".")[0], (
        f"{path.name}: version {data['version']!r} incompatible avec le contrat {contract!r}"
    )

    # Validation Pydantic réelle (extra='forbid') : un champ hors contrat échoue ici.
    if "matches" in data:
        MatchPlacementInputSchema.model_validate(data)
    else:
        ScheduleInputSchema.model_validate(data)
