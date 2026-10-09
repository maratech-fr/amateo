"""Level-2 objective — bonus d'enchaînement même gymnase dos à dos (sous-module du paquet ENG-39).

Extrait verbatim de l'ancien ``objective/terms.py`` (découpe pure, P4-295 C4). Dépend de
``compromise``, ``normalise`` et ``weights``. Aucune arête vers un autre sous-module de ``terms`` ;
``objective/__init__`` l'appelle depuis ``add_level_2_objective`` (ordre des termes CP-SAT inchangé)."""

from __future__ import annotations

from collections.abc import Iterable, Mapping
from typing import Any

from ...compromise import CompromiseTermInfo
from ..normalise import (
    _assignment_key,
    _get,
    _get_slot_id,
    _get_venue_id,
    _higher_tier,
    _normalise_assignments,
    _parse_time_minutes,
    _person_ids_for,
    _priority_tier_name,
    _teams_by_id,
    _var,
)
from ..weights import CHAINING_TIER_WEIGHTS

AssignmentLike = Any
BoolVarLike = Any


def add_chaining_bonus(
    model: Any,
    assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike],
    *,
    teams: Iterable[Any] = (),
    team_player_map: Mapping[str, list[str]] | None = None,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, int]]:
    """Build SOFT bonus terms for same-venue back-to-back sessions.

    For each pair of consecutive slots (A, B) in the same venue on the same
    day where A.end == B.start, and for each PERSON present at both slots —
    a coach of the session OR a player of its team (see *team_player_map*) —
    create a ``chained`` BoolVar that is true when both sessions are placed.
    The bonus weight is ``CHAINING_TIER_WEIGHTS[tier]`` where the tier is the
    highest-tier team across the two sessions.

    *team_player_map* maps ``str(team_id) -> [person_id, ...]`` (built from the
    coach/player links). With it None, only coaches count and the result is
    byte-identical to the coach-only behaviour.

    Returns a list of ``(chained_var, weight)`` terms. The caller MUST fold
    these into its single ``model.Maximize(...)`` — this function must not call
    Maximize itself, or CP-SAT's single-objective model would drop them.
    """

    assignment_list = _normalise_assignments(assignments)
    if len(assignment_list) < 2:
        return []

    teams_by_id = _teams_by_id(teams)

    slot_lookup: dict[tuple[str, str, int], list[dict[str, Any]]] = {}

    for assignment in assignment_list:
        venue_id = _get_venue_id(assignment)
        slot_id = _get_slot_id(assignment)
        if venue_id is None or slot_id is None:
            continue

        slot_id_str = str(slot_id)
        parts = slot_id_str.split(":", 1)
        if len(parts) != 2:
            continue

        day = parts[0]
        start_minutes = _parse_time_minutes(parts[1])
        if start_minutes is None:
            continue

        start_val = _get(assignment, "start", "start_minute", "starts_at", default=None)
        end_val = _get(assignment, "end", "end_minute", "ends_at", default=None)

        start_min = int(start_val) if start_val is not None else start_minutes
        end_min = int(end_val) if end_val is not None else None

        key = (str(venue_id), day, start_min)
        slot_lookup.setdefault(key, []).append(
            {
                "assignment": assignment,
                "start": start_min,
                "end": end_min,
            }
        )

    chaining_pairs: list[tuple[BoolVarLike, int]] = []
    seen_pairs: set[tuple[str, str]] = set()

    for key, entries in slot_lookup.items():
        venue_id, day, _start_min = key
        for entry in entries:
            end_min = entry["end"]
            if end_min is None:
                continue

            next_key = (venue_id, day, end_min)
            next_entries = slot_lookup.get(next_key)
            if next_entries is None:
                continue

            for next_entry in next_entries:
                pair_id_a = str(_assignment_key(entry["assignment"], _var(entry["assignment"])))
                pair_id_b = str(_assignment_key(next_entry["assignment"], _var(next_entry["assignment"])))
                pair_key = (pair_id_a, pair_id_b)
                if pair_key in seen_pairs:
                    continue
                seen_pairs.add(pair_key)

                persons_a = _person_ids_for(entry["assignment"], team_player_map)
                persons_b = _person_ids_for(next_entry["assignment"], team_player_map)
                common_persons = persons_a & persons_b

                for person_id in common_persons:
                    tier_a = _priority_tier_name(entry["assignment"], teams_by_id)
                    tier_b = _priority_tier_name(next_entry["assignment"], teams_by_id)
                    highest_tier = _higher_tier(tier_a, tier_b)
                    weight = CHAINING_TIER_WEIGHTS.get(highest_tier, 0)
                    if weight == 0:
                        continue

                    var_a = _var(entry["assignment"])
                    var_b = _var(next_entry["assignment"])
                    # Cheap encoding: `chained` only ever appears in the objective
                    # with a positive weight, so two linear upper bounds suffice —
                    # the maximiser pushes it to min(var_a, var_b) = "both placed".
                    # Avoids the reified AddBoolAnd/AddBoolOr + OnlyEnforceIf, which
                    # blow up the model on real datasets (BCCL solve > 30 s).
                    chained = model.NewBoolVar(f"chained_{person_id}_{pair_id_a}_{pair_id_b}")
                    model.Add(chained <= var_a)
                    model.Add(chained <= var_b)

                    chaining_pairs.append((chained, int(weight)))
                    if info_out is not None:
                        try:
                            day_int: int | None = int(day)
                        except (TypeError, ValueError):
                            day_int = None
                        info_out.append(
                            CompromiseTermInfo(
                                var=chained,
                                family="chaining",
                                honored_when_active=True,
                                key=("chaining", str(person_id), venue_id, day, pair_id_a, pair_id_b),
                                venue_id=venue_id,
                                day_of_week=day_int,
                            )
                        )

    return chaining_pairs
