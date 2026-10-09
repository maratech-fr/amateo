from __future__ import annotations

from datetime import date, time

from app.schemas.match_input_schema import ClubRuleSchema, CoachUnavailabilitySchema, MatchTeamSchema

# ── Geometry (D1, P4-203, lot M) ──────────────────────────────────────────────
# Durations are PER TEAM now: the payload carries teams[].matchMinutes /
# teams[].warmupMinutes (resolved by the backend from the sport category). The
# court is held for the MATCH only ([kickoff, kickoff + matchMinutes]) — the
# warm-up no longer occupies the venue (founder decision 2026-09-13: "you warm
# up on the side during the previous match").
#
# ⚠ Lot M — the PERSON footprint (coach / player / NOT_SIMULTANEOUS link) ALSO
# drops the warm-up, mirroring MatchConflictDetector: a person coming from another
# engagement only has to ARRIVE by the kickoff, the warm-up is hers to skip.
#
# ⚠ P4-240 ③ (décision B) — the placement solver IGNORES every person footprint of
# an AWAY match: away matches project NO person window at all (« c'est la vie ; the
# radar signals the conflict, we handle it after »). Only FIXED (home) anchors and
# projected trainings hold a person; the only person window in play is therefore
# [kickoff, kickoff + matchMinutes] (home = no travel, warm-up dropped = the VENUE
# window). `roundTripMinutes` is still carried on the contract but no longer read.
# A PERSON is a coach OR an active player (teams[].players, P4-240 ③): both project
# and clash on the same window, a player weighted like a MAIN coach (W_COACH_MAIN).
# warmupMinutes stays on the contract (no version bump) but the solver no longer
# reads it for any window; keeping it avoids re-syncing the schemas for a nil gain.
STEP_MIN = 15
DEFAULT_MATCH_MIN = 105
DEFAULT_WARMUP_MIN = 30


def _minutes(value: time) -> int:
    return value.hour * 60 + value.minute


def _to_time(total: int) -> time:
    return time(hour=(total // 60) % 24, minute=total % 60)


def _iso_day(value: date) -> int:
    return value.isoweekday()


def _kick_in_club_rule(kick: int, rule: ClubRuleSchema) -> bool:
    """Does a kickoff (minutes) satisfy a club rule's range? A missing bound is OPEN
    on that side (« pas après 21h » = kickoff_max only). Closed interval, matching the
    league window (both bounds inclusive)."""
    below_min = rule.kickoff_min is not None and kick < _minutes(rule.kickoff_min)
    above_max = rule.kickoff_max is not None and kick > _minutes(rule.kickoff_max)
    return not (below_min or above_max)


def _kick_in_unavailability(kick: int, unav: CoachUnavailabilitySchema) -> bool:
    """Is a kickoff (minutes) INSIDE a coach's unavailability window (P4-272 ⑤)? A missing
    bound is OPEN on that side (« indispo avant 12h » = kickoff_max only). Closed interval —
    the coach cannot be there, so a candidate at that kickoff is PENALISED (SOFT). Same range
    shape as a club rule, but the intent is inverted: a club rule wants the kickoff IN range,
    an unavailability penalises the kickoff being IN range."""
    after_min = unav.kickoff_min is None or kick >= _minutes(unav.kickoff_min)
    before_max = unav.kickoff_max is None or kick <= _minutes(unav.kickoff_max)
    return after_min and before_max


def _durations(team: MatchTeamSchema | None) -> tuple[int, int]:
    """(matchMinutes, warmupMinutes) of a team — the documented defaults when the
    team is absent or the fields were omitted (Pydantic already fills 105 / 30)."""
    if team is None:
        return DEFAULT_MATCH_MIN, DEFAULT_WARMUP_MIN
    return team.match_minutes, team.warmup_minutes


def _team_players(team: MatchTeamSchema | None) -> list[str]:
    """The player person-ids of a team, MINUS anyone who also coaches it (the coach
    role wins — parité MatchConflictDetector) and MINUS duplicates. The backend
    already excludes coaches (PlayersPayloadParityTest); this belt keeps a defensive
    double-listing from ever yielding a double malus (P4-240 ③)."""
    if team is None:
        return []
    coach_ids = {ref.coach_id for ref in team.coaches}
    seen: set[str] = set()
    out: list[str] = []
    for player_id in team.players:
        if player_id in coach_ids or player_id in seen:
            continue
        seen.add(player_id)
        out.append(player_id)
    return out
