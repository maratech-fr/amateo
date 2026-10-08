"""Result builder — diagnostics post-solve lisibles par le gestionnaire (paquet).

Ce ``__init__`` est le point d'entrée du paquet : il porte cette docstring partagée,
l'orchestrateur ``_generate_diagnostics`` et les ré-exports de chaque sous-module (noms publics ET
privés — plusieurs ``_…`` sont importés directement par ``tests/`` et par l'agrégateur
``result_builder``, la surface d'import reste donc byte-identique au module d'avant la découpe).

Les sous-modules forment un DAG simple, tous assis sur ``helpers`` (lecteurs de champ, cartes de
noms, libellés FR) et les modules solveur ``model`` / ``constraints`` : ``locks`` (conflits entre
verrous) · ``placement`` (équipes non placées, surcharge coach, créneaux inutilisés) · ``sessions``
(causes de séances manquantes) · ``infeasibility`` (noyau/agrégat et message d'infaisabilité) ·
``conflicts`` (double-réservations — seule arête interne : importe ``infeasibility``) ·
``shared_blocks`` (blocs mutualisés) · ``links_travel`` (passerelles et temps de trajet) ·
``implicit_rules`` (règles implicites). Le paquet ne dépend PAS de ``slots`` ni de l'agrégateur.

⚠ ENG-37 : ``_diagnose_travel_times`` (dans ``links_travel``) consomme la SOURCE UNIQUE
``is_travel_too_tight``. Le test-garde ``test_travel_diagnostic_delegates_to_the_shared_geometry_source``
neutralise cette source en patchant
``app.solver.result_builder.diagnostics.links_travel.is_travel_too_tight`` — le nom doit rester
résolu DANS ``links_travel`` pour que le garde morde.
"""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

from ortools.sat.python import cp_model

from ...constraints import ResolvedImplicitRules
from ...model import ScheduleCpModel
from .conflicts import (
    _diagnose_conflicts as _diagnose_conflicts,
)
from .conflicts import (
    _occupant_list_with_blocks as _occupant_list_with_blocks,
)
from .implicit_rules import (
    _IMPLICIT_RULE_LABELS as _IMPLICIT_RULE_LABELS,
)
from .implicit_rules import (
    _diagnose_age_violations as _diagnose_age_violations,
)
from .implicit_rules import (
    _diagnose_chain_violations as _diagnose_chain_violations,
)
from .implicit_rules import (
    _diagnose_implicit_rule_violations as _diagnose_implicit_rule_violations,
)
from .implicit_rules import (
    _first_back_to_back_chain as _first_back_to_back_chain,
)
from .implicit_rules import (
    _list_days as _list_days,
)
from .implicit_rules import (
    _softened_prefix as _softened_prefix,
)
from .infeasibility import (
    _CAUSE_KIND_FR as _CAUSE_KIND_FR,
)
from .infeasibility import (
    _closure_var_index as _closure_var_index,
)
from .infeasibility import (
    _collect_infeasibility_causes as _collect_infeasibility_causes,
)
from .infeasibility import (
    _collect_locked_out_teams as _collect_locked_out_teams,
)
from .infeasibility import (
    _infeasible_message as _infeasible_message,
)
from .infeasibility import (
    _lock_summary_message as _lock_summary_message,
)
from .infeasibility import (
    _saturated_venue_minimum as _saturated_venue_minimum,
)
from .infeasibility import (
    _slot_capacity_by_key as _slot_capacity_by_key,
)
from .links_travel import (
    _diagnose_team_links as _diagnose_team_links,
)
from .links_travel import (
    _diagnose_travel_times as _diagnose_travel_times,
)
from .links_travel import (
    _team_link_placements_from_slots as _team_link_placements_from_slots,
)
from .locks import (
    _diagnose_locked_structural_conflicts as _diagnose_locked_structural_conflicts,
)
from .locks import (
    _diagnose_soft_lock_moved as _diagnose_soft_lock_moved,
)
from .placement import (
    _DAY_NAMES as _DAY_NAMES,
)
from .placement import (
    _diagnose_coach_overload as _diagnose_coach_overload,
)
from .placement import (
    _diagnose_unplaced as _diagnose_unplaced,
)
from .placement import (
    _diagnose_unused_slots as _diagnose_unused_slots,
)
from .placement import (
    _unplaced_team_ids as _unplaced_team_ids,
)
from .sessions import (
    _collect_session_causes as _collect_session_causes,
)
from .sessions import (
    _diagnose_session_below_effective_min as _diagnose_session_below_effective_min,
)
from .shared_blocks import (
    _diagnose_shared_blocks as _diagnose_shared_blocks,
)


def _generate_diagnostics(
    model_data: Mapping[str, Any] | Any,
    solver_status: int,
    slots: list[dict[str, Any]],
    *,
    slot_capacities: dict[Any, int] | None = None,
    implicit_rules: ResolvedImplicitRules | None = None,
    team_coach_map: Mapping[str, list[str]] | None = None,
    team_player_map: Mapping[str, list[str]] | None = None,
    session_causes_by_team: Mapping[str, dict[str, Any]] | None = None,
    model: ScheduleCpModel | Any | None = None,
    solver: cp_model.CpSolver | Any | None = None,
    diagnostic_model: ScheduleCpModel | Any | None = None,
    diagnostic_solver: cp_model.CpSolver | Any | None = None,
) -> list[dict[str, Any]]:
    """Run post-solve checks and return manager-readable diagnostics.

    P4-96 — ``_diagnose_conflicts`` nomme, sur INFEASIBLE, le noyau de règles en conflit (D1) à
    partir du SECOND solve diagnostique (``diagnostic_model``/``diagnostic_solver``), et agrège les
    candidats fermés par équipe (D2) depuis le modèle NOMINAL (``model``). Diagnostique absent ⇒ D1
    vide, message d'infaisabilité générique conservé ; ``model`` absent ⇒ pas d'agrégat D2."""
    diagnostics: list[dict[str, Any]] = []
    # ENG-22: every "analysis of the placed slots" diagnostic only makes sense for a REAL
    # solve (OPTIMAL/FEASIBLE). On INFEASIBLE the demand-vs-supply message explains it; on
    # UNKNOWN/timeout the solver simply didn't finish — claiming teams are "below their
    # minimum for lack of gym slots" or slots are "occupied" would be a lie contradicting the
    # timeout diagnostic. _diagnose_conflicts owns the INFEASIBLE + timeout cases.
    if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        # Les règles implicites TOUJOURS diagnostiquées, quel que soit le cran (sur les slots
        # FINAUX, verrous inclus). Calculé d'abord : un coach déjà signalé « repos non tenu »
        # ne doit pas recevoir EN PLUS un coach_overload (un seul warning par fait).
        implicit_diags = _diagnose_implicit_rule_violations(
            model_data,
            slots,
            implicit_rules if implicit_rules is not None else ResolvedImplicitRules(),
            team_coach_map or {},
            team_player_map or {},
        )
        diagnostics.extend(implicit_diags)
        rest_flagged_coaches = {
            str(diag.get("coachId"))
            for diag in implicit_diags
            if diag.get("ruleKey") == "coachRestDay" and diag.get("coachId")
        }

        diagnostics.extend(
            _diagnose_locked_structural_conflicts(model_data, slots, team_coach_map or {}, team_player_map or {})
        )
        diagnostics.extend(_diagnose_unplaced(model_data, slots))
        diagnostics.extend(_diagnose_soft_lock_moved(model_data, slots))
        diagnostics.extend(
            diag
            for diag in _diagnose_coach_overload(model_data, slots)
            if str(diag.get("coachId")) not in rest_flagged_coaches
        )
        diagnostics.extend(
            _diagnose_session_below_effective_min(model_data, slots, session_causes_by_team=session_causes_by_team)
        )
        diagnostics.extend(_diagnose_unused_slots(model_data, slots))
    diagnostics.extend(
        _diagnose_conflicts(
            model_data,
            solver_status,
            slots,
            slot_capacities=slot_capacities,
            model=model,
            solver=solver,
            diagnostic_model=diagnostic_model,
            diagnostic_solver=diagnostic_solver,
        )
    )
    diagnostics.extend(_diagnose_shared_blocks(model_data, solver_status, slots))
    diagnostics.extend(_diagnose_team_links(model_data, solver_status, slots))
    diagnostics.extend(_diagnose_travel_times(model_data, solver_status, slots, team_coach_map or {}))
    return diagnostics
