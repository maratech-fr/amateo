"""ENG-49 — mémoire du placement : le solve de /place-matches tourne dans un
PROCESSUS FILS jetable (spawn), avec ``malloc_trim(0)`` en ceinture côté parent.

Trois garanties, chacune falsifiable :
  * le solve passe bien par un FILS (PID différent du parent) — rougit si on
    recâblait l'executor sur des threads (solve de retour dans le parent) ;
  * la réponse est IDENTIQUE au chemin direct en processus (hors ``wall_time_ms``,
    intrinsèquement variable) ;
  * une erreur du fils remonte (exception verbatim) et un fils TUÉ ne laisse pas
    un pool cassé pour les placements suivants.
"""

from __future__ import annotations

import asyncio
import copy
import os
from concurrent.futures import ProcessPoolExecutor
from concurrent.futures.process import BrokenProcessPool
from typing import Any

import pytest

import app.main as main
from app.main import read_contract_version
from app.schemas.match_input_schema import MatchPlacementInputSchema
from app.solver.match_placement import solve_match_placement
from tests.support import placement_probes

# 2026-10-03 est un samedi (isoweekday 6), cohérent avec la fenêtre dayOfWeek 6.
SATURDAY = "2026-10-03"
_SUBMIT_TIMEOUT = 60  # le fils doit importer ortools (coût du spawn) ; large de sécurité.


def _payload() -> MatchPlacementInputSchema:
    """Un problème de placement minimal mais non trivial : deux matchs d'équipes
    distinctes dans une même fenêtre d'accès — placement déterministe (1 worker,
    seed figé dans le solveur)."""
    return MatchPlacementInputSchema.model_validate(
        {
            "version": read_contract_version(),
            "clubId": "club-eng49",
            "seasonId": "season-1",
            "venues": [
                {
                    "id": "v1",
                    "name": "Gymnase 1",
                    "matchWindows": [{"dayOfWeek": 6, "start": "14:00", "end": "20:00"}],
                    "unavailabilities": [],
                }
            ],
            "teams": [
                {"id": "t1", "name": "U13", "leagueWindows": [], "habits": [], "coaches": []},
                {"id": "t2", "name": "U15", "leagueWindows": [], "habits": [], "coaches": []},
            ],
            "matches": [
                {"id": "m1", "teamId": "t1", "date": SATURDAY, "kind": "TO_PLACE"},
                {"id": "m2", "teamId": "t2", "date": SATURDAY, "kind": "TO_PLACE"},
            ],
        }
    )


def _strip_volatile(result: dict[str, Any]) -> dict[str, Any]:
    """Retire les champs intrinsèquement non déterministes (le temps mur du solveur)
    pour une comparaison byte-à-byte du RESTE de la réponse."""
    out = copy.deepcopy(result)
    metrics = out.get("metrics")
    if isinstance(metrics, dict):
        metrics.pop("wall_time_ms", None)
    return out


def test_placement_solve_runs_in_a_child_process() -> None:
    # Prouve que l'executor du rail placement est bien un POOL DE PROCESSUS : une
    # sonde y renvoie un PID différent de celui du parent. Falsifiable — si on
    # remplaçait `_get_placement_executor` par un ThreadPoolExecutor (solve de
    # retour dans le parent), la sonde renverrait le PID du parent et ce test rougirait.
    executor = main._get_placement_executor()
    assert isinstance(executor, ProcessPoolExecutor)
    child_pid = executor.submit(placement_probes.probe_pid, None).result(timeout=_SUBMIT_TIMEOUT)
    assert child_pid != os.getpid(), "le solve de placement doit tourner dans un FILS, pas dans le parent"


def test_child_result_is_identical_to_the_direct_path() -> None:
    # Même payload, deux chemins : solve direct en processus vs solve via le fils
    # (le vrai câblage de l'endpoint). La réponse doit être identique, hors wall_time_ms.
    payload = _payload()
    direct = solve_match_placement(payload)
    via_child = asyncio.run(main._run_match_placement(payload))
    assert _strip_volatile(via_child) == _strip_volatile(direct)
    # Et c'est une réponse réelle, pas un cas dégénéré vide.
    assert via_child["status"] == "completed"
    assert len(via_child["placements"]) == 2


def test_child_exception_propagates_to_the_parent() -> None:
    # exception dans le fils → l'executor la relève VERBATIM dans le parent (même
    # remontée qu'avant, jusqu'au handler 500). On exerce ce passage via une sonde.
    executor = main._get_placement_executor()
    with pytest.raises(ValueError, match="boom from placement child"):
        executor.submit(placement_probes.probe_raise, None).result(timeout=_SUBMIT_TIMEOUT)


def test_broken_pool_is_recovered_for_the_next_placement() -> None:
    # Un fils TUÉ (comme un OOM `mem_limit`) casse le pool : `_run_match_placement`
    # doit le recréer pour que les placements suivants repartent sur un pool sain.
    executor = main._get_placement_executor()
    with pytest.raises(BrokenProcessPool):
        executor.submit(placement_probes.probe_hard_exit, None).result(timeout=_SUBMIT_TIMEOUT)

    # Reset comme le fait `_run_match_placement` sur BrokenProcessPool, puis un
    # nouveau placement doit réussir sur un executor NEUF.
    main._reset_placement_executor()
    fresh = main._get_placement_executor()
    assert fresh is not executor, "un pool cassé doit être remplacé, pas réutilisé"
    pid = fresh.submit(placement_probes.probe_pid, None).result(timeout=_SUBMIT_TIMEOUT)
    assert isinstance(pid, int)


def test_run_match_placement_turns_a_broken_pool_into_a_clear_error(monkeypatch: pytest.MonkeyPatch) -> None:
    # Branche OOM de `_run_match_placement` : un pool cassé devient une RuntimeError
    # claire (→ 500) ET l'executor est réinitialisé pour les appels suivants.
    class _BrokenExecutor:
        def submit(self, *_args: object, **_kwargs: object) -> Any:
            raise BrokenProcessPool("child killed")

    reset_calls = {"n": 0}
    real_reset = main._reset_placement_executor

    def _spy_reset() -> None:
        reset_calls["n"] += 1
        real_reset()

    monkeypatch.setattr(main, "_get_placement_executor", lambda: _BrokenExecutor())
    monkeypatch.setattr(main, "_reset_placement_executor", _spy_reset)

    with pytest.raises(RuntimeError, match="mémoire disponible"):
        asyncio.run(main._run_match_placement(_payload()))
    assert reset_calls["n"] == 1, "un pool cassé doit déclencher la réinitialisation de l'executor"


@pytest.fixture(autouse=True)
def _reset_executor_between_tests() -> Any:
    # Chaque test repart sur un executor propre (les tests tuent/cassent le pool) ;
    # on nettoie aussi après, pour ne pas laisser un fils pendouiller à la suite.
    main._reset_placement_executor()
    yield
    main._reset_placement_executor()
