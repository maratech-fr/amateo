"""Contrainte de bien-être — une ÉQUIPE ne s'entraîne pas N jours de suite (sous-paquet P4-295 C6).

⚠ ALIGN-08 — voisine par le nom de ``chains.add_max_consecutive_sessions_constraints``, étrangère
par le sujet : celle-ci interdit à une ÉQUIPE d'enchaîner des JOURS, l'autre à une PERSONNE
d'enchaîner des CRÉNEAUX dos-à-dos dans une journée (l'avertissement vit aussi dans le corps).

Extrait verbatim de l'ancien ``constraints/wellness.py`` (découpe pure). N'importe que les externes
``...compromise`` / ``..common`` ; aucune arête vers un autre sous-module. L'orchestrateur
``add_level_1_hard_constraints`` du paquet ``constraints/__init__`` consomme via les ré-exports."""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any, cast

from ...compromise import FAMILY_IMPLICIT, CompromiseTermInfo
from ..common import (
    CONSECUTIVE_DAYS_VIOLATION_WEIGHT,
    HARD,
    OFF,
    AssignmentVariable,
    BoolVarLike,
    _to_day_int,
)


def add_max_consecutive_days_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    intensity: str = HARD,
    max_consecutive_days: int = 3,
    soft_terms_out: list[tuple[BoolVarLike, str]] | None = None,
    soft_term_info_out: list[CompromiseTermInfo] | None = None,
) -> int:
    """Constraint 3e (P2-42): a TEAM never trains ``max_consecutive_days`` days in a row.

    ⚠ Ne pas confondre avec :func:`add_max_consecutive_sessions_constraints`, dont le nom
    est presque le même : celle-là interdit à une PERSONNE d'enchaîner des créneaux
    dos-à-dos DANS UNE JOURNÉE ; celle-ci interdit à une ÉQUIPE de s'entraîner N JOURS de
    suite. L'audit ALIGN-08 a montré qu'on pouvait croire ce besoin couvert en lisant le
    seul nom de l'autre — d'où cet avertissement aux deux endroits.

    Un littéral ``trains[team][day]`` est réifié comme le OR des affectations de l'équipe
    ce jour-là (une équipe peut avoir plusieurs séances le même jour : c'est UN jour
    d'entraînement, pas deux). Puis, pour chaque fenêtre de ``max_consecutive_days`` jours
    consécutifs :

    * ``HARD`` : ``sum(fenêtre) <= max_consecutive_days - 1`` — la suite est impossible ;
    * ``PREFERRED`` : un littéral de violation par fenêtre (le ET de ses jours) part en
      ``soft_terms_out`` à −6, comme ses quatre sœurs.

    **Absente du payload, la règle ne s'applique PAS** (``intensity=OFF``) — au contraire de
    ses quatre sœurs, qui retombent sur HARD par héritage historique. Elle est neuve : la
    faire naître dure changerait le planning de tous les clubs existants sans qu'ils aient
    rien demandé.

    **La semaine ne boucle pas** : dimanche→lundi n'est pas une suite. Le planning est
    hebdomadaire et se relit semaine par semaine ; faire boucler produirait des refus que
    personne ne saurait expliquer. Le week-end, lui, compte comme n'importe quel jour.
    """
    if intensity == OFF or max_consecutive_days < 2:
        return 0

    team_days: dict[str, dict[int, list[BoolVarLike]]] = {}
    for assignment in assignments:
        team_id = assignment.team_id
        slot_id = assignment.slot_id
        if team_id is None or slot_id is None:
            continue
        parts = str(slot_id).split(":", 1)
        if len(parts) < 2:
            continue
        day = _to_day_int(parts[0])
        if day is None:
            continue
        team_days.setdefault(str(team_id), {}).setdefault(day, []).append(assignment.var)

    added = 0
    for team_id, by_day in team_days.items():
        trains: dict[int, BoolVarLike] = {}
        for day, day_vars in by_day.items():
            if len(day_vars) == 1:
                trains[day] = day_vars[0]
                continue
            # Plusieurs séances le même jour = UN jour d'entraînement : OR réifié.
            flag = cast(Any, model).NewBoolVar(f"trains_{team_id}_{day}")
            cast(Any, model).AddMaxEquality(flag, day_vars)
            trains[day] = flag

        for first_day in sorted(trains):
            window = [trains[d] for d in range(first_day, first_day + max_consecutive_days) if d in trains]
            if len(window) < max_consecutive_days:
                continue  # la fenêtre n'est pas entièrement candidate : rien à interdire
            if intensity == HARD:
                cast(Any, model).Add(sum(window) <= max_consecutive_days - 1)
                added += 1
                continue

            violated = cast(Any, model).NewBoolVar(f"consecutive_days_{team_id}_{first_day}")
            # ET : la fenêtre n'est en violation que si TOUS ses jours sont retenus.
            cast(Any, model).AddMinEquality(violated, window)
            if soft_terms_out is not None:
                soft_terms_out.append((violated, CONSECUTIVE_DAYS_VIOLATION_WEIGHT))
            if soft_term_info_out is not None:
                soft_term_info_out.append(
                    CompromiseTermInfo(
                        var=violated,
                        family=FAMILY_IMPLICIT,
                        honored_when_active=False,
                        key=(FAMILY_IMPLICIT, "consecutive_days", team_id, str(first_day)),
                        team_id=team_id,
                        day_of_week=first_day,
                        detail="consecutive_days",
                    )
                )
            added += 1

    return added
