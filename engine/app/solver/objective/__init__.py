"""Level-2 linear objective for the OR-Tools CP-SAT scheduler model.

The T24 score formula is intentionally fixed. Any change to one of these
weights must be accompanied by a new SCORE_FORMULA_VERSION.

Package layout (ENG-39). This file is the package entry point: it holds this
shared docstring, the orchestrator ``add_level_2_objective`` (with its
``Level2ObjectiveStats``), ``is_team_satisfied_by_hard_locks``, and the
re-exports of every submodule (public AND private names — several ``_…`` helpers
are imported directly by ``tests/`` and by the twin ``result_builder`` /
``validate_assignments`` consumers, so the import surface stays byte-identical to
the pre-split module). The submodules form a simple DAG: ``weights`` (fixed
tables/aliases, base of the DAG) → ``normalise`` (assignment/tier readers) →
``terms`` (the ``add_*`` soft-term builders + ``build_stability_terms``).
"""

from __future__ import annotations

from collections.abc import Iterable, Mapping
from dataclasses import dataclass
from typing import Any

from ..compromise import (
    CompromiseTermInfo as CompromiseTermInfo,
)
from .normalise import (
    _active_bonus_weight_names as _active_bonus_weight_names,
)
from .normalise import (
    _assignment_from_mapping_item as _assignment_from_mapping_item,
)
from .normalise import (
    _assignment_key as _assignment_key,
)
from .normalise import (
    _coach_ids_for as _coach_ids_for,
)
from .normalise import (
    _get as _get,
)
from .normalise import (
    _get_slot_id as _get_slot_id,
)
from .normalise import (
    _get_venue_id as _get_venue_id,
)
from .normalise import (
    _higher_tier as _higher_tier,
)
from .normalise import (
    _normalise_assignments as _normalise_assignments,
)
from .normalise import (
    _normalise_bonus_weight_name as _normalise_bonus_weight_name,
)
from .normalise import (
    _normalise_priority_rank as _normalise_priority_rank,
)
from .normalise import (
    _normalise_priority_tier as _normalise_priority_tier,
)
from .normalise import (
    _parse_time_minutes as _parse_time_minutes,
)
from .normalise import (
    _person_ids_for as _person_ids_for,
)
from .normalise import (
    _priority_tier_name as _priority_tier_name,
)
from .normalise import (
    _scalar_id as _scalar_id,
)
from .normalise import (
    _soft_term_variable_and_weight as _soft_term_variable_and_weight,
)
from .normalise import (
    _team_id as _team_id,
)
from .normalise import (
    _teams_by_id as _teams_by_id,
)
from .normalise import (
    _var as _var,
)
from .terms import (
    _add_preferred_bonus as _add_preferred_bonus,
)
from .terms import (
    _group_team_slots as _group_team_slots,
)
from .terms import (
    _safe_minutes as _safe_minutes,
)
from .terms import (
    add_chaining_bonus as add_chaining_bonus,
)
from .terms import (
    add_coach_day_cap_penalty as add_coach_day_cap_penalty,
)
from .terms import (
    add_match_day_rest_bonus as add_match_day_rest_bonus,
)
from .terms import (
    add_missing_session_penalty as add_missing_session_penalty,
)
from .terms import (
    add_preferred_day_bonus as add_preferred_day_bonus,
)
from .terms import (
    add_preferred_time_bonus as add_preferred_time_bonus,
)
from .terms import (
    add_socle_reference_bonus as add_socle_reference_bonus,
)
from .terms import (
    add_spacing_penalty as add_spacing_penalty,
)
from .terms import (
    add_team_link_penalty as add_team_link_penalty,
)
from .terms import (
    add_venue_preference_bonus as add_venue_preference_bonus,
)
from .terms import (
    build_stability_terms as build_stability_terms,
)
from .weights import (
    _BONUS_FIELD_ALIASES as _BONUS_FIELD_ALIASES,
)
from .weights import (
    _EXPLICIT_BONUS_FIELDS as _EXPLICIT_BONUS_FIELDS,
)
from .weights import (
    _MISSING as _MISSING,
)
from .weights import (
    _ONE_BASED_TIER_IDS as _ONE_BASED_TIER_IDS,
)
from .weights import (
    _PRIORITY_RANK_FIELDS as _PRIORITY_RANK_FIELDS,
)
from .weights import (
    _PRIORITY_TIER_FIELDS as _PRIORITY_TIER_FIELDS,
)
from .weights import (
    _TIER_ALIASES as _TIER_ALIASES,
)
from .weights import (
    _ZERO_BASED_TIER_RANKS as _ZERO_BASED_TIER_RANKS,
)
from .weights import (
    BONUS_WEIGHT_NAMES as BONUS_WEIGHT_NAMES,
)
from .weights import (
    CHAINING_STABILITY_MULTIPLIER as CHAINING_STABILITY_MULTIPLIER,
)
from .weights import (
    CHAINING_TIER_WEIGHTS as CHAINING_TIER_WEIGHTS,
)
from .weights import (
    LEVEL_2_OBJECTIVE_WEIGHTS as LEVEL_2_OBJECTIVE_WEIGHTS,
)
from .weights import (
    SCORE_FORMULA_VERSION as SCORE_FORMULA_VERSION,
)
from .weights import (
    SOCLE_REFERENCE_TIER_WEIGHTS as SOCLE_REFERENCE_TIER_WEIGHTS,
)
from .weights import (
    STABILITY_TERM_WEIGHT as STABILITY_TERM_WEIGHT,
)
from .weights import (
    TEAM_LINK_TIER_WEIGHTS as TEAM_LINK_TIER_WEIGHTS,
)
from .weights import (
    TIER_WEIGHT_NAMES as TIER_WEIGHT_NAMES,
)
from .weights import (
    UNPLACED_PENALTY as UNPLACED_PENALTY,
)

AssignmentLike = Any
BoolVarLike = Any


def is_team_satisfied_by_hard_locks(
    team_id: str,
    locked_slots: Iterable[Mapping[str, Any]],
    sessions_per_week: int,
) -> bool:
    """Return True if the team's weekly sessions are fully covered by HARD locks.

    Each entry in *locked_slots* represents one HARD-locked session for a team.
    If the count of HARD locks for *team_id* is greater than or equal to
    *sessions_per_week*, the team is fully satisfied and must NOT receive the
    ``-UNPLACED_PENALTY`` term in the objective.
    """

    hard_count = sum(1 for slot in locked_slots if str(slot.get("team_id", "")) == str(team_id))
    return hard_count >= sessions_per_week


@dataclass(frozen=True)
class Level2ObjectiveStats:
    """Summary of the T24 objective installed on the CP-SAT model."""

    score_formula_version: str
    placement_terms: int
    soft_bonus_terms: int
    total_terms: int
    chaining_bonus: int
    coefficient_by_assignment: Mapping[Any, int]
    # Two-phase support: the placement-only objective expression and the built
    # chaining terms (var, weight). When add_level_2_objective is called with
    # apply_chaining=False, chaining is NOT in the objective yet — the caller can
    # lock placement (placement_expression >= optimum) then optimise chaining in
    # a bounded second pass. Proving optimality of the tiny chaining bonuses in a
    # single objective is what blows up solve time on real datasets.
    placement_expression: Any = None
    chaining_terms: tuple[tuple[Any, int], ...] = ()


def add_level_2_objective(
    model: Any,
    assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike],
    *,
    teams: Iterable[Any] = (),
    soft_terms: Iterable[Any] = (),
    hard_satisfied_team_ids: set[str] | None = None,
    score_formula_version: str = SCORE_FORMULA_VERSION,
    apply_chaining: bool = True,
    team_player_map: Mapping[str, list[str]] | None = None,
    info_out: list[CompromiseTermInfo] | None = None,
    extra_placement_terms: Iterable[tuple[BoolVarLike, int]] = (),
) -> Level2ObjectiveStats:
    """Maximize the fixed T24 weighted score for candidate placements.

    Each selected placement receives the weight of its priority tier (S/A/B/C/D).
    If the placement also marks soft criteria as satisfied, their fixed bonus
    weights are added to the same linear term. Extra soft literals can be passed
    through soft_terms as (literal, weight_name) pairs or mapping/object
    values with var/weight_name fields.

    *extra_placement_terms* are already-weighted ``(literal, int_weight)`` terms folded straight
    into the PLACEMENT expression (no ``weight_name`` lookup) — the tier-derived passerelle malus
    (``add_team_link_penalty``) rides here, so it is part of the placement optimum locked before
    phase 2, exactly like the soft comfort terms.

    When *hard_satisfied_team_ids* is provided, teams whose weekly sessions are
    fully covered by HARD locks are excluded from the ``placed.Not() *
    -UNPLACED_PENALTY`` term — their solver variables are forced to 0 by the
    remaining_sessions constraint, which would otherwise trigger the penalty
    even though the team is effectively placed.
    """

    if score_formula_version != SCORE_FORMULA_VERSION:
        raise ValueError(
            f"unsupported score_formula_version {score_formula_version!r}; expected {SCORE_FORMULA_VERSION!r}"
        )

    assignment_list = _normalise_assignments(assignments)
    teams_by_id = _teams_by_id(teams)
    variables: list[BoolVarLike] = []
    coefficients: list[int] = []
    coefficient_by_assignment: dict[Any, int] = {}
    placement_terms = 0
    soft_bonus_terms = 0

    for assignment in assignment_list:
        variable = _var(assignment)
        tier_name = _priority_tier_name(assignment, teams_by_id)
        coefficient = LEVEL_2_OBJECTIVE_WEIGHTS[tier_name]
        active_bonuses = _active_bonus_weight_names(assignment)
        for bonus_name in active_bonuses:
            coefficient += LEVEL_2_OBJECTIVE_WEIGHTS[bonus_name]

        variables.append(variable)
        coefficients.append(coefficient)
        coefficient_by_assignment[_assignment_key(assignment, variable)] = coefficient
        placement_terms += 1
        soft_bonus_terms += len(active_bonuses)

    # Soft bonus: reward every placed session to maximise total session count
    for assignment in assignment_list:
        variable = _var(assignment)
        variables.append(variable)
        coefficients.append(LEVEL_2_OBJECTIVE_WEIGHTS["session_count"])
        soft_bonus_terms += 1

    # Unplaced penalty: objective -= UNPLACED_PENALTY * (1 - placed) per team.
    assignments_by_team: dict[Any, list[BoolVarLike]] = {}
    for assignment in assignment_list:
        team_id = _team_id(assignment)
        if team_id is not None:
            assignments_by_team.setdefault(team_id, []).append(_var(assignment))

    for team_id, team_vars in assignments_by_team.items():
        if hard_satisfied_team_ids is not None and str(team_id) in hard_satisfied_team_ids:
            continue
        placed = model.NewBoolVar(f"placed_{team_id}")
        model.Add(sum(team_vars) >= 1).OnlyEnforceIf(placed)
        model.Add(sum(team_vars) == 0).OnlyEnforceIf(placed.Not())
        variables.append(placed.Not())
        coefficients.append(-UNPLACED_PENALTY)

    for soft_term in soft_terms:
        variable, weight_name = _soft_term_variable_and_weight(soft_term)
        variables.append(variable)
        coefficients.append(LEVEL_2_OBJECTIVE_WEIGHTS[weight_name])
        soft_bonus_terms += 1

    # Lot PASSERELLES PR-2 — les malus passerelle PREFERRED, déjà pondérés (poids dérivé du tier),
    # entrent tels quels dans le placement (aucun ``weight_name`` : ce sont des entiers négatifs).
    for extra_var, extra_weight in extra_placement_terms:
        variables.append(extra_var)
        coefficients.append(int(extra_weight))
        soft_bonus_terms += 1

    # Placement objective (tiers + session_count + unplaced penalty + soft terms).
    placement_expression = (
        sum(coefficient * variable for variable, coefficient in zip(variables, coefficients, strict=False))
        if variables
        else 0
    )

    # Chaining terms are BUILT (vars + linking constraints) regardless — a
    # two-phase caller needs them present in the model. Whether they enter the
    # objective now depends on apply_chaining: single-phase (default) folds them
    # into the one Maximize; two-phase (apply_chaining=False) maximises placement
    # only, then the caller locks placement and optimises chaining separately.
    chaining_pairs = add_chaining_bonus(
        model, assignment_list, teams=teams, team_player_map=team_player_map, info_out=info_out
    )

    if apply_chaining and chaining_pairs:
        model.Maximize(placement_expression + sum(weight * variable for variable, weight in chaining_pairs))
    else:
        model.Maximize(placement_expression)

    return Level2ObjectiveStats(
        score_formula_version=SCORE_FORMULA_VERSION,
        placement_terms=placement_terms,
        soft_bonus_terms=soft_bonus_terms,
        total_terms=len(variables) + len(chaining_pairs),
        chaining_bonus=len(chaining_pairs),
        coefficient_by_assignment=coefficient_by_assignment,
        placement_expression=placement_expression,
        chaining_terms=tuple(chaining_pairs),
    )


__all__ = [
    "BONUS_WEIGHT_NAMES",
    "CHAINING_STABILITY_MULTIPLIER",
    "CHAINING_TIER_WEIGHTS",
    "LEVEL_2_OBJECTIVE_WEIGHTS",
    "SCORE_FORMULA_VERSION",
    "SOCLE_REFERENCE_TIER_WEIGHTS",
    "STABILITY_TERM_WEIGHT",
    "TEAM_LINK_TIER_WEIGHTS",
    "TIER_WEIGHT_NAMES",
    "UNPLACED_PENALTY",
    "Level2ObjectiveStats",
    "add_chaining_bonus",
    "add_level_2_objective",
    "add_missing_session_penalty",
    "add_preferred_day_bonus",
    "add_socle_reference_bonus",
    "add_team_link_penalty",
    "add_venue_preference_bonus",
    "build_stability_terms",
    "is_team_satisfied_by_hard_locks",
]
