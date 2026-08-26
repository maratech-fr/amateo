from __future__ import annotations

import asyncio
import contextlib
import logging
import re
import resource
import time
import uuid
from collections.abc import Awaitable, Callable
from pathlib import Path
from typing import Any, cast

from fastapi import FastAPI, HTTPException, Request, Response, status
from fastapi.responses import JSONResponse
from ortools.sat.python import cp_model
from pydantic import BaseModel, ConfigDict, Field, ValidationError

from app.core.config import get_settings
from app.core.logging import configure_logging, request_id_var
from app.schemas.input_schema import ScheduleInputSchema
from app.schemas.match_input_schema import MatchPlacementInputSchema
from app.schemas.match_output_schema import MatchPlacementOutputSchema
from app.schemas.output_schema import ScheduleOutputSchema
from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.schemas.validate_output_schema import ValidateAssignmentsOutputSchema
from app.solver.constraints import (
    add_level_1_hard_constraints,
    add_time_window_constraints,
    add_travel_departage_penalty,
    add_travel_time_penalty,
    add_venue_minimum_constraints,
    diagnose_locked_slot_violations,
    parse_v2_constraints,
    resolve_implicit_rules,
)
from app.solver.match_placement import solve_match_placement
from app.solver.model import DEFAULT_SESSION_MINUTES, ScheduleCpModel, _time_to_minutes, build_model
from app.solver.objective import (
    CHAINING_STABILITY_MULTIPLIER,
    LEVEL_2_OBJECTIVE_WEIGHTS,
    add_coach_day_cap_penalty,
    add_level_2_objective,
    add_match_day_rest_bonus,
    add_missing_session_penalty,
    add_preferred_day_bonus,
    add_preferred_time_bonus,
    add_spacing_penalty,
    add_team_link_penalty,
    add_venue_preference_bonus,
    build_stability_terms,
    is_team_satisfied_by_hard_locks,
)
from app.solver.result_builder import build_result
from app.solver.validate_assignments import validate_assignment

ENGINE_ROOT = Path(__file__).resolve().parents[1]
CONTRACT_VERSION_PATH = ENGINE_ROOT / "CONTRACT_VERSION"
IMPLICIT_RULES_PATH = ENGINE_ROOT / "implicit_rules.json"

settings = get_settings()
# Structured JSON logs carrying the correlation id (P5-11). force=True: uvicorn
# installs root handlers first, which would make a plain config a silent no-op.
configure_logging(settings.log_level)
logger = logging.getLogger("engine")

# Correlation id (X-Request-Id) form — same UUID shape the backend validates, so
# a client header is never echoed/logged verbatim (anti log-injection): a
# malformed value is replaced by a generated one.
_REQUEST_ID_RE = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$", re.IGNORECASE)

# Sentry ERROR capture only (no APM/tracing — solver perf lives in solver_metrics).
# Empty DSN = disabled no-op init: wired now, activated by setting ENGINE_SENTRY_DSN.
# Initialised BEFORE the app so the FastAPI integration hooks unhandled exceptions.
# NEVER let observability take the solver down: a malformed DSN (BadDsn at init)
# degrades to a logged warning, the engine boots without Sentry (#258 review).
if settings.sentry_dsn:
    try:
        import sentry_sdk

        sentry_sdk.init(dsn=settings.sentry_dsn, environment=settings.environment, traces_sample_rate=0.0)
    except Exception as sentry_error:  # any init failure must not kill the engine
        logger.warning("Sentry init failed (engine runs WITHOUT error capture): %s", sentry_error)

app = FastAPI(title=settings.app_name, version=settings.app_version)


@app.middleware("http")
async def _request_id_middleware(
    request: Request,
    call_next: Callable[[Request], Awaitable[Response]],
) -> Response:
    """P5-11 — correlation id across the stack. Reads X-Request-Id (validated),
    generates one when absent/malformed, exposes it on the contextvar for the
    JSON logs (the solve thread inherits it via to_thread) and echoes it on the
    response so the caller — and its own logs — share the SAME id."""
    incoming = request.headers.get("X-Request-Id")
    # fullmatch : `$` seul matche aussi avant un \n final — un « uuid\n »
    # passerait (revue sécurité du lot).
    request_id = incoming if incoming is not None and _REQUEST_ID_RE.fullmatch(incoming) else str(uuid.uuid4())
    token = request_id_var.set(request_id)
    if settings.sentry_dsn:
        import sentry_sdk

        sentry_sdk.set_tag("request_id", request_id)
    try:
        response = await call_next(request)
    finally:
        request_id_var.reset(token)
    response.headers["X-Request-Id"] = request_id
    return response


@app.exception_handler(Exception)
async def _unhandled_exception_handler(request: Request, exc: Exception) -> JSONResponse:
    """ENG-06: last-resort handler so an unexpected solver/runtime error is
    logged with its traceback server-side and returns a clean JSON 500 instead
    of leaking internals. HTTPException and request-validation errors keep their
    own dedicated handlers (this only catches the truly unhandled)."""
    # Log the exception we were handed (exc_info=exc), not the ambient
    # sys.exc_info() — robust whether called in an except context or directly.
    logger.error("Unhandled error on %s %s", request.method, request.url.path, exc_info=exc)

    return JSONResponse(
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        content={"status": "error", "detail": "Internal solver error."},
    )


_club_locks: dict[str, asyncio.Lock] = {}
_club_locks_guard = asyncio.Lock()
_MAX_CLUB_LOCKS = 256
_solve_semaphore = asyncio.Semaphore(settings.max_concurrent_solves)
# AUD-ENG-30 — le rail matchs a son PROPRE budget : un placement synchrone de 3 s ne doit
# pas attendre derrière un solve hebdomadaire de 600 s. Deux sémaphores distincts, pas un
# sémaphore plus large : élargir le partagé aurait aussi autorisé deux GÉNÉRATIONS
# simultanées, ce que `max_concurrent_solves` borne délibérément.
_placement_semaphore = asyncio.Semaphore(settings.max_concurrent_placements)
# AUD-ENG-33 — et le rail VERDICT a le sien, pour la même raison d'un cran : un placement de
# 30 s tenait le jeton pendant qu'un verdict, qui abandonne à 20 s côté client, attendait. Le
# détail des budgets et le résidu assumé vivent dans `core/config.py`, à côté du réglage.
_verdict_semaphore = asyncio.Semaphore(settings.max_concurrent_verdicts)


class SerializableModel(BaseModel):
    model_config = ConfigDict(extra="forbid", populate_by_name=True)


class ImplicitRuleSchema(SerializableModel):
    name: str
    enabled: bool
    description: str


class ImplicitConstraintSyncRequest(SerializableModel):
    version: str
    rules: list[ImplicitRuleSchema] = Field(default_factory=list)


def read_contract_version() -> str:
    """Version du contrat parlée par CE build — le fichier `engine/CONTRACT_VERSION` fait foi.

    ⚑ AUD-ENG-35 — ce fichier manquant est une ERREUR DE BUILD, pas un cas courant : le
    Dockerfile le copie explicitement (`docker/engine/Dockerfile:12`). L'ancien repli rendait
    `settings.contract_version` (défaut "2.0") et l'engine annonçait alors tranquillement « 2.0 »
    au lieu de refuser de servir. Or le garde de contrat est **MAJOR-only** : « 2.0 » et « 2.12 »
    partagent la même majeure, donc un build amputé de son fichier de version passait le
    handshake — et resolvait des payloads 2.12 en se croyant d'accord.

    Les trois endpoints échouent BRUYAMMENT quand les majeures divergent, avec ce motif écrit
    juste au-dessus d'eux : « a major bump on one side must fail loud rather than produce a
    subtly wrong plan ». Être bruyant sur le désaccord mais silencieux sur « je ne connais pas
    ma propre version » était la contradiction. On échoue donc ici aussi.
    """
    try:
        return CONTRACT_VERSION_PATH.read_text(encoding="utf-8").strip()
    except FileNotFoundError as exc:  # pragma: no cover - build cassé, pas un chemin courant
        raise RuntimeError(
            f"CONTRACT_VERSION introuvable ({CONTRACT_VERSION_PATH}) : ce build est incomplet. "
            "Ce fichier EST la version que l'engine parle au backend ; sans lui, aucune version "
            "ne peut être annoncée honnêtement. Vérifier le COPY du Dockerfile."
        ) from exc


def read_implicit_rules() -> ImplicitConstraintSyncRequest:
    try:
        return ImplicitConstraintSyncRequest.model_validate_json(
            IMPLICIT_RULES_PATH.read_text(encoding="utf-8"),
        )
    except FileNotFoundError as exc:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="implicit_rules.json not found",
        ) from exc
    except ValidationError as exc:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail="implicit_rules.json is invalid",
        ) from exc


def _day_constraint_conflict_team_ids(time_windows: list[dict[str, Any]]) -> set[str]:
    forced_days_by_team: dict[str, set[int]] = {}
    forbidden_days_by_team: dict[str, set[int]] = {}

    for constraint in time_windows or []:
        if not constraint.get("isActive", True):
            continue

        rule_type = constraint.get("ruleType") or constraint.get("rule_type")
        family = constraint.get("family")
        if rule_type == "PREFERRED" and family == "TIME":
            continue
        # LOCK is enforced as hard as HARD downstream. Aligning this set is defensive
        # only (ENG-20): its consumer just writes 0 into a min-floor that is already
        # all-zeros today (min is soft-only, ENG-18), and the ACTUAL LOCK-DAY conflict
        # enforcement already lives in add_time_window_constraints. Kept for the day a
        # hard min floor is re-activated, not for any effect today.
        if rule_type not in ("HARD", "LOCK") or family != "DAY":
            continue

        team_id = constraint.get("scope_target_id") or constraint.get("scopeTargetId")
        if team_id is None:
            continue

        team_id_text = str(team_id)
        config = constraint.get("config") or {}
        forced_days = config.get("forcedDays") or []
        forbidden_days = config.get("forbiddenDays") or []

        forced_days_by_team.setdefault(team_id_text, set()).update(int(day) for day in forced_days if day is not None)
        forbidden_days_by_team.setdefault(team_id_text, set()).update(
            int(day) for day in forbidden_days if day is not None
        )

    return {
        team_id
        for team_id, forced_days in forced_days_by_team.items()
        if forced_days & forbidden_days_by_team.get(team_id, set())
    }


def _lock_is_idle(lock: asyncio.Lock) -> bool:
    # Idle = neither held NOR awaited. Checking locked() alone is not enough:
    # during release, asyncio sets _locked=False before the woken waiter runs,
    # so a lock with a pending waiter can momentarily report not-locked. Deleting
    # it then would orphan the waiter and let a fresh request create a second
    # lock for the same club, breaking per-club serialisation (audit review).
    waiters = getattr(lock, "_waiters", None)
    return not lock.locked() and not waiters


async def get_club_lock(club_id: str) -> asyncio.Lock:
    async with _club_locks_guard:
        # Bound the dict: drop only genuinely idle locks (not held, no waiter).
        if len(_club_locks) > _MAX_CLUB_LOCKS:
            for cid in [c for c, lk in _club_locks.items() if c != club_id and _lock_is_idle(lk)]:
                del _club_locks[cid]
        lock = _club_locks.get(club_id)
        if lock is None:
            lock = asyncio.Lock()
            _club_locks[club_id] = lock
        return lock


# P5-10 — map a CP-SAT status int to the name the backend persists as
# solver_status_detail. A small explicit table (rather than solver.StatusName)
# keeps the value deterministic and mypy-clean; unknown ints degrade to UNKNOWN.
_STATUS_NAMES: dict[cp_model.CpSolverStatus, str] = {
    cp_model.OPTIMAL: "OPTIMAL",
    cp_model.FEASIBLE: "FEASIBLE",
    cp_model.INFEASIBLE: "INFEASIBLE",
    cp_model.MODEL_INVALID: "MODEL_INVALID",
    cp_model.UNKNOWN: "UNKNOWN",
}


def _read_vmrss_mb() -> float | None:
    """Process resident set size (MB) from /proc/self/status, or None off Linux.

    A module function (not inlined) so the RSS-sampler test can monkeypatch it and
    stay deterministic — no real memory has to grow on cue. Reading a fixed proc
    path is not a security-sensitive operation (no user input, no shell)."""
    try:
        with open("/proc/self/status", encoding="utf-8") as status_file:
            for line in status_file:
                if line.startswith("VmRSS:"):
                    return int(line.split()[1]) / 1024  # kB → MB
    except OSError:
        return None
    return None


class _RssSampler:
    """Poll VmRSS while the solve runs in a worker thread — the event loop is free.

    Assumed limits (documented decisions, not defects):
      1. A memory spike SHORTER than the sampling period can be missed. A long
         solve is a plateau, not a spike, so the peak we care about is well sampled.
      2. It is the WHOLE process RSS (FastAPI baseline included), not the solve's
         marginal cost — DELIBERATE: that is exactly what the container cgroup caps.
      3. A concurrent /place-matches shares the process and can inflate it a little.

    Future avenue, rejected: /sys/fs/cgroup/memory.peak is cumulative in practice
    (never reset between solves) — the same defect as ru_maxrss — so it is not used.
    """

    def __init__(self, interval_seconds: float = 0.25) -> None:
        self._interval = interval_seconds
        self.first_mb: float | None = None
        self.peak_mb: float | None = None

    async def run(self) -> None:
        # Sample IMMEDIATELY, then on cadence: even a sub-interval solve yields a
        # first (baseline) sample, so rss_before_mb is never left unset.
        while True:
            self._sample()
            await asyncio.sleep(self._interval)

    def _sample(self) -> None:
        rss = _read_vmrss_mb()
        if rss is None:
            return
        if self.first_mb is None:
            self.first_mb = rss
        self.peak_mb = rss if self.peak_mb is None else max(self.peak_mb, rss)


async def build_schedule(
    input_data: ScheduleInputSchema,
    received_at: float | None = None,
) -> ScheduleOutputSchema:
    # Convert Pydantic input to a plain dict for the solver pipeline.
    data: dict[str, Any] = input_data.model_dump(by_alias=True)

    # received_at = perf_counter() at request reception (endpoint entry). It lets
    # us charge engine_wait_ms = queue/lock/semaphore wait BEFORE the solve. Direct
    # callers (tests) omit it → wait ≈ 0, measured from here.
    if received_at is None:
        received_at = time.perf_counter()

    # Single pass with all HARD constraints (including coach rest day + salarie
    # distribution).  If the solver returns INFEASIBLE, build_result produces
    # status="failed" with conflict diagnostics — no silent constraint dropping.
    # Decision: docs/architecture/adr-0001-single-pass-solve.md
    #
    # _solve is CPU-bound (up to 650 s of CP-SAT). Run it in a worker thread so
    # the event loop stays responsive (/health answers during a solve). Solve()
    # releases the GIL and each request builds its own model/solver, so this is
    # thread-safe. A global semaphore bounds how many solves run at once.
    async with _solve_semaphore:
        logger.info("solve start club=%s teams=%d", input_data.club_id, len(input_data.teams))
        # Metrics AROUND the solve: total wall clock (both phases — see the schema
        # note), process CPU delta (honest because max_concurrent_solves=1), and an
        # RSS sampler running on the free event loop while _solve holds the thread.
        solve_start = time.perf_counter()
        engine_wait_ms = round((solve_start - received_at) * 1000)
        rusage_before = resource.getrusage(resource.RUSAGE_SELF)
        sampler = _RssSampler()
        sampler_task = asyncio.create_task(sampler.run())
        try:
            solver_status, solver, model, conflicts, solve_stats = await asyncio.to_thread(_solve, data, input_data)
        finally:
            sampler_task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await sampler_task
        total_wall_time_ms = round((time.perf_counter() - solve_start) * 1000)
        rusage_after = resource.getrusage(resource.RUSAGE_SELF)
        cpu_time_ms = round(
            ((rusage_after.ru_utime + rusage_after.ru_stime) - (rusage_before.ru_utime + rusage_before.ru_stime)) * 1000
        )

    result_dict = build_result(
        data,
        solver,
        model,
        status=solver_status,
        constraint_version=read_contract_version(),
    )
    if conflicts:
        result_dict.setdefault("diagnostics", []).extend(conflicts)

    # Merge the capacity metrics into the metrics dict before schema validation
    # (all optional fields; snake_case keys validate via populate_by_name). solve_stats
    # carries workers/budget_seconds/solver_status_detail/nb_conflicts from _solve.
    result_dict["metrics"].update(
        {
            "total_wall_time_ms": total_wall_time_ms,
            "cpu_time_ms": cpu_time_ms,
            "engine_wait_ms": engine_wait_ms,
            "peak_rss_mb": sampler.peak_mb,
            "rss_before_mb": sampler.first_mb,
            **solve_stats,
        }
    )

    logger.info(
        "solve done club=%s status=%s slots=%d",
        input_data.club_id,
        result_dict.get("status"),
        len(result_dict.get("slots", [])),
    )
    # Validate and return.
    return ScheduleOutputSchema.model_validate(result_dict)


# Hard cap (seconds) on the phase-2 chaining optimisation. Placement is already
# optimal and locked by then, so this only bounds how long we polish the small
# back-to-back bonus — best-effort, never at the expense of placement or budget.
CHAINING_PHASE_MAX_SECONDS = 10


def _adaptive_timeout(n_teams: int, n_venues: int, payload_cap: int) -> int:
    """Scale the solve budget to problem size, capped by the payload budget.

    complexity = n_teams * n_venues → small problems return fast instead of
    burning the full 650 s ceiling. Tiers: ≤50 → 60 s · ≤200 → 180 s · else
    600 s. ``payload_cap`` (``solver_timeout_seconds``) is the hard ceiling:
    the manager can never be made to wait longer than they asked for.
    """
    complexity = n_teams * n_venues
    if complexity <= 50:
        adaptive = 60
    elif complexity <= 200:
        adaptive = 180
    else:
        adaptive = 600
    return min(adaptive, payload_cap)


# The single default worker's objective bound stays hopelessly loose on dense,
# soft-preference-rich problems (e.g. 49 teams with 55 soft venue preferences):
# it FINDS the optimal placement in ~2 s but then fails to PROVE it, burning the
# whole adaptive budget (measured: 612 s, gap never closes). CP-SAT's 8-worker
# portfolio includes the bound-tightening worker that closes the proof in ~2 s
# with an identical objective. Small problems keep 1 worker so their solve stays
# bit-for-bit reproducible (the golden fixtures depend on it); only the top
# complexity tier — where the stall lives and speed matters — pays the
# multi-worker cost (the optimal *value* is stable run-to-run; the exact
# equally-optimal assignment may differ, which is why the large golden fixtures
# assert score + slot count, not exact placement).
LARGE_PROBLEM_WORKERS = 8


def _adaptive_workers(n_teams: int, n_venues: int) -> int:
    """Number of CP-SAT search workers, scaled to problem size (see above).

    Mirrors the ``_adaptive_timeout`` tiers: ≤200 complexity → 1 (deterministic,
    already fast), else → 8 (fast optimality proof on the stall-prone tier).
    """
    return 1 if n_teams * n_venues <= 200 else LARGE_PROBLEM_WORKERS


def _solve(
    data: dict[str, Any],
    input_data: ScheduleInputSchema,
) -> tuple[int, cp_model.CpSolver, ScheduleCpModel, list[dict[str, Any]], dict[str, Any]]:
    """Run the solver pipeline: build model, add constraints, solve.

    Returns (status, solver, model, conflicts, solve_stats).  All HARD constraints
    are active — no fallback pass that silently drops rest-day or distribution
    constraints.  Uses the full ``solver_timeout_seconds``. ``solve_stats`` carries
    the P5-10 capacity metrics known here: the workers/budget actually posted, and
    the PHASE-1 (real solve) status + conflict count.
    """
    model: ScheduleCpModel = build_model(data)

    parsed = parse_v2_constraints(data.get("constraints", []))
    team_coach_map: dict[str, list[str]] = parsed.get("team_coach_map", {})
    # ENG-17 — le builder de résultat en a besoin pour nommer le coach des séances
    # GÉNÉRÉES : on la POSE sur le modèle (idiome `locked_slots`/`slot_durations`)
    # plutôt que de re-parser les contraintes une seconde fois.
    model.team_coach_map = team_coach_map
    team_player_map: dict[str, list[str]] = parsed.get("team_player_map", {})
    # ENG-17 idiome : le builder de résultat lit ces cartes ET le réglage implicite depuis
    # le MODÈLE pour diagnostiquer au MÊME grain que la pose (coachs/joueurs des contraintes,
    # jamais slot.coachId). Résoudre ici = source unique du réglage pose ⇄ diagnostic.
    model.team_player_map = team_player_map
    resolved_implicit_rules = resolve_implicit_rules(data.get("implicitRules"))
    model.implicit_rules = resolved_implicit_rules
    # P4-99 — les sources de contraintes (gymnase forcé, indispo coach) sur le MODÈLE, pour que
    # les sites de pose nomment la contrainte fermant un candidat sans re-parser (décision B).
    model.forced_venue_sources = parsed.get("forced_venue_sources", {})
    model.coach_unavailability_sources = parsed.get("coach_unavailability_sources", {})

    # (La famille FACILITY_CAPACITY rabotait ici la capacité d'un gymnase entier
    # à `maxTeams`. RETIRÉE le 2026-08-08 : aucun chemin UI ne la créait, zéro
    # ligne en base. La capacité se règle par CRÉNEAU — `trainingSlots.capacity`,
    # que le backend borne déjà à 1 pour un gymnase non divisible.)

    locked_slots_by_team: dict[str, int] = {}
    for locked_slot in model.locked_slots:
        locked_team_id: str | None = locked_slot.get("team_id")
        if locked_team_id:
            locked_slots_by_team[locked_team_id] = locked_slots_by_team.get(locked_team_id, 0) + 1

    # Identify teams whose sessionsPerWeek is fully covered by HARD locks.
    # These teams must NOT receive the -UNPLACED_PENALTY term in the objective
    # because their solver variables are forced to 0 by remaining_sessions,
    # not because they are genuinely unplaced.
    hard_satisfied_team_ids: set[str] = set()
    for team in data.get("teams", []):
        team_id = team.get("id")
        sessions_per_week = team.get("sessions_per_week") or team.get("sessionsPerWeek")
        if (
            team_id
            and sessions_per_week
            and is_team_satisfied_by_hard_locks(str(team_id), model.locked_slots, int(sessions_per_week))
        ):
            hard_satisfied_team_ids.add(str(team_id))

    # Hard min_sessions forces UNKNOWN when venue capacity < total sessions needed.
    # Soft-only via objective bonus (session_count:20) + WARNING diagnostics.
    adjusted_min_by_team: dict[str, int] = {
        str(team.get("id") or ""): 0 for team in data.get("teams", []) if team.get("id")
    }

    available_assignments_by_team: dict[str, list[Any]] = {}
    for slot_key, var in model.x.items():
        team_id = slot_key[0]
        available_assignments_by_team.setdefault(team_id, []).append(var)

    for team in data.get("teams", []):
        team_id = team.get("id")
        max_sessions = team.get("sessions_per_week") or team.get("sessionsPerWeek")
        if team_id and max_sessions and not available_assignments_by_team.get(team_id, []):
            adjusted_min_by_team[str(team_id)] = 0

    for team_id in _day_constraint_conflict_team_ids(parsed["time_windows"]):
        adjusted_min_by_team[team_id] = 0

    # Build assignments from model.x with start/end for consecutive-session constraints.
    # Each (team, venue, day, slot) appears exactly ONCE — no per-coach duplication.
    # Player info is passed separately via team_player_map. The team's MAIN coach
    # (first entry of team_coach_map after the role filter) is attached so the
    # chaining bonus can reward back-to-back sessions of the same coach.
    assignments: list[dict[str, Any]] = []
    for slot_key, var in model.x.items():
        team_id_str = str(slot_key[0])
        venue_id_str = str(slot_key[1])
        day_of_week = slot_key[2]
        slot_start = slot_key[3]
        slot_id = f"{day_of_week}:{slot_start}"

        vsk = (venue_id_str, day_of_week, slot_start)
        duration = model.slot_durations.get(vsk, DEFAULT_SESSION_MINUTES)
        start_minutes = _time_to_minutes(slot_start)
        end_minutes = start_minutes + duration

        team_coaches = team_coach_map.get(team_id_str) or []
        main_coach_id = team_coaches[0] if team_coaches else None

        assignments.append(
            {
                "var": var,
                "team_id": team_id_str,
                "venue_id": venue_id_str,
                "slot_id": slot_id,
                "start": start_minutes,
                "end": end_minutes,
                "coach_id": main_coach_id,
            }
        )

    hard_stats = add_level_1_hard_constraints(
        model,
        assignments,
        teams=data.get("teams", []),
        coaches=data.get("coaches", []),
        forbidden_assignments=parsed["forbidden_assignments"],
        coach_unavailability=parsed["coach_unavailability"],
        forced_venues=parsed["forced_venues"],
        priority_tiers=parsed.get("priority_tiers", {}),
        min_sessions_by_team=adjusted_min_by_team or None,
        implicit_rules=resolved_implicit_rules,
        team_coach_map=team_coach_map,
        team_player_map=team_player_map,
        shared_trainings=data.get("sharedTrainings", []),
        team_links=data.get("teamLinks", []),
        venue_travel_times=data.get("venueTravelTimes", []),
    )

    _time_window_added, conflicts = add_time_window_constraints(model, model.x, parsed["time_windows"])
    _vm_added, vm_conflicts = add_venue_minimum_constraints(model, model.x, parsed.get("venue_minimums", []))
    # Parse-time "constraint not honored" warnings (target-less scope, coach
    # ruleType coerced…) ride the same diagnostics channel as hard conflicts.
    conflicts = [*conflicts, *vm_conflicts, *parsed.get("parse_warnings", [])]

    # P2-9 — dire ce qu'un verrou HARD a écrasé. Le créneau verrouillé n'a PAS de
    # variable (model.py), donc aucune des contraintes ci-dessus ne pouvait le
    # toucher : sans ce diagnostic, le solveur renvoyait `completed` sans un mot
    # sur une réservation posée le jour de congé du coach.
    conflicts = [
        *conflicts,
        *diagnose_locked_slot_violations(
            model.locked_slots,
            parsed,
            team_names={str(t.get("id")): str(t.get("name") or t.get("id")) for t in data.get("teams", [])},
            coach_names={str(c.get("id")): _coach_label(c) for c in data.get("coaches", [])},
            venue_names={str(v.get("id")): str(v.get("name") or v.get("id")) for v in data.get("venues", [])},
        ),
    ]

    assignments_by_team: dict[str, list[Any]] = {}
    for slot_key, var in model.x.items():
        team_id = slot_key[0]
        assignments_by_team.setdefault(team_id, []).append(var)

    # « Combien de séances reste-t-il à placer pour cette équipe ? » — SOURCE UNIQUE :
    # max(0, spw − verrous HARD). Elle BORNE le nombre de séances placées (ci-dessous) ET
    # calibre le malus missing_session (add_missing_session_penalty, V10) ; les deux doivent
    # parler du même reste, sinon le solveur optimise une définition et est borné par une autre.
    remaining_by_team: dict[str, int] = {}
    for team in data.get("teams", []):
        team_id = team.get("id")
        max_sessions = team.get("sessions_per_week") or team.get("sessionsPerWeek")
        if team_id and max_sessions:
            remaining_by_team[str(team_id)] = max(0, int(max_sessions) - locked_slots_by_team.get(team_id, 0))

    for team_id, team_vars in assignments_by_team.items():
        remaining = remaining_by_team.get(str(team_id))
        if remaining is not None and team_vars:
            cast(Any, model).Add(sum(team_vars) <= remaining)

    # Add objective function.
    # PR B — SET of preferred venues per team + soft "avoid this venue" MALUS (ENG-11) : le sens
    # vit désormais dans une MAISON UNIQUE (D-6, P2-32) partagée avec l'évaluation des compromis.
    soft_terms = add_venue_preference_bonus(model.x, parsed)

    soft_terms.extend(add_preferred_day_bonus(model, model.x, parsed["time_windows"], LEVEL_2_OBJECTIVE_WEIGHTS))
    soft_terms.extend(add_preferred_time_bonus(model, model.x, parsed["time_windows"], LEVEL_2_OBJECTIVE_WEIGHTS))
    soft_terms.extend(add_match_day_rest_bonus(model, model.x, data.get("teams", []), LEVEL_2_OBJECTIVE_WEIGHTS))
    soft_terms.extend(add_spacing_penalty(model, model.x, data.get("teams", []), LEVEL_2_OBJECTIVE_WEIGHTS))
    # P4-51 — le plafond de jours d'un coach (préféré, jamais dur) : voir add_coach_day_cap_penalty.
    soft_terms.extend(
        add_coach_day_cap_penalty(model, model.x, data.get("coaches", []), team_coach_map, LEVEL_2_OBJECTIVE_WEIGHTS)
    )
    # Littéraux de violation des règles implicites passées PREFERRED (poids −6). Vide quand
    # tout est HARD (défaut) : objectif byte-identique à l'ancien contrat dans ce cas.
    soft_terms.extend(hard_stats.implicit_soft_terms)
    # V10 — LE REMPLISSAGE PRIME SUR LE CONFORT : un malus −1000 par séance sous le quota
    # (remaining_by_team = même reste que la borne ci-dessus). Les équipes satisfaites par
    # verrous HARD n'en reçoivent pas (comme pour UNPLACED_PENALTY).
    soft_terms.extend(
        add_missing_session_penalty(
            model,
            assignments_by_team,
            remaining_by_team,
            LEVEL_2_OBJECTIVE_WEIGHTS,
            hard_satisfied_team_ids=hard_satisfied_team_ids,
        )
    )

    # Lot PASSERELLES PR-2 — malus des passerelles PREFERRED (poids dérivé du tier), termes déjà
    # pondérés repliés dans le PLACEMENT (phase 1). Vide/tout MANDATORY ⇒ [] (chemin byte-identique).
    team_link_penalty_terms = add_team_link_penalty(
        model,
        assignments,
        team_links=data.get("teamLinks", []),
        shared_trainings=data.get("sharedTrainings", []),
        teams=data.get("teams", []),
    )

    # P2-53 RMM-8 PR-2 — battement de trajet PREFERRED : malus SOFT (−6) par enchaînement au
    # battement trop court, plié dans le PLACEMENT (phase 1, patron passerelle PREFERRED). Ne
    # produit des termes QUE si la règle est active ET PREFERRED (le MANDATORY est dur ci-dessus).
    # Matrice absente / règle inactive / MANDATORY ⇒ [] (chemin byte-identique).
    travel_battement_terms: list[Any] = []
    if resolved_implicit_rules.travel_time_active and resolved_implicit_rules.travel_time_intensity != "MANDATORY":
        travel_battement_terms = add_travel_time_penalty(
            model,
            assignments,
            coaches=data.get("coaches", []),
            team_links=data.get("teamLinks", []),
            team_coach_map=team_coach_map,
            venue_travel_times=data.get("venueTravelTimes", []),
            default_minutes=resolved_implicit_rules.travel_time_default_minutes,
        )

    # Phase 1 installs the PLACEMENT objective only; the chaining terms are built
    # into the model but kept out of the objective (apply_chaining=False) so their
    # tiny coefficients never wreck the placement optimality proof.
    objective_stats = add_level_2_objective(
        model,
        assignments,
        teams=data.get("teams", []),
        soft_terms=soft_terms,
        hard_satisfied_team_ids=hard_satisfied_team_ids,
        apply_chaining=False,
        extra_placement_terms=[*team_link_penalty_terms, *travel_battement_terms],
        # A person chains a back-to-back pair as coach OR as player of the team;
        # the map is built once from the constraint links (l.446) and the chaining
        # terms are built once here, so phase 2 (which reuses chaining_terms) is
        # covered too.
        team_player_map=team_player_map,
    )

    # P3-21 — termes de stabilité (convergence). Une clé absente de model.x (créneau HARD, ou
    # créneau qu'aucune training-slot ne porte) est ignorée : jamais de double paiement d'un
    # pin. Vide/absent ⇒ [] ⇒ phase 2 inchangée (chemin byte-identique).
    stability_terms = build_stability_terms(model.x, data.get("previousAssignments", []))

    # P2-53 RMM-8 PR-2 — DÉPARTAGE « moindre trajet » : malus FAIBLE (−1×palier) par enchaînement
    # cross-gymnase réalisé, quel que soit le cran. Il vit dans la SOUS-BANDE de PHASE 2 (comme la
    # stabilité, SOUS le chaînage ×4096) : le placement est verrouillé à son optimum de phase 1, le
    # départage n'ordonne donc QUE des ex æquo exacts (arbitrage fondateur « en cas d'égalité,
    # jamais dominant »). Matrice absente / règle inactive ⇒ [] ⇒ phase 2 byte-identique.
    travel_departage_terms: list[Any] = []
    if resolved_implicit_rules.travel_time_active:
        travel_departage_terms = add_travel_departage_penalty(
            model,
            assignments,
            coaches=data.get("coaches", []),
            team_links=data.get("teamLinks", []),
            team_coach_map=team_coach_map,
            venue_travel_times=data.get("venueTravelTimes", []),
            default_minutes=resolved_implicit_rules.travel_time_default_minutes,
        )

    # Adaptive timeout capped by the payload budget.
    n_teams = len(data.get("teams") or [])
    n_venues = len(data.get("venues") or [])
    timeout_seconds = _adaptive_timeout(n_teams, n_venues, input_data.solver_timeout_seconds)
    workers = _adaptive_workers(n_teams, n_venues)

    # --- Phase 1: solve for the optimal placement (fast, chaining excluded). ---
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = timeout_seconds
    solver.parameters.random_seed = input_data.solver_seed
    solver.parameters.num_search_workers = workers
    status = solver.Solve(model)

    # P5-10 — snapshot the PHASE-1 diagnostics NOW, before phase 2 chaining may
    # reassign solver/status: nb_conflicts and the status detail must describe the
    # real placement solve, not the tiny back-to-back polish (which caps at 10 s).
    solve_stats: dict[str, Any] = {
        "workers": workers,
        "budget_seconds": timeout_seconds,
        "solver_status_detail": _STATUS_NAMES.get(status, "UNKNOWN"),
        "nb_conflicts": int(solver.NumConflicts()),
    }

    # --- Phase 2: lock the placement quality, then optimise the chaining bonus
    # under a hard time cap. Proving chaining-optimality can be slow, so we bound
    # it and keep the best-effort result — placement stays optimal either way.
    # P3-21 — phase 2 is now ALSO entered when only stability terms exist (chaining
    # empty): `chaining_terms OR stability_terms`. Without previousAssignments this
    # reduces to the historical `chaining_terms` condition (byte-identical). ---
    # P2-53 RMM-8 PR-2 — la phase 2 s'ouvre AUSSI quand seul le départage de trajet existe (comme
    # pour la stabilité). Sans trajet ni stabilité, la condition se réduit à l'historique
    # `chaining_terms` (byte-identique).
    low_band_terms = [*stability_terms, *travel_departage_terms]
    if status in (cp_model.OPTIMAL, cp_model.FEASIBLE) and (objective_stats.chaining_terms or low_band_terms):
        placement_optimum = int(solver.ObjectiveValue())
        cast(Any, model).Add(objective_stats.placement_expression >= placement_optimum)
        # Warm-start phase 2 with the placement-optimal solution so it always has
        # at least that (chaining ≥ 0) to return, even if the cap fires early.
        for phase1_var in model.x.values():
            cast(Any, model).AddHint(phase1_var, solver.Value(phase1_var))
        chaining_expr = sum(weight * var for var, weight in objective_stats.chaining_terms)
        if low_band_terms:
            # Séparation LEXICOGRAPHIQUE : placement (verrouillé ci-dessus) > chaînage (×K) >
            # SOUS-BANDE (stabilité de convergence + départage de trajet). K =
            # CHAINING_STABILITY_MULTIPLIER > masse max de la sous-bande (voir la preuve dans
            # objective.py ; le départage y ajoute un terme de grain 1 borné, sous la même barre) :
            # la sous-bande ne départage QUE les ex æquo exacts de placement ET de chaînage.
            low_band_expr = sum(weight * var for var, weight in low_band_terms)
            cast(Any, model).Maximize(
                objective_stats.placement_expression + CHAINING_STABILITY_MULTIPLIER * chaining_expr + low_band_expr
            )
        else:
            # previousAssignments + trajet vides/absents ⇒ objectif phase 2 STRICTEMENT historique.
            cast(Any, model).Maximize(objective_stats.placement_expression + chaining_expr)
        phase2_solver = cp_model.CpSolver()
        phase2_solver.parameters.max_time_in_seconds = min(timeout_seconds, CHAINING_PHASE_MAX_SECONDS)
        phase2_solver.parameters.random_seed = input_data.solver_seed
        phase2_solver.parameters.num_search_workers = workers
        phase2_status = phase2_solver.Solve(model)
        if phase2_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
            solver, status = phase2_solver, phase2_status
        if low_band_terms:
            # Score RAPPORTÉ aux poids d'ORIGINE, SOUS-BANDE EXCLUE (stabilité de convergence ET
            # départage de trajet) : le placement est verrouillé à `placement_optimum` (phase 1 =
            # placement seul), le chaînage se lit aux poids naturels sur le solveur final. C'est
            # exactement ce qu'aurait rapporté une phase 2 SANS sous-bande — ni la stabilité ni le
            # départage ne modifient donc le score. Sans sous-bande on ne touche à rien :
            # `result_builder` lit `ObjectiveValue()` tel quel (byte-identique).
            model.reported_score_override = placement_optimum + sum(
                int(weight) * int(solver.Value(var)) for var, weight in objective_stats.chaining_terms
            )

    return status, solver, model, conflicts, solve_stats


@app.get("/health")
async def health() -> dict[str, str]:
    return {"status": "ok"}


@app.post("/generate", response_model=ScheduleOutputSchema)
async def generate_schedule(input_data: ScheduleInputSchema) -> ScheduleOutputSchema:
    # P5-10 — stamp reception NOW so engine_wait_ms captures the club-lock +
    # semaphore wait that follows (queued-behind-a-600 s-solve is the case we want).
    received_at = time.perf_counter()
    # ENG-14: reject a payload whose contract MAJOR the engine does not speak,
    # instead of silently solving against a schema it may misread. The contract
    # is manually synced (no codegen), so a major bump on one side must fail
    # loud rather than produce a subtly wrong plan.
    contract_version = read_contract_version()
    if input_data.version.split(".")[0] != contract_version.split(".")[0]:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT,
            detail=f"Unsupported contract version {input_data.version!r}; engine speaks {contract_version}.",
        )

    lock = await get_club_lock(input_data.club_id)
    async with lock:
        return await build_schedule(input_data, received_at)


@app.post("/place-matches", response_model=MatchPlacementOutputSchema)
async def place_matches(input_data: MatchPlacementInputSchema) -> MatchPlacementOutputSchema:
    """P1-4 PR D (ADR-0003) — the dated match-placement solve, SEPARATE from the
    weekly /generate problem. Same MAJOR-only contract check; the club lock is
    prefixed so a long weekly solve never blocks a 3-second placement — et depuis
    AUD-ENG-30 le SÉMAPHORE l'est aussi, sans quoi le verrou préfixé ne servait à rien."""
    contract_version = read_contract_version()
    if input_data.version.split(".")[0] != contract_version.split(".")[0]:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT,
            detail=f"Unsupported contract version {input_data.version!r}; engine speaks {contract_version}.",
        )

    lock = await get_club_lock(f"matches:{input_data.club_id}")
    async with lock, _placement_semaphore:
        logger.info("match placement start club=%s matches=%d", input_data.club_id, len(input_data.matches))
        result = await asyncio.to_thread(solve_match_placement, input_data)
    return MatchPlacementOutputSchema.model_validate(result)


@app.post("/validate-assignments", response_model=ValidateAssignmentsOutputSchema)
async def validate_assignments(input_data: ValidateAssignmentsInputSchema) -> ValidateAssignmentsOutputSchema:
    """P2-2 F2a — verdict moteur sur UN candidat de déplacement (mono-candidat).

    Le reste du planning est FIGÉ via ``add_fixed_slots`` ; on épingle le candidat
    et on demande au solveur si le modèle HARD reste faisable. Réponse booléenne du
    MOTEUR + règles cassées NOMMÉES pour l'UI. Même garde de version MAJOR-only que
    les deux autres endpoints (un seul contrat). Le verrou club est préfixé pour ne
    jamais s'asseoir derrière un solve hebdomadaire, et depuis AUD-ENG-33 le rail a son
    PROPRE sémaphore (l'appel est court — baseline figée, un seul candidat — mais il abandonne
    à 20 s côté client : le faire attendre derrière un placement de 30 s le condamnait)."""
    contract_version = read_contract_version()
    if input_data.version.split(".")[0] != contract_version.split(".")[0]:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_CONTENT,
            detail=f"Unsupported contract version {input_data.version!r}; engine speaks {contract_version}.",
        )

    lock = await get_club_lock(f"validate:{input_data.club_id}")
    async with lock, _verdict_semaphore:
        result = await asyncio.to_thread(validate_assignment, input_data, contract_version=contract_version)
    return ValidateAssignmentsOutputSchema.model_validate(result)


@app.post("/implicit-constraints")
async def sync_implicit_constraints(input_data: ImplicitConstraintSyncRequest) -> JSONResponse:
    engine_rules = read_implicit_rules()
    backend_rules = sorted(rule.name for rule in input_data.rules if rule.enabled)
    engine_enabled_rules = sorted(rule.name for rule in engine_rules.rules if rule.enabled)
    missing_in_engine = sorted(set(backend_rules) - set(engine_enabled_rules))
    missing_in_backend = sorted(set(engine_enabled_rules) - set(backend_rules))

    # D-43 — ne comparer que les NOMS laissait passer une contradiction de fond : `MIN_SESSIONS`
    # etait decrite comme un plancher dur cote backend alors que le solveur n'en fait qu'une
    # cible (ENG-18). Deux cotes d'accord sur la liste, en desaccord sur ce qu'elle veut dire :
    # l'endpoint repondait « synchronized » sur un mensonge.
    engine_descriptions = {rule.name: rule.description for rule in engine_rules.rules if rule.enabled}
    backend_descriptions = {rule.name: rule.description for rule in input_data.rules if rule.enabled}
    contradicting = sorted(
        name
        for name, description in backend_descriptions.items()
        if name in engine_descriptions and engine_descriptions[name] != description
    )

    if not missing_in_engine and not missing_in_backend and not contradicting:
        return JSONResponse(
            status_code=status.HTTP_200_OK,
            content={"status": "synchronized", "rules_count": len(engine_enabled_rules)},
        )

    return JSONResponse(
        status_code=status.HTTP_409_CONFLICT,
        content={
            "status": "desynchronized",
            "backend_rules": backend_rules,
            "engine_rules": engine_enabled_rules,
            "missing_in_engine": missing_in_engine,
            "missing_in_backend": missing_in_backend,
            "contradicting_descriptions": contradicting,
        },
    )


@app.get("/")
async def root() -> dict[str, str]:
    return {"status": "ok", "contract_version": read_contract_version()}


def _coach_label(coach: dict[str, Any]) -> str:
    """Prénom + nom pour un message lisible ; à défaut, l'identifiant (P2-9)."""
    first = str(coach.get("first_name") or coach.get("firstName") or "").strip()
    last = str(coach.get("last_name") or coach.get("lastName") or "").strip()
    full = f"{first} {last}".strip()
    return full or str(coach.get("id"))
