"""« Tant qu'un coach tient la séance, c'est OK » (fondateur 2026-10-30) : qui peut tenir chaque séance.

Seul, un coach principal est indispensable ; à plusieurs, un seul suffit. Pour une séance qui a le
choix, ``h[séance, coach]`` dit que ce coach la tient : ``h <= x`` et ``Σh >= x``. Les rôles
(``overlap``) et les plafonds (``wellness``, ``travel``) opposent alors les tenues, pas les variables
``x``. Le rôle de joueur occupe sans condition ; un coach qui joue dans la séance la tient d'office.

Une séance à UN seul coach hors bloc n'a pas de tenue séparée, elle reste opposée par ``x`` : le modèle
d'un club sans co-coachs reste byte-identique (goldens intacts).

Séance de bloc : un coach de l'union des membres tient la séance de tous (``Σh + Σb >= x``, et une
tenue parmi les membres dès que ``b`` est actif). ``isRequired`` n'entre pas en jeu.

Verrous : un verrou est sans variable, souverain. Un verrou à plusieurs coachs consomme UN coach, choisi
par le solveur tant que les verrous entre eux laissent une solution (``assign_holders``). Sinon la
séance n'a personne : elle reste posée, sans tenue exigée, et le coach qu'elle nomme entre en collision
au diagnostic post-solve, qui nomme l'écart. Jamais d'INFEASIBLE dû à des verrous seuls."""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Mapping, Sequence
from typing import Any

from ...model import DEFAULT_SESSION_MINUTES, _time_to_minutes
from ..availability import HoldSession, _coach_available_at, assign_holders, holds_collide
from ..common import (
    AssignmentVariable,
    BoolVarLike,
    _assignment_day_start,
    _get,
    _hold_key,
    _late_arrival_relay_opens,
    _lock_hold_key,
    _record_closure,
)


def _add_lock_holds(
    model: Any,
    coach_map: Mapping[str, Sequence[Any]],
    player_map: Mapping[str, Sequence[Any]],
    rules: Mapping[str, Any],
) -> int:
    """Pose la tenue des verrous à plusieurs coachs, en ordre stable (cf. docstring du module)."""
    sole_locks: dict[str, list[HoldSession]] = defaultdict(list)
    playing: dict[str, list[HoldSession]] = defaultdict(list)
    shared_locks: list[tuple[HoldSession, list[str], int]] = []

    for index, locked in enumerate(getattr(model, "locked_slots", ()) or ()):
        team_id = str(_get(locked, "team_id", "teamId", default="") or "")
        venue_id = str(_get(locked, "venue_id", "venueId", default="") or "")
        day_raw = _get(locked, "day_of_week", "dayOfWeek", default=None)
        start_raw = _get(locked, "start_time", "startTime", default=None)
        if not team_id or day_raw is None or start_raw is None:
            continue
        day = int(day_raw)
        start = _time_to_minutes(start_raw)
        end = start + int(_get(locked, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))
        session = HoldSession(id=str(index), team_id=team_id, venue_id=venue_id, day=day, start=start, end=end)
        for player_id in player_map.get(team_id, ()):
            playing[str(player_id)].append(session)
        coaches = [str(c) for c in coach_map.get(team_id, ())]
        if len(coaches) == 1:
            sole_locks[coaches[0]].append(session)
        elif coaches:
            # Le relais d'arrivée tardive décale l'occupation du coach, comme pour un verrou à un coach.
            tolerance = int(_get(locked, "coach_late_tolerance_minutes", "coachLateToleranceMinutes", default=0) or 0)
            relay_opens = tolerance > 0 and _late_arrival_relay_opens(model, team_id, day, start, tolerance)
            coach_start = min(start + tolerance, end) if relay_opens else start
            shared_locks.append((session, coaches, coach_start))

    added = 0
    covered: list[HoldSession] = []
    candidates: dict[str, list[str]] = {}
    for session, coaches, coach_start in shared_locks:
        free = [c for c in coaches if _coach_available_at(c, session.day, session.start, rules)] or coaches
        eligible = [
            c
            for c in free
            if not any(holds_collide(session, other, same_team_collides=True) for other in sole_locks.get(c, ()))
            and not any(
                other.team_id != session.team_id
                and other.day == session.day
                and other.start < session.end
                and session.start < other.end
                for other in playing.get(c, ())
            )
        ]
        candidates[session.id] = eligible
        holders = assign_holders([*covered, session], candidates, same_team_collides=True)
        key = _lock_hold_key(session.team_id, session.venue_id, session.day, session.start, session.end)
        hold: dict[str, Any] = {
            "team": session.team_id,
            "venue": session.venue_id,
            "day": session.day,
            "start": session.start,
            "coach_start": coach_start,
            "end": session.end,
            "vars": {},
            "named": free[0],
        }
        model.lock_holds[key] = hold
        if holders is None:
            continue
        covered.append(session)
        for coach_id in eligible:
            hold["vars"][coach_id] = model.NewBoolVar(f"hold_lock_{session.id}_{coach_id}")
        model.Add(sum(hold["vars"].values()) >= 1)
        added += 1
    return added


def add_coach_hold_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    team_coach_map: dict[str, list[str]] | None = None,
    team_player_map: dict[str, list[str]] | None = None,
    coach_unavailability: Any = (),
) -> int:
    """Crée les tenues des séances qui ont le choix (cf. docstring du module). Modèle nu des tests de
    pose (sans ``hold_vars``) : rien n'est posé, toutes les variables restent opposées par ``x``."""
    if not team_coach_map or not isinstance(getattr(model, "hold_vars", None), dict):
        return 0

    rules: Mapping[str, Any] = coach_unavailability if isinstance(coach_unavailability, Mapping) else {}
    player_map = team_player_map or {}
    case_bvars: Mapping[tuple[str, str], list[tuple[frozenset[str], Any]]] = (
        getattr(model, "shared_block_case_bvars", {}) or {}
    )
    added = _add_lock_holds(model, team_coach_map, player_map, rules)

    holders_by_case: dict[tuple[str, str], dict[str, dict[str, BoolVarLike]]] = defaultdict(dict)
    for assignment in assignments:
        team_id = str(assignment.team_id)
        coaches = [str(c) for c in team_coach_map.get(team_id, ())]
        day, start = _assignment_day_start(assignment)
        if not coaches or day is None or start is None or assignment.venue_id is None or assignment.slot_id is None:
            continue
        case = (str(assignment.venue_id), str(assignment.slot_id))
        block_bvars = [b for members, b in case_bvars.get(case, ()) if team_id in members]
        if len(coaches) < 2 and not block_bvars:
            continue

        var = assignment.var
        players = {str(p) for p in player_map.get(team_id, ())}
        available = [c for c in coaches if _coach_available_at(c, day, start, rules)]
        # Tous indisponibles : la fermeture d'indisponibilité tranche. Sur une case de bloc, le relais
        # passe par un autre membre ; ailleurs, ils restent candidats pour le solve best-effort.
        eligible = available or ([] if block_bvars else coaches)
        holders: dict[str, BoolVarLike] = {}
        for coach_id in eligible:
            if coach_id in players:
                holders[coach_id] = var  # il joue dans la séance : il est là, il la tient.
                continue
            hold = model.NewBoolVar(f"hold_{var.Index()}_{coach_id}")
            model.Add(hold <= var)
            holders[coach_id] = hold
            added += 1
        model.hold_vars[_hold_key(var)] = holders
        holders_by_case[case][team_id] = holders
        if any(literal is var for literal in holders.values()):
            continue
        model.Add(sum(holders.values()) + sum(block_bvars) >= var)
        added += 1

    locked_coached_cases: set[tuple[str, str]] = set()
    for locked in getattr(model, "locked_slots", ()) or ():
        if team_coach_map.get(str(locked.get("team_id"))):
            minutes = _time_to_minutes(locked["start_time"])
            slot_id = f"{int(locked['day_of_week'])}:{minutes // 60:02d}:{minutes % 60:02d}"
            locked_coached_cases.add((str(locked["venue_id"]), slot_id))

    # Une séance de bloc active a au moins une tenue parmi ses membres, sauf si un membre VERROUILLÉ
    # coaché sur la case la tient déjà.
    for case, blocks in case_bvars.items():
        if case in locked_coached_cases:
            continue
        for members, block_var in blocks:
            if not any(team_coach_map.get(member) for member in members):
                continue
            member_holders = holders_by_case.get(case, {})
            literals = [literal for member in sorted(members) for literal in member_holders.get(member, {}).values()]
            model.Add(sum(literals) >= block_var)
            added += 1
    return added


def close_sessions_without_holder(model: Any) -> int:
    """Une séance dont toutes les tenues sont fermées par un verrou est elle-même fermée, cause
    nommée (rail des candidats fermés). Sur une case de bloc, un autre membre peut encore la tenir."""
    closures = getattr(model, "candidate_closures", None)
    holds = getattr(model, "hold_vars", None)
    if not closures or not holds:
        return 0
    case_bvars = getattr(model, "shared_block_case_bvars", {}) or {}
    added = 0
    for (team_id, venue_id, day, start), var in getattr(model, "x", {}).items():
        holders = holds.get(_hold_key(var))
        if not holders or any(team_id in members for members, _b in case_bvars.get((venue_id, f"{day}:{start}"), ())):
            continue
        if all(literal is not var and literal.Index() in closures for literal in holders.values()):
            model.Add(var == 0)
            _record_closure(model, var, {"kind": "hard_lock"})
            added += 1
    return added
