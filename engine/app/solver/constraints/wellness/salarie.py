"""Contrainte de bien-être — présence d'un salarié chaque jour ouvré (sous-paquet P4-295 C6).

Extrait verbatim de l'ancien ``constraints/wellness.py`` (découpe pure). N'importe que les externes
``...compromise`` / ``..common`` ; aucune arête vers un autre sous-module. L'orchestrateur
``add_level_1_hard_constraints`` du paquet ``constraints/__init__`` consomme via les ré-exports."""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Iterable, Sequence
from typing import Any, cast

from ...compromise import FAMILY_IMPLICIT, CompromiseTermInfo
from ..common import (
    HARD,
    PREFERRED,
    SALARIE_VIOLATION_WEIGHT,
    AssignmentVariable,
    BoolVarLike,
    _dedupe_variables,
    _get,
    _locked_person_day_intervals,
    _scalar_id,
)


def add_salarie_distribution_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    coaches: Iterable[Any] = (),
    team_coach_map: dict[str, list[str]] | None = None,
    team_player_map: dict[str, list[str]] | None = None,
    intensity: str = HARD,
    soft_terms_out: list[tuple[BoolVarLike, str]] | None = None,
    soft_term_info_out: list[CompromiseTermInfo] | None = None,
) -> int:
    """Constraint 3c: at least one salarié coach must be present each Mon-Fri day.

    A salarié is a coach with ``isEmployee=True``. For each day 1-5 (Mon-Fri),
    creates a ``day_has_salarie[d]`` BoolVar with reification and (``intensity=HARD``)
    enforces ``day_has_salarie[d] == 1``. When ``intensity=PREFERRED`` the ``== 1`` is
    NOT posted; instead ONE aggregated violation literal per working day (Mon-Fri) —
    ``day_has_salarie[d] == 0`` — is appended to ``soft_terms_out`` for the objective.

    Both coaching assignments (via ``team_coach_map``) and coach-player playing
    assignments (via ``team_player_map``) count as being present. Falls back to
    assignment attributes when maps are not provided.

    P4-97 — a day on which a salarié has a HARD-locked session (coached OR played) is a
    CONSTANT « salarié present » day: ``day_has_salarie[d]`` is forced to 1. In HARD this
    removes the phantom INFEASIBLE of a schedule whose salarié sessions are all locked; in
    PREFERRED it removes the phantom violation literals (a day truly without any salarié
    still lights its literal).

    Skipped if there are fewer than 2 salarié coaches.
    """

    salarie_ids: set[str] = set()
    for coach in coaches:
        coach_id = _scalar_id(_get(coach, "id", "coach_id", default=None))
        if coach_id is None:
            continue
        is_employee = _get(coach, "isEmployee", "is_employee", default=False)
        if is_employee:
            salarie_ids.add(str(coach_id))

    if len(salarie_ids) < 2:
        return 0

    day_vars: dict[int, list[BoolVarLike]] = defaultdict(list)

    for assignment in assignments:
        slot_id = assignment.slot_id
        if slot_id is None:
            continue
        day_str = str(slot_id).split(":")[0]
        try:
            day = int(day_str)
        except (TypeError, ValueError):
            continue
        if day < 1 or day > 5:
            continue

        team_id = assignment.team_id
        team_id_str = str(team_id) if team_id is not None else None

        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            for coach_id in team_coach_map[team_id_str]:
                if coach_id in salarie_ids:
                    day_vars[day].append(assignment.var)
        else:
            coach_id = assignment.coach_id
            if coach_id is not None and str(coach_id) in salarie_ids:
                day_vars[day].append(assignment.var)

        if team_player_map is not None and team_id_str is not None and team_id_str in team_player_map:
            for player_id in team_player_map[team_id_str]:
                if player_id in salarie_ids:
                    day_vars[day].append(assignment.var)
        else:
            for player_id in assignment.player_ids:
                if str(player_id) in salarie_ids:
                    day_vars[day].append(assignment.var)

    # P4-97 — jours ouvrés où un salarié a une séance VERROUILLÉE : présence constante.
    locked_person_days = _locked_person_day_intervals(model, team_coach_map, team_player_map)
    locked_salarie_days: set[int] = set()
    for salarie_id in salarie_ids:
        locked_salarie_days.update(locked_person_days.get(salarie_id, {}))

    added = 0
    for day in range(1, 6):
        day_has_salarie = cast(Any, model).NewBoolVar(f"day_has_salarie_day{day}")

        # P4-99 — HORS mesure de cause : `day_has_salarie` est une var de réification agrégée
        # (canal `OnlyEnforceIf`), pas un candidat de séance ; aucune fermeture inconditionnelle.
        if day in locked_salarie_days:
            # Un salarié encadre/joue ce jour-là par un verrou → présence constante.
            cast(Any, model).Add(day_has_salarie == 1)
        else:
            day_assignments = _dedupe_variables(day_vars.get(day, []))
            if not day_assignments:
                cast(Any, model).Add(day_has_salarie == 0)
            else:
                day_sum = sum(cast(Any, v) for v in day_assignments)
                cast(Any, model).Add(day_sum >= 1).OnlyEnforceIf(day_has_salarie)
                cast(Any, model).Add(day_sum == 0).OnlyEnforceIf(day_has_salarie.Not())

        if intensity == PREFERRED:
            # Un littéral de violation par jour ouvré : « aucun salarié ce jour-là ».
            if soft_terms_out is not None:
                soft_terms_out.append((day_has_salarie.Not(), SALARIE_VIOLATION_WEIGHT))
            if soft_term_info_out is not None:
                soft_term_info_out.append(
                    CompromiseTermInfo(
                        var=day_has_salarie.Not(),
                        family=FAMILY_IMPLICIT,
                        honored_when_active=False,
                        key=(FAMILY_IMPLICIT, "salarie", day),
                        day_of_week=day,
                        detail="salarie",
                    )
                )
        else:
            cast(Any, model).Add(day_has_salarie == 1)
        added += 1

    return added
