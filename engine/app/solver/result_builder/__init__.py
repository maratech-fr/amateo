"""Transform a CP-SAT solution into a ScheduleOutputSchema-compatible dict.

The builder reads the solved boolean variables ``x[team, venue, day, slot]``,
merges them with pre-placed HARD locked slots, and produces manager-readable
diagnostics for any post-solve issues it detects.

Package layout (ENG-39). This file is the package entry point: it holds this
shared docstring, the ``build_result`` orchestrator, and the re-exports of every
submodule (public AND private names — several ``_…`` helpers, e.g.
``_diagnose_conflicts`` / ``_infeasible_message`` / ``_occupant_list`` /
``_collect_session_causes``, are imported directly by ``tests/``, so the import
surface stays byte-identical to the pre-split module). The submodules form a
simple DAG: ``helpers`` (field readers, name maps, FR labels — base of the DAG) →
``slots`` (locked + solver output slots) and ``diagnostics`` (``_generate_diagnostics``
+ the thirteen ``_diagnose_*``). ``diagnostics`` owns the ENG-37 travel-geometry
delegation: patch ``…result_builder.diagnostics.is_travel_too_tight``, not this
package namespace.
"""

from __future__ import annotations

from collections.abc import Mapping
from importlib.metadata import version
from typing import Any

from ortools.sat.python import cp_model

from ..model import ScheduleCpModel
from ..objective import SCORE_FORMULA_VERSION as SCORE_FORMULA_VERSION
from .diagnostics import (
    _DAY_NAMES as _DAY_NAMES,
)
from .diagnostics import (
    _IMPLICIT_RULE_LABELS as _IMPLICIT_RULE_LABELS,
)
from .diagnostics import (
    _collect_session_causes as _collect_session_causes,
)
from .diagnostics import (
    _diagnose_age_violations as _diagnose_age_violations,
)
from .diagnostics import (
    _diagnose_chain_violations as _diagnose_chain_violations,
)
from .diagnostics import (
    _diagnose_coach_overload as _diagnose_coach_overload,
)
from .diagnostics import (
    _diagnose_conflicts as _diagnose_conflicts,
)
from .diagnostics import (
    _diagnose_implicit_rule_violations as _diagnose_implicit_rule_violations,
)
from .diagnostics import (
    _diagnose_locked_structural_conflicts as _diagnose_locked_structural_conflicts,
)
from .diagnostics import (
    _diagnose_session_below_effective_min as _diagnose_session_below_effective_min,
)
from .diagnostics import (
    _diagnose_shared_trainings as _diagnose_shared_trainings,
)
from .diagnostics import (
    _diagnose_soft_lock_moved as _diagnose_soft_lock_moved,
)
from .diagnostics import (
    _diagnose_team_links as _diagnose_team_links,
)
from .diagnostics import (
    _diagnose_travel_times as _diagnose_travel_times,
)
from .diagnostics import (
    _diagnose_unplaced as _diagnose_unplaced,
)
from .diagnostics import (
    _diagnose_unused_slots as _diagnose_unused_slots,
)
from .diagnostics import (
    _first_back_to_back_chain as _first_back_to_back_chain,
)
from .diagnostics import (
    _generate_diagnostics as _generate_diagnostics,
)
from .diagnostics import (
    _infeasible_message as _infeasible_message,
)
from .diagnostics import (
    _list_days as _list_days,
)
from .diagnostics import (
    _saturated_venue_minimum as _saturated_venue_minimum,
)
from .diagnostics import (
    _slot_capacity_by_key as _slot_capacity_by_key,
)
from .diagnostics import (
    _softened_prefix as _softened_prefix,
)
from .diagnostics import (
    _team_link_placements_from_slots as _team_link_placements_from_slots,
)
from .diagnostics import (
    _unplaced_team_ids as _unplaced_team_ids,
)
from .helpers import (
    _FR_DAYS as _FR_DAYS,
)
from .helpers import (
    _coach_name_map as _coach_name_map,
)
from .helpers import (
    _coach_threshold as _coach_threshold,
)
from .helpers import (
    _collection as _collection,
)
from .helpers import (
    _day_label as _day_label,
)
from .helpers import (
    _find_coach_for_team as _find_coach_for_team,
)
from .helpers import (
    _get as _get,
)
from .helpers import (
    _label as _label,
)
from .helpers import (
    _named_list as _named_list,
)
from .helpers import (
    _occupant_list as _occupant_list,
)
from .helpers import (
    _slot_day as _slot_day,
)
from .helpers import (
    _slot_templates as _slot_templates,
)
from .helpers import (
    _team_ids as _team_ids,
)
from .helpers import (
    _team_name_map as _team_name_map,
)
from .helpers import (
    _time_range as _time_range,
)
from .helpers import (
    _venue_name_map as _venue_name_map,
)
from .slots import (
    _build_solver_slots as _build_solver_slots,
)
from .slots import (
    _locked_slot_to_dict as _locked_slot_to_dict,
)
from .slots import (
    _slot_id as _slot_id,
)


def build_result(
    model_data: Mapping[str, Any] | Any,
    solver: cp_model.CpSolver,
    model: ScheduleCpModel,
    *,
    status: Any | None = None,
    constraint_version: str | None = None,
    team_coach_map: Mapping[str, list[str]] | None = None,
) -> dict[str, Any]:
    """Transform a CP-SAT solution into a dict matching ``ScheduleOutputSchema``.

    Args:
        model_data: The original input data (dict or Pydantic model).
        solver: The OR-Tools ``CpSolver`` instance after solving.
        model: The ``ScheduleCpModel`` containing variables and locked slots.
        team_coach_map: ENG-17 — la carte équipe → coachs MAIN issue de
            ``parse_v2_constraints``. SOURCE UNIQUE : c'est déjà elle que le solveur
            utilise pour l'exclusivité coach et pour attacher le coach aux
            assignments (``main.py``). La redériver ici recopierait sa règle (filtre
            de rôle, alias de clés) et les deux divergeraient.
        status: Optional solver status. If omitted, inferred from the solver.

    Returns:
        A dictionary that validates against ``ScheduleOutputSchema``.
    """
    if status is not None:
        solver_status = status
    elif hasattr(solver, "_checked_response") and hasattr(solver._checked_response, "status"):
        solver_status = solver._checked_response.status
    else:
        solver_status = cp_model.UNKNOWN

    schema_status = "completed" if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE) else "failed"

    slots: list[dict[str, Any]] = []
    diagnostics: list[dict[str, Any]] = []

    # Preserve HARD locked slots regardless of solver status.
    for locked in model.locked_slots:
        slots.append(_locked_slot_to_dict(locked))

    # Add solver-placed slots when the problem was solved successfully.
    if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        # ENG-17 — la carte vient du MODÈLE par défaut (posée par `_solve`) ; le
        # paramètre reste pour les appels directs (tests, harnais) qui construisent
        # un modèle à la main.
        solver_slots = _build_solver_slots(
            model_data,
            solver,
            model,
            team_coach_map if team_coach_map is not None else getattr(model, "team_coach_map", None),
        )
        slots.extend(solver_slots)

    # Always run diagnostic checks. Le réglage implicite + les cartes coach/joueur viennent
    # du MODÈLE (posés par ``main._solve``) : les warnings de règle implicite doivent se
    # calculer au MÊME grain que la pose (personnes = coachs/joueurs des contraintes).
    slot_capacities: dict[Any, int] = getattr(model, "slot_capacities", {})
    resolved_team_coach_map = team_coach_map if team_coach_map is not None else getattr(model, "team_coach_map", None)
    # P4-99 — la cause MESURÉE d'un créneau manquant, par équipe. Calculée UNIQUEMENT sur un
    # solve abouti (les variables n'ont de valeur qu'alors) ; l'inversion var→équipe se fait
    # ici, depuis `model.x`, seul endroit qui tient variable ET slot_key.
    session_causes_by_team: dict[str, dict[str, Any]] = {}
    if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        session_causes_by_team = _collect_session_causes(model, solver)
    diagnostics.extend(
        _generate_diagnostics(
            model_data,
            solver_status,
            slots,
            slot_capacities=slot_capacities,
            implicit_rules=getattr(model, "implicit_rules", None),
            team_coach_map=resolved_team_coach_map,
            team_player_map=getattr(model, "team_player_map", None),
            session_causes_by_team=session_causes_by_team,
        )
    )

    unplaced = _unplaced_team_ids(model_data, slots)

    score: int | None = None
    if solver_status in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        # P3-21 — quand la phase 2 a appliqué le terme de stabilité, `_solve` a recalculé le
        # score aux poids d'ORIGINE (placement + chaînage naturel, stabilité exclue) car
        # `ObjectiveValue()` porterait alors le multiplicateur de chaînage + la stabilité.
        # Sans stabilité l'override reste None ⇒ `ObjectiveValue()` tel quel (byte-identique).
        override = getattr(model, "reported_score_override", None)
        score = override if override is not None else int(solver.ObjectiveValue())

    metrics = {
        "solver_version": version("ortools"),
        "nb_variables": int(model.NumVariables()),
        "nb_constraints": len(model.Proto().constraints),
        "wall_time_ms": round(solver.WallTime() * 1000),
        # Determinism identifiers — the backend persists these on the Schedule.
        "score_formula_version": SCORE_FORMULA_VERSION,
        "constraint_version": constraint_version,
    }

    return {
        "status": schema_status,
        "score": score,
        "metrics": metrics,
        "unplaced": unplaced,
        "slots": slots,
        "diagnostics": diagnostics,
    }
