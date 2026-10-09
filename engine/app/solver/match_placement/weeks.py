from __future__ import annotations

from datetime import date
from typing import Any

from app.schemas.match_input_schema import MatchPlacementInputSchema, MatchSchema, TrainingOccupancySchema


def _iso_week(value: date) -> tuple[int, int]:
    """(ISO year, ISO week) of a date — the partition key. The ISO year (not the
    calendar year) is part of the key so the last days of December that belong to
    week 1 of the next year group with that week, not a phantom week 53 (and the
    reverse in early January). A weekend's Saturday and Sunday always share one key."""
    iso = value.isocalendar()
    return (iso.year, iso.week)


def _partition_by_iso_week(
    input_data: MatchPlacementInputSchema,
) -> list[tuple[tuple[int, int], MatchPlacementInputSchema]]:
    """Split a placement request into one sub-request per ISO WEEK (ENG-50).

    Each slice embeds EVERY match dated in the week — TO_PLACE, FIXED AND AWAY: a
    FIXED anchor consumes its venue slot and a person window, an AWAY match frees
    that day's habit protection (``team_dates``), so dropping either would change
    the week's optimum — plus the week's ``training_occupancies``. Everything else
    (teams, venues, club rules, links, coach unavailabilities, solver params) is
    COMMON and copied verbatim onto each slice. Weeks are returned in ascending key
    order so the merged output is deterministic.

    Correctness rests on an invariant of ``_place_matches``: NO term couples two
    different dates. Every cross-date construction requires ``match_date`` equality
    — the no-overlap group key is (venue, date), ``_overlap_pairs`` returns early
    when the two dates differ, BACK_TO_BACK is same-date, habit-window protection
    and person windows are indexed by date, and training occupancies by date. Since
    each date belongs to exactly one ISO week, solving a week in isolation yields
    the same placement for that week as the global solve would — the slicing is
    behaviour-preserving, it only bounds the model size (and the memory) per build.
    """
    matches_by_week: dict[tuple[int, int], list[MatchSchema]] = {}
    for match in input_data.matches:
        matches_by_week.setdefault(_iso_week(match.match_date), []).append(match)
    occupancies_by_week: dict[tuple[int, int], list[TrainingOccupancySchema]] = {}
    for occupancy in input_data.training_occupancies:
        occupancies_by_week.setdefault(_iso_week(occupancy.occupancy_date), []).append(occupancy)

    slices: list[tuple[tuple[int, int], MatchPlacementInputSchema]] = []
    for key in sorted(matches_by_week):
        # model_copy(update=…) swaps the two per-week lists without re-validating
        # the already-validated shared objects; no new schema is constructed.
        sub_input = input_data.model_copy(
            update={
                "matches": matches_by_week[key],
                "training_occupancies": occupancies_by_week.get(key, []),
            }
        )
        slices.append((key, sub_input))
    return slices


def _merge_week_results(results: list[dict[str, Any]]) -> dict[str, Any]:
    """Concatenate the per-week results into the single response the contract
    expects (no schema change, CONTRACT_VERSION stays 1.2).

    Placements / unplaced / diagnostics are concatenated in week order (stable,
    deterministic). ``metrics``: ``wall_time_ms`` SUMMED (total solve time of the
    pass), and ``nb_variables`` / ``nb_constraints`` SUMMED too — each week is a
    SEPARATE CP-SAT build, so the total model size the pass constructed is the sum,
    not the max of one week (summing also stays coherent with the summed wall time;
    a single-week request keeps byte-identical metrics, the sum of one term). A
    too-large week carries ``metrics=None`` (nothing was built) and is skipped in
    the aggregation. ``status``: "failed" ONLY when EVERY attempted week failed —
    so one too-large week never sinks the weeks that did place (its error
    diagnostic still rides in ``diagnostics``); with a single week this degrades to
    the historical single-solve behaviour (one too-large week → "failed")."""
    if not results:
        # No week held a TO_PLACE match → nothing to solve. A no-op completed result
        # (the backend controller already short-circuits toPlaceCount == 0 upstream).
        return {"status": "completed", "placements": [], "unplaced": [], "diagnostics": [], "metrics": None}

    placements: list[dict[str, Any]] = []
    unplaced: list[dict[str, str]] = []
    diagnostics: list[dict[str, Any]] = []
    nb_variables = nb_constraints = wall_time_ms = 0
    any_metrics = False
    for result in results:
        placements.extend(result["placements"])
        unplaced.extend(result["unplaced"])
        diagnostics.extend(result["diagnostics"])
        metrics = result["metrics"]
        if metrics is not None:
            any_metrics = True
            nb_variables += metrics["nb_variables"]
            nb_constraints += metrics["nb_constraints"]
            wall_time_ms += metrics["wall_time_ms"]

    status = "failed" if all(result["status"] == "failed" for result in results) else "completed"
    merged_metrics: dict[str, Any] | None = None
    if any_metrics:
        merged_metrics = {
            "solver_version": "cp-sat",
            "nb_variables": nb_variables,
            "nb_constraints": nb_constraints,
            "wall_time_ms": wall_time_ms,
        }
    return {
        "status": status,
        "placements": placements,
        "unplaced": unplaced,
        "diagnostics": diagnostics,
        "metrics": merged_metrics,
    }
