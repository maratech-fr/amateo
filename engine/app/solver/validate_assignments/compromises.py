from __future__ import annotations

from typing import Any, cast

from app.solver.compromise import CompromiseTermInfo, compute_compromises
from app.solver.constraints import MANDATORY, ParsedConstraints, add_travel_time_penalty, resolve_implicit_rules
from app.solver.model import SlotKey, build_model
from app.solver.objective import (
    LEVEL_2_OBJECTIVE_WEIGHTS,
    add_coach_day_cap_penalty,
    add_level_2_objective,
    add_match_day_rest_bonus,
    add_preferred_day_bonus,
    add_preferred_time_bonus,
    add_spacing_penalty,
    add_team_link_penalty,
    add_venue_preference_bonus,
)

from .hard_layer import _apply_hard, _build_assignments, _solve


def _evaluate_state(
    data: dict[str, Any],
    parsed: ParsedConstraints,
    team_coach_map: dict[str, list[str]],
    team_player_map: dict[str, list[str]],
    frozen_keys: set[SlotKey],
    pinned_keys: set[SlotKey],
    *,
    timeout_seconds: int,
    seed: int,
) -> list[CompromiseTermInfo]:
    """Un état FIGÉ (baseline gelée + ``pinned_keys`` épinglés, TOUT le reste forcé à 0),
    évalué par le solveur avec le MÊME objectif que ``/generate`` + ``Maximize``.

    Le modèle est entièrement déterminé : les placements sont fixes, la maximisation ne fait
    que résoudre les littéraux réifiés de confort (et pousser le littéral ``chained`` à vrai
    quand ses deux séances sont posées — ce que SEUL l'objectif peut faire, cf. objective.py).
    Renvoie la métadonnée de chaque terme soft, sa ``value`` remplie depuis la solution.
    """
    model = build_model(data)
    model.team_coach_map = team_coach_map
    assignments = _build_assignments(model, team_coach_map, frozen_keys)

    kept: set[SlotKey] = set(frozen_keys)
    for key in pinned_keys:
        if key in model.x:
            cast(Any, model).Add(model.x[key] == 1)
            kept.add(key)
    # Toutes les AUTRES variables à 0 : sans quoi le Maximize placerait des séances fantômes
    # (le confort a des bonus positifs) et l'état évalué ne serait plus « baseline + candidat ».
    for key, var in model.x.items():
        if key not in kept:
            cast(Any, model).Add(var == 0)

    stats = _apply_hard(model, assignments, data, parsed, team_coach_map, team_player_map)

    info: list[CompromiseTermInfo] = list(stats.implicit_soft_info)
    soft_terms: list[tuple[Any, str]] = []
    soft_terms.extend(add_venue_preference_bonus(model.x, parsed, info_out=info))
    soft_terms.extend(
        add_preferred_day_bonus(model, model.x, parsed["time_windows"], LEVEL_2_OBJECTIVE_WEIGHTS, info_out=info)
    )
    soft_terms.extend(
        add_preferred_time_bonus(model, model.x, parsed["time_windows"], LEVEL_2_OBJECTIVE_WEIGHTS, info_out=info)
    )
    soft_terms.extend(
        add_match_day_rest_bonus(model, model.x, data.get("teams", []), LEVEL_2_OBJECTIVE_WEIGHTS, info_out=info)
    )
    soft_terms.extend(
        add_spacing_penalty(model, model.x, data.get("teams", []), LEVEL_2_OBJECTIVE_WEIGHTS, info_out=info)
    )
    soft_terms.extend(
        add_coach_day_cap_penalty(
            model, model.x, data.get("coaches", []), team_coach_map, LEVEL_2_OBJECTIVE_WEIGHTS, info_out=info
        )
    )
    soft_terms.extend(stats.implicit_soft_terms)

    # Lot PASSERELLES PR-2 — malus des passerelles PREFERRED, avec ``info_out`` : un chevauchement
    # créé par le déplacement remonte alors comme COMPROMIS nommé (rail P2-32, arbitrage n°4).
    team_link_penalty_terms = add_team_link_penalty(
        model,
        assignments,
        team_links=data.get("teamLinks", []),
        shared_blocks=data.get("sharedBlocks", []),
        teams=data.get("teams", []),
        info_out=info,
    )

    # P2-55 — battement de trajet PREFERRED : le malus SOFT du déplacement remonte comme COMPROMIS
    # nommé (famille ``FAMILY_TRAVEL``), à l'identique du chemin ``/generate`` (main.py). Ne produit
    # des termes QUE si la règle est active ET PREFERRED (le MANDATORY est dur, posé dans
    # ``_apply_hard``). Matrice absente / règle inactive / MANDATORY ⇒ [] (chemin byte-identique).
    resolved_rules = resolve_implicit_rules(data.get("implicitRules"))
    travel_battement_terms: list[tuple[Any, int]] = []
    if resolved_rules.travel_time_active and resolved_rules.travel_time_intensity != MANDATORY:
        travel_battement_terms = add_travel_time_penalty(
            model,
            assignments,
            coaches=data.get("coaches", []),
            team_links=data.get("teamLinks", []),
            team_coach_map=team_coach_map,
            venue_travel_times=data.get("venueTravelTimes", []),
            default_minutes=resolved_rules.travel_time_default_minutes,
            tolerance_minutes=resolved_rules.travel_time_tolerance_minutes,
            info_out=info,
        )

    add_level_2_objective(
        model,
        assignments,
        teams=data.get("teams", []),
        soft_terms=soft_terms,
        apply_chaining=True,
        team_player_map=team_player_map,
        info_out=info,
        extra_placement_terms=[*team_link_penalty_terms, *travel_battement_terms],
    )

    _, solver = _solve(model, timeout_seconds=timeout_seconds, seed=seed)
    for term in info:
        term.value = int(solver.Value(term.var))
    return info


def _compromises_for(
    data: dict[str, Any],
    parsed: ParsedConstraints,
    team_coach_map: dict[str, list[str]],
    team_player_map: dict[str, list[str]],
    frozen_keys: set[SlotKey],
    candidate_keys: set[SlotKey],
    reference_keys: set[SlotKey],
    names: dict[str, dict[str, str]],
    *,
    timeout_seconds: int,
    seed: int,
) -> list[dict[str, Any]]:
    """Le DELTA de confort entre « avant » (baseline + les N références, ou baseline nue) et
    « après » (baseline + les N candidats) — appelé UNIQUEMENT sur un déplacement accepté."""
    after = _evaluate_state(
        data,
        parsed,
        team_coach_map,
        team_player_map,
        frozen_keys,
        candidate_keys,
        timeout_seconds=timeout_seconds,
        seed=seed,
    )
    before = _evaluate_state(
        data,
        parsed,
        team_coach_map,
        team_player_map,
        frozen_keys,
        reference_keys,
        timeout_seconds=timeout_seconds,
        seed=seed,
    )
    return compute_compromises(before, after, names)
