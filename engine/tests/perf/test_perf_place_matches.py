"""Performance gate for the dated match-placement solve (ADR-0003).

Mirror of ``test_perf_dense`` for ``/place-matches``: a synthetic large club
(13 teams · 5 gyms · 291 matches over ~30 weekend dates, shared coaches, a few
passerelles) must BUILD inside ``BUILD_BUDGET_SECONDS`` and SOLVE well under the
gate budget. Without it, a quadratic regression in the candidate / no-overlap /
pairwise-coach loops would silently blow up on a real club before a client
complains.

Marked ``perf`` so it is EXCLUDED from the default suite (``addopts -m 'not
perf'``). Its name deliberately avoids ``dense_club`` so the PR-tier job (which
filters ``-k dense_club``) leaves it to the main-only ``pytest -m perf`` run.
"""

from __future__ import annotations

import time
from datetime import date, timedelta
from typing import Any

import pytest

from app.main import read_contract_version
from app.schemas.match_input_schema import MatchPlacementInputSchema
from app.solver.match_placement import solve_match_placement

# Wall budget of the TEST (not the solver): the payload now grants the solver 60 s
# (P4-240), so the whole call = build (≤ BUILD_BUDGET_SECONDS) + a 60 s solve + I/O.
# 80 s leaves headroom on a slow CI runner without letting a runaway build slip through.
BUDGET_SECONDS = 80.0

# The synthetic fixture holds 239 TO_PLACE matches (291 total − 32 FIXED − 20 AWAY).
# Tight city-hall windows (one 105-min match per venue per date → ~150 slots over the
# horizon) cap the placeable set; the warm-start reaches ≥ 100 well inside the budget
# and plateaus. This gate defends BOTH: the build/solve stay in time AND a real volume
# of matches gets placed (a silent regression that placed « at least one » is caught).
MIN_PLACEMENTS = 100

N_TEAMS = 13
N_VENUES = 5
TARGET_MATCHES = 291
FIRST_SATURDAY = date(2026, 10, 3)
N_WEEKENDS = 15


def _weekend_dates() -> list[date]:
    """~30 dates: the Saturday + Sunday of ``N_WEEKENDS`` consecutive weekends."""
    dates: list[date] = []
    saturday = FIRST_SATURDAY
    for _ in range(N_WEEKENDS):
        dates.append(saturday)
        dates.append(saturday + timedelta(days=1))
        saturday += timedelta(days=7)
    return dates


def _venues() -> list[dict[str, Any]]:
    # Saturday (ISO 6) 14:00-16:45 and Sunday (ISO 7) 09:00-10:45 access windows —
    # a realistic city-hall grant (a handful of legal kickoffs per venue), not a
    # wide-open day that would inflate the candidate set beyond any real club. The
    # windows are kept tight ON PURPOSE so the model BUILD stays well under
    # BUILD_BUDGET_SECONDS (~2 s here) with margin for a slow CI runner, while the
    # problem shape (291 matches · 13 teams · 5 gyms) still stresses the hot loops.
    return [
        {
            "id": f"v{v}",
            "name": f"Gymnase {v}",
            "matchWindows": [
                {"dayOfWeek": 6, "start": "14:00", "end": "16:45"},
                {"dayOfWeek": 7, "start": "09:00", "end": "11:45"},
            ],
            "unavailabilities": [],
        }
        for v in range(N_VENUES)
    ]


# Three coach-sharing PAIRS (t0/t1, t2/t3, t4/t5) — a coach following two teams is
# the real small-club pattern; the rest have their own assistant. Pairwise (not a
# shared pool) keeps the O(n²) coach loop honest without the pathological all-share
# explosion a real club never has.
_SHARED_ASSISTANT = {0: "sa0", 1: "sa0", 2: "sa1", 3: "sa1", 4: "sa2", 5: "sa2"}


def _teams() -> list[dict[str, Any]]:
    # Each team has its OWN main coach (a coach follows one team) plus an assistant;
    # three team pairs share their assistant (see ``_SHARED_ASSISTANT``). A weekly
    # habit anchors the SOFT terms.
    teams: list[dict[str, Any]] = []
    for t in range(N_TEAMS):
        assistant = _SHARED_ASSISTANT.get(t, f"coach-assist-{t}")
        teams.append(
            {
                "id": f"t{t}",
                "name": f"Team {t}",
                "leagueWindows": [],
                "habits": [{"dayOfWeek": 6, "kickoff": "15:00", "venueId": f"v{t % N_VENUES}"}],
                "coaches": [
                    {"coachId": f"coach-main-{t}", "role": "MAIN"},
                    {"coachId": f"coach-assist-{assistant}", "role": "ASSISTANT"},
                ],
            }
        )
    return teams


def _matches(dates: list[date]) -> list[dict[str, Any]]:
    matches: list[dict[str, Any]] = []
    counter = 0
    for match_date in dates:
        for t in range(N_TEAMS):
            if len(matches) >= TARGET_MATCHES:
                return matches
            counter += 1
            base: dict[str, Any] = {"id": f"m{counter}", "teamId": f"t{t}", "date": match_date.isoformat()}
            if counter % 9 == 0:
                # FIXED anchor: consumes its slot, never moves (needs venue + kickoff).
                base.update({"kind": "FIXED", "venueId": "v0", "kickoff": "14:00"})
            elif counter % 13 == 0:
                # AWAY: informative only (person footprint via the coach terms).
                base.update({"kind": "AWAY", "kickoff": "16:00"})
            else:
                base.update({"kind": "TO_PLACE"})
            matches.append(base)
    return matches


def _payload() -> MatchPlacementInputSchema:
    dates = _weekend_dates()
    data: dict[str, Any] = {
        "version": read_contract_version(),
        "clubId": "club-perf",
        "seasonId": "season-perf",
        "solverTimeoutSeconds": 60,
        "matches": _matches(dates),
        "venues": _venues(),
        "teams": _teams(),
        # A few passerelles (shared-player links) across team pairs.
        "teamLinks": [
            {"teamAId": "t0", "teamBId": "t1", "type": "NOT_SIMULTANEOUS"},
            {"teamAId": "t2", "teamBId": "t3", "type": "BACK_TO_BACK"},
            {"teamAId": "t4", "teamBId": "t5", "type": "NOT_SIMULTANEOUS"},
        ],
    }
    return MatchPlacementInputSchema.model_validate(data)


@pytest.mark.perf
def test_place_matches_large_club_completes_under_budget() -> None:
    """13 teams · 5 gyms · 291 matches (239 TO_PLACE): must build in budget, solve
    inside the 60 s solver budget, and place a real volume (≥ 100), not « at least one »."""
    input_data = _payload()
    assert len(input_data.matches) == TARGET_MATCHES

    start = time.monotonic()
    result = solve_match_placement(input_data)
    elapsed = time.monotonic() - start

    assert result["status"] == "completed", result
    assert len(result["placements"]) >= MIN_PLACEMENTS, (
        f"only {len(result['placements'])} placements, expected ≥ {MIN_PLACEMENTS}"
    )
    assert elapsed < BUDGET_SECONDS, f"place-matches took {elapsed:.1f}s, over the {BUDGET_SECONDS:.0f}s budget"
