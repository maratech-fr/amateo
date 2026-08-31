"""Shared test harness that drives the REAL production pipeline.

The engine's production entry point is ``app.main.build_schedule`` (async). The
older per-test ``_run_pipeline`` copies re-implemented a *different* pipeline
(no ``parse_v2_constraints``, coach via slotTemplates, single-pass, full
sessions bound) so they could pass while the real path silently ignored
constraints. Every test now routes through ``solve_payload`` so a green test
means the production solver actually honours the input.

``make_payload`` builds a payload in the exact shape the backend emits
(``ScheduleConstraintBuilder::serializeUnifiedConstraints`` — nested ``config``,
plus the v1 ``type``/``metadata`` coach constraints), so semantic tests exercise
the true contract — ``version`` inclus, lu depuis ``CONTRACT_VERSION`` (ENG-26 :
il était figé à ``"1.0"``, que le garde de ``POST /generate`` refuse en 422).
"""

from __future__ import annotations

import asyncio
from typing import Any

from app.main import build_schedule, read_contract_version
from app.schemas.input_schema import ScheduleInputSchema


def solve_payload(data: dict[str, Any], *, timeout: int | None = None) -> dict[str, Any]:
    """Run a raw payload dict through the production pipeline, return the output dict.

    Mirrors ``POST /generate`` exactly minus the FastAPI/lock layer:
    ScheduleInputSchema → build_schedule → ScheduleOutputSchema → dict JSON.

    ⚑ AUD-ENG-28 — ``mode="json"`` n'est pas cosmétique. Sans lui, ``model_dump`` rend des
    objets PYTHON (``datetime.time(18, 0)``) là où l'API rend des chaînes (``"18:00:00"``) :
    le harnais qui promet « exactement POST /generate » livrait un type que le backend ne
    voit jamais. Un test comparant ``slot["startTime"]`` à ``"18:00"`` échouait alors pour
    une raison qui n'existe pas en production — et, dans l'autre sens, un test aurait pu
    passer sur une comparaison d'objets que la vraie réponse JSON n'aurait jamais permise.

    FastAPI sérialise ; un harnais qui court-circuite FastAPI doit sérialiser aussi.
    """
    payload = dict(data)
    if timeout is not None:
        payload["solverTimeoutSeconds"] = timeout
    output = asyncio.run(build_schedule(ScheduleInputSchema.model_validate(payload)))
    return output.model_dump(mode="json", by_alias=True)


def as_validate_payload(data: dict[str, Any]) -> dict[str, Any]:
    """Shim de CONFORT des tests du verdict : traduit la saisie SINGULIÈRE historique
    (``candidate`` / ``reference``) vers la forme de contrat 2.18 (``candidates`` / ``references``,
    des LISTES). Le schéma de production n'accepte QUE la forme liste (``extra="forbid"``, aucun
    champ mort de compat gardé — décision de forme (a), bump 2.18) ; ce shim vit CÔTÉ TESTS pour ne
    pas réécrire des dizaines de payloads mono-candidat existants. Les tests N-candidats du
    déplacement de bloc, eux, écrivent ``candidates=[...]`` en clair (la vraie forme du contrat).

    Idempotent : un payload déjà en ``candidates`` traverse sans changement.
    """
    payload = dict(data)
    if "candidate" in payload:
        payload["candidates"] = [payload.pop("candidate")]
    if "reference" in payload:
        payload["references"] = [payload.pop("reference")]
    return payload


def make_venue(
    venue_id: str,
    slots: list[tuple[int, str]],
    *,
    duration_minutes: int = 90,
    capacity: int = 1,
) -> dict[str, Any]:
    """A venue with training slots. ``slots`` = list of (dayOfWeek, "HH:MM")."""
    return {
        "id": venue_id,
        "name": venue_id,
        "isActive": True,
        "trainingSlots": [
            {
                "dayOfWeek": day,
                "startTime": start,
                "durationMinutes": duration_minutes,
                "capacity": capacity,
            }
            for day, start in slots
        ],
    }


def team_constraint(
    *,
    constraint_id: str,
    team_id: str,
    family: str,
    rule_type: str,
    config: dict[str, Any],
    name: str = "test constraint",
) -> dict[str, Any]:
    """A v2 unified constraint scoped to a team (backend serializeUnifiedConstraints shape)."""
    return {
        "id": constraint_id,
        "scope": "TEAM",
        "scopeTargetId": team_id,
        "family": family,
        "ruleType": rule_type,
        "name": name,
        "config": config,
        "sortOrder": 0,
        "isActive": True,
    }


def team_coach(constraint_id: str, team_id: str, coach_id: str, *, role: str = "MAIN") -> dict[str, Any]:
    """A v1 TEAM_COACH constraint (backend serializeTeamCoachConstraints shape)."""
    return {
        "id": constraint_id,
        "teamId": team_id,
        "type": "TEAM_COACH",
        "severity": "HARD",
        "value": coach_id,
        "metadata": {"coachId": coach_id, "role": role, "isRequired": True},
    }


def coach_availability(
    constraint_id: str,
    coach_id: str,
    *,
    unavailable_days: list[int] | None = None,
    available_days: list[int] | None = None,
    from_time: str | None = None,
    until_time: str | None = None,
) -> dict[str, Any]:
    """A COACH_AVAILABILITY constraint scoped to a coach.

    AUD-ENG-29 (2026-08-09) — le config ne porte PLUS ``coachId``. Le coach est identifié
    par ``scopeTargetId``, et ``coachId`` en était un doublon supprimé par SEC-13 : le
    backend refuse aujourd'hui toute clé hors liste (`ConstraintConfigValidator::errors`),
    donc un payload réel la portant prendrait 422. Le harnais se dit « contract-accurate » ;
    il émettait un payload que l'API rejetterait.

    Zéro effet sur le solveur — il n'a jamais lu que ``scopeTargetId`` pour cette famille.
    C'est une dérive de FIDÉLITÉ : un harnais qui ment sur le contrat fait passer des tests
    pour une preuve qu'ils ne sont pas.

    ``from_time``/``until_time`` = la FENÊTRE horaire : absente, la règle vaut
    pour la journée entière (comportement d'origine).
    """
    config: dict[str, Any] = {}

    if unavailable_days is not None:
        config["unavailableDays"] = unavailable_days
    if available_days is not None:
        config["availableDays"] = available_days
    if from_time is not None:
        config["fromTime"] = from_time
    if until_time is not None:
        config["untilTime"] = until_time
    return {
        "id": constraint_id,
        "scope": "COACH",
        "scopeTargetId": coach_id,
        "family": "COACH_AVAILABILITY",
        "ruleType": "HARD",
        "name": "coach availability",
        "config": config,
        "sortOrder": 0,
        "isActive": True,
    }


def make_payload(
    *,
    teams: list[dict[str, Any]],
    venues: list[dict[str, Any]],
    constraints: list[dict[str, Any]] | None = None,
    slot_templates: list[dict[str, Any]] | None = None,
    priority_tiers: list[dict[str, Any]] | None = None,
    coaches: list[dict[str, Any]] | None = None,
    seed: int = 42,
    timeout: int = 30,
    implicit_rules: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Assemble a minimal but contract-accurate payload."""
    return {
        "clubId": "test-club",
        "seasonId": "test-season",
        # ENG-26 — la version RÉELLE du contrat, pas un "1.0" figé. `solve_payload`
        # court-circuite la couche FastAPI, donc le garde de version ne tourne pas
        # en test : le harnais envoyait tranquillement un payload que la PROD
        # rejette en 422 (MAJOR différent), tout en promettant, docstring à
        # l'appui, « la forme exacte que le backend émet ». Des tests sémantiques
        # verts sur une enveloppe que personne n'accepterait.
        "version": read_contract_version(),
        "solverSeed": seed,
        "solverTimeoutSeconds": timeout,
        "venues": venues,
        "teams": teams,
        "coaches": coaches or [],
        "slotTemplates": slot_templates or [],
        "constraints": constraints or [],
        # P2-42 — bloc OPTIONNEL : absent, le payload est byte-identique à ce qu'il était
        # (les règles implicites retombent alors sur leurs défauts historiques). Ne le
        # poser que lorsqu'un test règle explicitement une règle.
        **({"implicitRules": implicit_rules} if implicit_rules else {}),
        "priorityTiers": priority_tiers
        or [
            {"id": 1, "label": "S", "orToolsWeight": 10000, "defaultMinSessions": 2},
            {"id": 2, "label": "A", "orToolsWeight": 1000, "defaultMinSessions": 2},
            {"id": 3, "label": "B", "orToolsWeight": 100, "defaultMinSessions": 2},
            {"id": 4, "label": "C", "orToolsWeight": 10, "defaultMinSessions": 2},
            {"id": 5, "label": "D", "orToolsWeight": 1, "defaultMinSessions": 1},
        ],
    }


def make_team(
    team_id: str,
    *,
    sessions_per_week: int = 1,
    priority_tier_id: int = 3,
    match_day: int | None = None,
) -> dict[str, Any]:
    team: dict[str, Any] = {
        "id": team_id,
        "sportCategoryId": "cat",
        "priorityTierId": priority_tier_id,
        "name": team_id,
        "sessionsPerWeek": sessions_per_week,
        "isActive": True,
    }
    if match_day is not None:
        team["matchDay"] = match_day
    return team
