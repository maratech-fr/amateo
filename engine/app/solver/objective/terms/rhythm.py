"""Level-2 objective — repos après match et espacement des séances (sous-module du paquet ENG-39).

Extrait verbatim de l'ancien ``objective/terms.py`` (découpe pure, P4-295 C4). Dépend de
``compromise`` et ``normalise``. Aucune arête vers un autre sous-module de ``terms`` ; l'agrégateur
``objective/__init__`` consomme."""

from __future__ import annotations

from collections.abc import Iterable, Mapping
from typing import Any

from ...compromise import FAMILY_MATCH_REST, FAMILY_SPACING, CompromiseTermInfo
from ..normalise import _get, _scalar_id

BoolVarLike = Any


def add_match_day_rest_bonus(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    teams: Iterable[Any],
    weights: Mapping[str, int],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """Return soft objective terms rewarding a rest day AFTER a team's match day.

    Implicit rule (no UI constraint): for a team playing on match_day m, the day
    after (m mod 7 + 1) should be left free of training. Nominal case: matches
    Sat/Sun, training Mon-Fri — a Saturday match's rest day (Sunday) simply has
    no slots (no-op); a SUNDAY match makes Monday the rest day, gently avoided.

    Reified per team/week: rest_ok is true iff no session lands on the rest day.
    Safety: placing a session is worth its tier weight + session_count (20) — at
    least 21 — while the rest bonus is only ``rest`` (3), so the solver never
    drops or moves a real placement to collect it; it only breaks ties. No term
    is emitted when the team has no slot that day (avoids a constant bonus that
    would inflate the score).
    """

    if "rest" not in weights:
        raise KeyError("rest")

    team_day_vars: dict[str, dict[int, list[BoolVarLike]]] = {}
    for slot_key, variable in x.items():
        if not isinstance(slot_key, tuple) or len(slot_key) < 4:
            continue
        team_id = _scalar_id(slot_key[0])
        try:
            day = int(_scalar_id(slot_key[2]))
        except (TypeError, ValueError):
            continue
        team_day_vars.setdefault(str(team_id), {}).setdefault(day, []).append(variable)

    soft_terms: list[tuple[BoolVarLike, str]] = []
    for team in teams:
        team_id = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        match_day = _get(team, "match_day", "matchDay", default=None)
        if team_id is None or match_day is None:
            continue
        try:
            match_day_int = int(_scalar_id(match_day))
        except (TypeError, ValueError):
            continue

        rest_day = match_day_int % 7 + 1
        rest_day_vars = team_day_vars.get(str(team_id), {}).get(rest_day, [])
        if not rest_day_vars:
            continue

        rest_ok = model.NewBoolVar(f"rest_ok_{team_id}")
        model.Add(sum(rest_day_vars) == 0).OnlyEnforceIf(rest_ok)
        model.Add(sum(rest_day_vars) >= 1).OnlyEnforceIf(rest_ok.Not())
        soft_terms.append((rest_ok, "rest"))
        if info_out is not None:
            info_out.append(
                CompromiseTermInfo(
                    var=rest_ok,
                    family=FAMILY_MATCH_REST,
                    honored_when_active=True,
                    key=(FAMILY_MATCH_REST, str(team_id)),
                    team_id=str(team_id),
                )
            )

    return soft_terms


def add_spacing_penalty(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    teams: Iterable[Any],
    weights: Mapping[str, int],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """Implicit soft rule (ALIGN-06): gently discourage a team training on two
    CONSECUTIVE days (spacing). Malus only — never blocks feasibility; the low
    weight means it only breaks ties, never moves a real placement."""
    if "spacing" not in weights:
        raise KeyError("spacing")

    team_day_vars: dict[str, dict[int, list[BoolVarLike]]] = {}
    for slot_key, variable in x.items():
        if not isinstance(slot_key, tuple) or len(slot_key) < 4:
            continue
        team_id = _scalar_id(slot_key[0])
        try:
            day = int(_scalar_id(slot_key[2]))
        except (TypeError, ValueError):
            continue
        team_day_vars.setdefault(str(team_id), {}).setdefault(day, []).append(variable)

    soft_terms: list[tuple[BoolVarLike, str]] = []
    for team in teams:
        team_id = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        if team_id is None:
            continue
        days = team_day_vars.get(str(team_id), {})
        # One reified "team trains on day D" bool per day, reused across both
        # adjacent pairs (D is the right end of (D-1,D) and the left end of
        # (D,D+1)) — building it per-pair doubled the vars/constraints (C8).
        present: dict[int, BoolVarLike] = {}
        for day in sorted(days):
            if day + 1 not in days:
                continue
            for d in (day, day + 1):
                if d not in present:
                    has_d = model.NewBoolVar(f"has_{team_id}_{d}")
                    model.Add(sum(days[d]) >= 1).OnlyEnforceIf(has_d)
                    model.Add(sum(days[d]) == 0).OnlyEnforceIf(has_d.Not())
                    present[d] = has_d
            both = model.NewBoolVar(f"consec_{team_id}_{day}")
            model.AddBoolAnd([present[day], present[day + 1]]).OnlyEnforceIf(both)
            model.AddBoolOr([present[day].Not(), present[day + 1].Not()]).OnlyEnforceIf(both.Not())
            soft_terms.append((both, "spacing"))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=both,
                        family=FAMILY_SPACING,
                        honored_when_active=False,
                        key=(FAMILY_SPACING, str(team_id)),
                        team_id=str(team_id),
                    )
                )

    return soft_terms
