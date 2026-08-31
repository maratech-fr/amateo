"""Runtime tests (ENG-03/06): the solve must not block the event loop, the club
lock dict must stay bounded, and an unrecognised constraint must be logged."""

from __future__ import annotations

import asyncio
import logging
import threading
import time
from typing import Any

import pytest

import app.main as main
from app.schemas.input_schema import ScheduleInputSchema
from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.constraints import parse_v2_constraints
from tests.support.pipeline import as_validate_payload, make_team, make_venue

_MINIMAL_OUTPUT = {
    "status": "completed",
    "score": 0,
    "metrics": {"solver_version": "test", "nb_variables": 0, "nb_constraints": 0, "wall_time_ms": 0},
    "slots": [],
    "diagnostics": [],
}


def _minimal_input() -> ScheduleInputSchema:
    return ScheduleInputSchema.model_validate(
        {
            "clubId": "c",
            "seasonId": "s",
            "version": "1.0",
            "venues": [],
            "teams": [],
            "coaches": [],
            "slotTemplates": [],
            "constraints": [],
            "priorityTiers": [],
        }
    )


def test_solve_runs_off_the_event_loop(monkeypatch: Any) -> None:
    # ENG-03: _solve is CPU-bound and must run in a worker thread so /health
    # keeps answering. Prove (a) _solve executes off the main thread, and
    # (b) health() completes while a slow _solve is still running.
    solve_thread: dict[str, str] = {}

    def fake_solve(data: dict[str, Any], input_data: Any) -> tuple[Any, ...]:
        solve_thread["name"] = threading.current_thread().name
        time.sleep(0.3)
        # P5-10 — _solve now also returns the capacity solve_stats (5th element).
        return (
            0,
            None,
            None,
            [],
            {"workers": 1, "budget_seconds": 60, "solver_status_detail": "UNKNOWN", "nb_conflicts": 0},
        )

    monkeypatch.setattr(main, "_solve", fake_solve)
    monkeypatch.setattr(main, "build_result", lambda *a, **k: dict(_MINIMAL_OUTPUT))

    async def scenario() -> str:
        solve_task = asyncio.create_task(main.build_schedule(_minimal_input()))
        await asyncio.sleep(0.05)  # let the solve start on its worker thread
        # The event loop is free: health answers while the solve is mid-flight.
        health = await main.health()
        assert not solve_task.done(), "solve should still be running (event loop free)"
        await solve_task
        return health["status"]

    assert asyncio.run(scenario()) == "ok"
    assert solve_thread["name"] != threading.main_thread().name, "_solve must run off the main thread"


def test_club_locks_bounded() -> None:
    async def hammer() -> int:
        for i in range(main._MAX_CLUB_LOCKS + 50):
            await main.get_club_lock(f"club-{i}")
        return len(main._club_locks)

    size = asyncio.run(hammer())
    # Idle (unheld) locks are purged past the cap, so the dict cannot grow
    # unbounded across many one-shot clubs.
    assert size <= main._MAX_CLUB_LOCKS + 1, f"club lock dict unbounded: {size}"


def test_unrecognised_constraint_is_logged(caplog: Any) -> None:
    with caplog.at_level(logging.WARNING, logger="engine.constraints"):
        parse_v2_constraints([{"id": "x", "isActive": True, "family": "TOTALLY_UNKNOWN"}])
    assert any("unrecognised constraint" in r.message for r in caplog.records)


def test_recognised_family_variant_does_not_warn(caplog: Any) -> None:
    # A recognised family (FACILITY) whose specific config variant isn't handled
    # (CLUB scope, no venue action) is an intentional no-op, NOT contract drift.
    with caplog.at_level(logging.WARNING, logger="engine.constraints"):
        parse_v2_constraints(
            [
                {
                    "id": "x",
                    "isActive": True,
                    "family": "FACILITY",
                    "scope": "CLUB",
                    "ruleType": "PREFERRED",
                    "config": {},
                }
            ]
        )
    assert not any("unrecognised constraint" in r.message for r in caplog.records)


def test_purge_keeps_lock_with_pending_waiter() -> None:
    # Regression (audit review F1): a lock that is momentarily unlocked but has a
    # pending waiter must NOT be purged, or per-club serialisation breaks.
    async def scenario() -> bool:
        lock = await main.get_club_lock("keepme")
        await lock.acquire()  # held → a waiter will queue behind it

        async def waiter() -> None:
            async with lock:
                pass

        w = asyncio.create_task(waiter())
        await asyncio.sleep(0)  # let the waiter queue on the lock

        # Fill past the cap with idle clubs to trigger the purge, then release.
        for i in range(main._MAX_CLUB_LOCKS + 10):
            await main.get_club_lock(f"filler-{i}")
        same = main._club_locks.get("keepme") is lock  # not orphaned
        lock.release()
        await w
        return same

    assert asyncio.run(scenario()), "a lock with a pending waiter must survive the purge"


def _input_with_version(version: str) -> ScheduleInputSchema:
    return ScheduleInputSchema.model_validate(
        {
            "clubId": "c",
            "seasonId": "s",
            "version": version,
            "venues": [],
            "teams": [],
            "coaches": [],
            "slotTemplates": [],
            "constraints": [],
            "priorityTiers": [],
        }
    )


def test_generate_rejects_incompatible_contract_major() -> None:
    # ENG-14 (backend↔engine contract axis): a payload whose contract MAJOR the
    # engine does not speak must be rejected up front, not solved against a
    # schema it may misread. Engine CONTRACT_VERSION is 2.x → major 1 is refused.
    from fastapi import HTTPException

    try:
        asyncio.run(main.generate_schedule(_input_with_version("1.0")))
    except HTTPException as exc:
        assert exc.status_code == 422
        assert "contract version" in exc.detail.lower()
    else:
        raise AssertionError("an incompatible contract major must be rejected (422)")


def test_generate_accepts_matching_contract_major() -> None:
    # A payload on the engine's own major (2.x) passes the guard and reaches the
    # solver (empty club → trivially completed).
    result = asyncio.run(main.generate_schedule(_input_with_version("2.0")))
    assert result.status == "completed"


def test_unhandled_exception_returns_clean_500(caplog: Any) -> None:
    # ENG-06: an unexpected error is logged with its traceback and returns a
    # clean JSON 500 — no internal detail leaks to the client.
    import json
    import types

    request = types.SimpleNamespace(method="POST", url=types.SimpleNamespace(path="/generate"))
    with caplog.at_level(logging.ERROR, logger="engine"):
        response = asyncio.run(main._unhandled_exception_handler(request, RuntimeError("boom: secret internal detail")))

    assert response.status_code == 500
    body = json.loads(response.body)
    assert body == {"status": "error", "detail": "Internal solver error."}
    assert "boom: secret internal detail" not in response.body.decode()
    assert "boom: secret internal detail" in caplog.text  # logged server-side


def test_a_placement_never_waits_behind_a_running_generate() -> None:
    """AUD-ENG-30 — les deux rails ont des budgets CPU SÉPARÉS.

    `/place-matches` est SYNCHRONE (ADR-0003 : ni Messenger ni Mercure — le gestionnaire
    attend la réponse HTTP) et dure ~3 s, quand `/generate` peut tenir 600 s. Tant que les
    deux partageaient `_solve_semaphore`, un placement lancé pendant une génération
    attendait le solve entier : timeout côté gestionnaire, sur une opération de trois
    secondes.

    ⚑ Le verrou de club de `/place-matches` était DÉJÀ préfixé (`matches:{club_id}`)
    précisément pour éviter cela — le sémaphore partagé défaisait cette intention, en
    silence. Deux protections qui se contredisent, et c'est la plus discrète qui gagnait.

    Ce test échoue (TimeoutError) si les deux sémaphores redeviennent le même objet.
    """

    async def scenario() -> str:
        async with main._solve_semaphore:  # une génération est en vol
            # Le placement doit acquérir SON budget immédiatement, sans attendre le solve.
            await asyncio.wait_for(main._placement_semaphore.acquire(), timeout=0.5)
            main._placement_semaphore.release()
        return "ok"

    assert asyncio.run(scenario()) == "ok"


def _verdict_input() -> ValidateAssignmentsInputSchema:
    """L'entrée la plus petite qui soit VALIDE — le sujet est l'attente, pas le contenu."""
    return ValidateAssignmentsInputSchema.model_validate(
        as_validate_payload(
            {
                "clubId": "club-b",
                "seasonId": "season",
                "venues": [make_venue("A", [(4, "18:00")])],
                "teams": [make_team("U13")],
                "coaches": [],
                "constraints": [],
                "slotTemplates": [],
                "candidate": {
                    "teamId": "U13",
                    "venueId": "A",
                    "dayOfWeek": 4,
                    "startTime": "18:00",
                    "durationMinutes": 90,
                },
            }
        )
    )


def test_a_verdict_never_waits_behind_a_running_placement() -> None:
    """AUD-ENG-33 — le rail VERDICT a son propre budget, comme le placement a le sien.

    C'est la classe d'incident d'ENG-30, réintroduite par la porte du voisin. Placement et
    verdict partageaient `_placement_semaphore` (défaut 1) alors que leurs budgets sont
    ASYMÉTRIQUES, et mesurés :

      * `/place-matches`  — solveur 30 s (`MatchPlacementPayloadBuilder.php`), transport 60 s ;
      * `/validate-assignments` — solveur 2 s, transport **20 s** (`MoveSlotService.php`), une
        valeur calée sur mesure : « 9 à 9,6 s de calcul réel constatés sur le club réel ».

    Un placement du club A pouvait donc tenir l'unique jeton jusqu'à 30 s pendant que le
    verdict du club B, lui, abandonne à 20 s : **une action LÉGALE d'un club échouait à cause
    d'un autre**, et le gestionnaire lisait un message honnête sur une cause fausse.

    ⚠ **Ce test exerce l'ENDPOINT, pas deux objets.** Sa première version se contentait
    d'acquérir `_verdict_semaphore` pendant que `_placement_semaphore` était tenu : elle
    restait VERTE quand on recâblait l'endpoint sur le sémaphore de placement, puisque les
    deux objets existaient toujours. Elle ne gardait donc rien du câblage — le seul endroit
    où le défaut vit. Falsification faite : recâbler `/validate-assignments` sur
    `_placement_semaphore` fait maintenant tomber ce test.
    """
    monkeypatch = pytest.MonkeyPatch()
    # Le solveur est remplacé : le sujet est l'ATTENTE, pas le calcul.
    monkeypatch.setattr(
        main, "validate_assignment", lambda *_args, **_kwargs: {"valid": True, "violations": [], "compromises": []}
    )

    async def scenario() -> str:
        async with main._placement_semaphore:  # un placement de matchs du club A est en vol
            # Le verdict du club B doit passer SANS attendre ce placement.
            await asyncio.wait_for(main.validate_assignments(_verdict_input()), timeout=1.0)
        return "ok"

    try:
        assert asyncio.run(scenario()) == "ok"
    finally:
        monkeypatch.undo()


def test_two_placements_are_still_serialised() -> None:
    """Le pendant, même raisonnement qu'ENG-30 : séparer les rails ne RELÂCHE rien.

    Élargir `max_concurrent_placements` à 2 aurait été la correction paresseuse — elle aurait
    aussi autorisé deux placements de 30 s en parallèle, et n'aurait toujours pas garanti
    qu'un verdict ne se retrouve pas derrière eux. Un budget propre, pas un budget plus large.
    """

    async def scenario() -> bool:
        async with main._placement_semaphore:
            try:
                await asyncio.wait_for(main._placement_semaphore.acquire(), timeout=0.2)
            except TimeoutError:
                return True
            main._placement_semaphore.release()
            return False

    assert asyncio.run(scenario()), "un second placement ne doit pas démarrer pendant le premier"


def test_two_generations_are_still_serialised() -> None:
    """Le pendant : séparer les rails ne relâche PAS la borne des générations.

    Élargir `max_concurrent_solves` aurait été la correction paresseuse — elle aurait
    aussi autorisé deux solves de 600 s en parallèle, ce que le réglage borne exprès.
    """

    async def scenario() -> bool:
        async with main._solve_semaphore:
            try:
                await asyncio.wait_for(main._solve_semaphore.acquire(), timeout=0.2)
            except TimeoutError:
                return True
            main._solve_semaphore.release()
            return False

    assert asyncio.run(scenario()), "une seconde génération ne doit pas démarrer pendant la première"
