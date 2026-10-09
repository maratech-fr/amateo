"""Placement daté des matchs — solve ISO-semaine par ISO-semaine (paquet).

Ce ``__init__`` est le point d'entrée du paquet : il porte cette docstring, le ``logger``, le
plafond de BUILD ``BUILD_BUDGET_SECONDS`` (ADR-0001), le cœur ``_place_matches`` (indécoupable
verbatim — il tient ses closures ``_ensure_budget`` / ``_greedy_key`` / ``_apply_greedy_hints`` /
``_overlap_pairs``), le solve d'une semaine ``_solve_one_week`` et l'orchestrateur public
``solve_match_placement`` ; les ré-exports ``from .x import y as y`` de chaque sous-module gardent
la surface d'import byte-identique au module d'avant la découpe (noms publics ET privés — plusieurs
``_…`` et constantes sont importés directement par ``tests/``).

Les sous-modules forment un DAG simple assis sur les schémas ``match_input_schema`` : ``geometry``
(constantes de durée D1/lot M, lecteurs d'horaire ``_minutes`` / ``_to_time`` / ``_iso_day``,
prédicats de fenêtre ``_kick_in_club_rule`` / ``_kick_in_unavailability``, durées ``_durations`` et
personnes ``_team_players``) · ``weights`` (les poids d'objectif SOFT, ADR-0003) · ``reasons``
(table ``REASON_MESSAGES`` et reclassement ``_remaining_reason``) · ``budget`` (dépassement de
build ``_BuildBudgetExceeded`` et réponse ``_too_large_result``) · ``candidates`` (seule arête
interne : ``_candidate_kickoffs`` assis sur ``geometry``, et la classe ``_Candidate``) · ``weeks``
(partition ``_partition_by_iso_week`` / clé ``_iso_week`` et fusion ``_merge_week_results``).

⚠ Coutures : ``tests/test_match_placement.py`` fait
``monkeypatch.setattr(match_placement, "BUILD_BUDGET_SECONDS", 0.0)`` — ``_place_matches`` lit cette
constante dans SES globals (ce module), d'où son ancrage ici. Et ``main.py`` dé-pickle
``solve_match_placement`` par nom qualifié dans le process fils (executor ``spawn``) : il doit rester
défini DANS ce ``__init__`` (``__module__ == "app.solver.match_placement"``).
"""

from __future__ import annotations

import logging
import time as time_module
import uuid
from datetime import date
from typing import Any

from ortools.sat.python import cp_model

from app.schemas.match_input_schema import (
    CoachUnavailabilitySchema,
    MatchPlacementInputSchema,
    MatchSchema,
    TeamHabitSchema,
)

from .budget import (
    _BuildBudgetExceeded as _BuildBudgetExceeded,
)
from .budget import (
    _too_large_result as _too_large_result,
)
from .candidates import (
    _Candidate as _Candidate,
)
from .candidates import (
    _candidate_kickoffs as _candidate_kickoffs,
)
from .geometry import (
    DEFAULT_MATCH_MIN as DEFAULT_MATCH_MIN,
)
from .geometry import (
    DEFAULT_WARMUP_MIN as DEFAULT_WARMUP_MIN,
)
from .geometry import (
    STEP_MIN as STEP_MIN,
)
from .geometry import (
    _durations as _durations,
)
from .geometry import (
    _iso_day as _iso_day,
)
from .geometry import (
    _kick_in_club_rule as _kick_in_club_rule,
)
from .geometry import (
    _kick_in_unavailability as _kick_in_unavailability,
)
from .geometry import (
    _minutes as _minutes,
)
from .geometry import (
    _team_players as _team_players,
)
from .geometry import (
    _to_time as _to_time,
)
from .reasons import (
    REASON_MESSAGES as REASON_MESSAGES,
)
from .reasons import (
    _remaining_reason as _remaining_reason,
)
from .weeks import (
    _iso_week as _iso_week,
)
from .weeks import (
    _merge_week_results as _merge_week_results,
)
from .weeks import (
    _partition_by_iso_week as _partition_by_iso_week,
)
from .weights import (
    W_BACK_TO_BACK as W_BACK_TO_BACK,
)
from .weights import (
    W_CLUB_RULE as W_CLUB_RULE,
)
from .weights import (
    W_COACH_ASSISTANT as W_COACH_ASSISTANT,
)
from .weights import (
    W_COACH_MAIN as W_COACH_MAIN,
)
from .weights import (
    W_COACH_UNAVAILABLE as W_COACH_UNAVAILABLE,
)
from .weights import (
    W_GAP_PER_STEP as W_GAP_PER_STEP,
)
from .weights import (
    W_HABIT_TIME as W_HABIT_TIME,
)
from .weights import (
    W_HABIT_VENUE as W_HABIT_VENUE,
)
from .weights import (
    W_LINK_NOT_SIMULTANEOUS as W_LINK_NOT_SIMULTANEOUS,
)
from .weights import (
    W_PLACE as W_PLACE,
)
from .weights import (
    W_PROTECT_HABIT as W_PROTECT_HABIT,
)
from .weights import (
    W_STABILITY as W_STABILITY,
)

logger = logging.getLogger("engine.match_placement")

# ── Build budget (ADR-0001: name the impossible, never hang) ──────────────────
# The CP-SAT time limit only bounds the SOLVE. A pathological placement problem
# (thousands of matches × wide windows → millions of candidate pairs) can spin
# the model BUILD — the candidate/no-overlap/pairwise loops below are O(matches²)
# and O(candidates²) — for minutes before the solver even starts. The schema caps
# (MAX_MATCHES=2000) bound the shape but not the pair explosion, so we watch a
# wall-clock deadline over the hot loops and abort with a named diagnostic rather
# than let a request hang. 10 s is orders of magnitude over a real club's build.
BUILD_BUDGET_SECONDS = 10.0


def _place_matches(input_data: MatchPlacementInputSchema) -> dict[str, Any]:
    """Place every placeable TO_PLACE match; name why the rest stayed out.

    HARD (never violated in the output): access windows ∩ league windows,
    venue unavailabilities, per-(venue, date) no-overlap of the MATCH windows
    ([kickoff, kickoff + matchMinutes] — the warm-up no longer occupies the
    court, D1; FIXED matches consume their slot without being variables).
    SOFT: habits (attraction + window protection — P4-271: the ideal slot is a
    SOFT preference, the week A/B tag never reaches the engine), person clashes —
    coach (MAIN/ASSISTANT) or active player (P4-240 ③) — vs FIXED anchors AND
    projected trainings, on the PERSON window (warm-up-free since lot M; AWAY
    footprints ignored since P4-240 ③, décision B), NOT_SIMULTANEOUS links (same
    window), BACK_TO_BACK chains, habit-window protection, day compaction,
    re-solve stability.
    """
    model = cp_model.CpModel()
    deadline = time_module.monotonic() + BUILD_BUDGET_SECONDS

    teams_by_id = {t.id: t for t in input_data.teams}
    to_place = [m for m in input_data.matches if m.kind == "TO_PLACE"]
    fixed = [m for m in input_data.matches if m.kind == "FIXED"]

    # 1. Domains + pre-solve reasons.
    candidates: dict[str, list[_Candidate]] = {}
    is_placed: dict[str, cp_model.IntVar] = {}
    unplaced: list[dict[str, str]] = []

    def _ensure_budget() -> None:
        # One cheap wall-clock check per hot-loop iteration: raise (with the shape
        # measured so far) rather than let an O(matches²)/O(candidates²) build hang.
        if time_module.monotonic() > deadline:
            raise _BuildBudgetExceeded(len(to_place), sum(len(c) for c in candidates.values()))

    for match in to_place:
        _ensure_budget()
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

    # 2. Venue no-overlap per (venue, date), on the MATCH window
    # [kickoff, kickoff + matchMinutes] (D1 — no warm-up). FIXED anchors are DATA,
    # not model variables: they PRUNE the candidates they cover instead of
    # entering the NoOverlap as fixed intervals — two manual anchors may
    # legitimately collide (the manual loop never blocks, the diagnostic alerts),
    # and a fixed-interval pair in overlap would make the WHOLE model infeasible
    # and unplace everything (bug caught by smoke-place-matches, P1-4 PR E1).
    fixed_busy: dict[tuple[str, date], list[tuple[int, int]]] = {}
    for match in fixed:
        if match.venue_id is None or match.kickoff is None:  # guarded by schema
            continue
        match_min, _ = _durations(teams_by_id.get(match.team_id))
        start = _minutes(match.kickoff)
        fixed_busy.setdefault((match.venue_id, match.match_date), []).append((start, start + match_min))

    intervals_by_group: dict[tuple[str, date], list[cp_model.IntervalVar]] = {}
    for match in solvable:
        _ensure_budget()
        match_min, _ = _durations(teams_by_id.get(match.team_id))
        for cand in candidates[match.id]:
            start = cand.kickoff_min
            busy = fixed_busy.get((cand.venue_id, match.match_date), [])
            if any(start < b_end and b_start < start + match_min for b_start, b_end in busy):
                model.add(cand.var == 0)
                continue
            interval = model.new_optional_fixed_size_interval_var(
                start, match_min, cand.var, f"iv_{match.id}_{cand.venue_id}_{cand.kickoff_min}"
            )
            intervals_by_group.setdefault((cand.venue_id, match.match_date), []).append(interval)
    for group in intervals_by_group.values():
        if len(group) > 1:
            model.add_no_overlap(group)

    # ── Warm-start (ADR-0003): a deterministic greedy first-fit over the pruned
    # domains hands CP-SAT ONE coherent hint per match — a feasible placement to
    # improve on — so the budget is spent optimising, not rediscovering a feasible
    # point. Order (date, team) is stable; per match the preferred candidate is the
    # habit slot, else the current SOLVER placement, else the first legal
    # free slot, each checked against the FIXED anchors AND the slots the greedy has
    # already taken. It ABSORBS the old stability hint: exactly one hint set, never
    # two contradictory ones on the same match.
    def _greedy_key(
        cand: _Candidate,
        habit: TeamHabitSchema | None,
        match: MatchSchema,
    ) -> tuple[int, str, int]:
        # rank 0 = habit ideal slot ; 1 = current SOLVER placement ;
        # 2 = any other legal slot. Ties broken by (venue, kickoff) = domain order.
        rank = 2
        if (
            habit is not None
            and cand.kickoff_min == _minutes(habit.kickoff)
            and (habit.venue_id is None or cand.venue_id == habit.venue_id)
        ):
            rank = 0
        elif (
            match.current_venue_id == cand.venue_id
            and match.current_kickoff is not None
            and _minutes(match.current_kickoff) == cand.kickoff_min
        ):
            rank = 1
        return (rank, cand.venue_id, cand.kickoff_min)

    def _apply_greedy_hints() -> None:
        greedy_busy: dict[tuple[str, date], list[tuple[int, int]]] = {
            key: list(windows) for key, windows in fixed_busy.items()
        }
        for match in sorted(solvable, key=lambda m: (m.match_date, m.team_id)):
            _ensure_budget()
            match_min, _ = _durations(teams_by_id.get(match.team_id))
            team = teams_by_id.get(match.team_id)
            habit = (
                next((h for h in team.habits if h.day_of_week == _iso_day(match.match_date)), None) if team else None
            )
            cands = candidates[match.id]
            chosen: _Candidate | None = None
            # Decorate-sort-undecorate: the key is computed eagerly in the generator
            # (no closure over the loop variable → no B023), and `sorted` compares
            # only ``pair[0]`` so the _Candidate is never ordered directly.
            ordered = sorted(
                ((_greedy_key(cand, habit, match), cand) for cand in cands),
                key=lambda pair: pair[0],
            )
            for _key, cand in ordered:
                busy = greedy_busy.get((cand.venue_id, match.match_date), [])
                if not any(
                    cand.kickoff_min < b_end and b_start < cand.kickoff_min + match_min for b_start, b_end in busy
                ):
                    chosen = cand
                    break
            for cand in cands:
                model.add_hint(cand.var, 1 if cand is chosen else 0)
            model.add_hint(is_placed[match.id], 1 if chosen is not None else 0)
            if chosen is not None:
                greedy_busy.setdefault((chosen.venue_id, match.match_date), []).append(
                    (chosen.kickoff_min, chosen.kickoff_min + match_min)
                )

    _apply_greedy_hints()

    objective: list[cp_model.LinearExpr | cp_model.IntVar] = []
    for match in solvable:
        objective.append(W_PLACE * is_placed[match.id])

    # 3. Per-candidate constant terms: habit bonus, stability, protection,
    # person clash vs FIXED footprints and projected trainings. P4-240 ③ (décision
    # B): only FIXED (home anchors) project a person window — AWAY matches are
    # IGNORED by the placement solver (« c'est la vie ; le radar signale le conflit »),
    # so no travel leg survives here. Lot M — the person window is warm-up-FREE, and
    # FIXED = home = no travel, so it is [kickoff, kickoff + matchMinutes]. A PERSON is
    # a coach OR an active player (P4-240 ③): both project the same window.
    fixed_windows_by_coach: dict[tuple[str, date], list[tuple[int, int]]] = {}
    for match in fixed:
        if match.kickoff is None:  # guarded by the schema (a FIXED match is anchored)
            continue
        team = teams_by_id.get(match.team_id)
        if team is None:
            continue
        match_min, _ = _durations(team)
        start = _minutes(match.kickoff)
        end = start + match_min
        for ref in team.coaches:
            fixed_windows_by_coach.setdefault((ref.coach_id, match.match_date), []).append((start, end))
        for player_id in _team_players(team):
            fixed_windows_by_coach.setdefault((player_id, match.match_date), []).append((start, end))
    for occupancy in input_data.training_occupancies:
        fixed_windows_by_coach.setdefault((occupancy.coach_id, occupancy.occupancy_date), []).append(
            (_minutes(occupancy.start), _minutes(occupancy.end))
        )

    # Habit-window protection (P4-271): dates where a team with a venue-anchored
    # habit has NO match at all — its habitual MATCH window [kickoff, kickoff +
    # matchMinutes] is defended (aligned on the venue occupancy, D1). Stored as a
    # SET per (venue, date): two teams whose ideal slots coincide physically (the
    # A/B alternation) protect the SAME window on a member-free date — deduped so a
    # third team's overlapping candidate is penalised W_PROTECT_HABIT ONCE, never
    # twice (a −50 would wrongly outweigh a −25 real habit conflict).
    match_dates = sorted({m.match_date for m in input_data.matches})
    team_dates = {(m.team_id, m.match_date) for m in input_data.matches}
    protected: dict[tuple[str, date], set[tuple[int, int]]] = {}
    for team in input_data.teams:
        match_min, _ = _durations(team)
        for habit in team.habits:
            if habit.venue_id is None:
                continue
            for day_key in match_dates:
                if _iso_day(day_key) != habit.day_of_week or (team.id, day_key) in team_dates:
                    continue
                kick = _minutes(habit.kickoff)
                protected.setdefault((habit.venue_id, day_key), set()).add((kick, kick + match_min))

    # P4-272 ⑤ — coach unavailabilities indexed by coach id (SOFT). A candidate whose
    # kickoff falls in an unavailable window of one of the match team's coaches, on a
    # covered ISO day, is penalised W_COACH_UNAVAILABLE below.
    coach_unavailabilities: dict[str, list[CoachUnavailabilitySchema]] = {}
    for unav in input_data.coach_unavailabilities:
        coach_unavailabilities.setdefault(unav.coach_id, []).append(unav)

    for match in solvable:
        _ensure_budget()
        team = teams_by_id.get(match.team_id)
        match_min, _ = _durations(team)
        team_habit: TeamHabitSchema | None = None
        if team is not None:
            team_habit = next((h for h in team.habits if h.day_of_week == _iso_day(match.match_date)), None)
        # P4-272 ③ — PREFERRED club rules covering this match's ISO day: a candidate
        # that violates one is penalised W_CLUB_RULE (a nudge, never a block — the
        # HARD rules already pruned the domain in _candidate_kickoffs).
        preferred_rules = [
            r
            for r in input_data.club_rules
            if r.rule_type == "PREFERRED" and _iso_day(match.match_date) in r.days_of_week
        ]
        # P4-272 ⑤ — coach unavailabilities of THIS team's coaches covering this ISO day.
        match_day = _iso_day(match.match_date)
        team_coach_unavailabilities = (
            [
                unav
                for ref in team.coaches
                for unav in coach_unavailabilities.get(ref.coach_id, [])
                if match_day in unav.days_of_week
            ]
            if team is not None
            else []
        )
        for cand in candidates[match.id]:
            weight = 0
            venue_start = cand.kickoff_min
            venue_end = cand.kickoff_min + match_min
            # Lot M — the person window is warm-up-free; a TO_PLACE match is HOME
            # (no travel), so it equals the venue window: [kickoff, kickoff + match].
            person_start = cand.kickoff_min
            person_end = cand.kickoff_min + match_min
            # P4-271 — is this candidate the team's OWN ideal slot (same venue AND
            # kickoff)? Then habit-window protection NEVER applies to it: an A/B
            # partner's protected window on the same physical slot must not chase a
            # team off its own declared ideal. Without this, +15 +5 −25 = −5 would
            # make the ideal LOSE to any neutral slot (the U9F1/U9M2 case).
            is_own_ideal = (
                team_habit is not None
                and team_habit.venue_id is not None
                and cand.venue_id == team_habit.venue_id
                and cand.kickoff_min == _minutes(team_habit.kickoff)
            )
            if team_habit is not None:
                if cand.kickoff_min == _minutes(team_habit.kickoff):
                    weight += W_HABIT_TIME
                if team_habit.venue_id is not None and cand.venue_id == team_habit.venue_id:
                    weight += W_HABIT_VENUE
            if (
                match.current_venue_id == cand.venue_id
                and match.current_kickoff is not None
                and _minutes(match.current_kickoff) == cand.kickoff_min
            ):
                weight += W_STABILITY
                # The stability hint is NOT posted here: a lone add_hint on the
                # stable candidate would fight the greedy warm-start below (two
                # contradictory hints on the same match). `_apply_greedy_hints`
                # posts ONE coherent hint per match — and prefers this current
                # placement when it is still free (see its preference tiers).
            # Protection is a VENUE conflict → the candidate's MATCH window (deduped
            # set). Skipped when the candidate IS the team's own ideal (P4-271).
            if not is_own_ideal:
                for p_start, p_end in protected.get((cand.venue_id, match.match_date), set()):
                    if venue_start < p_end and p_start < venue_end:
                        weight -= W_PROTECT_HABIT
            # Person clash (coach OR active player) → the warm-up-FREE person window
            # (lot M). A player weighs W_COACH_MAIN, like a MAIN coach (P4-240 ③).
            if team is not None:
                for ref in team.coaches:
                    role_weight = W_COACH_MAIN if ref.role == "MAIN" else W_COACH_ASSISTANT
                    for f_start, f_end in fixed_windows_by_coach.get((ref.coach_id, match.match_date), []):
                        if person_start < f_end and f_start < person_end:
                            weight -= role_weight
                for player_id in _team_players(team):
                    for f_start, f_end in fixed_windows_by_coach.get((player_id, match.match_date), []):
                        if person_start < f_end and f_start < person_end:
                            weight -= W_COACH_MAIN
            # P4-272 ③ — a PREFERRED club rule this candidate violates costs W_CLUB_RULE
            # (a nudge; HARD rules were already pruned from the domain). The ideal slot
            # loses to a neutral conforming slot, but never to nothing.
            for rule in preferred_rules:
                if not _kick_in_club_rule(cand.kickoff_min, rule):
                    weight -= W_CLUB_RULE
            # P4-272 ⑤ — a coach of this team is unavailable at this candidate's kickoff
            # (a declared window on this ISO day) → W_COACH_UNAVAILABLE. SOFT: it steers
            # the match out of the window when an alternative exists, never blocks it.
            for unav in team_coach_unavailabilities:
                if _kick_in_unavailability(cand.kickoff_min, unav):
                    weight -= W_COACH_UNAVAILABLE
            if weight:
                objective.append(weight * cand.var)

    # 4. Pairwise SOFT between TO_PLACE matches: shared-coach clash, links. Lot M —
    # coach AND NOT_SIMULTANEOUS overlap on the warm-up-FREE person window. TO_PLACE
    # matches are HOME (no travel), so the person window is [kickoff, kickoff + match].
    def _overlap_pairs(left: MatchSchema, right: MatchSchema, penalty: int, tag: str) -> None:
        if left.match_date != right.match_date:
            return
        l_match, _ = _durations(teams_by_id.get(left.team_id))
        r_match, _ = _durations(teams_by_id.get(right.team_id))
        for lc in candidates[left.id]:
            _ensure_budget()
            l_start, l_end = lc.kickoff_min, lc.kickoff_min + l_match
            for rc in candidates[right.id]:
                r_start, r_end = rc.kickoff_min, rc.kickoff_min + r_match
                if l_start < r_end and r_start < l_end:
                    both = model.new_bool_var(f"{tag}_{left.id}_{right.id}_{lc.kickoff_min}_{rc.kickoff_min}")
                    # Penalised (negative in a Maximize): only the LOWER bound is
                    # needed — the maximiser pushes `both` to 0 unless lc∧rc force it.
                    model.add(both >= lc.var + rc.var - 1)
                    objective.append(-penalty * both)

    # Per-(team, person) penalty weight: a MAIN coach and a PLAYER both weigh
    # W_COACH_MAIN, an ASSISTANT W_COACH_ASSISTANT (P4-240 ③). A PERSON = coach OR
    # active player; the coach role wins on her own team (setdefault + `_team_players`
    # exclusion), so she is weighted once. For coach-only clubs this is byte-identical
    # to the old MAIN-or-MAIN rule (max of two weights = the old role pick).
    person_weights: dict[str, dict[str, int]] = {}
    for team in input_data.teams:
        for ref in team.coaches:
            person_weights.setdefault(team.id, {})[ref.coach_id] = (
                W_COACH_MAIN if ref.role == "MAIN" else W_COACH_ASSISTANT
            )
        for player_id in _team_players(team):
            person_weights.setdefault(team.id, {}).setdefault(player_id, W_COACH_MAIN)
    for i, left in enumerate(solvable):
        _ensure_budget()
        for right in solvable[i + 1 :]:
            shared = set(person_weights.get(left.team_id, {})) & set(person_weights.get(right.team_id, {}))
            for person_id in shared:
                penalty = max(person_weights[left.team_id][person_id], person_weights[right.team_id][person_id])
                _overlap_pairs(left, right, penalty, f"coach_{person_id}")

    matches_by_team: dict[str, list[MatchSchema]] = {}
    for match in solvable:
        matches_by_team.setdefault(match.team_id, []).append(match)
    # ⚠ Lot M — DELIBERATE asymmetry: MatchConflictDetector no longer surfaces the
    # TEAM_LINK family (« ça fait plus de bruit qu'autre chose »), but the PLACEMENT
    # solver KEEPS this soft NOT_SIMULTANEOUS preference — it still tries to avoid
    # posing two bridged teams' matches at once. Safe in this direction: a soft
    # preference never blocks a placement, it only nudges. Do NOT "align" the two by
    # dropping this loop — the radar being mute is not a reason to make the solver
    # careless. The window is warm-up-free like every other person window (lot M).
    for link in input_data.team_links:
        _ensure_budget()
        for left in matches_by_team.get(link.team_a_id, []):
            for right in matches_by_team.get(link.team_b_id, []):
                if link.type == "NOT_SIMULTANEOUS":
                    _overlap_pairs(left, right, W_LINK_NOT_SIMULTANEOUS, "link")
                elif link.type == "BACK_TO_BACK" and left.match_date == right.match_date:
                    # Chained = same venue, MATCH windows contiguous: the right
                    # kicks off exactly when the left's match ends (or symmetric).
                    l_match, _ = _durations(teams_by_id.get(left.team_id))
                    r_match, _ = _durations(teams_by_id.get(right.team_id))
                    for lc in candidates[left.id]:
                        for rc in candidates[right.id]:
                            if lc.venue_id != rc.venue_id:
                                continue
                            chained_ok = (
                                rc.kickoff_min == lc.kickoff_min + l_match or lc.kickoff_min == rc.kickoff_min + r_match
                            )
                            if chained_ok:
                                chained = model.new_bool_var(f"btb_{left.id}_{right.id}_{lc.kickoff_min}")
                                # Rewarded (positive): only the UPPER bounds are
                                # needed — the maximiser pulls `chained` to 1
                                # whenever both candidates are chosen.
                                model.add(chained <= lc.var)
                                model.add(chained <= rc.var)
                                objective.append(W_BACK_TO_BACK * chained)

    # 5. Day compaction per (venue, date): penalise the idle span between the
    # first and last MATCH window (span − Σ matchMinutes placed, in 15-min steps).
    day_start, day_end = 0, 24 * 60
    groups: dict[tuple[str, date], list[tuple[cp_model.IntVar, int, int]]] = {}
    for match in solvable:
        _ensure_budget()
        m_match, _ = _durations(teams_by_id.get(match.team_id))
        for cand in candidates[match.id]:
            groups.setdefault((cand.venue_id, match.match_date), []).append((cand.var, cand.kickoff_min, m_match))
    fixed_by_group: dict[tuple[str, date], list[tuple[int, int]]] = {}
    for match in fixed:
        if match.venue_id is not None and match.kickoff is not None:
            m_match, _ = _durations(teams_by_id.get(match.team_id))
            fixed_by_group.setdefault((match.venue_id, match.match_date), []).append((_minutes(match.kickoff), m_match))
    for key, group_cands in groups.items():
        _ensure_budget()
        fixed_entries = fixed_by_group.get(key, [])
        # ENG-48 — a match may legitimately END after midnight (the league may
        # allow a late kickoff; "no surprise, no backend refusal"). The compaction
        # span/gap domains must reach the LATEST match end of the group, not stop
        # at 24:00: a fixed anchor (or candidate) finishing past midnight makes
        # `span_end >= kick + m_match` infeasible against a 1440-capped domain and
        # the whole model then unplaces everything. The horizon is the max MATCH
        # end over candidates AND fixed anchors, floored at day_end — so when
        # nothing crosses midnight it equals day_end and the model is byte-identical
        # (goldens intact).
        horizon = day_end
        for _var, kick, m_match in group_cands:
            horizon = max(horizon, kick + m_match)
        for kick, m_match in fixed_entries:
            horizon = max(horizon, kick + m_match)
        span_start = model.new_int_var(day_start, horizon, f"span_start_{key[0]}_{key[1]}")
        span_end = model.new_int_var(day_start, horizon, f"span_end_{key[0]}_{key[1]}")
        placed_footprint: list[cp_model.LinearExpr] = []
        for var, kick, m_match in group_cands:
            model.add(span_start <= kick).only_enforce_if(var)
            model.add(span_end >= kick + m_match).only_enforce_if(var)
            placed_footprint.append(m_match * var)
        for kick, m_match in fixed_entries:
            model.add(span_start <= kick)
            model.add(span_end >= kick + m_match)
        n_fixed = len(fixed_entries)
        fixed_footprint = sum(m_match for _, m_match in fixed_entries)
        gap = model.new_int_var(0, horizon, f"gap_{key[0]}_{key[1]}")
        # gap ≥ span − Σ matchMinutes ; NoOverlap guarantees span ≥ Σ matchMinutes
        # when all sit apart, so gap measures idle time. The maximiser pushes gap
        # down to its lower bound (it enters the objective negatively).
        total_footprint = sum(placed_footprint) + fixed_footprint if placed_footprint else fixed_footprint
        model.add(gap >= (span_end - span_start) - total_footprint)
        if n_fixed == 0:
            any_placed = model.new_bool_var(f"any_{key[0]}_{key[1]}")
            model.add_max_equality(any_placed, [var for var, _, _ in group_cands])
            model.add(span_end == span_start).only_enforce_if(any_placed.Not())
        # Per 15-min STEP (not per minute) — a 6 h hole must never outweigh a
        # coach clash: 24 steps × 1 « 60 (the D5 hierarchy holds).
        gap_steps = model.new_int_var(0, horizon // STEP_MIN, f"gap_steps_{key[0]}_{key[1]}")
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
    # Reclassification of an unchosen match: venue_full ONLY when no legal slot
    # remains free at its date given the FINAL placements + fixed anchors; if a
    # licit slot is still open, the solver simply did not retain it within the
    # budget → not_selected (« relancez le placement »).
    final_busy: dict[tuple[str, date], list[tuple[int, int]]] = {
        key: list(windows) for key, windows in fixed_busy.items()
    }
    unchosen: list[MatchSchema] = []
    if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        for match in solvable:
            match_min, _ = _durations(teams_by_id.get(match.team_id))
            chosen = next((c for c in candidates[match.id] if solver.value(c.var) == 1), None)
            if chosen is not None:
                placements.append(
                    {"matchId": match.id, "venueId": chosen.venue_id, "kickoff": _to_time(chosen.kickoff_min)}
                )
                final_busy.setdefault((chosen.venue_id, match.match_date), []).append(
                    (chosen.kickoff_min, chosen.kickoff_min + match_min)
                )
            else:
                unchosen.append(match)
    else:
        # Reachable on UNKNOWN: the solver hit its time limit before proving SAT
        # or UNSAT (placement is optional, so INFEASIBLE is not expected, but it
        # is handled the same way). Leave `placements` empty and let
        # `_remaining_reason` name why each match stayed down from the FINAL
        # occupancy (= the fixed anchors only, no free placement was retained).
        unchosen = list(solvable)

    for match in unchosen:
        match_min, _ = _durations(teams_by_id.get(match.team_id))
        slots = [(cand.venue_id, cand.kickoff_min) for cand in candidates[match.id]]
        reason = _remaining_reason(slots, match.match_date, match_min, final_busy)
        unplaced.append({"matchId": match.id, "reason": reason, "message": REASON_MESSAGES[reason]})

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


def _solve_one_week(sub_input: MatchPlacementInputSchema, week_key: tuple[int, int]) -> dict[str, Any]:
    """Solve a single ISO-week slice, aborting its BUILD with a named
    ``placement_problem_too_large`` diagnostic if it overruns the per-build budget
    (ADR-0001 — the impossible is spelled out, never a silent hang). A too-large
    week fails on its OWN; ``_merge_week_results`` keeps the other weeks' work."""
    try:
        return _place_matches(sub_input)
    except _BuildBudgetExceeded as exc:
        logger.warning(
            "match placement build budget exceeded club=%s iso_week=%s matches=%d candidates=%d",
            sub_input.club_id,
            week_key,
            exc.n_matches,
            exc.n_candidates,
        )
        return _too_large_result(exc)


def solve_match_placement(input_data: MatchPlacementInputSchema) -> dict[str, Any]:
    """Public entry point (ENG-50): solve the request ISO WEEK by ISO WEEK, under a
    PER-WEEK budget (``solver_timeout_seconds`` is now the budget OF EACH week, and
    ``BUILD_BUDGET_SECONDS`` the budget of each sub-build), and return ONE merged
    response. Slicing keeps every sub-model small so the child process's peak memory
    stays bounded (the whole pass runs in the single spawned child, main.py). A week
    with no TO_PLACE match is never solved. See ``_partition_by_iso_week`` for why
    this is equivalent to a single global solve."""
    results = [
        _solve_one_week(sub_input, week_key)
        for week_key, sub_input in _partition_by_iso_week(input_data)
        if any(match.kind == "TO_PLACE" for match in sub_input.matches)
    ]
    return _merge_week_results(results)
