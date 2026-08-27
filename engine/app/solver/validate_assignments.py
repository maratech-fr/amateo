from __future__ import annotations

import logging
import time
from typing import Any, cast

from ortools.sat.python import cp_model

from app.schemas.validate_input_schema import CandidateAssignmentSchema, ValidateAssignmentsInputSchema
from app.solver.compromise import CompromiseTermInfo, compute_compromises
from app.solver.constraints import (
    MANDATORY,
    HardConstraintStats,
    ParsedConstraints,
    ResolvedImplicitRules,
    add_level_1_hard_constraints,
    add_time_window_constraints,
    add_travel_time_penalty,
    build_travel_matrix,
    diagnose_candidate_conflicts,
    parse_v2_constraints,
    resolve_implicit_rules,
    team_share_declared_pairs,
)
from app.solver.constraints.travel import TravelPlacement, iter_travel_pairs_from_placements
from app.solver.model import (
    DEFAULT_SESSION_MINUTES,
    HARD_LOCK_LEVEL,
    ScheduleCpModel,
    SlotKey,
    _format_time,
    _time_to_minutes,
    build_model,
)
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


def _shared_training_move_violation(
    shared_trainings: list[dict[str, Any]],
    baseline_slots: list[dict[str, Any]],
    candidate: dict[str, Any],
    team_names: dict[str, str],
) -> dict[str, Any] | None:
    """P2-27 — refus NOMMÉ quand le candidat sort une équipe d'une case commune.

    Le verdict du solveur ne peut PAS produire ce refus tout seul : la baseline retire la
    source mais le modèle laisse la variable de l'ancienne case LIBRE ; sans plafond de séances,
    le solveur remet l'équipe sur son ancienne case pour tenir ``== K`` et conclut « oui » à
    tort. On juge donc l'ÉTAT CONCRET proposé (baseline sans la source + candidat), de façon
    déterministe : c'est le miroir de la contrainte ``add_shared_training_constraints``.

    On n'évalue QUE les groupes dont l'équipe DÉPLACÉE est membre (déplacer une équipe hors
    d'un groupe ne doit jamais être refusé au prétexte qu'un AUTRE groupe serait déjà rompu —
    ex. déclaration ajoutée après génération). Le résultat != K pour un tel groupe → refus.

    Message français nommant les équipes (jamais d'identifiant interne). ``None`` si rien à dire.
    """
    if not shared_trainings:
        return None

    c_team = str(candidate["team_id"])
    # équipe -> ensemble des cases (gymnase, jour, heure) occupées dans l'état proposé
    occupancy: dict[str, set[tuple[str, int, str]]] = {}
    for slot in baseline_slots:
        occupancy.setdefault(str(slot["team_id"]), set()).add(
            (str(slot["venue_id"]), int(slot["day"]), str(slot["start_time"]))
        )
    occupancy.setdefault(c_team, set()).add(
        (str(candidate["venue_id"]), int(candidate["day"]), str(candidate["start_time"]))
    )

    def _team_name(team_id: str) -> str:
        return team_names.get(team_id) or team_id

    for group in shared_trainings:
        members = [str(t) for t in (group.get("teamIds") or group.get("team_ids") or [])]
        if len(members) < 2 or c_team not in members:
            continue
        common_sessions = int(group.get("commonSessions") or group.get("common_sessions") or 0)
        member_sets = [occupancy.get(member, set()) for member in members]
        common = set.intersection(*member_sets) if member_sets else set()
        if len(common) != common_sessions:
            named = ", ".join(_team_name(member) for member in members)
            return {
                "rule": "shared_training_broken",
                "message": (
                    f"Ce déplacement rompt la mutualisation déclarée : les équipes {named} doivent "
                    f"partager exactement {common_sessions} séance(s) commune(s), or ce placement en "
                    f"laisse {len(common)}."
                ),
                "team_id": c_team,
                "venue_id": str(candidate["venue_id"]),
                "day_of_week": int(candidate["day"]),
                "start_time": str(candidate["start_time"]),
            }
    return None


def _team_link_move_violation(
    team_links: list[dict[str, Any]],
    shared_trainings: list[dict[str, Any]],
    baseline_slots: list[dict[str, Any]],
    candidate: dict[str, Any],
    team_names: dict[str, str],
) -> dict[str, Any] | None:
    """Lot PASSERELLES PR-2 — refus NOMMÉ (MIROIR MANDATORY) quand un déplacement CRÉE un
    chevauchement sur une passerelle ``MANDATORY`` (patron ``_shared_training_move_violation``).

    On juge l'ÉTAT CONCRET proposé (baseline gelée + candidat) de façon déterministe : la séance
    candidate de ``c_team`` chevauche-t-elle une séance de l'équipe LIÉE, même jour, intervalles
    intersectés (cross-gymnase compris, doctrine n°2) ? EXEMPTION : même case (gymnase, jour,
    heure) ET les deux équipes partagent un groupe ``sharedTrainings`` déclaré. Le HARD posé dans
    ``_apply_hard`` rendrait bien le solve INFEASIBLE, mais ``diagnose_candidate_conflicts`` ne
    saurait pas l'attribuer — ce miroir le NOMME avant le solve.

    Message français nommant les deux équipes ; aucun identifiant interne. ``None`` si rien à dire.
    """
    mandatory = [link for link in team_links if str(link.get("intensity") or "PREFERRED") == MANDATORY]
    if not mandatory:
        return None

    c_team = str(candidate["team_id"])
    c_start = int(candidate["start"])
    c_end = int(candidate["end"])
    c_day = int(candidate["day"])
    c_venue = str(candidate["venue_id"])
    share_pairs = team_share_declared_pairs(shared_trainings)

    by_team: dict[str, list[tuple[int, int, int, str]]] = {}
    for slot in baseline_slots:
        by_team.setdefault(str(slot["team_id"]), []).append(
            (int(slot["start"]), int(slot["end"]), int(slot["day"]), str(slot["venue_id"]))
        )

    def _team_name(team_id: str) -> str:
        return team_names.get(team_id) or team_id

    for link in mandatory:
        team_a = str(link.get("teamAId") or link.get("team_a_id") or "")
        team_b = str(link.get("teamBId") or link.get("team_b_id") or "")
        if c_team not in (team_a, team_b) or team_a == team_b or not team_a or not team_b:
            continue
        other = team_b if c_team == team_a else team_a
        share_declared = frozenset({team_a, team_b}) in share_pairs
        for o_start, o_end, o_day, o_venue in by_team.get(other, []):
            if o_day != c_day or not (c_start < o_end and o_start < c_end):
                continue
            if o_venue == c_venue and o_start == c_start and share_declared:
                continue  # séance mutualisée déclarée : chevauchement volontaire, autorisé.
            return {
                "rule": "team_link_broken",
                "message": (
                    f"Ce déplacement fait chevaucher {_team_name(c_team)} et {_team_name(other)}, "
                    "déclarées en passerelle obligatoire : elles partagent des joueurs et ne peuvent "
                    "pas s'entraîner en même temps."
                ),
                "team_id": c_team,
                "venue_id": c_venue,
                "day_of_week": c_day,
                "start_time": str(candidate["start_time"]),
            }
    return None


def _travel_time_move_violation(
    venue_travel_times: list[dict[str, Any]],
    resolved_rules: ResolvedImplicitRules,
    baseline_slots: list[dict[str, Any]],
    candidate: dict[str, Any],
    coaches: list[dict[str, Any]],
    team_coach_map: dict[str, list[str]],
    coach_names: dict[str, str],
    venue_names: dict[str, str],
) -> dict[str, Any] | None:
    """P2-55 (ENG-36) — refus NOMMÉ (MIROIR MANDATORY) quand un déplacement crée un enchaînement au
    battement trop court pour le coach (patron ``_team_link_move_violation``).

    Ne s'arme que sous ``travelTime`` MANDATORY, matrice présente. On juge l'ÉTAT CONCRET (baseline
    gelée + candidat) de façon déterministe, en RÉUTILISANT le prédicat géométrique de ``travel.py``
    (``iter_travel_pairs_from_placements`` — gap/barème JAMAIS recalculés ici, résorbe ENG-37 côté
    verdict) : un enchaînement cross-gymnase du MÊME coach dont l'écart est plus court que le barème
    (voiture/à pied selon ``isVehicled``) et qui IMPLIQUE le candidat → refus. Le HARD posé dans
    ``_apply_hard`` rendrait bien le solve INFEASIBLE, mais ``diagnose_candidate_conflicts`` ne
    saurait pas l'attribuer — ce miroir le NOMME (motif ``travel_time_infeasible``, aligné sur le
    diagnostic du rail ``/generate``).

    Message français nommant le coach + les deux gymnases/heures ; aucun identifiant interne dans le
    texte. ``None`` si rien à dire."""
    if not (resolved_rules.travel_time_active and resolved_rules.travel_time_intensity == MANDATORY):
        return None
    matrix = build_travel_matrix(venue_travel_times)
    if not matrix:
        return None

    placements_by_team: dict[str, list[TravelPlacement]] = {}
    for slot in baseline_slots:
        placements_by_team.setdefault(str(slot["team_id"]), []).append(
            (int(slot["start"]), int(slot["end"]), int(slot["day"]), str(slot["venue_id"]), None)
        )
    candidate_placement: TravelPlacement = (
        int(candidate["start"]),
        int(candidate["end"]),
        int(candidate["day"]),
        str(candidate["venue_id"]),
        None,
    )
    placements_by_team.setdefault(str(candidate["team_id"]), []).append(candidate_placement)

    for traveler_key, gap, barometer, pa, pb in iter_travel_pairs_from_placements(
        placements_by_team,
        coaches=coaches,
        team_links=(),  # miroir cadré au voyageur COACH (arbitrage P2-55) : passerelle hors champ.
        team_coach_map=team_coach_map,
        matrix=matrix,
        default_minutes=resolved_rules.travel_time_default_minutes,
    ):
        if gap >= barometer:
            continue  # battement suffisant : la pose ne poserait rien ici non plus.
        if pa is not candidate_placement and pb is not candidate_placement:
            continue  # enchaînement PRÉEXISTANT (baseline seule) : jamais imputé au déplacement.
        coach_id = traveler_key.split(":", 1)[1]
        first, second = (pa, pb) if pa[0] <= pb[0] else (pb, pa)
        coach_label = coach_names.get(coach_id) or "Le coach"
        first_venue = venue_names.get(first[3]) or first[3]
        second_venue = venue_names.get(second[3]) or second[3]
        return {
            "rule": "travel_time_infeasible",
            "message": (
                f"{coach_label} enchaînerait {first_venue} à {_format_time(first[0])} puis "
                f"{second_venue} à {_format_time(second[0])} : le battement est trop court pour "
                "rejoindre le gymnase suivant."
            ),
            "coach_id": coach_id,
            "team_id": str(candidate["team_id"]),
            "venue_id": str(candidate["venue_id"]),
            "day_of_week": int(candidate["day"]),
            "start_time": str(candidate["start_time"]),
        }
    return None


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
        shared_trainings=data.get("sharedTrainings", []),
        team_links=data.get("teamLinks", []),
        venue_travel_times=data.get("venueTravelTimes", []),
    )
    add_time_window_constraints(model, model.x, parsed["time_windows"])
    return stats


def _solve(model: ScheduleCpModel, *, timeout_seconds: int, seed: int) -> tuple[int, cp_model.CpSolver]:
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = float(timeout_seconds)
    # Mono-candidat, baseline entierement figee : pas de portefeuille, 1 worker
    # rend le verdict reproductible d'un appel a l'autre sur la meme entree.
    solver.parameters.num_search_workers = 1
    solver.parameters.random_seed = seed
    return solver.Solve(model), solver


def _baseline_is_feasible(
    data: dict[str, Any],
    parsed: ParsedConstraints,
    team_coach_map: dict[str, list[str]],
    team_player_map: dict[str, list[str]],
    frozen_keys: set[SlotKey],
    *,
    timeout_seconds: int,
    seed: int,
) -> bool:
    """Le planning courant, FIGE mais SANS le candidat, est-il faisable pour le
    moteur ? Utilise seulement sur le chemin rare « infaisable + rien de nomme »
    pour distinguer un candidat fautif d'une baseline deja invalide (condition
    d'arret fondateur : figer un planning pourtant valide ne doit pas conclure
    « non » a tout)."""
    model = build_model(data)
    model.team_coach_map = team_coach_map
    assignments = _build_assignments(model, team_coach_map, frozen_keys)
    _apply_hard(model, assignments, data, parsed, team_coach_map, team_player_map)
    status, _ = _solve(model, timeout_seconds=timeout_seconds, seed=seed)
    return status in (cp_model.OPTIMAL, cp_model.FEASIBLE)


def _slot_key_of(assignment: CandidateAssignmentSchema | None) -> SlotKey | None:
    """La SlotKey d'un candidat/référence, dans le format de ``model.x`` (heure normalisée)."""
    if assignment is None:
        return None
    start_text = _format_time(_time_to_minutes(assignment.start_time))
    return (str(assignment.team_id), str(assignment.venue_id), int(assignment.day_of_week), start_text)


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
        shared_trainings=data.get("sharedTrainings", []),
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
    candidate_key: SlotKey,
    reference: CandidateAssignmentSchema | None,
    names: dict[str, dict[str, str]],
    *,
    timeout_seconds: int,
    seed: int,
) -> list[dict[str, Any]]:
    """Le DELTA de confort entre « avant » (baseline + référence, ou baseline nue) et « après »
    (baseline + candidat) — appelé UNIQUEMENT sur un candidat accepté."""
    reference_key = _slot_key_of(reference)
    before_pins: set[SlotKey] = {reference_key} if reference_key is not None else set()
    after = _evaluate_state(
        data,
        parsed,
        team_coach_map,
        team_player_map,
        frozen_keys,
        {candidate_key},
        timeout_seconds=timeout_seconds,
        seed=seed,
    )
    before = _evaluate_state(
        data,
        parsed,
        team_coach_map,
        team_player_map,
        frozen_keys,
        before_pins,
        timeout_seconds=timeout_seconds,
        seed=seed,
    )
    return compute_compromises(before, after, names)


def validate_assignment(
    input_data: ValidateAssignmentsInputSchema,
    *,
    contract_version: str | None = None,
) -> dict[str, Any]:
    """Verdict moteur sur UN candidat de deplacement (P2-2 F2a).

    Le reste du planning est FIGE via ``add_fixed_slots`` ; on epingle le candidat
    et on demande au moteur si le modele HARD reste faisable. La reponse booleenne
    vient donc du SOLVEUR (« ce que le solveur applique vraiment ») ; les regles
    cassees sont ensuite NOMMEES pour l'UI. Sans le gel de baseline, le solveur
    pourrait tout redeplacer et le verdict ne voudrait plus rien dire.
    """
    started = time.monotonic()
    data: dict[str, Any] = input_data.model_dump(by_alias=True)
    parsed = parse_v2_constraints(data.get("constraints", []))
    team_coach_map: dict[str, list[str]] = parsed.get("team_coach_map", {})
    team_player_map: dict[str, list[str]] = parsed.get("team_player_map", {})

    model = build_model(data)
    model.team_coach_map = team_coach_map

    candidate = input_data.candidate
    c_team = str(candidate.team_id)
    c_venue = str(candidate.venue_id)
    c_day = int(candidate.day_of_week)
    c_start_min = _time_to_minutes(candidate.start_time)
    c_start_text = _format_time(c_start_min)
    c_end_min = c_start_min + int(candidate.duration_minutes)
    candidate_key: SlotKey = (c_team, c_venue, c_day, c_start_text)

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
            key: SlotKey = (t_team, t_venue, t_day, t_start_text)
            if key in model.x:
                frozen_keys.add(key)

    metrics = {
        "solver_version": "cp-sat",
        "nb_variables": 0,
        "nb_constraints": 0,
        "wall_time_ms": 0,
        "constraint_version": contract_version,
    }

    # The target must be a real, currently-empty slot: no variable = it is not an
    # available training slot, or a HARD lock already holds it. Either way the move
    # is impossible — a named verdict, no solve needed.
    if candidate_key not in model.x:
        return {
            "valid": False,
            "violations": [
                {
                    "rule": "slot_unavailable",
                    "message": (
                        f"{venue_names.get(c_venue, c_venue)} à {c_start_text} n'est pas un créneau "
                        f"libre pour {team_names.get(c_team, c_team)} (créneau inexistant ou déjà verrouillé)."
                    ),
                    "team_id": c_team,
                    "venue_id": c_venue,
                    "day_of_week": c_day,
                    "start_time": c_start_text,
                }
            ],
            "compromises": [],
            "metrics": metrics,
        }

    # P2-27 — miroir déterministe de la mutualisation : le solveur ne peut pas refuser à lui
    # seul un déplacement qui SORT une équipe d'une case commune (il remettrait l'équipe sur son
    # ancienne case, faute de plafond de séances). On juge l'état concret proposé.
    shared_violation = _shared_training_move_violation(
        data.get("sharedTrainings", []) or [],
        baseline_slots,
        {"team_id": c_team, "venue_id": c_venue, "day": c_day, "start_time": c_start_text},
        team_names,
    )
    if shared_violation is not None:
        return {"valid": False, "violations": [shared_violation], "compromises": [], "metrics": metrics}

    # Lot PASSERELLES PR-2 — miroir MANDATORY : un déplacement qui fait chevaucher deux équipes
    # passerelées obligatoires est refusé, NOMMÉ (le HARD posé plus bas le rendrait INFEASIBLE mais
    # sans l'attribuer). Patron du miroir mutualisation ci-dessus.
    team_link_violation = _team_link_move_violation(
        data.get("teamLinks", []) or [],
        data.get("sharedTrainings", []) or [],
        baseline_slots,
        {
            "team_id": c_team,
            "venue_id": c_venue,
            "day": c_day,
            "start": c_start_min,
            "end": c_end_min,
            "start_time": c_start_text,
        },
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
        {
            "team_id": c_team,
            "venue_id": c_venue,
            "day": c_day,
            "start": c_start_min,
            "end": c_end_min,
            "start_time": c_start_text,
        },
        data.get("coaches", []),
        team_coach_map,
        coach_names,
        venue_names,
    )
    if travel_violation is not None:
        return {"valid": False, "violations": [travel_violation], "compromises": [], "metrics": metrics}

    assignments = _build_assignments(model, team_coach_map, frozen_keys)
    # Le candidat est epingle SEPAREMENT du gel de baseline (model.Add, pas
    # fixed=True) : neutraliser le gel libere le reste du planning MAIS garde le
    # candidat epingle — sans quoi le solveur mettrait tout a 0, verdict toujours
    # « valide » (falsification 2).
    cast(Any, model).Add(model.x[candidate_key] == 1)
    _apply_hard(model, assignments, data, parsed, team_coach_map, team_player_map)

    status, solver = _solve(model, timeout_seconds=input_data.solver_timeout_seconds, seed=input_data.solver_seed)
    valid = status in (cp_model.OPTIMAL, cp_model.FEASIBLE)

    metrics["nb_variables"] = model.NumVariables()
    metrics["nb_constraints"] = len(model.Proto().constraints)
    metrics["wall_time_ms"] = int(solver.wall_time * 1000)

    violations: list[dict[str, Any]] = []
    if not valid:
        violations = diagnose_candidate_conflicts(
            candidate={
                "team_id": c_team,
                "venue_id": c_venue,
                "day": c_day,
                "start": c_start_min,
                "end": c_end_min,
                "start_time": c_start_text,
            },
            baseline_slots=baseline_slots,
            parsed=parsed,
            coaches=data.get("coaches", []),
            slot_capacities=model.slot_capacities,
            team_names=team_names,
            coach_names=coach_names,
            venue_names=venue_names,
        )
        if not violations:
            # Infaisable, mais aucun mirror n'a su l'attribuer : distinguer une
            # baseline deja invalide (condition d'arret) d'un conflit HARD reel
            # mais non nomme — jamais un « non » nu.
            baseline_ok = _baseline_is_feasible(
                data,
                parsed,
                team_coach_map,
                team_player_map,
                frozen_keys,
                timeout_seconds=input_data.solver_timeout_seconds,
                seed=input_data.solver_seed,
            )
            if not baseline_ok:
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
    compromises: list[dict[str, Any]] = []
    elapsed = time.monotonic() - started
    if valid and elapsed > COMPROMISE_ELAPSED_BUDGET_SECONDS:
        logger.warning(
            "verdict took %.1fs for club=%s team=%s; skipping compromises to answer in time",
            elapsed,
            input_data.club_id,
            c_team,
        )
    elif valid:
        try:
            compromises = _compromises_for(
                data,
                parsed,
                team_coach_map,
                team_player_map,
                frozen_keys,
                candidate_key,
                input_data.reference,
                {"teams": team_names, "coaches": coach_names, "venues": venue_names},
                timeout_seconds=input_data.solver_timeout_seconds,
                seed=input_data.solver_seed,
            )
        except Exception:
            logger.warning(
                "compromise computation failed for club=%s team=%s; returning the verdict without compromises",
                input_data.club_id,
                c_team,
                exc_info=True,
            )
            compromises = []

    logger.info(
        "validate club=%s team=%s -> %s valid=%s violations=%d compromises=%d",
        input_data.club_id,
        c_team,
        solver.status_name(status),
        valid,
        len(violations),
        len(compromises),
    )

    return {"valid": valid, "violations": violations, "compromises": compromises, "metrics": metrics}
