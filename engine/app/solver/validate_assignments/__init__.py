"""Validation d'un geste manuel — verdict HARD + compromis (paquet).

Ce ``__init__`` est le point d'entrée du paquet : il porte la docstring, le ``logger``, le
plafond ``COMPROMISE_ELAPSED_BUDGET_SECONDS``, les deux lecteurs ``_coach_label`` / ``_slot_key_of``
et surtout l'orchestrateur ``validate_assignment`` ; les ré-exports ``from .x import y as y`` de
chaque sous-module gardent la surface d'import byte-identique au module d'avant la découpe.

Les sous-modules forment un DAG simple assis sur les modules solveur ``model`` / ``constraints`` /
``objective`` : ``mirrors`` (les quatre miroirs de contrainte qui RE-DÉRIVENT une violation à partir
du seul geste, sans le solveur) · ``hard_layer`` (construction d'assignations, pose de la couche
HARD, ``_solve`` et le statut de référence) · ``compromises`` (seule arête interne : ``_evaluate_state``
reconstruit et évalue un état via ``_build_assignments`` / ``_apply_hard`` / ``_solve`` de
``hard_layer``, ``_compromises_for`` en déduit les compromis).

⚠ Trois coutures de test mordent sur CE module, pas sur les sous-modules : l'orchestrateur appelle
``_compromises_for``, ``_solve`` et lit ``COMPROMISE_ELAPSED_BUDGET_SECONDS`` par leur nom GLOBAL ici,
pour que ``monkeypatch.setattr(validate_assignments, …)`` reste mordant
(``tests/test_validate_compromise_failure_is_best_effort.py`` patche ``_compromises_for`` et
``COMPROMISE_ELAPSED_BUDGET_SECONDS`` ; ``tests/semantic/test_validate_assignments_semantics.py``
patche ``_solve``).
"""

from __future__ import annotations

import logging
import time
from typing import Any, cast

from ortools.sat.python import cp_model

from app.schemas.validate_input_schema import CandidateAssignmentSchema, ValidateAssignmentsInputSchema
from app.solver.constraints import diagnose_candidate_conflicts, parse_v2_constraints, resolve_implicit_rules
from app.solver.model import (
    DEFAULT_SESSION_MINUTES,
    HARD_LOCK_LEVEL,
    SlotKey,
    _format_time,
    _time_to_minutes,
    build_model,
)

from .compromises import (
    _compromises_for as _compromises_for,
)
from .compromises import (
    _evaluate_state as _evaluate_state,
)
from .hard_layer import (
    _apply_hard as _apply_hard,
)
from .hard_layer import (
    _baseline_solve_status as _baseline_solve_status,
)
from .hard_layer import (
    _build_assignments as _build_assignments,
)
from .hard_layer import (
    _solve as _solve,
)
from .mirrors import (
    _DAY_LABELS_FR as _DAY_LABELS_FR,
)
from .mirrors import (
    _shared_block_move_violation as _shared_block_move_violation,
)
from .mirrors import (
    _team_link_move_violation as _team_link_move_violation,
)
from .mirrors import (
    _travel_time_move_violation as _travel_time_move_violation,
)
from .mirrors import (
    _venue_minimum_move_violation as _venue_minimum_move_violation,
)

logger = logging.getLogger("engine.validate_assignments")

# Au-delà de ce temps DÉJÀ consommé par le verdict, on n'entame pas le calcul des compromis :
# le verdict est tranché, l'habillage explicatif est un bonus, et le backend coupe le transport
# à 20 s (`MoveSlotService::VALIDATE_HTTP_TIMEOUT_SECONDS`). Sans ce garde-fou, un club qui
# grossit rallonge silencieusement la réponse jusqu'à re-toucher le plafond — le geste échouerait
# de nouveau alors qu'il est LÉGAL (incident du 2026-08-17). Ici il dégrade : réponse honnête,
# compromis vides.
COMPROMISE_ELAPSED_BUDGET_SECONDS = 8.0


def _coach_label(coach: dict[str, Any]) -> str:
    first = str(coach.get("first_name") or coach.get("firstName") or "").strip()
    last = str(coach.get("last_name") or coach.get("lastName") or "").strip()
    full = f"{first} {last}".strip()
    return full or str(coach.get("id"))


def _slot_key_of(assignment: CandidateAssignmentSchema | None) -> SlotKey | None:
    """La SlotKey d'un candidat/référence, dans le format de ``model.x`` (heure normalisée)."""
    if assignment is None:
        return None
    start_text = _format_time(_time_to_minutes(assignment.start_time))
    return (str(assignment.team_id), str(assignment.venue_id), int(assignment.day_of_week), start_text)


def validate_assignment(
    input_data: ValidateAssignmentsInputSchema,
    *,
    contract_version: str | None = None,
) -> dict[str, Any]:
    """Verdict moteur sur N deplacements sous UN verdict (P2-2 F2a / P2-51 PR-5b).

    Le reste du planning est FIGE via ``add_fixed_slots`` ; on epingle les N candidats
    et on demande au moteur si le modele HARD reste faisable. La reponse booleenne
    vient donc du SOLVEUR (« ce que le solveur applique vraiment ») ; les regles
    cassees sont ensuite NOMMEES pour l'UI. Sans le gel de baseline, le solveur
    pourrait tout redeplacer et le verdict ne voudrait plus rien dire.

    Un deplacement simple = une liste ``candidates`` a UN element ; un deplacement de bloc
    = N candidats (les N sources deja retirees de la baseline cote backend). Les miroirs
    deterministes jugent l'ETAT FINAL (baseline + les N candidats), jamais N jugements
    sequentiels d'un etat intermediaire faux.
    """
    started = time.monotonic()
    data: dict[str, Any] = input_data.model_dump(by_alias=True)
    parsed = parse_v2_constraints(data.get("constraints", []))
    team_coach_map: dict[str, list[str]] = parsed.get("team_coach_map", {})
    team_player_map: dict[str, list[str]] = parsed.get("team_player_map", {})

    model = build_model(data)
    model.team_coach_map = team_coach_map

    # Les N candidats, dérivés une fois : un dict par déplacement (le langage des miroirs) + la
    # SlotKey pinnée dans model.x. Une liste à 1 élément EST le cas single — un seul chemin.
    moved: list[dict[str, Any]] = []
    candidate_keys: list[SlotKey] = []
    for candidate in input_data.candidates:
        c_team = str(candidate.team_id)
        c_venue = str(candidate.venue_id)
        c_day = int(candidate.day_of_week)
        c_start_min = _time_to_minutes(candidate.start_time)
        c_start_text = _format_time(c_start_min)
        c_end_min = c_start_min + int(candidate.duration_minutes)
        moved.append(
            {
                "team_id": c_team,
                "venue_id": c_venue,
                "day": c_day,
                "start": c_start_min,
                "end": c_end_min,
                "start_time": c_start_text,
            }
        )
        candidate_keys.append((c_team, c_venue, c_day, c_start_text))

    # Références appariées PAR INDEX à ``candidates`` (le validateur de schéma garantit la longueur
    # 0 ou N). ``ref_cases_by_team`` : l'ENSEMBLE des cases d'origine d'une équipe (elle peut être
    # déplacée PLUSIEURS fois dans le même lot), consommé par le miroir de BLOC ET le miroir plancher
    # qui raisonnent l'un et l'autre sur l'état FINAL complet — un « une case par équipe » leur
    # faisait perdre une case. ``reference_keys`` : les SlotKeys « avant » pour le DELTA de compromis.
    ref_cases_by_team: dict[str, set[tuple[str, int, str]]] = {}
    reference_keys: set[SlotKey] = set()
    for reference in input_data.references:
        r_team = str(reference.team_id)
        r_start_text = _format_time(_time_to_minutes(reference.start_time))
        r_case = (str(reference.venue_id), int(reference.day_of_week), r_start_text)
        ref_cases_by_team.setdefault(r_team, set()).add(r_case)
        key = _slot_key_of(reference)
        if key is not None:
            reference_keys.add(key)

    team_names = {str(t.get("id")): str(t.get("name") or t.get("id")) for t in data.get("teams", [])}
    coach_names = {str(c.get("id")): _coach_label(c) for c in data.get("coaches", [])}
    venue_names = {str(v.get("id")): str(v.get("name") or v.get("id")) for v in data.get("venues", [])}

    # Baseline: the current schedule. HARD locks stay pre-placed occupancy (as in
    # /generate); every non-HARD placement whose slot has a variable is FROZEN.
    # baseline_slots (for the naming layer) carries ALL current placements — a
    # candidate clashing with a locked session's coach must still be named.
    frozen_keys: set[SlotKey] = set()
    baseline_slots: list[dict[str, Any]] = []
    for tmpl in data.get("slotTemplates", []) or []:
        t_team = str(tmpl.get("teamId") or tmpl.get("team_id") or "")
        t_venue = str(tmpl.get("venueId") or tmpl.get("venue_id") or "")
        t_day = int(tmpl.get("dayOfWeek") or tmpl.get("day_of_week") or 0)
        t_start_min = _time_to_minutes(tmpl.get("startTime") or tmpl.get("start_time"))
        t_start_text = _format_time(t_start_min)
        t_duration = int(tmpl.get("durationMinutes") or tmpl.get("duration_minutes") or DEFAULT_SESSION_MINUTES)
        baseline_slots.append(
            {
                "team_id": t_team,
                "venue_id": t_venue,
                "day": t_day,
                "start": t_start_min,
                "end": t_start_min + t_duration,
                "start_time": t_start_text,
            }
        )
        lock_level = str(tmpl.get("lockLevel") or tmpl.get("lock_level") or "").upper()
        if lock_level != HARD_LOCK_LEVEL:
            baseline_key: SlotKey = (t_team, t_venue, t_day, t_start_text)
            if baseline_key in model.x:
                frozen_keys.add(baseline_key)

    metrics = {
        "solver_version": "cp-sat",
        "nb_variables": 0,
        "nb_constraints": 0,
        "wall_time_ms": 0,
        "constraint_version": contract_version,
    }

    # Chaque cible doit être un créneau réel actuellement libre : pas de variable = ce n'est pas un
    # créneau d'entraînement disponible, ou un verrou HARD l'occupe déjà. Le déplacement est alors
    # impossible — verdict NOMMÉ (sur le candidat fautif), sans solve. Un seul faux suffit à refuser.
    for candidate_key, m in zip(candidate_keys, moved, strict=True):
        if candidate_key not in model.x:
            return {
                "valid": False,
                "violations": [
                    {
                        "rule": "slot_unavailable",
                        "message": (
                            f"{venue_names.get(str(m['venue_id']), str(m['venue_id']))} à {m['start_time']} "
                            f"n'est pas un créneau libre pour {team_names.get(str(m['team_id']), str(m['team_id']))} "
                            f"(créneau inexistant ou déjà verrouillé)."
                        ),
                        "team_id": str(m["team_id"]),
                        "venue_id": str(m["venue_id"]),
                        "day_of_week": int(m["day"]),
                        "start_time": str(m["start_time"]),
                    }
                ],
                "compromises": [],
                "metrics": metrics,
            }

    # P2-51 (D11) — miroir déterministe du BLOC, dans les DEUX sens de `Σb == commonSessions` : un
    # déplacement qui RETIRE une équipe d'une séance de bloc jusque-là honorée (`shared_block_broken`)
    # OU qui FORME une séance commune de TROP (`shared_block_overformed`) est refusé, NOMMÉ. Le HARD
    # posé dans `_apply_hard` ne saurait pas l'attribuer (le solveur réinventerait la séance ailleurs,
    # ou l'INFEASIBLE de sur-formation tomberait sur `unknown_hard_conflict`). ⚠ N candidats jugés
    # ENSEMBLE : déplacer les N membres d'un bloc vers une MÊME case le laisse honoré. ⚠ Anti-enfermement
    # (P4-152) : on ne refuse QUE si le déplacement est la CAUSE (bloc honoré avant / pas déjà au-dessus).
    shared_block_violation = _shared_block_move_violation(
        data.get("sharedBlocks", []) or [],
        baseline_slots,
        moved,
        ref_cases_by_team,
        team_names,
        venue_names,
    )
    if shared_block_violation is not None:
        return {"valid": False, "violations": [shared_block_violation], "compromises": [], "metrics": metrics}

    # Lot PASSERELLES PR-2 — miroir MANDATORY : un déplacement qui fait chevaucher deux équipes
    # passerelées obligatoires est refusé, NOMMÉ (le HARD posé plus bas le rendrait INFEASIBLE mais
    # sans l'attribuer). Patron du miroir mutualisation ci-dessus.
    team_link_violation = _team_link_move_violation(
        data.get("teamLinks", []) or [],
        data.get("sharedBlocks", []) or [],
        baseline_slots,
        moved,
        team_names,
    )
    if team_link_violation is not None:
        return {"valid": False, "violations": [team_link_violation], "compromises": [], "metrics": metrics}

    # P2-55 (ENG-36) — miroir MANDATORY du TRAJET : un déplacement qui crée un enchaînement au
    # battement trop court pour le coach est refusé, NOMMÉ (motif `travel_time_infeasible`). Le HARD
    # posé dans `_apply_hard` rendrait bien le solve INFEASIBLE, mais `diagnose_candidate_conflicts`
    # ne saurait pas l'attribuer — sans ce miroir, le refus atterrirait sur `unknown_hard_conflict`.
    travel_violation = _travel_time_move_violation(
        data.get("venueTravelTimes", []) or [],
        resolve_implicit_rules(data.get("implicitRules")),
        baseline_slots,
        moved,
        data.get("coaches", []),
        team_coach_map,
        coach_names,
        venue_names,
    )
    if travel_violation is not None:
        return {"valid": False, "violations": [travel_violation], "compromises": [], "metrics": metrics}

    # P4-152 — miroir déterministe du PLANCHER de gymnase : un déplacement qui fait passer une
    # équipe sous « au moins N séances au gymnase V » est refusé, NOMMÉ (motif
    # `venue_minimum_infeasible`). Le HARD posé dans `_apply_hard` ne saurait pas l'attribuer (le
    # solveur tiendrait le plancher avec une séance fantôme ailleurs). ⚠ On ne refuse QUE si le
    # plancher était satisfait AVANT le déplacement : un planning déjà en infraction laisse le
    # gestionnaire continuer à bouger (jamais un blocage total).
    venue_minimum_violation = _venue_minimum_move_violation(
        parsed.get("venue_minimums", []),
        baseline_slots,
        moved,
        ref_cases_by_team,
        team_names,
        venue_names,
    )
    if venue_minimum_violation is not None:
        return {"valid": False, "violations": [venue_minimum_violation], "compromises": [], "metrics": metrics}

    assignments = _build_assignments(model, team_coach_map, frozen_keys)
    # Les N candidats sont epingles SEPAREMENT du gel de baseline (model.Add, pas
    # fixed=True) : neutraliser le gel libere le reste du planning MAIS garde les
    # candidats epingles — sans quoi le solveur mettrait tout a 0, verdict toujours
    # « valide » (falsification 2).
    for candidate_key in candidate_keys:
        cast(Any, model).Add(model.x[candidate_key] == 1)
    _apply_hard(model, assignments, data, parsed, team_coach_map, team_player_map)

    status, solver = _solve(model, timeout_seconds=input_data.solver_timeout_seconds, seed=input_data.solver_seed)
    valid = status in (cp_model.OPTIMAL, cp_model.FEASIBLE)

    metrics["nb_variables"] = model.NumVariables()
    metrics["nb_constraints"] = len(model.Proto().constraints)
    metrics["wall_time_ms"] = int(solver.wall_time * 1000)

    # ENG-51 — le solveur a épuisé son budget sans PROUVER la (in)faisabilité (UNKNOWN).
    # On ne peut RIEN conclure : ni nommer une règle cassée (le diagnostic exige un UNSAT
    # réel), ni sonder la baseline (ce serait un second UNKNOWN). Verdict NEUTRE
    # « indéterminé » : le déplacement n'est PAS appliqué, l'UI invite à réessayer. Aucun
    # mirror, aucune baseline sur ce chemin.
    if status == cp_model.UNKNOWN:
        return {"valid": False, "indeterminate": True, "violations": [], "compromises": [], "metrics": metrics}

    violations: list[dict[str, Any]] = []
    if not valid:
        # Chaque candidat est diagnostiqué contre la baseline gelée AUGMENTÉE des AUTRES candidats :
        # un conflit HARD entre deux déplacements du même geste (coach en double sur deux gymnases,
        # capacité…) est alors NOMMÉ, pas seulement candidat-contre-baseline.
        seen: set[tuple[str, str]] = set()
        # PARITÉ D'INTENSITÉ (A) : le diagnostic doit connaître le réglage des règles implicites —
        # coachRestDay PREFERRED n'est pas un interdit dur, il ne doit pas NOMMER coach_no_rest_day.
        diag_rules = resolve_implicit_rules(data.get("implicitRules"))
        for i, m in enumerate(moved):
            augmented = baseline_slots + [other for j, other in enumerate(moved) if j != i]
            for violation in diagnose_candidate_conflicts(
                candidate=m,
                baseline_slots=augmented,
                parsed=parsed,
                coaches=data.get("coaches", []),
                slot_capacities=model.slot_capacities,
                team_names=team_names,
                coach_names=coach_names,
                venue_names=venue_names,
                resolved_rules=diag_rules,
                shared_blocks=data.get("sharedBlocks", []) or [],
            ):
                dedupe_key = (str(violation.get("rule")), str(violation.get("message")))
                if dedupe_key not in seen:
                    seen.add(dedupe_key)
                    violations.append(violation)
        if not violations:
            # Infaisable, mais aucun mirror n'a su l'attribuer : distinguer une
            # baseline deja invalide (condition d'arret) d'un conflit HARD reel
            # mais non nomme — jamais un « non » nu.
            baseline_status = _baseline_solve_status(
                data,
                parsed,
                team_coach_map,
                team_player_map,
                frozen_keys,
                timeout_seconds=input_data.solver_timeout_seconds,
                seed=input_data.solver_seed,
            )
            # ENG-51 — la sonde de baseline a elle-même expiré (UNKNOWN) : impossible de
            # distinguer « baseline déjà invalide » d'un « conflit HARD réel ». Verdict
            # indéterminé plutôt que `baseline_infeasible` affirmé à tort.
            if baseline_status == cp_model.UNKNOWN:
                return {
                    "valid": False,
                    "indeterminate": True,
                    "violations": [],
                    "compromises": [],
                    "metrics": metrics,
                }
            if baseline_status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
                violations = [
                    {
                        "rule": "baseline_infeasible",
                        "message": (
                            "le planning courant est déjà infaisable pour le moteur : le verdict ne "
                            "peut rien conclure sur ce déplacement."
                        ),
                    }
                ]
            else:
                violations = [
                    {
                        "rule": "unknown_hard_conflict",
                        "message": "ce déplacement casse une règle du moteur qui n'a pas pu être nommée.",
                    }
                ]

    # P2-32 — SEULEMENT sur un candidat accepté : le DELTA de confort (compromis nommés). Le
    # chemin REFUS ci-dessus reste byte-identique (compromis vide). Deux solves entièrement
    # figés, sous le même budget court, en réutilisant les MÊMES builders que /generate.
    #
    # AU MIEUX : ces deux solves ajoutent JUSQU'À deux constructions de modèle + budgets par-dessus
    # le verdict — sur un club dense, ils dominent le coût total et peuvent dépasser le délai
    # transport côté backend. Or le verdict, lui, est DÉJÀ tranché : un candidat accepté ne doit
    # jamais mourir de son habillage explicatif. Si le calcul échoue (budget épuisé → aucune
    # solution à lire, ou toute autre panne du solveur), on répond quand même le verdict, avec des
    # compromis vides. La FORME de la réponse ne change pas (contrat inchangé) — seul le contenu.
    log_teams = ",".join(str(m["team_id"]) for m in moved)
    compromises: list[dict[str, Any]] = []
    elapsed = time.monotonic() - started
    if valid and elapsed > COMPROMISE_ELAPSED_BUDGET_SECONDS:
        logger.warning(
            "verdict took %.1fs for club=%s teams=%s; skipping compromises to answer in time",
            elapsed,
            input_data.club_id,
            log_teams,
        )
    elif valid:
        try:
            compromises = _compromises_for(
                data,
                parsed,
                team_coach_map,
                team_player_map,
                frozen_keys,
                set(candidate_keys),
                reference_keys,
                {"teams": team_names, "coaches": coach_names, "venues": venue_names},
                timeout_seconds=input_data.solver_timeout_seconds,
                seed=input_data.solver_seed,
            )
        except Exception:
            logger.warning(
                "compromise computation failed for club=%s teams=%s; returning the verdict without compromises",
                input_data.club_id,
                log_teams,
                exc_info=True,
            )
            compromises = []

    logger.info(
        "validate club=%s teams=%s -> %s valid=%s violations=%d compromises=%d",
        input_data.club_id,
        log_teams,
        solver.status_name(status),
        valid,
        len(violations),
        len(compromises),
    )

    return {"valid": valid, "violations": violations, "compromises": compromises, "metrics": metrics}
