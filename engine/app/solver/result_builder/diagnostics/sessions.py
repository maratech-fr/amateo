"""Diagnostics des séances manquantes et causes mesurées à la pose, par équipe.

Extrait verbatim de l'ancien ``diagnostics.py`` (découpe pure, P4-295 C1).
"""

from __future__ import annotations

import contextlib
from collections import defaultdict
from collections.abc import Mapping
from datetime import UTC, datetime
from typing import Any

from ortools.sat.python import cp_model

from ...model import ScheduleCpModel
from ..helpers import _collection, _get


def _collect_session_causes(
    model: ScheduleCpModel,
    solver: cp_model.CpSolver,
) -> dict[str, dict[str, Any]]:
    """Agrège, PAR ÉQUIPE, la cause MESURÉE des créneaux non retenus (P4-99, décision B).

    Ne RE-TESTE aucune règle : lit les fermetures enregistrées À LA POSE — par variable dans
    ``model.candidate_closures``, et pour les candidats SANS variable (retirés par le verrou
    d'une autre équipe) dans ``model.lock_removed_candidates``. Un candidat dont la variable
    EXISTE, sans fermeture, et non retenu (``solver.Value == 0``) tombe dans la famille
    « resté ouvert » : on rapporte le COMPTE seul, jamais qui a pris la place (ce serait une
    re-dérivation). Renvoie ``team_id -> {"causes": [...], "openCandidates": int}`` où chaque
    cause est ``{kind, constraintId, label, count}`` (forme ``DiagnosticCauseSchema``)."""
    candidate_closures: dict[int, list[dict[str, Any]]] = getattr(model, "candidate_closures", {}) or {}
    lock_removed: dict[Any, dict[str, Any]] = getattr(model, "lock_removed_candidates", {}) or {}

    # team -> (kind, constraintId, label) -> nombre de créneaux fermés par cette cause
    aggregated: dict[str, dict[tuple[Any, Any, Any], int]] = defaultdict(lambda: defaultdict(int))
    open_counts: dict[str, int] = defaultdict(int)

    for slot_key, var in model.x.items():
        if solver.Value(var) != 0:
            continue  # créneau RETENU — ce n'est pas un candidat manquant
        team_id = str(slot_key[0])
        closures = candidate_closures.get(int(var.Index()))
        if closures:
            for cause in closures:
                key = (cause.get("kind"), cause.get("constraintId"), cause.get("label"))
                aggregated[team_id][key] += 1
        else:
            open_counts[team_id] += 1  # existe, non fermé, non retenu → resté ouvert

    for slot_key, cause in lock_removed.items():
        team_id = str(slot_key[0])
        key = (cause.get("kind"), cause.get("constraintId"), cause.get("label"))
        aggregated[team_id][key] += 1

    result: dict[str, dict[str, Any]] = {}
    for team_id in set(aggregated) | set(open_counts):
        causes = [
            {"kind": kind, "constraintId": constraint_id, "label": label, "count": count}
            for (kind, constraint_id, label), count in aggregated.get(team_id, {}).items()
        ]
        result[team_id] = {"causes": causes, "openCandidates": open_counts.get(team_id, 0)}
    return result


# P4-96 — descripteur FR d'une cause de fermeture SANS libellé de contrainte (un verrou n'a pas
# de « nom de règle » ; les autres kinds gardent le leur quand il existe). Sert aux agrégats D2 et
# ne touche à AUCUN message existant.


def _diagnose_session_below_effective_min(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
    *,
    session_causes_by_team: Mapping[str, dict[str, Any]] | None = None,
) -> list[dict[str, Any]]:
    """Warn when a team's placed session units fall below its effective minimum."""
    diagnostics: list[dict[str, Any]] = []
    causes_by_team = session_causes_by_team or {}

    tier_min: dict[int, int] = {}
    for tier in _collection(model_data, "priorityTiers", "priority_tiers"):
        tid = _get(tier, "id")
        default_min = _get(tier, "defaultMinSessions", "default_min_sessions")
        if tid is not None and default_min is not None:
            with contextlib.suppress(TypeError, ValueError):
                tier_min[int(tid)] = int(default_min)

    for constraint in _collection(model_data, "constraints"):
        if not isinstance(constraint, Mapping):
            continue
        if constraint.get("type") != "PRIORITY_TIER":
            continue
        metadata = constraint.get("metadata") or {}
        tier_id = metadata.get("id")
        default_min = metadata.get("defaultMinSessions")
        if tier_id is not None and default_min is not None:
            with contextlib.suppress(TypeError, ValueError):
                tier_min[int(tier_id)] = int(default_min)

    # Count SESSIONS (one placed slot = one session), not 15-min units. The
    # comparison is against sessionsPerWeek / tier default_min, both expressed
    # in sessions — counting units (duration // 15) would make a single 90-min
    # session look like 6 and hide a genuinely missing session.
    placed_counts: dict[str, int] = defaultdict(int)
    for slot in slots:
        team_id = slot.get("teamId")
        if team_id:
            placed_counts[str(team_id)] += 1

    teams: dict[str, Any] = {}
    team_names: dict[str, str] = {}
    for team in _collection(model_data, "teams"):
        team_id = str(_get(team, "id", "team_id", "teamId"))
        teams[team_id] = team
        team_names[team_id] = str(_get(team, "name", "team_name", default=team_id))

    for team_id, team in teams.items():
        spw_raw = _get(team, "sessions_per_week", "sessionsPerWeek", default=None)
        if spw_raw is None:
            continue
        spw = int(spw_raw)

        tier_id_raw = _get(team, "priority_tier_id", "priorityTierId", default=None)
        effective_min = spw
        if tier_id_raw is not None and tier_min:
            try:
                tier_key = int(tier_id_raw)
            except (TypeError, ValueError):
                tier_key = None
            if tier_key is not None and tier_key in tier_min:
                effective_min = min(spw, tier_min[tier_key])

        placed = placed_counts.get(team_id, 0)
        # Warn whenever fewer sessions were placed than the team REQUESTED
        # (sessionsPerWeek), even if its tier floor (effective_min) is met — the
        # manager still needs to know a requested session is missing. Below the
        # tier floor is the more severe case (the guaranteed minimum was missed).
        if placed < spw:
            team_name = team_names.get(team_id, team_id)
            below_floor = placed < effective_min
            severity = "ERROR" if below_floor else "WARNING"
            # Message NEUTRE : on rapporte le FAIT (N demandée(s), M placée(s), et le cas
            # échéant sous le minimum cible), jamais une CAUSE devinée. Affirmer « créneaux
            # de gymnase insuffisants » / « faute de créneau disponible » était un mensonge
            # de diagnostic — sous V10 une séance peut manquer alors que des créneaux étaient
            # libres (le remplissage prime, mais un conflit dur ou un arbitrage a laissé un
            # trou). La vraie transgression, quand il y en a une, remonte par son diagnostic
            # dédié (verrou, conflit, coach_overload…), pas par une cause inventée ici.
            # "cible", pas "garanti" : le minimum est visé en objectif soft (ENG-18), pas
            # garanti en plancher dur.
            reason = f" — en-dessous de son minimum cible de {effective_min}." if below_floor else "."
            # P4-99 — la cause RÉELLE, MESURÉE à la pose (jamais devinée) : la liste des règles
            # ayant fermé un créneau de cette équipe (cliquable côté front) + le compte des
            # créneaux « restés ouverts » (libres, non fermés, non retenus). Absent de la carte
            # (aucune donnée mesurée) → causes vide + openCandidates None : on garde alors le
            # message NEUTRE et les suggestions statiques, sans inventer de cause.
            team_causes = causes_by_team.get(team_id, {})
            diagnostics.append(
                {
                    "id": f"diag-session-below-min-{team_id}",
                    "type": "session_below_effective_min",
                    "severity": severity,
                    "teamId": team_id,
                    "message": (
                        f"L'équipe {team_name} : {spw} séance(s) demandée(s) par semaine, "
                        f"seulement {placed} placée(s){reason}"
                    ),
                    "suggestions": [
                        "Ajoutez de la disponibilité de gymnase ou un créneau supplémentaire pour cette équipe.",
                        "Vérifiez le tier de priorité et le nombre de séances/semaine de l'équipe.",
                    ],
                    "causes": team_causes.get("causes", []),
                    "openCandidates": team_causes.get("openCandidates"),
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )

    return diagnostics
