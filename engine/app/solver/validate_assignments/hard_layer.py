from __future__ import annotations

from typing import Any, cast

from ortools.sat.python import cp_model

from app.solver.constraints import (
    HardConstraintStats,
    ParsedConstraints,
    add_level_1_hard_constraints,
    add_time_window_constraints,
    add_venue_minimum_constraints,
    resolve_implicit_rules,
)
from app.solver.model import (
    DEFAULT_SESSION_MINUTES,
    ScheduleCpModel,
    SlotKey,
    _format_time,
    _time_to_minutes,
    build_model,
)


def _build_assignments(
    model: ScheduleCpModel,
    team_coach_map: dict[str, list[str]],
    frozen_keys: set[SlotKey],
) -> list[dict[str, Any]]:
    """Assignments over the full model.x — identical shape to ``main._solve`` —
    with ``fixed=True`` on the frozen baseline (consumed by ``add_fixed_slots``)."""
    assignments: list[dict[str, Any]] = []
    for slot_key, var in model.x.items():
        team_id_str = str(slot_key[0])
        venue_id_str = str(slot_key[1])
        day_of_week = slot_key[2]
        slot_start = slot_key[3]
        vsk = (venue_id_str, day_of_week, slot_start)
        duration = model.slot_durations.get(vsk, DEFAULT_SESSION_MINUTES)
        start_minutes = _time_to_minutes(slot_start)
        team_coaches = team_coach_map.get(team_id_str) or []
        assignments.append(
            {
                "var": var,
                "team_id": team_id_str,
                "venue_id": venue_id_str,
                "slot_id": f"{day_of_week}:{slot_start}",
                "start": start_minutes,
                "end": start_minutes + duration,
                "coach_id": team_coaches[0] if team_coaches else None,
                "fixed": slot_key in frozen_keys,
            }
        )
    return assignments


def _frozen_occupancy(data: dict[str, Any]) -> set[tuple[str, str, int, str]]:
    """Ce que le planning occupait AVANT le geste : le backend retire la séance déplacée du figé et la
    passe en référence. Sans elle, un déplacement ferait croire qu'une règle tenue était déjà violée."""
    occupancy: set[tuple[str, str, int, str]] = set()
    for slot in [*(data.get("slotTemplates") or []), *(data.get("references") or [])]:
        occupancy.add(
            (
                str(slot.get("teamId") or slot.get("team_id") or ""),
                str(slot.get("venueId") or slot.get("venue_id") or ""),
                int(slot.get("dayOfWeek") or slot.get("day_of_week") or 0),
                _format_time(_time_to_minutes(slot.get("startTime") or slot.get("start_time"))),
            )
        )
    return occupancy


def _apply_hard(
    model: ScheduleCpModel,
    assignments: list[dict[str, Any]],
    data: dict[str, Any],
    parsed: ParsedConstraints,
    team_coach_map: dict[str, list[str]],
    team_player_map: dict[str, list[str]],
) -> HardConstraintStats:
    """The generation model's HARD layer, minus objective and session caps —
    ``add_fixed_slots`` (inside) freezes the baseline; nothing here relaxes.

    Parité génération ⇄ verdict : le même réglage ``implicitRules`` s'applique, et la
    ``venueTravelTimes`` est CONSOMMÉE ici comme dans ``/generate`` (P2-55) — sous
    ``travelTime`` MANDATORY, un enchaînement au battement trop court pose l'INTERDIT DUR
    et rend le déplacement fautif INFEASIBLE. Un cran HARD bloque le déplacement qui le
    casse ; un cran PREFERRED ne bloque pas (ses littéraux de violation sont posés mais
    sans objectif ici — feasibility check seul)."""
    # P4-361 — relais de l'adjoint (chantier H) : la pose HARD du trajet EXEMPTE un enchaînement
    # vers un verrou tolérant dont l'adjoint ouvre la séance. `add_travel_time_hard_constraints` lit
    # ces cartes sur le modèle (`late_relay_tolerances`) ; on les câble ici pour que le verdict pose
    # EXACTEMENT la même couche HARD que `/generate` (parité `test_hard_layer_parity_registry`).
    model.team_assistant_map = parsed["team_assistant_map"]
    model.coach_unavailability = parsed["coach_unavailability"]
    model.frozen_occupancy = _frozen_occupancy(data)
    min_by_team: dict[str, int] = {str(t.get("id")): 0 for t in data.get("teams", []) if t.get("id")}
    stats = add_level_1_hard_constraints(
        model,
        assignments,
        teams=data.get("teams", []),
        coaches=data.get("coaches", []),
        forbidden_assignments=parsed["forbidden_assignments"],
        coach_unavailability=parsed["coach_unavailability"],
        forced_venues=parsed["forced_venues"],
        priority_tiers=parsed.get("priority_tiers", {}),
        min_sessions_by_team=min_by_team or None,
        implicit_rules=resolve_implicit_rules(data.get("implicitRules")),
        team_coach_map=team_coach_map,
        team_player_map=team_player_map,
        shared_blocks=data.get("sharedBlocks", []),
        team_links=data.get("teamLinks", []),
        venue_travel_times=data.get("venueTravelTimes", []),
    )
    add_time_window_constraints(model, model.x, parsed["time_windows"])
    # P4-152 — le PLANCHER de gymnase (« au moins N séances au gymnase V ») est POSÉ ici comme sur
    # ``/generate`` (main.py) : parité de la couche HARD, gardée par le registre
    # ``test_hard_layer_parity_registry``. Il ne peut PAS, à lui seul, NOMMER un déplacement fautif
    # — les autres créneaux restent libres et le solveur place une séance fantôme pour tenir le
    # plancher (verdict « valide » à tort). C'est le miroir déterministe
    # ``_venue_minimum_move_violation`` (avant le solve) qui juge l'état concret et NOMME le refus,
    # exactement comme le trajet (ENG-36).
    add_venue_minimum_constraints(model, model.x, parsed.get("venue_minimums", []))
    return stats


def _solve(model: ScheduleCpModel, *, timeout_seconds: int, seed: int) -> tuple[int, cp_model.CpSolver]:
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = float(timeout_seconds)
    # Mono-candidat, baseline entierement figee : pas de portefeuille, 1 worker
    # rend le verdict reproductible d'un appel a l'autre sur la meme entree.
    solver.parameters.num_search_workers = 1
    solver.parameters.random_seed = seed
    return solver.Solve(model), solver


def _baseline_solve_status(
    data: dict[str, Any],
    parsed: ParsedConstraints,
    team_coach_map: dict[str, list[str]],
    team_player_map: dict[str, list[str]],
    frozen_keys: set[SlotKey],
    *,
    timeout_seconds: int,
    seed: int,
) -> int:
    """Statut CP-SAT du planning courant, FIGE mais SANS le candidat. Utilise seulement
    sur le chemin rare « infaisable + rien de nomme » pour distinguer un candidat fautif
    d'une baseline deja invalide (condition d'arret fondateur : figer un planning pourtant
    valide ne doit pas conclure « non » a tout). Renvoie le STATUT brut (et non un booleen)
    pour que l'appelant distingue un UNKNOWN (sonde expiree -> verdict indetermine, ENG-51)
    d'un INFEASIBLE (baseline reellement invalide)."""
    model = build_model(data)
    model.team_coach_map = team_coach_map
    assignments = _build_assignments(model, team_coach_map, frozen_keys)
    _apply_hard(model, assignments, data, parsed, team_coach_map, team_player_map)
    status, _ = _solve(model, timeout_seconds=timeout_seconds, seed=seed)
    return status


def _least_unheld_locks(model: ScheduleCpModel, *, timeout_seconds: int, seed: int) -> list[dict[str, Any]] | None:
    """Les verrous qu'aucun coach ne peut tenir, au mieux des tenues. ``None`` si le solve ne le prouve pas."""
    holds = list({id(hold): hold for hold in model.lock_holds.values()}.values())
    cast(Any, model).Minimize(sum(hold["unheld"] for hold in holds))
    status, solver = _solve(model, timeout_seconds=timeout_seconds, seed=seed)
    if status != cp_model.OPTIMAL:
        return None
    return [hold for hold in holds if solver.Value(hold["unheld"]) == 1]


def _locks_left_unheld_by_the_move(
    model: ScheduleCpModel,
    solver: cp_model.CpSolver,
    data: dict[str, Any],
    parsed: ParsedConstraints,
    team_coach_map: dict[str, list[str]],
    team_player_map: dict[str, list[str]],
    frozen_keys: set[SlotKey],
    reference_keys: set[SlotKey],
    *,
    timeout_seconds: int,
    seed: int,
) -> list[dict[str, Any]]:
    """La tenue d'un verrou cède sans rendre le modèle infaisable : le verdict doit donc regarder si le
    geste a retiré le dernier coach d'une séance fixée qui en avait un. Un verrou déjà sans coach avant
    le geste ne compte pas (jamais de blocage sur un écart déjà là). Sans preuve, le geste passe."""
    if not any(solver.Value(hold["unheld"]) == 1 for hold in model.lock_holds.values()):
        return []
    after = _least_unheld_locks(model, timeout_seconds=timeout_seconds, seed=seed)
    if not after:
        return []

    baseline = build_model(data)
    baseline.team_coach_map = team_coach_map
    assignments = _build_assignments(baseline, team_coach_map, frozen_keys)
    for key in reference_keys:
        if key in baseline.x:
            cast(Any, baseline).Add(baseline.x[key] == 1)
    _apply_hard(baseline, assignments, data, parsed, team_coach_map, team_player_map)
    before = _least_unheld_locks(baseline, timeout_seconds=timeout_seconds, seed=seed)
    if before is None:
        return []

    already_unheld = {(hold["team"], hold["venue"], hold["day"], hold["start"]) for hold in before}
    return [hold for hold in after if (hold["team"], hold["venue"], hold["day"], hold["start"]) not in already_unheld]
