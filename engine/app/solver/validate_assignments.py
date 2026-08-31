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
    add_venue_minimum_constraints,
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

# Libellés FR des jours (1 = lundi … 7 = dimanche), pour NOMMER la case rompue d'un bloc dans le
# message du verdict sans dépendre du paquet ``result_builder`` (qui a son propre ``_day_label``).
_DAY_LABELS_FR = {1: "lundi", 2: "mardi", 3: "mercredi", 4: "jeudi", 5: "vendredi", 6: "samedi", 7: "dimanche"}


def _coach_label(coach: dict[str, Any]) -> str:
    first = str(coach.get("first_name") or coach.get("firstName") or "").strip()
    last = str(coach.get("last_name") or coach.get("lastName") or "").strip()
    full = f"{first} {last}".strip()
    return full or str(coach.get("id"))


def _shared_block_move_violation(
    shared_blocks: list[dict[str, Any]],
    baseline_slots: list[dict[str, Any]],
    moved: list[dict[str, Any]],
    ref_case_by_team: dict[str, tuple[str, int, str]],
    team_names: dict[str, str],
    venue_names: dict[str, str],
) -> dict[str, Any] | None:
    """P2-51 (D11) — refus NOMMÉ ``shared_block_broken`` quand un déplacement RETIRE une équipe
    d'une séance de BLOC jusque-là honorée (miroir déterministe + anti-enfermement, patron
    ``_venue_minimum_move_violation``).

    Le HARD posé dans ``_apply_hard`` (``add_shared_block_constraints``) NE SUFFIT PAS : les
    variables de l'ancienne case restent LIBRES → le solveur réinvente la séance de bloc ailleurs
    pour tenir ``Σb == commonSessions`` et conclut « valide » à tort (même faille qu'ENG-36 / la
    mutualisation / le plancher de gymnase). On juge donc l'ÉTAT FINAL proposé, de façon
    déterministe : c'est le miroir de la contrainte.

    ⚠ N déplacements jugés ENSEMBLE, sur l'état FINAL (P2-51 PR-5b) — c'est le CŒUR du rail
    « déplacer le bloc ». « avant » = baseline gelée (elle EXCLUT déjà les N sources) + chaque
    source ré-ajoutée à SA case d'origine (``ref_case_by_team``) ; « après » = baseline + les N
    candidats à leurs cases cibles. Déplacer les 2 membres d'un bloc vers la MÊME case le laisse
    HONORÉ (le bloc s'y reconstitue) — le refus séquentiel (juger t1 seul verrait le bloc rompu)
    est précisément ce qu'il faut ÉVITER. En retirer UN SEUL le casse → refus.

    ⚠ GARDE ANTI-ENFERMEMENT (leçon P4-152) : un bloc DÉJÀ cassé dans la baseline ne bloque pas
    les déplacements. On ne refuse QUE si le bloc était HONORÉ avant (≥ commonSessions cases
    communes) et CESSE de l'être après. On n'évalue QUE les blocs dont AU MOINS une équipe
    DÉPLACÉE est membre.

    Message français nommant les équipes du bloc, le nombre de séances communes exigé, la case
    rompue ; aucun identifiant interne. ``None`` si rien à dire."""
    if not shared_blocks:
        return None

    moved_teams = {str(m["team_id"]) for m in moved}
    cand_case_by_team = {str(m["team_id"]): (str(m["venue_id"]), int(m["day"]), str(m["start_time"])) for m in moved}

    # équipe -> cases (gymnase, jour, heure) occupées dans la baseline GELÉE (sources exclues).
    base_occupancy: dict[str, set[tuple[str, int, str]]] = {}
    for slot in baseline_slots:
        base_occupancy.setdefault(str(slot["team_id"]), set()).add(
            (str(slot["venue_id"]), int(slot["day"]), str(slot["start_time"]))
        )

    def _team_name(team_id: str) -> str:
        return team_names.get(team_id) or team_id

    def _venue_name(venue_id: str) -> str:
        return venue_names.get(venue_id) or venue_id

    def _common(members: list[str], *, use_reference: bool) -> set[tuple[str, int, str]]:
        # Cases où TOUS les membres sont ensemble ; pour CHAQUE équipe déplacée, sa case (référence
        # ré-ajoutée pour « avant », candidat pour « après ») est AJOUTÉE à sa baseline gelée.
        sets: list[set[tuple[str, int, str]]] = []
        for member in members:
            occ = set(base_occupancy.get(member, set()))
            if member in moved_teams:
                case = ref_case_by_team.get(member) if use_reference else cand_case_by_team.get(member)
                if case is not None:
                    occ.add(case)
            sets.append(occ)
        return set.intersection(*sets) if sets else set()

    for block in shared_blocks:
        members = [str(t) for t in (block.get("teamIds") or block.get("team_ids") or [])]
        if len(members) < 2 or not (moved_teams & set(members)):
            continue
        common_sessions = int(block.get("commonSessions") or block.get("common_sessions") or 0)
        before = _common(members, use_reference=True)
        after = _common(members, use_reference=False)
        if len(before) >= common_sessions and len(after) < common_sessions:
            offender = next(m for m in moved if str(m["team_id"]) in members)
            named = ", ".join(_team_name(member) for member in members)
            broken = sorted(before - after)
            where = ""
            if broken:
                b_venue, b_day, b_start = broken[0]
                where = f" (séance commune du {_DAY_LABELS_FR[b_day] if 1 <= b_day <= 7 else f'jour {b_day}'} à {str(b_start)[:5]} au gymnase {_venue_name(b_venue)})"
            return {
                "rule": "shared_block_broken",
                "message": (
                    f"Ce déplacement casse le bloc de mutualisation : les équipes {named} doivent "
                    f"partager {common_sessions} séance(s) commune(s) en bloc{where}, or retirer "
                    f"{_team_name(str(offender['team_id']))} de sa séance n'en laisserait plus que {len(after)}."
                ),
                "team_id": str(offender["team_id"]),
                "venue_id": str(offender["venue_id"]),
                "day_of_week": int(offender["day"]),
                "start_time": str(offender["start_time"]),
            }
    return None


def _team_link_move_violation(
    team_links: list[dict[str, Any]],
    shared_blocks: list[dict[str, Any]],
    baseline_slots: list[dict[str, Any]],
    moved: list[dict[str, Any]],
    team_names: dict[str, str],
) -> dict[str, Any] | None:
    """Lot PASSERELLES PR-2 — refus NOMMÉ (MIROIR MANDATORY) quand un déplacement CRÉE un
    chevauchement sur une passerelle ``MANDATORY`` (patron ``_shared_block_move_violation``).

    On juge l'ÉTAT FINAL proposé (baseline gelée + les N candidats) de façon déterministe : deux
    séances de deux équipes passerelées MANDATORY se chevauchent-elles (même jour, intervalles
    intersectés, cross-gymnase compris — doctrine n°2) ? On ne refuse QUE si le chevauchement
    IMPLIQUE au moins un candidat (créé/aggravé par le déplacement) — deux séances déjà présentes
    dans la baseline ne sont jamais imputées au geste (anti-enfermement). ⚠ N déplacements jugés
    ENSEMBLE (P2-51 PR-5b) : un chevauchement créé entre les séances de DEUX équipes déplacées est
    vu, pas seulement candidat-contre-baseline. EXEMPTION : même case (gymnase, jour, heure) ET les
    deux équipes partagent un groupe/bloc déclaré. Le HARD posé dans ``_apply_hard`` rendrait bien
    le solve INFEASIBLE, mais ``diagnose_candidate_conflicts`` ne saurait pas l'attribuer — ce
    miroir le NOMME avant le solve.

    Message français nommant les deux équipes ; aucun identifiant interne. ``None`` si rien à dire.
    """
    mandatory = [link for link in team_links if str(link.get("intensity") or "PREFERRED") == MANDATORY]
    if not mandatory:
        return None

    moved_teams = {str(m["team_id"]) for m in moved}
    share_pairs = team_share_declared_pairs(shared_blocks)

    # état FINAL par équipe : baseline gelée + candidats. Le 5e champ marque une séance CANDIDATE
    # (créée par le déplacement) — un chevauchement n'est imputé au geste que s'il en implique une.
    by_team: dict[str, list[tuple[int, int, int, str, bool]]] = {}
    for slot in baseline_slots:
        by_team.setdefault(str(slot["team_id"]), []).append(
            (int(slot["start"]), int(slot["end"]), int(slot["day"]), str(slot["venue_id"]), False)
        )
    for m in moved:
        by_team.setdefault(str(m["team_id"]), []).append(
            (int(m["start"]), int(m["end"]), int(m["day"]), str(m["venue_id"]), True)
        )

    def _team_name(team_id: str) -> str:
        return team_names.get(team_id) or team_id

    for link in mandatory:
        team_a = str(link.get("teamAId") or link.get("team_a_id") or "")
        team_b = str(link.get("teamBId") or link.get("team_b_id") or "")
        if team_a == team_b or not team_a or not team_b or not (moved_teams & {team_a, team_b}):
            continue
        share_declared = frozenset({team_a, team_b}) in share_pairs
        for a_start, a_end, a_day, a_venue, a_cand in by_team.get(team_a, []):
            for b_start, b_end, b_day, b_venue, b_cand in by_team.get(team_b, []):
                if a_day != b_day or not (a_start < b_end and b_start < a_end):
                    continue
                if a_venue == b_venue and a_start == b_start and share_declared:
                    continue  # séance mutualisée déclarée : chevauchement volontaire, autorisé.
                if not (a_cand or b_cand):
                    continue  # chevauchement PRÉEXISTANT (baseline seule) : jamais imputé au geste.
                # Nommer le côté DÉPLACÉ (celui dont le candidat crée le conflit) en premier.
                culprit = team_a if a_cand else team_b
                other = team_b if a_cand else team_a
                offender = next(m for m in moved if str(m["team_id"]) == culprit)
                return {
                    "rule": "team_link_broken",
                    "message": (
                        f"Ce déplacement fait chevaucher {_team_name(culprit)} et {_team_name(other)}, "
                        "déclarées en passerelle obligatoire : elles partagent des joueurs et ne peuvent "
                        "pas s'entraîner en même temps."
                    ),
                    "team_id": culprit,
                    "venue_id": str(offender["venue_id"]),
                    "day_of_week": int(offender["day"]),
                    "start_time": str(offender["start_time"]),
                }
    return None


def _travel_time_move_violation(
    venue_travel_times: list[dict[str, Any]],
    resolved_rules: ResolvedImplicitRules,
    baseline_slots: list[dict[str, Any]],
    moved: list[dict[str, Any]],
    coaches: list[dict[str, Any]],
    team_coach_map: dict[str, list[str]],
    coach_names: dict[str, str],
    venue_names: dict[str, str],
) -> dict[str, Any] | None:
    """P2-55 (ENG-36) — refus NOMMÉ (MIROIR MANDATORY) quand un déplacement crée un enchaînement au
    battement trop court pour le coach (patron ``_team_link_move_violation``).

    Ne s'arme que sous ``travelTime`` MANDATORY, matrice présente. On juge l'ÉTAT FINAL (baseline
    gelée + les N candidats) de façon déterministe, en RÉUTILISANT le prédicat géométrique de
    ``travel.py`` (``iter_travel_pairs_from_placements`` — gap/barème JAMAIS recalculés ici, résorbe
    ENG-37 côté verdict) : un enchaînement cross-gymnase du MÊME coach dont l'écart est plus court
    que le barème (voiture/à pied selon ``isVehicled``) et qui IMPLIQUE au moins un candidat → refus.
    ⚠ N déplacements jugés ENSEMBLE (P2-51 PR-5b) : un enchaînement trop serré créé par le
    déplacement de DEUX équipes DIFFÉRENTES d'un même coach est vu (les deux séances sont
    candidates). Le HARD posé dans ``_apply_hard`` rendrait bien le solve INFEASIBLE, mais
    ``diagnose_candidate_conflicts`` ne saurait pas l'attribuer — ce miroir le NOMME (motif
    ``travel_time_infeasible``, aligné sur le diagnostic du rail ``/generate``).

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
    # Chaque candidat pose SA séance ; on garde l'identité (``id``) pour distinguer, dans les paires
    # énumérées, celles qui IMPLIQUENT un déplacement de celles déjà présentes dans la baseline.
    placement_by_moved_index: list[TravelPlacement] = []
    for m in moved:
        placement: TravelPlacement = (
            int(m["start"]),
            int(m["end"]),
            int(m["day"]),
            str(m["venue_id"]),
            None,
        )
        placements_by_team.setdefault(str(m["team_id"]), []).append(placement)
        placement_by_moved_index.append(placement)
    candidate_ids = {id(p) for p in placement_by_moved_index}
    moved_by_placement_id = {id(p): moved[i] for i, p in enumerate(placement_by_moved_index)}

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
        if id(pa) not in candidate_ids and id(pb) not in candidate_ids:
            continue  # enchaînement PRÉEXISTANT (baseline seule) : jamais imputé au déplacement.
        coach_id = traveler_key.split(":", 1)[1]
        first, second = (pa, pb) if pa[0] <= pb[0] else (pb, pa)
        # Attribuer à un candidat impliqué (le déplacé) : c'est lui que l'UI surligne.
        culprit = pa if id(pa) in candidate_ids else pb
        offender = moved_by_placement_id[id(culprit)]
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
            "team_id": str(offender["team_id"]),
            "venue_id": str(offender["venue_id"]),
            "day_of_week": int(offender["day"]),
            "start_time": str(offender["start_time"]),
        }
    return None


def _venue_minimum_move_violation(
    venue_minimums: list[dict[str, Any]],
    baseline_slots: list[dict[str, Any]],
    moved: list[dict[str, Any]],
    ref_case_by_team: dict[str, tuple[str, int, str]],
    team_names: dict[str, str],
    venue_names: dict[str, str],
) -> dict[str, Any] | None:
    """P4-152 — refus NOMMÉ (MIROIR DÉTERMINISTE) quand un déplacement fait passer une équipe SOUS
    son plancher « au moins N séances au gymnase V » (patron ``_travel_time_move_violation``).

    Le HARD posé dans ``_apply_hard`` ne peut PAS refuser ce déplacement à lui seul : les autres
    créneaux du modèle restent libres, le solveur place une séance fantôme ailleurs à V pour tenir
    ``sum >= N`` et conclut « valide » à tort (même faille que la mutualisation). On juge donc
    l'ÉTAT CONCRET, de façon déterministe.

    ⚠ N déplacements (P2-51 PR-5b) : le plancher d'une équipe ne dépend QUE de ses propres séances,
    on évalue donc CHAQUE équipe déplacée indépendamment sur la baseline gelée (qui exclut déjà les
    N sources). L'état « avant » d'une équipe déplacée = baseline + SA source ré-ajoutée
    (``ref_case_by_team``) ; « après » = baseline + SON candidat.

    ⚠ LE PLANNING DÉJÀ EN INFRACTION : on ne refuse QUE si le plancher était SATISFAIT AVANT le
    déplacement et cesse de l'être APRÈS. Si le plancher était DÉJÀ cassé (``current < N``), le
    déplacement n'en est pas la cause : on laisse passer — sans quoi le gestionnaire serait ENFERMÉ,
    incapable de corriger un planning généré avant la contrainte ou amputé d'un créneau (condition
    d'arrêt fondateur : jamais un blocage total).

    Message français nommant le gymnase, l'équipe, le plancher exigé et l'état résultant ; aucun
    identifiant interne. ``None`` si rien à dire."""
    if not venue_minimums:
        return None

    moved_by_team = {str(m["team_id"]): m for m in moved}

    # Nombre de séances de chaque (équipe, gymnase) dans la baseline GELÉE — elle exclut déjà les
    # sources des déplacements (MoveSlotService.baselineWithoutSiblings). Les séances HARD-verrouillées
    # sont comptées : elles créditent le plancher (parité ``add_venue_minimum_constraints``).
    base_count: dict[tuple[str, str], int] = {}
    for slot in baseline_slots:
        key = (str(slot["team_id"]), str(slot["venue_id"]))
        base_count[key] = base_count.get(key, 0) + 1

    def _team_name(team_id: str) -> str:
        return team_names.get(team_id) or team_id

    def _venue_name(venue_id: str) -> str:
        return venue_names.get(venue_id) or venue_id

    for rule in venue_minimums:
        team_id = str(rule.get("scope_target_id"))
        venue_id = str(rule.get("venue_id"))
        minimum = int(rule.get("min") or 1)
        moved_slot = moved_by_team.get(team_id)
        if moved_slot is None:
            continue  # un déplacement ne touche que les comptes des équipes déplacées.

        base_at_venue = base_count.get((team_id, venue_id), 0)
        ref_case = ref_case_by_team.get(team_id)
        # « avant » = baseline + source ré-ajoutée ; « après » = baseline + candidat.
        current_at_venue = base_at_venue + (1 if ref_case is not None and ref_case[0] == venue_id else 0)
        final_at_venue = base_at_venue + (1 if str(moved_slot["venue_id"]) == venue_id else 0)
        if current_at_venue >= minimum and final_at_venue < minimum:
            return {
                "rule": "venue_minimum_infeasible",
                "message": (
                    f"Ce déplacement ferait passer {_team_name(team_id)} sous son minimum de séances "
                    f"à {_venue_name(venue_id)} : {minimum} séance(s) y sont exigée(s), or ce placement "
                    f"n'en laisserait plus que {final_at_venue}."
                ),
                "team_id": team_id,
                "venue_id": venue_id,
                "day_of_week": int(moved_slot["day"]),
                "start_time": str(moved_slot["start_time"]),
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
    # 0 ou N). ``ref_case_by_team`` : la case d'ORIGINE d'une équipe déplacée, clé sur l'équipe de la
    # référence — l'anti-enfermement des miroirs bloc/plancher en dépend. ``reference_keys`` :
    # les SlotKeys « avant » pour le DELTA de compromis.
    ref_case_by_team: dict[str, tuple[str, int, str]] = {}
    reference_keys: set[SlotKey] = set()
    for reference in input_data.references:
        r_team = str(reference.team_id)
        r_start_text = _format_time(_time_to_minutes(reference.start_time))
        ref_case_by_team[r_team] = (str(reference.venue_id), int(reference.day_of_week), r_start_text)
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

    # P2-51 (D11) — miroir déterministe du BLOC : un déplacement qui RETIRE une équipe d'une séance
    # de bloc jusque-là honorée est refusé, NOMMÉ (`shared_block_broken`). Le HARD posé dans
    # `_apply_hard` ne saurait pas l'attribuer (le solveur réinventerait la séance de bloc ailleurs
    # pour tenir Σb == commonSessions). ⚠ N candidats jugés ENSEMBLE : déplacer les N membres d'un
    # bloc vers une MÊME case le laisse honoré. ⚠ Anti-enfermement (P4-152) : un bloc déjà cassé dans
    # la baseline ne bloque pas — on refuse SEULEMENT si le déplacement casse un bloc jusque-là honoré.
    shared_block_violation = _shared_block_move_violation(
        data.get("sharedBlocks", []) or [],
        baseline_slots,
        moved,
        ref_case_by_team,
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
        ref_case_by_team,
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

    violations: list[dict[str, Any]] = []
    if not valid:
        # Chaque candidat est diagnostiqué contre la baseline gelée AUGMENTÉE des AUTRES candidats :
        # un conflit HARD entre deux déplacements du même geste (coach en double sur deux gymnases,
        # capacité…) est alors NOMMÉ, pas seulement candidat-contre-baseline.
        seen: set[tuple[str, str]] = set()
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
            ):
                dedupe_key = (str(violation.get("rule")), str(violation.get("message")))
                if dedupe_key not in seen:
                    seen.add(dedupe_key)
                    violations.append(violation)
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
