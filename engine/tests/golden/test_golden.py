from __future__ import annotations

import json
import pathlib
from typing import Any

import pytest

from app.solver.model import SLOT_MINUTES
from tests.support import solve_payload

FIXTURES_DIR = pathlib.Path(__file__).resolve().parents[1] / "fixtures"

# Re-pinned when the goldens were switched onto the real production pipeline
# (PR0): parse_v2 active, min_sessions soft-only, two-phase solve, remaining
# bound. This is the true production score, not the old divergent harness.
DENSE_CLUB_BASELINE_SCORE = 117679


def _load_fixture(name: str) -> dict[str, Any]:
    path = FIXTURES_DIR / f"{name}.json"
    with open(path, encoding="utf-8") as f:
        return json.load(f)


class TestGoldenDatasets:
    # Scores pinned on the REAL production pipeline (seed 42, deterministic).
    # simple/medium keep coach-overlap "conflict" diagnostics because those
    # fixtures carry no TEAM_COACH constraints — the solver never sees a coach,
    # result_builder shows the template coach, and the same coach appears on two
    # teams. That is a fixture artifact, not a solver defect; coach correctness
    # is covered properly (with real constraints) by tests/semantic/.
    def test_simple_club_is_optimal(self) -> None:
        data = _load_fixture("simple_club")
        result = solve_payload(data)

        assert result["status"] == "completed"
        assert result["score"] == 22251
        assert len(result["slots"]) == 7

    def test_medium_club_is_feasible_or_optimal(self) -> None:
        data = _load_fixture("medium_club")
        result = solve_payload(data, timeout=30)

        assert result["status"] == "completed"
        assert result["score"] == 69684
        assert len(result["slots"]) == 32

    @pytest.mark.timeout(180)
    def test_dense_club_is_feasible_within_180s(self) -> None:
        data = _load_fixture("dense_club")
        result = solve_payload(data, timeout=180)

        assert result["status"] == "completed"
        assert result["score"] is not None
        assert len(result["slots"]) > 0
        assert result["score"] >= DENSE_CLUB_BASELINE_SCORE * 0.95

    def test_impossible_underserves_greedy_team_with_diagnostic(self) -> None:
        data = _load_fixture("impossible")
        result = solve_payload(data)

        # Production is single-pass with SOFT min_sessions (ADR-0001): a team
        # asking for 50 sessions with 1 slot is NOT infeasible — it completes
        # with the greedy team under-served and a below-effective-min diagnostic.
        # (HARD-infeasible scenarios are exercised via contradictory constraints
        # in tests/semantic/.)
        assert result["status"] == "completed"
        assert any(d["type"] == "session_below_effective_min" for d in result["diagnostics"])

    def test_vacation_week_is_feasible_and_respects_tiers(self) -> None:
        data = _load_fixture("vacation_week")
        result = solve_payload(data, timeout=30)

        assert result["status"] == "completed"
        assert result["score"] == 64521
        assert len(result["slots"]) > 0
        conflict_diags = [d for d in result["diagnostics"] if d["type"] == "conflict"]
        assert conflict_diags == []

        # Verify tier S and A teams have at least their min sessions.
        team_sessions: dict[str, int] = {}
        for slot in result["slots"]:
            tid = slot["teamId"]
            team_sessions[tid] = team_sessions.get(tid, 0) + int(slot["durationMinutes"]) // SLOT_MINUTES

        # Toutes les équipes du payload sont « actives » : la désactivation réelle passe par les
        # overrides de période, qui les FILTRENT du payload en amont (le flag entité isActive a
        # quitté le contrat 1.3, ENG-53). Le filtre porte donc sur le seul tier.
        tier_s_a_teams = [t for t in data["teams"] if t["priorityTierId"] in (1, 2)]
        tier_defaults = {1: 3, 2: 2, 3: 2, 4: 2, 5: 1}
        for team in tier_s_a_teams:
            spw = team["sessionsPerWeek"]
            tier_id = team.get("priorityTierId", 5)
            effective_min = min(spw, tier_defaults.get(tier_id, 1))
            placed = team_sessions.get(team["id"], 0)
            assert placed >= effective_min, (
                f"Team {team['id']} ({team.get('name', team['id'])}) has {placed} sessions, "
                f"expected >= {effective_min} (effective_min)"
            )
