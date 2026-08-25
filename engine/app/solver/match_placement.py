from __future__ import annotations

import logging
import uuid
from datetime import date, time
from typing import Any

from ortools.sat.python import cp_model

from app.schemas.match_input_schema import (
    MatchPlacementInputSchema,
    MatchSchema,
    SlotRotationSchema,
    TeamHabitSchema,
)

logger = logging.getLogger("engine.match_placement")

# ── Geometry (spec §4bis: the 2h15 home footprint occupies the venue) ─────────
STEP_MIN = 15
BEFORE_KICKOFF_MIN = 30
AFTER_KICKOFF_MIN = 105
FOOTPRINT_MIN = BEFORE_KICKOFF_MIN + AFTER_KICKOFF_MIN

# ── Objective weights (ADR-0003 — fixed, documented, golden-pinned) ──────────
# Placement dominates every SOFT combination of one match: the solver never
# sacrifices a placement for comfort.
W_PLACE = 10_000
W_COACH_MAIN = 60
W_LINK_NOT_SIMULTANEOUS = 40
W_HABIT_TIME = 15  # on top of the implicit day match (constant per candidate set)
W_HABIT_VENUE = 5
W_PROTECT_HABIT = 25
# RMM-5 (§8.2) — the A/B rotation image is the habit mechanism extended AT PARITY:
# a member's HOME match on the slot's day is attracted to (kickoff, venue) exactly
# like a habit, and the slot window is protected on member-free dates at
# W_PROTECT_HABIT. The backend suppléance guarantees a member never carries both a
# rotation and a same-day habit, so these never double up.
W_ROTATION_TIME = 15  # strict parity with W_HABIT_TIME
W_ROTATION_VENUE = 5  # strict parity with W_HABIT_VENUE
W_BACK_TO_BACK = 15
W_COACH_ASSISTANT = 10
W_STABILITY = 8
W_GAP_PER_STEP = 1

REASON_MESSAGES = {
    "venue_unavailable": "Tous les gymnases de match sont indisponibles à cette date.",
    "no_access_window": "Aucune fenêtre d'accès match ne contient l'empreinte de 2h15 ce jour-là.",
    "no_league_intersection": "Les fenêtres de la ligue ne croisent aucune fenêtre d'accès ce jour-là.",
    "venue_full": "Tous les créneaux licites sont déjà occupés par d'autres matchs.",
}


def _minutes(value: time) -> int:
    return value.hour * 60 + value.minute


def _to_time(total: int) -> time:
    return time(hour=(total // 60) % 24, minute=total % 60)


def _iso_day(value: date) -> int:
    return value.isoweekday()


class _Candidate:
    __slots__ = ("kickoff_min", "var", "venue_id")

    def __init__(self, venue_id: str, kickoff_min: int, var: cp_model.IntVar) -> None:
        self.venue_id = venue_id
        self.kickoff_min = kickoff_min
        self.var = var


def _candidate_kickoffs(
    input_data: MatchPlacementInputSchema,
    match: MatchSchema,
) -> tuple[dict[str, list[int]], str]:
    """Legal (venue → kickoff minutes) domain of a TO_PLACE match, plus the
    reason when it is EMPTY (derived at build time, before any solve)."""
    day = _iso_day(match.match_date)
    team = next((t for t in input_data.teams if t.id == match.team_id), None)
    league = [w for w in (team.league_windows if team else []) if w.day_of_week == day]
    league_mapped = team is not None and len(team.league_windows) > 0

    domain: dict[str, list[int]] = {}
    saw_open_venue = False
    saw_access_candidate = False
    for venue in input_data.venues:
        if any(u.start_date <= match.match_date <= u.end_date for u in venue.unavailabilities):
            continue
        saw_open_venue = True
        kicks: list[int] = []
        for window in venue.match_windows:
            if window.day_of_week != day:
                continue
            # The WHOLE footprint must fit inside the access window (warm-up
            # occupies the court too).
            first = _minutes(window.start) + BEFORE_KICKOFF_MIN
            last = _minutes(window.end) - AFTER_KICKOFF_MIN
            kick = ((first + STEP_MIN - 1) // STEP_MIN) * STEP_MIN
            while kick <= last:
                saw_access_candidate = True
                # League HARD only when the team maps: the kickoff must fall in
                # SOME league window of that day.
                if not league_mapped or any(_minutes(w.kickoff_min) <= kick <= _minutes(w.kickoff_max) for w in league):
                    kicks.append(kick)
                kick += STEP_MIN
        if kicks:
            domain[venue.id] = kicks

    if domain:
        return domain, ""
    if not saw_open_venue:
        return {}, "venue_unavailable"
    if not saw_access_candidate:
        return {}, "no_access_window"
    return {}, "no_league_intersection"


def solve_match_placement(input_data: MatchPlacementInputSchema) -> dict[str, Any]:
    """Place every placeable TO_PLACE match; name why the rest stayed out.

    HARD (never violated in the output): access windows ∩ league windows,
    venue unavailabilities, per-(venue, date) no-overlap of the 2h15 footprints
    (FIXED matches consume their slot without being variables).
    SOFT: habits, A/B slot rotations (attraction + window protection, at parity
    with habits — RMM-5), MAIN/ASSISTANT coach clashes (vs matches AND projected
    trainings), NOT_SIMULTANEOUS links, BACK_TO_BACK chains, habit-window
    protection, day compaction, re-solve stability.
    """
    model = cp_model.CpModel()

    teams_by_id = {t.id: t for t in input_data.teams}
    to_place = [m for m in input_data.matches if m.kind == "TO_PLACE"]
    fixed = [m for m in input_data.matches if m.kind == "FIXED"]

    # RMM-5: rotations a team belongs to, indexed by (teamId, ISO day) for the
    # per-candidate attraction term below. Iteration order stays that of the
    # (deterministically sorted) payload.
    rotations_by_team_day: dict[tuple[str, int], list[SlotRotationSchema]] = {}
    for rotation in input_data.slot_rotations:
        for member_id in rotation.team_ids:
            rotations_by_team_day.setdefault((member_id, rotation.day_of_week), []).append(rotation)

    # 1. Domains + pre-solve reasons.
    candidates: dict[str, list[_Candidate]] = {}
    is_placed: dict[str, cp_model.IntVar] = {}
    unplaced: list[dict[str, str]] = []
    for match in to_place:
        domain, reason = _candidate_kickoffs(input_data, match)
        if not domain:
            unplaced.append({"matchId": match.id, "reason": reason, "message": REASON_MESSAGES[reason]})
            continue
        cands: list[_Candidate] = []
        for venue_id, kicks in domain.items():
            for kick in kicks:
                var = model.new_bool_var(f"x_{match.id}_{venue_id}_{kick}")
                cands.append(_Candidate(venue_id, kick, var))
        placed = model.new_bool_var(f"placed_{match.id}")
        model.add(sum(c.var for c in cands) == 1).only_enforce_if(placed)
        model.add(sum(c.var for c in cands) == 0).only_enforce_if(placed.Not())
        candidates[match.id] = cands
        is_placed[match.id] = placed

    solvable = [m for m in to_place if m.id in candidates]

    # 2. Venue no-overlap per (venue, date). FIXED anchors are DATA, not model
    # variables: they PRUNE the candidates they cover instead of entering the
    # NoOverlap as fixed intervals — two manual anchors may legitimately collide
    # (the manual loop never blocks, the diagnostic alerts), and a fixed-interval
    # pair in overlap would make the WHOLE model infeasible and unplace
    # everything (bug caught by smoke-place-matches, P1-4 PR E1).
    fixed_busy: dict[tuple[str, date], list[tuple[int, int]]] = {}
    for match in fixed:
        if match.venue_id is None or match.kickoff is None:  # guarded by schema
            continue
        start = _minutes(match.kickoff) - BEFORE_KICKOFF_MIN
        fixed_busy.setdefault((match.venue_id, match.match_date), []).append((start, start + FOOTPRINT_MIN))

    intervals_by_group: dict[tuple[str, date], list[cp_model.IntervalVar]] = {}
    for match in solvable:
        for cand in candidates[match.id]:
            start = cand.kickoff_min - BEFORE_KICKOFF_MIN
            busy = fixed_busy.get((cand.venue_id, match.match_date), [])
            if any(start < b_end and b_start < start + FOOTPRINT_MIN for b_start, b_end in busy):
                model.add(cand.var == 0)
                continue
            interval = model.new_optional_fixed_size_interval_var(
                start, FOOTPRINT_MIN, cand.var, f"iv_{match.id}_{cand.venue_id}_{cand.kickoff_min}"
            )
            intervals_by_group.setdefault((cand.venue_id, match.match_date), []).append(interval)
    for group in intervals_by_group.values():
        if len(group) > 1:
            model.add_no_overlap(group)

    objective: list[cp_model.LinearExpr | cp_model.IntVar] = []
    for match in solvable:
        objective.append(W_PLACE * is_placed[match.id])

    # 3. Per-candidate constant terms: habit bonus, stability, protection,
    # coach clash vs FIXED/AWAY footprints and projected trainings.
    fixed_windows_by_coach: dict[tuple[str, date], list[tuple[int, int]]] = {}
    for match in input_data.matches:
        if match.kind == "TO_PLACE" or match.kickoff is None:
            continue
        team = teams_by_id.get(match.team_id)
        if team is None:
            continue
        start = _minutes(match.kickoff) - BEFORE_KICKOFF_MIN
        end = _minutes(match.kickoff) + AFTER_KICKOFF_MIN
        for ref in team.coaches:
            fixed_windows_by_coach.setdefault((ref.coach_id, match.match_date), []).append((start, end))
    for occupancy in input_data.training_occupancies:
        fixed_windows_by_coach.setdefault((occupancy.coach_id, occupancy.occupancy_date), []).append(
            (_minutes(occupancy.start), _minutes(occupancy.end))
        )

    # Habit-window protection: dates where a team with a venue-anchored habit
    # has NO match at all — its habitual footprint is defended.
    match_dates = sorted({m.match_date for m in input_data.matches})
    team_dates = {(m.team_id, m.match_date) for m in input_data.matches}
    protected: dict[tuple[str, date], list[tuple[int, int]]] = {}
    for team in input_data.teams:
        for habit in team.habits:
            if habit.venue_id is None:
                continue
            for day_key in match_dates:
                if _iso_day(day_key) != habit.day_of_week or (team.id, day_key) in team_dates:
                    continue
                kick = _minutes(habit.kickoff)
                protected.setdefault((habit.venue_id, day_key), []).append(
                    (kick - BEFORE_KICKOFF_MIN, kick + AFTER_KICKOFF_MIN)
                )

    # Rotation-window protection (RMM-5, §8): on a date at the slot's day where NO
    # member has a match at all, the shared slot's 2h15 footprint is defended
    # against other teams — the mirror of the habit protection above.
    for rotation in input_data.slot_rotations:
        members = set(rotation.team_ids)
        for day_key in match_dates:
            if _iso_day(day_key) != rotation.day_of_week:
                continue
            if any((member_id, day_key) in team_dates for member_id in members):
                continue
            kick = _minutes(rotation.kickoff)
            protected.setdefault((rotation.venue_id, day_key), []).append(
                (kick - BEFORE_KICKOFF_MIN, kick + AFTER_KICKOFF_MIN)
            )

    for match in solvable:
        team = teams_by_id.get(match.team_id)
        team_habit: TeamHabitSchema | None = None
        if team is not None:
            team_habit = next((h for h in team.habits if h.day_of_week == _iso_day(match.match_date)), None)
        match_rotations = rotations_by_team_day.get((match.team_id, _iso_day(match.match_date)), [])
        for cand in candidates[match.id]:
            weight = 0
            start = cand.kickoff_min - BEFORE_KICKOFF_MIN
            end = cand.kickoff_min + AFTER_KICKOFF_MIN
            if team_habit is not None:
                if cand.kickoff_min == _minutes(team_habit.kickoff):
                    weight += W_HABIT_TIME
                if team_habit.venue_id is not None and cand.venue_id == team_habit.venue_id:
                    weight += W_HABIT_VENUE
            # Rotation attraction (RMM-5) — extension of the habit bonus at strict
            # parity: pull a member's HOME match to the shared slot's (kickoff, venue).
            for rotation in match_rotations:
                if cand.kickoff_min == _minutes(rotation.kickoff):
                    weight += W_ROTATION_TIME
                if cand.venue_id == rotation.venue_id:
                    weight += W_ROTATION_VENUE
            if (
                match.current_venue_id == cand.venue_id
                and match.current_kickoff is not None
                and _minutes(match.current_kickoff) == cand.kickoff_min
            ):
                weight += W_STABILITY
                model.add_hint(cand.var, 1)
            for p_start, p_end in protected.get((cand.venue_id, match.match_date), []):
                if start < p_end and p_start < end:
                    weight -= W_PROTECT_HABIT
            if team is not None:
                for ref in team.coaches:
                    role_weight = W_COACH_MAIN if ref.role == "MAIN" else W_COACH_ASSISTANT
                    for f_start, f_end in fixed_windows_by_coach.get((ref.coach_id, match.match_date), []):
                        if start < f_end and f_start < end:
                            weight -= role_weight
            if weight:
                objective.append(weight * cand.var)

    # 4. Pairwise SOFT between TO_PLACE matches: shared-coach clash, links.
    def _overlap_pairs(left: MatchSchema, right: MatchSchema, penalty: int, tag: str) -> None:
        if left.match_date != right.match_date:
            return
        for lc in candidates[left.id]:
            l_start, l_end = lc.kickoff_min - BEFORE_KICKOFF_MIN, lc.kickoff_min + AFTER_KICKOFF_MIN
            for rc in candidates[right.id]:
                r_start, r_end = rc.kickoff_min - BEFORE_KICKOFF_MIN, rc.kickoff_min + AFTER_KICKOFF_MIN
                if l_start < r_end and r_start < l_end:
                    both = model.new_bool_var(f"{tag}_{left.id}_{right.id}_{lc.kickoff_min}_{rc.kickoff_min}")
                    # Penalised (negative in a Maximize): only the LOWER bound is
                    # needed — the maximiser pushes `both` to 0 unless lc∧rc force it.
                    model.add(both >= lc.var + rc.var - 1)
                    objective.append(-penalty * both)

    coach_roles: dict[str, dict[str, str]] = {}
    for team in input_data.teams:
        for ref in team.coaches:
            coach_roles.setdefault(team.id, {})[ref.coach_id] = ref.role
    for i, left in enumerate(solvable):
        for right in solvable[i + 1 :]:
            shared = set(coach_roles.get(left.team_id, {})) & set(coach_roles.get(right.team_id, {}))
            for coach_id in shared:
                role = (
                    "MAIN"
                    if coach_roles[left.team_id][coach_id] == "MAIN" or coach_roles[right.team_id][coach_id] == "MAIN"
                    else "ASSISTANT"
                )
                _overlap_pairs(left, right, W_COACH_MAIN if role == "MAIN" else W_COACH_ASSISTANT, f"coach_{coach_id}")

    matches_by_team: dict[str, list[MatchSchema]] = {}
    for match in solvable:
        matches_by_team.setdefault(match.team_id, []).append(match)
    for link in input_data.team_links:
        for left in matches_by_team.get(link.team_a_id, []):
            for right in matches_by_team.get(link.team_b_id, []):
                if link.type == "NOT_SIMULTANEOUS":
                    _overlap_pairs(left, right, W_LINK_NOT_SIMULTANEOUS, "link")
                elif link.type == "BACK_TO_BACK" and left.match_date == right.match_date:
                    # Chained = same venue, footprints contiguous (Δkickoff = 2h15).
                    for lc in candidates[left.id]:
                        for rc in candidates[right.id]:
                            if lc.venue_id == rc.venue_id and abs(lc.kickoff_min - rc.kickoff_min) == FOOTPRINT_MIN:
                                chained = model.new_bool_var(f"btb_{left.id}_{right.id}_{lc.kickoff_min}")
                                # Rewarded (positive): only the UPPER bounds are
                                # needed — the maximiser pulls `chained` to 1
                                # whenever both candidates are chosen.
                                model.add(chained <= lc.var)
                                model.add(chained <= rc.var)
                                objective.append(W_BACK_TO_BACK * chained)

    # 5. Day compaction per (venue, date): penalise the idle span between the
    # first and last footprint (span − 135 × placed count, in 15-min steps).
    day_start, day_end = 0, 24 * 60
    groups: dict[tuple[str, date], list[tuple[cp_model.IntVar, int]]] = {}
    for match in solvable:
        for cand in candidates[match.id]:
            groups.setdefault((cand.venue_id, match.match_date), []).append((cand.var, cand.kickoff_min))
    fixed_by_group: dict[tuple[str, date], list[int]] = {}
    for match in fixed:
        if match.venue_id is not None and match.kickoff is not None:
            fixed_by_group.setdefault((match.venue_id, match.match_date), []).append(_minutes(match.kickoff))
    for key, group_cands in groups.items():
        fixed_kicks = fixed_by_group.get(key, [])
        span_start = model.new_int_var(day_start, day_end, f"span_start_{key[0]}_{key[1]}")
        span_end = model.new_int_var(day_start, day_end, f"span_end_{key[0]}_{key[1]}")
        count_expr: list[cp_model.IntVar] = []
        for var, kick in group_cands:
            model.add(span_start <= kick - BEFORE_KICKOFF_MIN).only_enforce_if(var)
            model.add(span_end >= kick + AFTER_KICKOFF_MIN).only_enforce_if(var)
            count_expr.append(var)
        for kick in fixed_kicks:
            model.add(span_start <= kick - BEFORE_KICKOFF_MIN)
            model.add(span_end >= kick + AFTER_KICKOFF_MIN)
        n_fixed = len(fixed_kicks)
        total = sum(count_expr) + n_fixed if count_expr else n_fixed
        gap = model.new_int_var(0, day_end, f"gap_{key[0]}_{key[1]}")
        # gap ≥ span − 135·n ; NoOverlap guarantees span ≥ 135·n when all sit
        # apart, so gap measures idle time. The maximiser pushes gap down to its
        # lower bound (it enters the objective negatively).
        model.add(gap >= (span_end - span_start) - FOOTPRINT_MIN * total)
        if n_fixed == 0:
            any_placed = model.new_bool_var(f"any_{key[0]}_{key[1]}")
            model.add_max_equality(any_placed, [var for var, _ in group_cands])
            model.add(span_end == span_start).only_enforce_if(any_placed.Not())
        # Per 15-min STEP (not per minute) — a 6 h hole must never outweigh a
        # coach clash: 24 steps × 1 « 60 (the D5 hierarchy holds).
        gap_steps = model.new_int_var(0, day_end // STEP_MIN, f"gap_steps_{key[0]}_{key[1]}")
        model.add_division_equality(gap_steps, gap, STEP_MIN)
        objective.append((-W_GAP_PER_STEP) * gap_steps)

    model.maximize(sum(objective))

    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = float(input_data.solver_timeout_seconds)
    solver.parameters.num_search_workers = 1  # deterministic — golden fixtures depend on it
    solver.parameters.random_seed = input_data.solver_seed
    solver_status = solver.solve(model)

    placements: list[dict[str, Any]] = []
    diagnostics: list[dict[str, Any]] = []
    if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        for match in solvable:
            chosen = next((c for c in candidates[match.id] if solver.value(c.var) == 1), None)
            if chosen is not None:
                placements.append(
                    {"matchId": match.id, "venueId": chosen.venue_id, "kickoff": _to_time(chosen.kickoff_min)}
                )
            else:
                unplaced.append({"matchId": match.id, "reason": "venue_full", "message": REASON_MESSAGES["venue_full"]})
    else:  # pragma: no cover — the model is always feasible (placement optional)
        for match in solvable:
            unplaced.append({"matchId": match.id, "reason": "venue_full", "message": REASON_MESSAGES["venue_full"]})

    for item in unplaced:
        diagnostics.append(
            {
                "id": str(uuid.uuid5(uuid.NAMESPACE_URL, f"unplaced:{item['matchId']}")),
                "type": "unplaced_match",
                "severity": "warning",
                "message": item["message"],
                "suggestions": ["Demandez une dérogation à la ligue ou ouvrez une fenêtre d'accès match."],
            }
        )

    logger.info(
        "match placement club=%s to_place=%d placed=%d unplaced=%d status=%s",
        input_data.club_id,
        len(to_place),
        len(placements),
        len(unplaced),
        solver.status_name(solver_status),
    )

    return {
        "status": "completed",
        "placements": placements,
        "unplaced": unplaced,
        "diagnostics": diagnostics,
        "metrics": {
            "solver_version": "cp-sat",
            "nb_variables": len(model.proto.variables),
            "nb_constraints": len(model.proto.constraints),
            "wall_time_ms": int(solver.wall_time * 1000),
        },
    }
