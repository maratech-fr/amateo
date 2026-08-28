"""Level-2 objective — lecture/normalisation des assignments et des tiers (paquet ENG-39).

Extrait tel quel de l'ancien monolithe ``objective.py`` (déplacement pur, ENG-39). Dépend de
``weights`` (tables d'alias/champs) et du ``helpers`` du paquet solveur ; ne dépend NI de
``terms`` NI de l'agrégateur.
"""

from __future__ import annotations

from collections.abc import Iterable, Mapping, Sequence
from typing import Any

from ..helpers import assignment_team_id, assignment_var, get_field, scalar_id
from .weights import (
    _BONUS_FIELD_ALIASES,
    _EXPLICIT_BONUS_FIELDS,
    _MISSING,
    _ONE_BASED_TIER_IDS,
    _PRIORITY_RANK_FIELDS,
    _PRIORITY_TIER_FIELDS,
    _TIER_ALIASES,
    _ZERO_BASED_TIER_RANKS,
    BONUS_WEIGHT_NAMES,
)

AssignmentLike = Any
BoolVarLike = Any


def _normalise_assignments(assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike]) -> list[AssignmentLike]:
    if isinstance(assignments, Mapping):
        return [_assignment_from_mapping_item(key, value) for key, value in assignments.items()]
    return list(assignments)


def _assignment_from_mapping_item(key: Any, variable: BoolVarLike) -> Mapping[str, Any]:
    if isinstance(key, tuple):
        values = list(key)
        return {
            "var": variable,
            "team_id": str(values[0]) if len(values) > 0 and values[0] is not None else None,
            "slot_id": str(values[1]) if len(values) > 1 and values[1] is not None else None,
            "venue_id": str(values[2]) if len(values) > 2 and values[2] is not None else None,
            "coach_id": str(values[3]) if len(values) > 3 and values[3] is not None else None,
            "session_id": str(values[4]) if len(values) > 4 and values[4] is not None else None,
            "id": ":".join(str(value) for value in values),
        }
    return {"var": variable, "id": str(key)}


def _teams_by_id(teams: Iterable[Any]) -> dict[Any, Any]:
    indexed: dict[Any, Any] = {}
    for team in teams:
        team_id = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        if team_id is not None:
            indexed[team_id] = team
    return indexed


def _priority_tier_name(assignment: AssignmentLike, teams_by_id: Mapping[Any, Any]) -> str:
    rank_value = _get(assignment, *_PRIORITY_RANK_FIELDS, default=_MISSING)
    if rank_value is not _MISSING:
        return _normalise_priority_rank(rank_value)

    tier_value = _get(assignment, *_PRIORITY_TIER_FIELDS, default=_MISSING)
    if tier_value is not _MISSING:
        return _normalise_priority_tier(tier_value)

    team_id = _team_id(assignment)
    team = teams_by_id.get(team_id)
    if team is not None:
        rank_value = _get(team, *_PRIORITY_RANK_FIELDS, default=_MISSING)
        if rank_value is not _MISSING:
            return _normalise_priority_rank(rank_value)

        tier_value = _get(team, *_PRIORITY_TIER_FIELDS, default=_MISSING)
        if tier_value is not _MISSING:
            return _normalise_priority_tier(tier_value)

    raise ValueError("assignment is missing a priority tier (S/A/B/C/D or priority_tier_id 1..5)")


def _normalise_priority_tier(value: Any) -> str:
    scalar = _scalar_id(value)
    if isinstance(scalar, int):
        if scalar in _ONE_BASED_TIER_IDS:
            return _ONE_BASED_TIER_IDS[scalar]
        raise ValueError(f"unknown priority_tier_id {scalar!r}; expected 1..5")

    text = str(scalar).strip().upper().replace("-", "_").replace(" ", "_")
    if text.isdigit():
        return _normalise_priority_tier(int(text))
    if text in _TIER_ALIASES:
        return _TIER_ALIASES[text]
    raise ValueError(f"unknown priority tier {value!r}; expected S/A/B/C/D or 1..5")


def _normalise_priority_rank(value: Any) -> str:
    scalar = _scalar_id(value)
    if isinstance(scalar, int):
        if scalar in _ZERO_BASED_TIER_RANKS:
            return _ZERO_BASED_TIER_RANKS[scalar]
        raise ValueError(f"unknown priority rank {scalar!r}; expected 0..4")

    text = str(scalar).strip()
    if text.isdigit():
        return _normalise_priority_rank(int(text))
    return _normalise_priority_tier(text)


def _active_bonus_weight_names(assignment: AssignmentLike) -> tuple[str, ...]:
    active: set[str] = set()

    for weight_name, aliases in _BONUS_FIELD_ALIASES.items():
        if any(bool(_get(assignment, alias, default=False)) for alias in aliases):
            active.add(weight_name)

    explicit = _get(assignment, *_EXPLICIT_BONUS_FIELDS, default=())
    if isinstance(explicit, Mapping):
        for weight_name, enabled in explicit.items():
            if enabled:
                active.add(_normalise_bonus_weight_name(weight_name))
    elif explicit is not None and not isinstance(explicit, (str, bytes)):
        for weight_name in explicit:
            active.add(_normalise_bonus_weight_name(weight_name))
    elif explicit:
        active.add(_normalise_bonus_weight_name(explicit))

    return tuple(weight_name for weight_name in BONUS_WEIGHT_NAMES if weight_name in active)


def _soft_term_variable_and_weight(term: Any) -> tuple[BoolVarLike, str]:
    if isinstance(term, Sequence) and not isinstance(term, (str, bytes, Mapping)):
        if len(term) != 2:
            raise ValueError("soft objective term tuples must contain (variable, weight_name)")
        return term[0], _normalise_bonus_weight_name(term[1])

    variable = _var(term)
    weight_name = _get(
        term,
        "weight_name",
        "weightName",
        "objective_weight",
        "objectiveWeight",
        "weight",
        "type",
        "kind",
        default=_MISSING,
    )
    if weight_name is _MISSING:
        raise ValueError("soft objective term is missing a weight_name/objective_weight field")
    return variable, _normalise_bonus_weight_name(weight_name)


def _normalise_bonus_weight_name(value: Any) -> str:
    text = str(_scalar_id(value)).strip()
    normalized = text
    if normalized not in BONUS_WEIGHT_NAMES:
        raise ValueError(f"unknown level-2 bonus weight {value!r}")
    return normalized


def _get(source: Any, *names: str, default: Any = None) -> Any:
    return get_field(source, *names, default=default, skip_none=True)


def _scalar_id(value: Any) -> Any:
    return scalar_id(value)


def _var(assignment: AssignmentLike) -> BoolVarLike:
    return assignment_var(assignment, skip_none=True)


def _team_id(assignment: AssignmentLike) -> Any:
    return assignment_team_id(assignment, skip_none=True)


def _assignment_key(assignment: AssignmentLike, variable: BoolVarLike) -> Any:
    explicit = _scalar_id(_get(assignment, "id", "assignment_id", "assignmentId", "key", default=None))
    if explicit is not None:
        return explicit
    return variable.Index() if hasattr(variable, "Index") else id(variable)


def _get_venue_id(assignment: AssignmentLike) -> str | None:
    result = _scalar_id(
        _get(assignment, "venue_id", "room_id", "location_id", "venue", "room", "location", default=None)
    )
    return str(result) if result is not None else None


def _get_slot_id(assignment: AssignmentLike) -> str | None:
    result = _scalar_id(_get(assignment, "slot_id", "time_slot_id", "timeslot_id", "slot", "time_slot", default=None))
    return str(result) if result is not None else None


def _parse_time_minutes(time_str: str) -> int | None:
    try:
        parts = time_str.split(":")
        if len(parts) < 2:
            return None
        return int(parts[0]) * 60 + int(parts[1])
    except (ValueError, TypeError):
        return None


def _coach_ids_for(assignment: AssignmentLike) -> set[str]:
    coach_id = _scalar_id(_get(assignment, "coach_id", "trainer_id", "coach", "trainer", default=None))
    result: set[str] = set()
    if coach_id is not None:
        result.add(str(coach_id))
    return result


def _person_ids_for(
    assignment: AssignmentLike,
    team_player_map: Mapping[str, list[str]] | None,
) -> set[str]:
    """People present at a session: its coach(es) UNION the players of its team.

    A ``set`` gives the double-role dedup for free — someone who both coaches and
    plays the session counts once. With *team_player_map* None the result equals
    ``_coach_ids_for`` exactly (byte-identical legacy behaviour).
    """
    result = _coach_ids_for(assignment)
    if team_player_map:
        team_id = _team_id(assignment)
        if team_id is not None:
            result.update(team_player_map.get(str(team_id), []))
    return result


def _higher_tier(tier_a: str, tier_b: str) -> str:
    tier_order = {"S": 0, "A": 1, "B": 2, "C": 3, "D": 4}
    rank_a = tier_order.get(tier_a, 99)
    rank_b = tier_order.get(tier_b, 99)
    return tier_a if rank_a <= rank_b else tier_b
