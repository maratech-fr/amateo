"""Contrainte de bien-être — au plus une séance par jour et par équipe (sous-paquet P4-295 C6).

Extrait verbatim de l'ancien ``constraints/wellness.py`` (découpe pure). N'importe que les externes
``..common`` (aucun terme de compromis ici) ; aucune arête vers un autre sous-module.
L'orchestrateur ``add_level_1_hard_constraints`` du paquet ``constraints/__init__`` consomme via les
ré-exports."""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Iterable, Sequence
from typing import Any, cast

from ..common import (
    AssignmentVariable,
    BoolVarLike,
    _dedupe_variables,
    _get,
    _locked_team_days,
    _record_closure,
    _scalar_id,
    _to_day_int,
)


def add_one_session_per_day_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    teams: Iterable[Any] = (),
) -> int:
    """Implicit rule 11: a team can have at most one training session per day."""

    groups: dict[tuple[str, str], list[BoolVarLike]] = defaultdict(list)
    for assignment in assignments:
        team_id = assignment.team_id
        slot_id = assignment.slot_id
        if team_id is None or slot_id is None:
            continue
        day = str(slot_id).split(":")[0]
        groups[(str(team_id), day)].append(assignment.var)

    sessions_per_week: dict[str, int] = {}
    for team in teams:
        tid = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        if tid is None:
            continue
        spw = _get(team, "sessionsPerWeek", "sessions_per_week", default=1)
        sessions_per_week[str(tid)] = max(1, int(spw))

    days_by_team: dict[str, list[tuple[str, list[BoolVarLike]]]] = defaultdict(list)
    for (team_id, day), vars_list in groups.items():
        days_by_team[team_id].append((day, vars_list))

    # P4-97 bis — le second CAS RÉEL (BCCL) : une équipe a un jeudi VERROUILLÉ et le solveur
    # lui ajoutait une séance LIBRE ce même jeudi. Le jour verrouillé EST déjà la séance du
    # jour ; il crédite le budget hebdomadaire et interdit tout créneau libre ce jour-là.
    locked_team_days = _locked_team_days(model)

    added = 0
    for team_id, day_entries in days_by_team.items():
        spw = sessions_per_week.get(team_id, 1)
        locked_days_for_team = locked_team_days.get(team_id, {})

        if not locked_days_for_team:
            # Chemin historique — byte-identique en l'absence de verrou.
            if len(day_entries) <= 1:
                continue
            day_active_vars: list[BoolVarLike] = []
            for _day, vars_list in day_entries:
                day_active = cast(Any, model).NewBoolVar(f"day_active_{team_id}_{_day}")
                day_active_vars.append(day_active)
                slot_sum = sum(cast(Any, v) for v in vars_list)
                cast(Any, model).Add(slot_sum >= 1).OnlyEnforceIf(day_active)
                cast(Any, model).Add(slot_sum == 0).OnlyEnforceIf(day_active.Not())
            cast(Any, model).Add(sum(day_active_vars) <= spw)
            added += 1
            continue

        # Jours verrouillés = jours travaillés CONSTANTS : le budget hebdomadaire libre est
        # ``spw - nb_jours_verrouillés`` (plancher 0 — verrou souverain, jamais d'infaisable).
        free_day_entries = [(d, v) for (d, v) in day_entries if _to_day_int(d) not in locked_days_for_team]
        free_day_active_vars: list[BoolVarLike] = []
        for _day, vars_list in free_day_entries:
            day_active = cast(Any, model).NewBoolVar(f"day_active_{team_id}_{_day}")
            free_day_active_vars.append(day_active)
            slot_sum = sum(cast(Any, v) for v in vars_list)
            cast(Any, model).Add(slot_sum >= 1).OnlyEnforceIf(day_active)
            cast(Any, model).Add(slot_sum == 0).OnlyEnforceIf(day_active.Not())
        if free_day_active_vars:
            cast(Any, model).Add(sum(free_day_active_vars) <= max(0, spw - len(locked_days_for_team)))
            added += 1

    for (team_id, day), vars_list in groups.items():
        deduped = _dedupe_variables(vars_list)
        locked_count = 0
        team_locks = locked_team_days.get(team_id)
        if team_locks:
            try:
                locked_count = team_locks.get(int(day), 0)
            except (TypeError, ValueError):
                locked_count = 0
        if locked_count >= 1:
            # Un verrou occupe déjà l'unique séance du jour → aucun créneau libre ce jour-là.
            # ``<= 0`` (et non ``<= 1 - locked_count``) : deux verrous le même jour sont un
            # conflit ENTRE verrous, laissé au diagnostic — jamais une contrainte infaisable.
            if deduped:
                cast(Any, model).Add(sum(deduped) <= 0)
                added += 1
                # P4-99 — le jour est déjà pris par un verrou : chaque créneau libre fermé ici a
                # pour cause ce verrou. (Les `day_active`/`OnlyEnforceIf` du budget hebdomadaire
                # ci-dessus sont des canaux de réification, PAS des fermetures — hors mesure.)
                for locked_out_var in deduped:
                    _record_closure(model, locked_out_var, {"kind": "hard_lock"})
        elif len(deduped) > 1:
            cast(Any, model).Add(sum(deduped) <= 1)
            added += 1

    return added
