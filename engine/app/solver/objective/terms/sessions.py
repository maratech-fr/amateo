"""Level-2 objective — malus par séance sous le quota, V10 (sous-module du paquet ENG-39).

Extrait verbatim de l'ancien ``objective/terms.py`` (découpe pure, P4-295 C4). Autonome côté
solveur (ne lit ni ``normalise`` ni ``weights``). Aucune arête vers un autre sous-module de
``terms`` ; l'agrégateur ``objective/__init__`` consomme."""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any, cast

BoolVarLike = Any


def add_missing_session_penalty(
    model: Any,
    assignments_by_team: Mapping[Any, Sequence[BoolVarLike]],
    remaining_by_team: Mapping[str, int],
    weights: Mapping[str, int],
    *,
    hard_satisfied_team_ids: set[str] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """V10 — LE REMPLISSAGE PRIME SUR LE CONFORT : un malus PAR séance sous le quota.

    Pour chaque équipe ayant des variables candidates, ``remaining`` = le nombre de séances
    encore à placer après crédit des verrous HARD (``max(0, spw − verrous HARD)``), fourni
    par ``remaining_by_team`` — la MÊME source que la borne ``sum(vars) <= remaining`` posée
    dans ``build_schedule`` (une seule définition de « combien reste-t-il à placer »).

    Pour ``m`` de 1 à ``remaining``, un littéral ``miss_m`` est vrai ssi ``sum(vars) <=
    remaining − m`` : il compte « au moins m séances manquent ». Chaque littéral actif coûte
    ``missing_session`` (−1000) : 1 manquante → −1000, 2 → −2000, monotone. Une équipe
    satisfaite par des verrous HARD (``remaining`` ≤ 0, ou ``hard_satisfied_team_ids``) ou
    sans variable candidate n'émet AUCUN littéral (ni malus indu, ni terme mort).

    Complète ``UNPLACED_PENALTY`` sans le remplacer : une équipe à zéro paie les deux
    (100000 + spw × 1000). Voir la preuve d'empilement (P3/P4/P5) sur ``missing_session``
    dans ``LEVEL_2_OBJECTIVE_WEIGHTS``.
    """

    if "missing_session" not in weights:
        raise KeyError("missing_session")

    terms: list[tuple[BoolVarLike, str]] = []
    for team_id, team_vars in assignments_by_team.items():
        if not team_vars:
            continue
        if hard_satisfied_team_ids is not None and str(team_id) in hard_satisfied_team_ids:
            continue
        remaining = int(remaining_by_team.get(str(team_id), 0))
        if remaining <= 0:
            continue
        team_sum = sum(cast(Any, v) for v in team_vars)
        for m in range(1, remaining + 1):
            miss = cast(Any, model).NewBoolVar(f"miss_{team_id}_{m}")
            cast(Any, model).Add(team_sum <= remaining - m).OnlyEnforceIf(miss)
            cast(Any, model).Add(team_sum >= remaining - m + 1).OnlyEnforceIf(miss.Not())
            terms.append((miss, "missing_session"))

    return terms
