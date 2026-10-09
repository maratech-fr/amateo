"""Level-2 objective — plafond de jours d'un coach en termes SOFT (sous-module du paquet ENG-39).

Extrait verbatim de l'ancien ``objective/terms.py`` (découpe pure, P4-295 C4). Dépend de
``compromise`` (métadonnée de nommage des compromis). Aucune arête vers un autre sous-module de
``terms`` ; l'agrégateur ``objective/__init__`` consomme."""

from __future__ import annotations

from collections.abc import Iterable, Mapping
from typing import Any, cast

from ...compromise import FAMILY_COACH_DAY_CAP, CompromiseTermInfo

BoolVarLike = Any


def add_coach_day_cap_penalty(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    coaches: Iterable[Any],
    team_coach_map: Mapping[str, list[str]] | None,
    weights: Mapping[str, int],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """P4-51 — le plafond de jours d'un coach, en termes SOFT (arbitrage : préféré, pas dur).

    ⚑ Avant cette fonction, ``maxDaysOverride`` n'était appliqué NULLE PART. Pire : la
    contrainte du jour de repos SAUTAIT les coachs dont l'override est ≤ 4, au motif —
    faux — que « le plafond garantit déjà le repos ». Régler « max 3 jours » sur un coach
    RETIRAIT donc sa garantie de repos sans rien plafonner : l'inverse exact du libellé.
    Le skip est mort (`add_coach_rest_day_constraints`), et le plafond agit ici.

    Un littéral par jour au-delà du plafond : ``over_d`` est vrai ssi le coach travaille
    au moins *d* jours (d > plafond). Chaque littéral actif coûte ``overload_day``. Un
    dépassement de 2 jours coûte donc 2 × 15 — proportionnel, et purement booléen.

    Les jours comptés vont de 1 à 7 : le diagnostic ``coach_overload`` (ENG-24) compte
    tous les jours travaillés, samedi compris — l'objectif doit compter comme lui, sinon
    le solveur optimise une définition et le récap en juge une autre.
    """

    terms: list[tuple[BoolVarLike, str]] = []
    if not team_coach_map:
        return terms

    caps: dict[str, int] = {}
    for coach in coaches:
        raw = coach.get("maxDaysOverride") if isinstance(coach, Mapping) else getattr(coach, "max_days_override", None)
        if raw is None and isinstance(coach, Mapping):
            raw = coach.get("max_days_override")
        cid = coach.get("id") if isinstance(coach, Mapping) else getattr(coach, "id", None)
        if cid is not None and raw is not None and 0 < int(raw) < 7:
            caps[str(cid)] = int(raw)

    if not caps:
        return terms

    day_vars: dict[tuple[str, int], list[BoolVarLike]] = {}
    for key, var in x.items():
        team_id, _venue_id, day_of_week, _start = key
        for coach_id in team_coach_map.get(str(team_id), []):
            if str(coach_id) in caps:
                day_vars.setdefault((str(coach_id), int(day_of_week)), []).append(var)

    for coach_id, cap in caps.items():
        is_working: list[BoolVarLike] = []
        for day in range(1, 8):
            vars_of_day = day_vars.get((coach_id, day), [])
            if not vars_of_day:
                continue
            working = cast(Any, model).NewBoolVar(f"cap_is_working_{coach_id}_d{day}")
            day_sum = sum(cast(Any, v) for v in vars_of_day)
            cast(Any, model).Add(day_sum >= 1).OnlyEnforceIf(working)
            cast(Any, model).Add(day_sum == 0).OnlyEnforceIf(working.Not())
            is_working.append(working)

        total = sum(cast(Any, w) for w in is_working)
        for over in range(cap + 1, len(is_working) + 1):
            literal = cast(Any, model).NewBoolVar(f"cap_over_{coach_id}_{over}")
            cast(Any, model).Add(total >= over).OnlyEnforceIf(literal)
            cast(Any, model).Add(total <= over - 1).OnlyEnforceIf(literal.Not())
            terms.append((literal, "overload_day"))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=literal,
                        family=FAMILY_COACH_DAY_CAP,
                        honored_when_active=False,
                        key=(FAMILY_COACH_DAY_CAP, coach_id),
                        coach_id=coach_id,
                    )
                )

    return terms
