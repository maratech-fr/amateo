"""Contrainte de bien-être — ordre des âges (les plus jeunes plus tôt) (sous-paquet P4-295 C6).

Extrait verbatim de l'ancien ``constraints/wellness.py`` (découpe pure). N'importe que les externes
``...compromise`` / ``...model`` / ``..common`` ; aucune arête vers un autre sous-module.
L'orchestrateur ``add_level_1_hard_constraints`` du paquet ``constraints/__init__`` consomme via les
ré-exports."""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Iterable, Sequence
from typing import Any, cast

from ...compromise import FAMILY_IMPLICIT, CompromiseTermInfo
from ...model import _time_to_minutes
from ..common import (
    AGE_VIOLATION_WEIGHT,
    HARD,
    PREFERRED,
    AssignmentVariable,
    BoolVarLike,
    _get,
    _scalar_id,
    _to_day_int,
)


def add_age_ascending_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    teams: Iterable[Any] = (),
    intensity: str = HARD,
    soft_terms_out: list[tuple[BoolVarLike, str]] | None = None,
    soft_term_info_out: list[CompromiseTermInfo] | None = None,
) -> int:
    """Implicit rule 12: younger teams train earlier than older teams
    in the same venue on the same day.

    For each pair (A, B) where A.ageMin < B.ageMin (both not None, neither
    HARD-locked), and for each venue+day, if slot_A starts later than slot_B,
    (``intensity=HARD``) prevent both from being selected simultaneously:
    ``x[A, venue, day, slot_A] + x[B, venue, day, slot_B] <= 1``.

    When ``intensity=PREFERRED`` the hard bound is NOT posted; instead ONE aggregated
    violation literal per ``(venue, day)`` — the OR of that venue-day's inverted pairs
    being both selected — is appended to ``soft_terms_out`` for the objective.

    Teams with ``ageMin=None`` (Loisir, Baby) and HARD-locked teams are exempt.
    No constraint is added between teams sharing the same ``ageMin``.
    """

    team_age_min: dict[str, int] = {}
    for team in teams:
        tid = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        if tid is None:
            continue
        age_min = _get(team, "ageMin", "age_min", default=None)
        if age_min is None:
            continue
        team_age_min[str(tid)] = int(age_min)

    if len(team_age_min) < 2:
        return 0

    hard_locked_teams: set[str] = set()
    hard_slot_keys: frozenset[tuple[str, str, int, str]] = getattr(model, "hard_slot_keys", frozenset())
    for slot_key in hard_slot_keys:
        hard_locked_teams.add(str(slot_key[0]))

    locked_slots = getattr(model, "locked_slots", ())
    for locked in locked_slots:
        tid = _scalar_id(_get(locked, "team_id", "teamId", default=None))
        if tid is not None:
            hard_locked_teams.add(str(tid))

    groups: dict[tuple[str, str], list[tuple[str, int, BoolVarLike]]] = defaultdict(list)
    for assignment in assignments:
        team_id = assignment.team_id
        venue_id = assignment.venue_id
        slot_id = assignment.slot_id
        if team_id is None or venue_id is None or slot_id is None:
            continue
        team_id_str = str(team_id)
        if team_id_str not in team_age_min or team_id_str in hard_locked_teams:
            continue
        slot_id_str = str(slot_id)
        parts = slot_id_str.split(":", 1)
        if len(parts) != 2:
            continue
        day = parts[0]
        start_minutes = _time_to_minutes(parts[1])
        groups[(str(venue_id), day)].append((team_id_str, start_minutes, assignment.var))

    added = 0
    for (venue_id_str, day), _entries in groups.items():
        by_team: dict[str, list[tuple[int, BoolVarLike]]] = defaultdict(list)
        for team_id_str, start_minutes, var in _entries:
            by_team[team_id_str].append((start_minutes, var))

        team_ids_here = [t for t in by_team if t in team_age_min]
        team_ids_here.sort(key=lambda t: team_age_min[t])

        pair_active_literals: list[BoolVarLike] = []
        for i in range(len(team_ids_here)):
            for j in range(i + 1, len(team_ids_here)):
                team_a = team_ids_here[i]
                team_b = team_ids_here[j]
                if team_age_min[team_a] == team_age_min[team_b]:
                    continue
                for start_a, var_a in by_team[team_a]:
                    for start_b, var_b in by_team[team_b]:
                        if start_a > start_b:
                            if intensity == PREFERRED:
                                active = cast(Any, model).NewBoolVar(
                                    f"age_pair_{venue_id_str}_{day}_{len(pair_active_literals)}"
                                )
                                cast(Any, model).Add(var_a + var_b >= 2).OnlyEnforceIf(active)
                                cast(Any, model).Add(var_a + var_b <= 1).OnlyEnforceIf(active.Not())
                                pair_active_literals.append(active)
                            else:
                                model.Add(var_a + var_b <= 1)
                                added += 1

        if intensity == PREFERRED and pair_active_literals:
            gv_violated = cast(Any, model).NewBoolVar(f"age_violated_{venue_id_str}_{day}")
            for active in pair_active_literals:
                cast(Any, model).Add(gv_violated >= active)
            cast(Any, model).Add(gv_violated <= sum(pair_active_literals))
            if soft_terms_out is not None:
                soft_terms_out.append((gv_violated, AGE_VIOLATION_WEIGHT))
            if soft_term_info_out is not None:
                soft_term_info_out.append(
                    CompromiseTermInfo(
                        var=gv_violated,
                        family=FAMILY_IMPLICIT,
                        honored_when_active=False,
                        key=(FAMILY_IMPLICIT, "age", venue_id_str, day),
                        venue_id=venue_id_str,
                        day_of_week=_to_day_int(day),
                        detail="age",
                    )
                )
            added += 1

    return added
