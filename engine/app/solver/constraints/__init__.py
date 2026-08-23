"""Level-1 hard constraints for the OR-Tools CP-SAT scheduler model.

The solver treats these rules as hard constraints only: no relaxation
variables and no penalties are introduced in this module.

Implicit rules (always applied):
  VENUE_AT_MOST_ONE, COACH_NO_OVERLAP, COACH_PLAYER_NO_OVERLAP,
  TEAM_NO_OVERLAP
MIN_SESSIONS is CAPABLE of a hard floor (add_min_sessions_constraints) but is
currently wired SOFT-ONLY: main._solve passes a floor of 0 for every team and
relies on the objective bonus (session_count) + a WARNING/ERROR diagnostic. It
is a target, not a guarantee (ENG-18).

Derived rules (parsed from v2 constraints[] payload → ParsedConstraints):
  forbidden_assignments, coach_unavailability, forced_venues,
  preferred_venues, time_windows (TIME/DAY/LOCK).

Package layout (ENG-32). This file is the package entry point: it holds this
shared docstring, the re-exports of every submodule (public AND private names —
e.g. ``_intervals_overlap`` is imported by ``tests/golden/test_overlap.py``), and
the ``add_level_1_hard_constraints`` orchestrator itself. The submodules are
``common`` (types/constants/locked-slot readers/normalisation, base of the DAG),
``parsing``, ``wellness``, ``targeting``, ``structural`` and ``diagnostics``.

Why the orchestrator lives HERE and not in ``structural`` beside the posers it
drives: a TEST-SEAM constraint, not an architectural one.
``tests/semantic/test_implicit_rules_are_still_applied.py`` guards "the rule the
wizard announces is still wired" by ``patch.object(C, "add_room_at_most_one")``
on the package namespace ``C`` (``from app.solver import constraints as C``), then
calling ``C.add_level_1_hard_constraints(...)``. The spy is only hit when the
orchestrator resolves its poser calls through THIS module's globals — i.e. the
re-exports. Move it into a submodule and its calls resolve in that submodule's
globals instead: the spies stop biting though no behaviour changes. Whoever wants
to move it must move that test seam first.
"""

from __future__ import annotations

from collections.abc import Iterable, Mapping
from typing import Any

from ..compromise import (
    CompromiseTermInfo,
)
from .common import (
    _MISSING as _MISSING,
)
from .common import (
    AGE_VIOLATION_WEIGHT as AGE_VIOLATION_WEIGHT,
)
from .common import (
    CHAIN_VIOLATION_WEIGHT as CHAIN_VIOLATION_WEIGHT,
)
from .common import (
    COACH_REST_VIOLATION_WEIGHT as COACH_REST_VIOLATION_WEIGHT,
)
from .common import (
    CONSECUTIVE_DAYS_VIOLATION_WEIGHT as CONSECUTIVE_DAYS_VIOLATION_WEIGHT,
)
from .common import (
    HARD as HARD,
)
from .common import (
    MANDATORY as MANDATORY,
)
from .common import (
    OFF as OFF,
)
from .common import (
    PREFERRED as PREFERRED,
)
from .common import (
    SALARIE_VIOLATION_WEIGHT as SALARIE_VIOLATION_WEIGHT,
)
from .common import (
    AssignmentInput as AssignmentInput,
)
from .common import (
    AssignmentVariable as AssignmentVariable,
)
from .common import (
    BoolVarLike as BoolVarLike,
)
from .common import (
    HardConstraintStats as HardConstraintStats,
)
from .common import (
    ParsedConstraints as ParsedConstraints,
)
from .common import (
    ResolvedImplicitRules as ResolvedImplicitRules,
)
from .common import (
    RuleCollection as RuleCollection,
)
from .common import (
    _as_assignment_variable as _as_assignment_variable,
)
from .common import (
    _assignment_day_start as _assignment_day_start,
)
from .common import (
    _assignment_from_mapping_item as _assignment_from_mapping_item,
)
from .common import (
    _assignment_time_key as _assignment_time_key,
)
from .common import (
    _bool_field as _bool_field,
)
from .common import (
    _coerce_id as _coerce_id,
)
from .common import (
    _day_int_set as _day_int_set,
)
from .common import (
    _dedupe_variables as _dedupe_variables,
)
from .common import (
    _extract_interval as _extract_interval,
)
from .common import (
    _get as _get,
)
from .common import (
    _interval_key as _interval_key,
)
from .common import (
    _intervals_overlap as _intervals_overlap,
)
from .common import (
    _locked_person_day_intervals as _locked_person_day_intervals,
)
from .common import (
    _locked_person_day_occupations as _locked_person_day_occupations,
)
from .common import (
    _locked_team_days as _locked_team_days,
)
from .common import (
    _locked_venue_substart_counts as _locked_venue_substart_counts,
)
from .common import (
    _looks_like_day_of_week as _looks_like_day_of_week,
)
from .common import (
    _looks_like_schedule_slot_key as _looks_like_schedule_slot_key,
)
from .common import (
    _looks_like_slot_start as _looks_like_slot_start,
)
from .common import (
    _normalise_assignments as _normalise_assignments,
)
from .common import (
    _not_honored_warning as _not_honored_warning,
)
from .common import (
    _raw_player_ids as _raw_player_ids,
)
from .common import (
    _record_closure as _record_closure,
)
from .common import (
    _scalar_id as _scalar_id,
)
from .common import (
    _to_day_int as _to_day_int,
)
from .common import (
    logger as logger,
)
from .diagnostics import _DAY_LABELS as _DAY_LABELS
from .diagnostics import _constraint_of_coach as _constraint_of_coach
from .diagnostics import _day_constraints_excluding as _day_constraints_excluding
from .diagnostics import _day_label as _day_label
from .diagnostics import _day_rules_union as _day_rules_union
from .diagnostics import _is_enforced_window as _is_enforced_window
from .diagnostics import diagnose_candidate_conflicts as diagnose_candidate_conflicts
from .diagnostics import diagnose_locked_slot_violations as diagnose_locked_slot_violations
from .parsing import _KNOWN_FAMILIES as _KNOWN_FAMILIES
from .parsing import _KNOWN_TYPES as _KNOWN_TYPES
from .parsing import _coach_window_minutes as _coach_window_minutes
from .parsing import _intensity as _intensity
from .parsing import _rule_block as _rule_block
from .parsing import _set_venue_rule as _set_venue_rule
from .parsing import parse_v2_constraints as parse_v2_constraints
from .parsing import resolve_implicit_rules as resolve_implicit_rules
from .structural import _add_at_most_one_groups as _add_at_most_one_groups
from .structural import _add_cross_venue_at_most_one as _add_cross_venue_at_most_one
from .structural import _add_free_vs_locked_interval_conflicts as _add_free_vs_locked_interval_conflicts
from .structural import _add_interval_at_most_one as _add_interval_at_most_one
from .structural import _compute_effective_min_sessions as _compute_effective_min_sessions
from .structural import _effective_min_sessions_by_team as _effective_min_sessions_by_team
from .structural import add_coach_at_most_one as add_coach_at_most_one
from .structural import add_coach_player_non_overlap as add_coach_player_non_overlap
from .structural import add_coach_unavailability_constraints as add_coach_unavailability_constraints
from .structural import add_fixed_slots as add_fixed_slots
from .structural import add_forbidden_assignments as add_forbidden_assignments
from .structural import add_min_sessions_constraints as add_min_sessions_constraints
from .structural import add_room_at_most_one as add_room_at_most_one
from .structural import add_team_no_overlap as add_team_no_overlap
from .targeting import TeamLinkPlacement as TeamLinkPlacement
from .targeting import _forced_venue_id as _forced_venue_id
from .targeting import add_forced_venue_constraints as add_forced_venue_constraints
from .targeting import add_shared_training_constraints as add_shared_training_constraints
from .targeting import add_team_link_constraints as add_team_link_constraints
from .targeting import add_time_window_constraints as add_time_window_constraints
from .targeting import add_venue_minimum_constraints as add_venue_minimum_constraints
from .targeting import iter_team_link_overlaps as iter_team_link_overlaps
from .targeting import team_link_placements_by_team as team_link_placements_by_team
from .targeting import team_share_declared_pairs as team_share_declared_pairs
from .wellness import _find_consecutive_chains as _find_consecutive_chains
from .wellness import add_age_ascending_constraints as add_age_ascending_constraints
from .wellness import add_coach_rest_day_constraints as add_coach_rest_day_constraints
from .wellness import add_max_consecutive_days_constraints as add_max_consecutive_days_constraints
from .wellness import add_max_consecutive_sessions_constraints as add_max_consecutive_sessions_constraints
from .wellness import add_one_session_per_day_constraints as add_one_session_per_day_constraints
from .wellness import add_salarie_distribution_constraints as add_salarie_distribution_constraints


def add_level_1_hard_constraints(
    model: Any,
    assignments: Iterable[AssignmentInput] | Mapping[Any, BoolVarLike] | None = None,
    *,
    teams: Iterable[Any] = (),
    coaches: Iterable[Any] = (),
    min_sessions_by_team: Mapping[Any, int] | None = None,
    forbidden_assignments: Iterable[Any] = (),
    coach_unavailability: RuleCollection = (),
    forced_venues: Mapping[Any, Any] | None = None,
    priority_tiers: Mapping[int, int] | None = None,
    implicit_rules: ResolvedImplicitRules | None = None,
    team_coach_map: dict[str, list[str]] | None = None,
    team_player_map: dict[str, list[str]] | None = None,
    shared_trainings: Iterable[Any] = (),
    team_links: Iterable[Any] = (),
) -> HardConstraintStats:
    """Add the implicit + derived + new-implicit level-1 hard constraints to a CP-SAT model.

    Implicit (always applied):
      1. VENUE_AT_MOST_ONE  — one venue hosts at most capacity teams per time slot
      2. COACH_NO_OVERLAP   — one coach coaches at most one team per time slot
      3. COACH_PLAYER_NO_OVERLAP — a coach-player cannot be in two roles at once
      4. TEAM_NO_OVERLAP    — a team cannot have two sessions at the same time
      5. MIN_SESSIONS        — soft TARGET only (ENG-18): the objective rewards reaching a
                               team's effective minimum; it is NOT a hard guarantee

    Derived (fed from parse_v2_constraints or direct arguments):
      6. fixed_slots          — pre-placed slots forced to 1
      7. forbidden_assignments — forbidden variables forced to 0
      8. coach_unavailability — unavailable coach slots forced to 0
      9. forced_venues        — forced venue excludes alternatives

    New implicit rule:
     10. one_session_per_day  — at most one session per day per team
     11. age_ascending        — younger teams train earlier than older teams (same venue+day)

    ``implicit_rules`` règle par club les 4 règles « bien-être » (3b repos coach, 3c
    distribution salariés, 3d dos-à-dos, 12 âge croissant). ``None`` = tout HARD, seuils
    historiques : la pose est alors byte-identique à l'ancien modèle. Un cran PREFERRED
    RETIRE la contrainte dure de la règle et pose à la place des littéraux de violation
    AGRÉGÉS, collectés dans ``stats.implicit_soft_terms`` pour que l'objectif les pénalise
    (poids −6). ADR-0001 pose un solve single-pass SANS relaxation ; PREFERRED n'est pas
    une relaxation de secours mais un réglage explicite du gestionnaire, toujours
    diagnostiqué post-solve quel que soit le cran.
    """

    if assignments is None:
        assignments = getattr(model, "x", ())

    assignment_list = _normalise_assignments(assignments)
    stats = HardConstraintStats()
    rules = implicit_rules if implicit_rules is not None else ResolvedImplicitRules()
    soft_terms: list[tuple[BoolVarLike, str]] = stats.implicit_soft_terms
    # Métadonnée de nommage des compromis (P2-32) : collectée en parallèle des littéraux soft.
    # N'ajoute AUCUNE variable ni contrainte au modèle — le solve (et donc les goldens) est
    # rigoureusement inchangé, qu'on la collecte ou non.
    soft_info: list[CompromiseTermInfo] = stats.implicit_soft_info

    # 1. One venue hosts at most one team at a time.
    stats.room_at_most_one = add_room_at_most_one(model, assignment_list)

    # 2. One coach works with at most one team at a time.
    stats.coach_at_most_one = add_coach_at_most_one(model, assignment_list, team_coach_map=team_coach_map)

    # 3. A person cannot coach and play at the same time.
    stats.coach_player_non_overlap = add_coach_player_non_overlap(
        model, assignment_list, team_coach_map=team_coach_map, team_player_map=team_player_map
    )

    # 3b. Every coach must keep at least ``min_rest_days`` rest days from Monday to Friday.
    stats.coach_rest_day = add_coach_rest_day_constraints(
        model,
        assignment_list,
        coaches=coaches,
        team_coach_map=team_coach_map,
        team_player_map=team_player_map,
        intensity=rules.coach_rest_day_intensity,
        min_rest_days=rules.min_rest_days,
        soft_terms_out=soft_terms,
        soft_term_info_out=soft_info,
    )

    # 3c. At least one salarié coach must be present each Mon-Fri day.
    stats.salarie_distribution = add_salarie_distribution_constraints(
        model,
        assignment_list,
        coaches=coaches,
        team_coach_map=team_coach_map,
        team_player_map=team_player_map,
        intensity=rules.salarie_distribution_intensity,
        soft_terms_out=soft_terms,
        soft_term_info_out=soft_info,
    )

    # 3d. A person may not be in ``max_consecutive`` back-to-back slots.
    stats.max_consecutive_sessions = add_max_consecutive_sessions_constraints(
        model,
        assignment_list,
        coaches=coaches,
        team_coach_map=team_coach_map,
        team_player_map=team_player_map,
        intensity=rules.max_consecutive_sessions_intensity,
        max_consecutive=rules.max_consecutive,
        soft_terms_out=soft_terms,
        soft_term_info_out=soft_info,
    )

    # 3e. P2-42 — une ÉQUIPE ne s'entraîne pas N JOURS de suite. Voisine de 3d par le nom,
    # étrangère par le sujet : 3d parle d'une personne et de créneaux dos-à-dos dans une
    # journée, 3e d'une équipe et de jours dans une semaine.
    stats.max_consecutive_days = add_max_consecutive_days_constraints(
        model,
        assignment_list,
        intensity=rules.max_consecutive_days_intensity,
        max_consecutive_days=rules.max_consecutive_days,
        soft_terms_out=soft_terms,
        soft_term_info_out=soft_info,
    )

    # 4. A team cannot have two sessions at the same time slot.
    stats.team_no_overlap = add_team_no_overlap(model, assignment_list)

    # 5. Pre-placed slots are fixed and excluded from optimization choices.
    stats.fixed_slots = add_fixed_slots(model, assignment_list)

    # 6. Explicitly forbidden assignment variables are forced to 0.
    stats.forbidden_assignments = add_forbidden_assignments(model, assignment_list, forbidden_assignments)

    # 7. Coach unavailable variables are forced to 0.
    stats.coach_unavailability = add_coach_unavailability_constraints(
        model, assignment_list, coach_unavailability, team_coach_map=team_coach_map
    )

    # 8. Effective minimum sessions are guaranteed by a hard linear bound.
    # (Venue closures are honored upstream: the backend expands them to FACILITY
    # forbiddenVenueId → forbidden_assignments, ENG-02. No dead engine path.)
    stats.min_sessions = add_min_sessions_constraints(
        model,
        assignment_list,
        teams=teams,
        min_sessions_by_team=min_sessions_by_team,
        priority_tiers=priority_tiers,
    )

    # 10. If a venue is forced, every other venue option is forced to 0.
    stats.forced_venues = add_forced_venue_constraints(model, assignment_list, forced_venues=forced_venues)

    # 11. At most one session per day per team (unless explicitly allowed).
    stats.one_session_per_day = add_one_session_per_day_constraints(model, assignment_list, teams=teams)

    # 12. Younger teams train earlier than older teams in the same venue+day.
    stats.age_ascending = add_age_ascending_constraints(
        model,
        assignment_list,
        teams=teams,
        intensity=rules.age_ascending_intensity,
        soft_terms_out=soft_terms,
        soft_term_info_out=soft_info,
    )

    # 13. P2-27 — mutualisation : chaque groupe déclaré partage EXACTEMENT K séances. Vide ⇒
    # aucune pose (chemin byte-identique, goldens inchangés).
    stats.shared_training = add_shared_training_constraints(model, assignment_list, shared_trainings=shared_trainings)

    # 14. Lot PASSERELLES — anti-chevauchement DUR des passerelles MANDATORY. Vide/tout PREFERRED
    # ⇒ aucune pose (chemin byte-identique). Les PREFERRED vivent dans l'objectif.
    stats.team_link = add_team_link_constraints(
        model, assignment_list, team_links=team_links, shared_trainings=shared_trainings
    )

    return stats


__all__ = [
    "AssignmentVariable",
    "HardConstraintStats",
    "add_age_ascending_constraints",
    "add_coach_at_most_one",
    "add_coach_player_non_overlap",
    "add_coach_rest_day_constraints",
    "add_coach_unavailability_constraints",
    "add_fixed_slots",
    "add_forbidden_assignments",
    "add_forced_venue_constraints",
    "add_level_1_hard_constraints",
    "add_max_consecutive_sessions_constraints",
    "add_min_sessions_constraints",
    "add_one_session_per_day_constraints",
    "add_room_at_most_one",
    "add_salarie_distribution_constraints",
    "add_shared_training_constraints",
    "add_team_link_constraints",
    "add_team_no_overlap",
    "add_time_window_constraints",
    "diagnose_locked_slot_violations",
    "iter_team_link_overlaps",
    "parse_v2_constraints",
    "team_link_placements_by_team",
    "team_share_declared_pairs",
]
