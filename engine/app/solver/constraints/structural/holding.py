"""« Tant qu'un coach tient la séance, c'est OK » (fondateur 2026-10-30) : qui peut tenir chaque séance.

Seul, un coach principal est indispensable ; à plusieurs, un seul suffit. Pour une séance qui a le
choix, ``h[séance, coach]`` dit que ce coach la tient : ``h <= x`` et ``Σh >= x``. Les rôles
(``overlap``) et les plafonds (``wellness``, ``travel``) opposent alors les tenues, pas les variables
``x``. Le rôle de joueur occupe sans condition ; un coach qui joue dans la séance la tient d'office.

Une séance à UN seul coach hors bloc n'a pas de tenue séparée, elle reste opposée par ``x`` : le modèle
d'un club sans co-coachs reste byte-identique (goldens intacts).

Séance de bloc : un coach de l'union des membres tient la séance de tous (``Σh + Σb >= x``, et une
tenue parmi les membres dès que ``b`` est actif). ``isRequired`` n'entre pas en jeu.

Verrous : un verrou est sans variable, souverain. Un verrou à plusieurs coachs consomme UN coach ; ceux
d'un même groupe déclaré sur la même case partagent une tenue, sur l'union de leurs coachs. Si les règles
dures (repos, trajet, enchaînement, autres verrous) ne laissent personne, la tenue cède (``unheld``) : le
verrou reste posé et le diagnostic post-solve nomme l'écart. Jamais d'INFEASIBLE dû à des verrous seuls."""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Mapping, Sequence
from typing import Any

from ...model import DEFAULT_SESSION_MINUTES, _time_to_minutes
from ..availability import HoldSession, _coach_available_at, holds_collide
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

# Le malus d'un verrou sans tenue : il domine tout placement, une séance libre ne prend jamais le coach
# d'un verrou. Il ne pèse que dans un club dont un verrou a le choix (sinon aucun terme, goldens intacts).
LOCK_UNHELD_WEIGHT = 1_000_000


def _lock_groups(sessions: list[HoldSession], partners: Mapping[str, set[str]]) -> list[list[HoldSession]]:
    """Les verrous, regroupés : ceux d'un même groupe DÉCLARÉ sur la même case forment une seule séance."""
    groups: list[list[HoldSession]] = []
    for session in sessions:
        for group in groups:
            head = group[0]
            same_case = (head.venue_id, head.day, head.start) == (session.venue_id, session.day, session.start)
            if same_case and any(member.team_id in partners.get(session.team_id, ()) for member in group):
                group.append(session)
                break
        else:
            groups.append([session])
    return groups


def _add_lock_holds(
    model: Any,
    coach_map: Mapping[str, Sequence[Any]],
    player_map: Mapping[str, Sequence[Any]],
    rules: Mapping[str, Any],
) -> int:
    """Pose la tenue des verrous qui ont le choix, en ordre stable (cf. docstring du module)."""
    playing: dict[str, list[HoldSession]] = defaultdict(list)
    coach_starts: dict[str, int] = {}
    sessions: list[HoldSession] = []

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
        sessions.append(session)
        for player_id in player_map.get(team_id, ()):
            playing[str(player_id)].append(session)
        # Le relais d'arrivée tardive décale l'occupation du coach, comme pour un verrou à un coach.
        tolerance = int(_get(locked, "coach_late_tolerance_minutes", "coachLateToleranceMinutes", default=0) or 0)
        relay_opens = tolerance > 0 and _late_arrival_relay_opens(model, team_id, day, start, tolerance)
        coach_starts[session.id] = min(start + tolerance, end) if relay_opens else start

    groups = _lock_groups(sessions, getattr(model, "block_partners", None) or {})
    coaches_of_group = [
        list(dict.fromkeys(str(c) for member in group for c in coach_map.get(member.team_id, ()))) for group in groups
    ]
    sole_locks: dict[str, list[HoldSession]] = defaultdict(list)
    for group, coaches in zip(groups, coaches_of_group, strict=True):
        if len(coaches) == 1:
            sole_locks[coaches[0]].extend(group)

    added = 0
    for group, coaches in zip(groups, coaches_of_group, strict=True):
        if len(coaches) < 2:
            continue
        head = group[0]
        team_ids = {member.team_id for member in group}
        free = [c for c in coaches if _coach_available_at(c, head.day, head.start, rules)] or coaches
        eligible = [
            c
            for c in free
            if not any(holds_collide(head, other, same_team_collides=True) for other in sole_locks.get(c, ()))
            and not any(
                other.team_id not in team_ids
                and other.day == head.day
                and other.start < head.end
                and head.start < other.end
                for other in playing.get(c, ())
            )
        ]
        # La tenue cède : ``unheld`` laisse le verrou posé sans personne quand une règle dure ne laisse
        # aucun coach. Son malus (placement) la réserve à ce cas ; le diagnostic post-solve la nomme.
        unheld = model.NewBoolVar(f"hold_lock_{head.id}_unheld")
        hold: dict[str, Any] = {
            "team": head.team_id,
            "teams": sorted(team_ids),
            "venue": head.venue_id,
            "day": head.day,
            "start": head.start,
            "coach_start": coach_starts[head.id],
            "end": head.end,
            "vars": {c: model.NewBoolVar(f"hold_lock_{head.id}_{c}") for c in eligible},
            "unheld": unheld,
            "named": free[0],
        }
        for member in group:
            key = _lock_hold_key(member.team_id, member.venue_id, member.day, member.start, member.end)
            model.lock_holds[key] = hold
        model.Add(sum(hold["vars"].values()) + unheld == 1)
        model.lock_hold_penalties.append((unheld, -LOCK_UNHELD_WEIGHT))
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
        # Il joue dans la séance : il est là, il la tient.
        playing_here = [c for c in eligible if c in players]
        if playing_here:
            model.hold_vars[_hold_key(var)] = {playing_here[0]: var}
            holders_by_case[case][team_id] = {playing_here[0]: var}
            continue
        holders: dict[str, BoolVarLike] = {}
        for coach_id in eligible:
            hold = model.NewBoolVar(f"hold_{var.Index()}_{coach_id}")
            model.Add(hold <= var)
            holders[coach_id] = hold
            added += 1
        model.hold_vars[_hold_key(var)] = holders
        holders_by_case[case][team_id] = holders
        if block_bvars:
            model.Add(sum(holders.values()) + sum(block_bvars) >= var)
        else:
            model.Add(sum(holders.values()) == var)  # une séance, un coach qui la tient : celui qu'elle affiche.
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
