from __future__ import annotations

from ortools.sat.python import cp_model

from app.schemas.match_input_schema import MatchPlacementInputSchema, MatchSchema

from .geometry import STEP_MIN, _durations, _iso_day, _kick_in_club_rule, _minutes


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
    match_min, _ = _durations(team)
    league = [w for w in (team.league_windows if team else []) if w.day_of_week == day]
    league_mapped = team is not None and len(team.league_windows) > 0
    # P4-272 ③ — HARD club rules covering this ISO day. Every one must accept the
    # kickoff (AND semantics); a domain emptied by them alone is `club_rule_no_slot`.
    hard_rules = [r for r in input_data.club_rules if r.rule_type == "HARD" and day in r.days_of_week]
    # P4-272 ④ — venues this team is FORBIDDEN to play at. A forbidden venue is removed
    # from the domain (never chosen), but tracked apart: if a legal (access ∩ league ∩
    # club-rule) slot existed ONLY on forbidden venues, the reason is `team_venue_forbidden`.
    forbidden_venues = set(team.forbidden_venue_ids) if team else set()

    domain: dict[str, list[int]] = {}
    saw_open_venue = False
    saw_access_candidate = False
    saw_league_candidate = False
    saw_forbidden_legal = False
    for venue in input_data.venues:
        if any(u.start_date <= match.match_date <= u.end_date for u in venue.unavailabilities):
            continue
        saw_open_venue = True
        kicks: list[int] = []
        for window in venue.match_windows:
            if window.day_of_week != day:
                continue
            # The venue is held for the MATCH only (D1): kickoff ≥ start and
            # kickoff + matchMinutes ≤ end. The warm-up no longer reserves the
            # court, so a match may start at the very opening of the window.
            first = _minutes(window.start)
            last = _minutes(window.end) - match_min
            kick = ((first + STEP_MIN - 1) // STEP_MIN) * STEP_MIN
            while kick <= last:
                saw_access_candidate = True
                # League HARD only when the team maps: the kickoff must fall in
                # SOME league window of that day.
                league_ok = not league_mapped or any(
                    _minutes(w.kickoff_min) <= kick <= _minutes(w.kickoff_max) for w in league
                )
                if league_ok:
                    saw_league_candidate = True
                    # Club HARD rules (P4-272 ③): every rule covering this day must
                    # accept the kickoff. A club rule that empties an otherwise-legal
                    # domain is told apart below (`club_rule_no_slot`).
                    if all(_kick_in_club_rule(kick, rule) for rule in hard_rules):
                        kicks.append(kick)
                kick += STEP_MIN
        if not kicks:
            continue
        # P4-272 ④ — a forbidden venue never enters the domain (the solver must never
        # put the team there), but a would-be-legal slot on it flags the reason so an
        # emptied domain reads `team_venue_forbidden`, not a misleading access/league one.
        if venue.id in forbidden_venues:
            saw_forbidden_legal = True
            continue
        domain[venue.id] = kicks

    if domain:
        return domain, ""
    if not saw_open_venue:
        return {}, "venue_unavailable"
    if not saw_access_candidate:
        return {}, "no_access_window"
    if not saw_league_candidate:
        return {}, "no_league_intersection"
    # A legal slot survived on a forbidden venue alone → the ban is what empties the
    # domain (told apart from a club rule doing the same, `club_rule_no_slot`).
    if saw_forbidden_legal:
        return {}, "team_venue_forbidden"
    return {}, "club_rule_no_slot"
