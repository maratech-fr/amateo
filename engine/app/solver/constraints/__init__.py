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
"""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Iterable, Mapping, Sequence
from datetime import UTC, datetime
from typing import Any, cast

from ..compromise import (
    CompromiseTermInfo,
)
from ..model import DEFAULT_SESSION_MINUTES, SLOT_MINUTES, _format_time, _time_to_minutes
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
from .parsing import _KNOWN_FAMILIES as _KNOWN_FAMILIES
from .parsing import _KNOWN_TYPES as _KNOWN_TYPES
from .parsing import _coach_window_minutes as _coach_window_minutes
from .parsing import _intensity as _intensity
from .parsing import _rule_block as _rule_block
from .parsing import _set_venue_rule as _set_venue_rule
from .parsing import parse_v2_constraints as parse_v2_constraints
from .parsing import resolve_implicit_rules as resolve_implicit_rules
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


def add_room_at_most_one(model: Any, assignments: Sequence[AssignmentVariable]) -> int:
    """Constraint 1: one room/venue can host at most capacity teams per time slot."""

    slot_capacities: dict[Any, int] = getattr(model, "slot_capacities", {})
    groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    for assignment in assignments:
        venue_id = assignment.venue_id
        time_key = _assignment_time_key(assignment)
        if venue_id is None or time_key is None:
            continue
        groups[(venue_id, time_key)].append(assignment.var)

    added = 0
    for (venue_id, time_key), variables in groups.items():
        deduped = _dedupe_variables(variables)
        if len(deduped) < 2:
            continue
        parts = str(time_key).split(":", 1)
        if len(parts) == 2 and parts[0].isdigit():
            cap = slot_capacities.get((venue_id, int(parts[0]), parts[1]), 1)
        else:
            cap = 1
        model.Add(sum(deduped) <= cap)
        added += 1

    # P4-97 bis — un verrou occupe une place de la capacité. ``build_model`` retire déjà les
    # variables libres dont le DÉBUT tombe sur un sous-créneau verrouillé ; il reste le cas
    # d'un placement libre qui commence AVANT le verrou et le chevauche (mêmes gymnase et jour,
    # départs différents) — invisible au groupement par heure exacte ci-dessus. On force ce
    # créneau libre à 0 quand, sur l'un de ses sous-créneaux de 15 min, les verrous saturent
    # déjà la capacité. (Un conflit entre verrous SEULS est laissé au diagnostic post-solve.)
    locked_counts = _locked_venue_substart_counts(model)
    if locked_counts:
        for assignment in assignments:
            venue_id = assignment.venue_id
            start = assignment.start
            end = assignment.end
            if venue_id is None or start is None or end is None:
                continue
            day, _start_min = _assignment_day_start(assignment)
            if day is None:
                continue
            start_min = int(start)
            end_min = int(end)
            cap = slot_capacities.get((venue_id, day, _format_time(start_min)), 1)
            max_locked = 0
            minute = start_min
            while minute < end_min:
                occupied = locked_counts.get((str(venue_id), day, minute), 0)
                if occupied > max_locked:
                    max_locked = occupied
                minute += SLOT_MINUTES
            if max_locked >= cap:
                model.Add(assignment.var == 0)
                added += 1
                # P4-99 — un verrou (d'une autre équipe) sature la capacité du gymnase sur ce
                # sous-créneau : la vraie cause de ce candidat fermé est un verrou.
                _record_closure(model, assignment.var, {"kind": "hard_lock"})
    return added


def add_coach_at_most_one(
    model: Any, assignments: Sequence[AssignmentVariable], *, team_coach_map: dict[str, list[str]] | None = None
) -> int:
    """Constraint 2: one coach can coach at most one team per time slot.

    When ``team_coach_map`` is provided and the assignment's team is in the map,
    all coaches for that team are looked up from the map. Otherwise, falls back
    to the assignment's ``coach_id`` attribute for backward compatibility.

    Overlap detection uses both ``_assignment_time_key`` grouping (same slot start) and
    ``_intervals_overlap`` (interval intersection) so that coaching assignments
    with different start times but overlapping intervals are also prevented.

    ⚑ D-14 (arbitrage fondateur, 2026-08-09) — la règle est **venue-aware** : le même
    gymnase est AUTORISÉ. Un coach qui tient les SM1 et les SM2 sur le même créneau, au
    même endroit, est présent une fois et surveille deux groupes ; c'est un choix de
    gestion légitime, courant dans les petites structures. Ce sont les gymnases
    DIFFÉRENTS qui restent interdits — là, c'est physiquement impossible.

    Le backend (`CoachDoubleBookingDetector`) et la modale du wizard
    (`coachDoubleBooking.ts`) appliquaient déjà cette exemption ; le moteur était le seul
    des trois à l'ignorer, et refusait donc de placer ce que les deux autres offraient.
    """

    groups: dict[tuple[Any, Any], list[tuple[BoolVarLike, str | None]]] = defaultdict(list)
    person_entries: dict[str, list[tuple[int, int, BoolVarLike, str, str | None, str]]] = defaultdict(list)

    for assignment in assignments:
        time_key = _assignment_time_key(assignment)
        if time_key is None:
            continue

        team_id = assignment.team_id
        team_id_str = str(team_id) if team_id is not None else None

        # Look up coaches from team_coach_map
        coach_ids: list[Any] = []
        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            coach_ids = list(team_coach_map[team_id_str])
        else:
            # Fall back to assignment's coach_id attribute
            coach_id = assignment.coach_id
            if coach_id is not None:
                coach_ids = [coach_id]

        var = assignment.var
        venue_id = str(assignment.venue_id) if assignment.venue_id is not None else None
        # Le gymnase reste HORS de la clé (cf. `_add_cross_venue_at_most_one`) : il est
        # porté par l'entrée, et c'est la comparaison de paire qui exempte le même gymnase
        # sans désarmer les gymnases différents.
        for coach_id in coach_ids:
            groups[(coach_id, time_key)].append((var, venue_id))

        start, end, day = _extract_interval(assignment)
        if start is not None and end is not None and day is not None:
            for coach_id in coach_ids:
                person_entries[str(coach_id)].append((start, end, var, day, venue_id, "coach"))

    time_key_added = _add_cross_venue_at_most_one(model, groups)
    interval_added = _add_interval_at_most_one(model, person_entries, same_venue_allowed=True)

    # P4-97 bis — un coach VERROUILLÉ dans un gymnase occupe la personne : un placement LIBRE
    # qui la ferait coacher AILLEURS au même moment est refusé (le même gymnase reste permis,
    # D-14). ``team_player_map=None`` : ici on ne modélise que la ressource COACH (comme ci-dessus).
    coach_locked = _locked_person_day_occupations(model, team_coach_map, None)
    locked_added = _add_free_vs_locked_interval_conflicts(model, person_entries, coach_locked)
    return time_key_added + interval_added + locked_added


def add_coach_player_non_overlap(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    team_coach_map: dict[str, list[str]] | None = None,
    team_player_map: dict[str, list[str]] | None = None,
) -> int:
    """Constraint 3: a coach-player cannot be in two roles at the same time.

    When ``team_coach_map`` / ``team_player_map`` are provided and the
    assignment's team is found, coaches and players are looked up from the
    maps. Otherwise, falls back to the assignment's own attributes.

    Overlap detection uses both ``_assignment_time_key`` grouping (same slot start) and
    ``_intervals_overlap`` (interval intersection) so that assignments with
    different start times but overlapping intervals are also prevented. The
    interval check covers ALL role combinations for the same person
    (coach-coach, coach-player, player-player).
    """

    coach_groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    player_groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    person_entries: dict[str, list[tuple[int, int, BoolVarLike, str, str | None, str]]] = defaultdict(list)

    for assignment in assignments:
        time_key = _assignment_time_key(assignment)
        if time_key is None:
            continue

        team_id = assignment.team_id
        team_id_str = str(team_id) if team_id is not None else None

        # D-14 : le RÔLE est retenu, pas seulement la personne. Une même personne peut être
        # coach ici et joueuse là ; seule la paire coach-coach tolère le même gymnase, et
        # `player` l'emporte quand les deux s'appliquent (on ne joue pas en coachant).
        person_roles: dict[str, str] = {}
        all_person_ids: set[str] = set()

        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            for coach_id in team_coach_map[team_id_str]:
                coach_groups[(coach_id, time_key)].append(assignment.var)
                all_person_ids.add(str(coach_id))
                person_roles.setdefault(str(coach_id), "coach")
        else:
            single_coach = assignment.coach_id
            if single_coach is not None:
                coach_groups[(single_coach, time_key)].append(assignment.var)
                all_person_ids.add(str(single_coach))
                person_roles.setdefault(str(single_coach), "coach")

        if team_player_map is not None and team_id_str is not None and team_id_str in team_player_map:
            for player_id in team_player_map[team_id_str]:
                player_groups[(player_id, time_key)].append(assignment.var)
                all_person_ids.add(str(player_id))
                person_roles[str(player_id)] = "player"
        else:
            for player_id in assignment.player_ids:
                player_groups[(player_id, time_key)].append(assignment.var)
                all_person_ids.add(str(player_id))
                person_roles[str(player_id)] = "player"

        var = assignment.var
        start, end, day = _extract_interval(assignment)
        if start is not None and end is not None and day is not None:
            venue_id = str(assignment.venue_id) if assignment.venue_id is not None else None
            for person_id in all_person_ids:
                person_entries[person_id].append(
                    (start, end, var, day, venue_id, person_roles.get(person_id, "player"))
                )

    overlap_groups = (coach_groups[key] + player_groups[key] for key in coach_groups.keys() & player_groups.keys())
    time_key_added = _add_at_most_one_groups(model, overlap_groups)
    # D-14 : le drapeau est levé ici AUSSI, mais il ne relâche que les paires coach-coach —
    # que la contrainte 2 possède déjà. Coach-joueur et joueur-joueur restent opposés.
    interval_added = _add_interval_at_most_one(model, person_entries, same_venue_allowed=True)

    # P4-97 bis — le CAS RÉEL (BCCL) : « Mara » coache une équipe LIBRE pendant qu'elle JOUE
    # dans une équipe VERROUILLÉE au même moment dans un AUTRE gymnase. Le verrou occupe la
    # personne ; le placement libre incompatible est refusé (toutes combinaisons de rôles, avec
    # la seule exemption coach-coach même-gymnase de D-14). Source : les cartes, jamais slot.coachId.
    locked_occ = _locked_person_day_occupations(model, team_coach_map, team_player_map)
    locked_added = _add_free_vs_locked_interval_conflicts(model, person_entries, locked_occ)
    return time_key_added + interval_added + locked_added


def _add_free_vs_locked_interval_conflicts(
    model: Any,
    free_entries: dict[str, list[tuple[int, int, BoolVarLike, str, str | None, str]]],
    locked_occupations: dict[str, dict[int, list[tuple[int, int, str | None, str]]]],
) -> int:
    """Force à 0 tout créneau LIBRE d'une personne qui chevauche une de ses occupations
    VERROUILLÉES, sous l'exemption D-14 (P4-97 bis).

    ``free_entries`` : ``person -> [(start, end, var, day, venue, role)]`` (le ``day`` est une
    chaîne, comme le produit ``_extract_interval``). ``locked_occupations`` :
    ``person -> weekday(int) -> [(start, end, venue, role)]`` (cf. ``_locked_person_day_occupations``).

    D-14 (arbitrage fondateur) : deux occupations **coach-coach dans le MÊME gymnase** ne
    s'opposent pas (le coach surveille deux groupes, présent une fois) ; tout le reste —
    gymnases différents, ou l'un des deux rôles ``player`` — est une impossibilité physique.
    Le verrou est souverain : on ne touche QUE le créneau libre, jamais le verrou.
    """
    added = 0
    for person, entries in free_entries.items():
        locked_days = locked_occupations.get(person)
        if not locked_days:
            continue
        for start, end, var, day, venue, role in entries:
            try:
                day_int = int(day)
            except (TypeError, ValueError):
                continue
            for l_start, l_end, l_venue, l_role in locked_days.get(day_int, ()):
                if not _intervals_overlap(start, end, l_start, l_end):
                    continue
                both_coaching = role == "coach" and l_role == "coach"
                if both_coaching and venue is not None and venue == l_venue:
                    continue
                model.Add(var == 0)
                added += 1
                # P4-99 — une occupation VERROUILLÉE de la personne rend ce créneau libre
                # impossible : cause hard_lock.
                _record_closure(model, var, {"kind": "hard_lock"})
                break
    return added


def add_team_no_overlap(model: Any, assignments: Sequence[AssignmentVariable]) -> int:
    """A team cannot have two sessions at the same time slot."""

    groups: dict[tuple[Any, Any], list[BoolVarLike]] = defaultdict(list)
    for assignment in assignments:
        team_id = assignment.team_id
        time_key = _assignment_time_key(assignment)
        if team_id is None or time_key is None:
            continue
        groups[(team_id, time_key)].append(assignment.var)
    return _add_at_most_one_groups(model, groups.values())


def add_fixed_slots(model: Any, assignments: Sequence[AssignmentVariable]) -> int:
    """Constraint 5: pre-placed slots are fixed to 1.

    ⚑ AUD-ENG-31 (2026-08-09) — cette contrainte n'ajoute RIEN en production, et c'est
    voulu : les verrous HARD sont pré-placés **hors** du solveur (P2-9 PR B), donc leur
    variable n'existe pas et aucun constructeur de production ne pose ``fixed=True``.

    Ce qui a été RETIRÉ, c'est la seconde entrée : une liste d'identifiants
    ``fixed_assignments`` alimentée par ``parsed["fixed_slots"]``. Cette clé était
    initialisée à ``[]`` et **plus personne ne l'écrivait** depuis que le chemin UUID des
    contraintes LOCK a été supprimé (il ne matchait jamais). Elle restait pourtant câblée
    jusqu'au solveur : du code qui annonce « le payload peut épingler des créneaux » alors
    qu'aucun payload ne le peut.

    L'attribut ``fixed`` reste, lui : il est le chemin naturel si les verrous devenaient un
    jour des variables du modèle, et il est testé.
    """
    added = 0
    for assignment in assignments:
        if assignment.fixed:
            model.Add(assignment.var == 1)
            added += 1
    return added


def add_forbidden_assignments(
    model: Any, assignments: Sequence[AssignmentVariable], forbidden_assignments: Iterable[Any] = ()
) -> int:
    """Constraint 6: forbidden assignment variables are fixed to 0.

    ``forbidden_assignments`` may contain either:
    - plain string/hashable IDs matched against the assignment's ``id`` field, OR
    - dicts with ``scope_target_id`` (team) and ``venue_id`` keys — every variable
      for that (team, venue) pair is forced to 0 regardless of day/slot.
    """

    forbidden_ids: set[Any] = set()
    # P4-99 — la paire (équipe, gymnase) porte AUSSI l'id/le libellé de la contrainte source
    # (enrichis dans `parse_v2_constraints`), pour rendre la cause cliquable côté front.
    forbidden_pairs: dict[tuple[str, str], tuple[str | None, str | None]] = {}

    for item in forbidden_assignments or ():
        if isinstance(item, dict):
            tid = item.get("scope_target_id") or item.get("team_id")
            vid = item.get("venue_id") or item.get("room_id")
            if tid is not None and vid is not None:
                forbidden_pairs[(str(tid), str(vid))] = (item.get("constraint_id"), item.get("label"))
        else:
            forbidden_ids.add(item)

    added = 0
    for assignment in assignments:
        assignment_id = assignment.id
        team_id = assignment.team_id
        venue_id = assignment.venue_id
        pair: tuple[str, str] | None = (
            (str(team_id), str(venue_id)) if team_id is not None and venue_id is not None else None
        )
        pair_match = pair is not None and pair in forbidden_pairs
        if assignment.forbidden or (assignment_id is not None and assignment_id in forbidden_ids) or pair_match:
            model.Add(assignment.var == 0)
            added += 1
            cause: dict[str, Any] = {"kind": "venue_forbidden"}
            if pair_match and pair is not None:
                constraint_id, label = forbidden_pairs[pair]
                cause["constraintId"] = constraint_id
                cause["label"] = label
            _record_closure(model, assignment.var, cause)
    return added


def add_coach_unavailability_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    coach_unavailability: RuleCollection = (),
    *,
    team_coach_map: dict[str, list[str]] | None = None,
) -> int:
    """Constraint 7: coach-unavailable assignment variables are fixed to 0.

    ``coach_unavailability`` maps a coach id to a set of blocked ``(weekday,
    from_minute, to_minute)`` intervals. A slot is blocked when its day matches
    and its start time falls in ``[from, to)`` (start-based, like the team time
    windows). A whole-day block is ``(day, 0, 1440)`` — the legacy day-level
    behaviour (Lot C added the time dimension; ENG-01 fixed the old no-match bug).

    A team can have several required (non-ASSISTANT) coaches; the assignment only
    carries the first. If ``team_coach_map`` is given, EVERY coach of the team is
    checked — a co-head-coach's unavailability must block the slot too (audit
    review), otherwise ENG-01 survives for co-coached teams.
    """
    rules: Mapping[Any, Any] = coach_unavailability if isinstance(coach_unavailability, Mapping) else {}
    coach_map = team_coach_map or {}
    # P4-99 — coach → [{constraint_id, label, intervals}] (posé sur le modèle par `_solve`).
    # La cause est MESURÉE : on retient QUEL intervalle ferme le créneau et on remonte à SA
    # contrainte — jamais la « première venue ». L'arité `(day, from, to)` de `rules` (union
    # consommée par validate_assignments) NE change pas ; `intervals` vit dans la carte parallèle.
    sources: Mapping[str, Any] = getattr(model, "coach_unavailability_sources", {}) or {}
    added = 0
    for assignment in assignments:
        intrinsic = assignment.coach_unavailable
        day, start = _assignment_day_start(assignment)
        # (coach_id, source) de chaque contrainte dont un intervalle contient (jour, début) :
        # ce sont TOUTES celles qui ferment réellement ce créneau (même règle que day_forbidden).
        matched_sources: list[tuple[str, dict[str, Any]]] = []
        first_matched_coach: str | None = None
        if not intrinsic and rules and day is not None and start is not None:
            coach_ids = coach_map.get(str(assignment.team_id))
            if not coach_ids:
                single = assignment.coach_id
                coach_ids = [str(single)] if single is not None else []
            for cid in coach_ids:
                cid_str = str(cid)
                coach_blocked = any(
                    iv_day == day and iv_from <= start < iv_to for iv_day, iv_from, iv_to in (rules.get(cid_str) or ())
                )
                if not coach_blocked:
                    continue
                if first_matched_coach is None:
                    first_matched_coach = cid_str
                for src in sources.get(cid_str) or []:
                    if any(
                        iv_day == day and iv_from <= start < iv_to
                        for iv_day, iv_from, iv_to in (src.get("intervals") or ())
                    ):
                        matched_sources.append((cid_str, src))
        if intrinsic or first_matched_coach is not None:
            model.Add(assignment.var == 0)
            added += 1
            if matched_sources:
                # Une cause PAR contrainte qui ferme le créneau — plusieurs sont vraies quand
                # deux règles couvrent le même moment (mesuré, jamais deviné).
                for cid_str, src in matched_sources:
                    _record_closure(
                        model,
                        assignment.var,
                        {
                            "kind": "coach_unavailability",
                            "coachId": cid_str,
                            "constraintId": src.get("constraint_id"),
                            "label": src.get("label"),
                        },
                    )
            else:
                # Bloqué mais aucune source identifiable (indispo intrinsèque, ou carte absente) :
                # `constraintId` null honnête + le coach s'il est connu — jamais un id faux.
                cause: dict[str, Any] = {"kind": "coach_unavailability"}
                if first_matched_coach is not None:
                    cause["coachId"] = first_matched_coach
                _record_closure(model, assignment.var, cause)
    return added


def add_time_window_constraints(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    time_windows: Iterable[dict[str, Any]] = (),
) -> tuple[int, list[dict[str, Any]]]:
    added = 0
    conflicts: list[dict[str, Any]] = []

    day_rules_by_team: dict[str, dict[str, set[int]]] = defaultdict(
        lambda: {"forced": set(), "forbidden": set(), "allowed": set()}
    )
    # P4-99 — les règles DAY sont FUSIONNÉES par équipe (plusieurs contraintes peuvent nourrir
    # le même jour interdit) : on garde, MESURÉE au moment de la fusion, la liste des
    # contraintes qui citent chaque jour interdit, plus celles qui posent une liste blanche
    # (`allowedDays`) — le complément d'une liste blanche est un jour interdit sans règle
    # `forbiddenDays` propre. Sert à nommer la cause `day_forbidden` à la fermeture.
    day_forbid_sources: dict[str, dict[int, list[tuple[str | None, str | None]]]] = defaultdict(
        lambda: defaultdict(list)
    )
    allowed_sources: dict[str, list[tuple[str | None, str | None]]] = defaultdict(list)

    for constraint in time_windows or ():
        if not constraint.get("isActive", True):
            continue

        rule_type = constraint.get("ruleType") or constraint.get("rule_type")
        family = constraint.get("family")
        if rule_type == "PREFERRED" and family == "TIME":
            # PREFERRED TIME is a soft bonus handled in the objective (E-feat),
            # not a hard window here.
            continue
        # LOCK on a time/day rule is enforced as HARD (a locked window is fixed).
        if rule_type not in ("HARD", "LOCK") or family not in ("TIME", "DAY"):
            continue

        team_id = constraint.get("scope_target_id") or constraint.get("scopeTargetId")
        if team_id is None:
            continue
        team_id_text = str(team_id)
        config = constraint.get("config") or {}

        if family == "DAY":
            forbidden_days = _day_int_set(config.get("forbiddenDays"))
            allowed_days = _day_int_set(config.get("allowedDays"))
            day_rules_by_team[team_id_text]["forced"].update(_day_int_set(config.get("forcedDays")))
            day_rules_by_team[team_id_text]["forbidden"].update(forbidden_days)
            # An empty allowedDays is treated as "unconfigured" (no restriction),
            # matching the coach-availability whitelist semantics — never "no day
            # allowed" (which would force the team to zero sessions).
            day_rules_by_team[team_id_text]["allowed"].update(allowed_days)
            # P4-99 — trace la contrainte source de chaque jour interdit / liste blanche.
            constraint_id = constraint.get("id")
            constraint_label = constraint.get("name")
            for forbidden_day in forbidden_days:
                day_forbid_sources[team_id_text][forbidden_day].append((constraint_id, constraint_label))
            if allowed_days:
                allowed_sources[team_id_text].append((constraint_id, constraint_label))
            continue

        min_start_time = config.get("minStartTime")
        max_start_time = config.get("maxStartTime")
        max_end_time = config.get("maxEndTime")
        min_start_minutes = _time_to_minutes(min_start_time) if min_start_time is not None else None
        max_start_minutes = _time_to_minutes(max_start_time) if max_start_time is not None else None
        max_end_minutes = _time_to_minutes(max_end_time) if max_end_time is not None else None

        for slot_key, var in x.items():
            if not isinstance(slot_key, tuple) or len(slot_key) < 4:
                continue

            slot_team_id = slot_key[0]
            if str(slot_team_id) != team_id_text:
                continue

            slot_start = slot_key[3]
            slot_start_minutes = _time_to_minutes(slot_start)
            # P4-99 — fenêtre horaire violée : cause `time_window` + la contrainte source
            # (`constraint` porte déjà son id/name, aucun re-parse).
            window_cause: dict[str, Any] = {
                "kind": "time_window",
                "constraintId": constraint.get("id"),
                "label": constraint.get("name"),
            }
            if min_start_minutes is not None and slot_start_minutes < min_start_minutes:
                model.Add(var == 0)
                added += 1
                _record_closure(model, var, dict(window_cause))
                continue
            if max_start_minutes is not None and slot_start_minutes > max_start_minutes:
                model.Add(var == 0)
                added += 1
                _record_closure(model, var, dict(window_cause))
                continue
            # maxEndTime: the session must END by that time (start + its duration).
            # The duration is the slot's own (venue/day/start), default 90 min.
            if max_end_minutes is not None:
                duration = model.slot_durations.get((slot_key[1], slot_key[2], slot_key[3]), DEFAULT_SESSION_MINUTES)
                if slot_start_minutes + duration > max_end_minutes:
                    model.Add(var == 0)
                    added += 1
                    _record_closure(model, var, dict(window_cause))

    team_day_vars: dict[str, dict[int, list[BoolVarLike]]] = defaultdict(lambda: defaultdict(list))
    team_all_vars: dict[str, list[BoolVarLike]] = defaultdict(list)

    for slot_key, var in x.items():
        if not isinstance(slot_key, tuple) or len(slot_key) < 4:
            continue

        slot_team_id = slot_key[0]
        team_id_text = str(slot_team_id)
        team_all_vars[team_id_text].append(var)

        day = slot_key[2]
        try:
            day_value = int(day)
        except (TypeError, ValueError):
            continue
        team_day_vars[team_id_text][day_value].append(var)

    for team_id_text, day_rules in day_rules_by_team.items():
        forced_day_set = day_rules["forced"]
        original_forbidden = set(day_rules["forbidden"])
        allowed_day_set = day_rules["allowed"]
        forbidden_day_set = set(original_forbidden)
        # allowedDays = whitelist: forbid every day the team could train on that
        # is not allowed (the complement, restricted to days that actually exist).
        if allowed_day_set:
            forbidden_day_set |= {day for day in team_day_vars.get(team_id_text, {}) if day not in allowed_day_set}
        # Contradiction → the team can be placed on NO day. Two shapes: a forced day
        # is also forbidden, OR a whitelist ('uniquement'/allowedDays) has ALL its
        # days explicitly forbidden ('évite'). Both are checked against the ORIGINAL
        # forbidden set (not the whitelist complement) so the diagnostic is explicit
        # rather than a downstream "insufficient gym slots" (audit ENG-16 review).
        forced_vs_forbidden = forced_day_set & original_forbidden
        allowed_all_forbidden = bool(allowed_day_set) and not (allowed_day_set - original_forbidden)
        if forced_vs_forbidden or allowed_all_forbidden:
            conflicts.append(
                {
                    "id": f"day_constraint_conflict-{team_id_text}",
                    "type": "day_constraint_conflict",
                    "severity": "ERROR",
                    "teamId": team_id_text,
                    "message": (
                        f"Team {team_id_text} has contradictory day rules "
                        "(the allowed/forced days are all forbidden); the team is forced to 0 slots."
                    ),
                    "suggestions": [
                        "Remove the overlapping days between the 'only these days' / 'forced' rule and the 'avoid' rule.",
                    ],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )
            for var in team_all_vars.get(team_id_text, []):
                model.Add(var == 0)
                added += 1
                # P4-99 — jours contradictoires : aucune contrainte seule n'est « la » cause
                # (c'est leur combinaison) → kind `day_conflict`, sans constraintId unique.
                _record_closure(model, var, {"kind": "day_conflict"})
            continue

        for day_value in forbidden_day_set:
            # P4-99 — les contraintes qui interdisent CE jour : celles qui le listent en
            # `forbiddenDays`, à défaut celles qui posent la liste blanche qui l'exclut.
            day_sources = day_forbid_sources.get(team_id_text, {}).get(day_value) or allowed_sources.get(team_id_text)
            for var in team_day_vars.get(team_id_text, {}).get(day_value, []):
                model.Add(var == 0)
                added += 1
                if day_sources:
                    for source_id, source_label in day_sources:
                        _record_closure(
                            model,
                            var,
                            {"kind": "day_forbidden", "constraintId": source_id, "label": source_label},
                        )
                else:
                    _record_closure(model, var, {"kind": "day_forbidden"})

        if forced_day_set:
            forced_day_vars: list[BoolVarLike] = []
            for day_value in forced_day_set:
                forced_day_vars.extend(team_day_vars.get(team_id_text, {}).get(day_value, []))

            model.Add(sum(forced_day_vars) >= 1)
            added += 1

    return added, conflicts


def add_min_sessions_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    teams: Iterable[Any] = (),
    min_sessions_by_team: Mapping[Any, int] | None = None,
    priority_tiers: Mapping[int, int] | None = None,
) -> int:
    """MIN_SESSIONS as a soft TARGET (ENG-18): rewards reaching each team's effective
    minimum via the objective; NOT a hard "every team gets at least its minimum" guarantee
    (production passes 0 as the hard floor, so no hard MIN_SESSIONS constraint is posted)."""

    if priority_tiers:
        minimums = _compute_effective_min_sessions(teams, priority_tiers)
        if min_sessions_by_team:
            for tid, minimum in min_sessions_by_team.items():
                minimums[_scalar_id(tid)] = int(minimum)
    else:
        minimums = _effective_min_sessions_by_team(teams, min_sessions_by_team)
    if not minimums:
        return 0

    assignments_by_team: dict[Any, list[BoolVarLike]] = defaultdict(list)
    for assignment in assignments:
        team_id = assignment.team_id
        if team_id is None:
            continue
        assignments_by_team[team_id].append(assignment.var)

    added = 0
    for team_id, minimum in minimums.items():
        if minimum <= 0:
            continue
        team_vars = _dedupe_variables(assignments_by_team.get(team_id, []))
        model.Add(sum(team_vars) >= minimum)
        added += 1
    return added


def add_forced_venue_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    forced_venues: Mapping[Any, Any] | None = None,
) -> int:
    """Constraint 11: when a venue is forced, all other venues are fixed to 0."""

    # P4-99 — équipe → contrainte de gymnase forcé (posé sur le modèle par `_solve`), pour
    # nommer la cause `forced_venue_elsewhere` sans re-parser.
    sources: Mapping[str, Any] = getattr(model, "forced_venue_sources", {}) or {}
    added = 0
    for assignment in assignments:
        venue_id = assignment.venue_id
        target_venue_id = _forced_venue_id(assignment, forced_venues)
        if target_venue_id is None or venue_id is None or venue_id == target_venue_id:
            continue
        model.Add(assignment.var == 0)
        added += 1
        cause: dict[str, Any] = {"kind": "forced_venue_elsewhere"}
        source = sources.get(str(assignment.team_id)) if assignment.team_id is not None else None
        if source:
            cause["constraintId"] = source.get("constraint_id")
            cause["label"] = source.get("label")
        _record_closure(model, assignment.var, cause)
    return added


def add_venue_minimum_constraints(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    venue_minimums: Iterable[Mapping[str, Any]] = (),
) -> tuple[int, list[dict[str, Any]]]:
    """ALIGN-05: 'at least N of the team's sessions at venue V'.

    A COUNT (sum(team vars at V) >= N), NOT a forced venue. If the team has fewer
    available slots at V than N, it is provably unsatisfiable → emit an explicit
    diagnostic (never a silent INFEASIBLE).

    P4-97 — HARD-locked sessions of the team at V already count toward N but carry no
    variable (``model.py``). Each DISTINCT locked day at V credits the minimum:
    ``effective_min = minimum - locked_days_at_venue`` (floor 0). ``effective_min <= 0``
    means the reservations already satisfy the floor → no constraint AND no diagnostic
    (previously a false ``venue_minimum_unreachable`` fired here, e.g. SM2 pinned to
    Matéo). Otherwise the constraint and the reachability test both run on the free days,
    excluding the locked ones (a locked day cannot host a second session — one/day cap)."""
    added = 0
    conflicts: list[dict[str, Any]] = []

    # Distinct HARD-locked days per (team, venue) — a locked session guarantees one
    # session that day at V, so it credits the minimum.
    locked_days_by_team_venue: dict[tuple[str, str], set[int]] = defaultdict(set)
    for locked in getattr(model, "locked_slots", ()) or ():
        locked_team = str(_get(locked, "team_id", "teamId", default="") or "")
        locked_venue = str(_get(locked, "venue_id", "venueId", default="") or "")
        locked_day = _get(locked, "day_of_week", "dayOfWeek", default=None)
        if not locked_team or not locked_venue or locked_day is None:
            continue
        try:
            locked_days_by_team_venue[(locked_team, locked_venue)].add(int(locked_day))
        except (TypeError, ValueError):
            continue

    for rule in venue_minimums or []:
        team_id = str(rule.get("scope_target_id"))
        venue_id = str(rule.get("venue_id"))
        minimum = int(rule.get("min") or 1)

        locked_days = locked_days_by_team_venue.get((team_id, venue_id), set())
        effective_min = minimum - len(locked_days)
        if effective_min <= 0:
            # Les réservations saturent déjà le plancher : rien à contraindre, rien à signaler.
            continue

        team_venue_vars = [
            var
            for slot_key, var in x.items()
            if isinstance(slot_key, tuple)
            and len(slot_key) >= 2
            and str(slot_key[0]) == team_id
            and str(slot_key[1]) == venue_id
        ]

        # Reachability is bounded by the number of DISTINCT FREE DAYS available at the
        # venue, NOT the raw slot count: a team plays ≤1 session/day (per-day cap),
        # so two same-day slots still contribute at most ONE session. Locked days are
        # excluded — they already carry a session and cannot host another. Counting raw
        # vars would let a provably-infeasible minimum slip past → silent INFEASIBLE.
        team_venue_days = {
            slot_key[2]
            for slot_key in x
            if isinstance(slot_key, tuple)
            and len(slot_key) >= 3
            and str(slot_key[0]) == team_id
            and str(slot_key[1]) == venue_id
        } - locked_days

        if len(team_venue_days) < effective_min:
            conflicts.append(
                {
                    "id": f"venue_minimum_unreachable-{team_id}-{venue_id}",
                    "type": "venue_minimum_unreachable",
                    "severity": "ERROR",
                    "teamId": team_id,
                    "message": (
                        f"Team {team_id} cannot reach {minimum} session(s) at venue {venue_id}: "
                        f"only {len(team_venue_days)} distinct free day(s) available there beyond "
                        f"{len(locked_days)} locked day(s) (≤1 session/day)."
                    ),
                    "suggestions": ["Lower the minimum, or add availability slots on OTHER days at this venue."],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )
            continue

        model.Add(sum(team_venue_vars) >= effective_min)
        added += 1

    return added, conflicts


def _add_at_most_one_groups(model: Any, groups: Iterable[Iterable[BoolVarLike]]) -> int:
    added = 0
    for group in groups:
        variables = _dedupe_variables(group)
        if len(variables) < 2:
            continue
        if hasattr(model, "add_at_most_one"):
            model.add_at_most_one(variables)
        else:
            model.AddAtMostOne(variables)
        added += 1
    return added


def _add_cross_venue_at_most_one(
    model: Any,
    keyed_entries: dict[tuple[Any, Any], list[tuple[BoolVarLike, str | None]]],
) -> int:
    """``varA + varB <= 1`` pour toute paire de MÊME clé posée dans des gymnases DIFFÉRENTS.

    D-14 — remplace un `_add_at_most_one_groups` sur la clé `(coach, temps)`. Ajouter
    simplement le gymnase à cette clé serait le réflexe évident, et il est FAUX : deux
    gymnases différents tomberaient alors dans deux groupes séparés, chacun réduit à une
    variable, et plus rien ne les opposerait — on aurait autorisé le même gymnase en
    autorisant AUSSI ce qu'on voulait interdire. C'est
    `test_coach_on_two_venues_at_same_time_is_impossible` qui l'a rattrapé.

    D'où le passage en paires explicites : le gymnase reste hors de la clé, et c'est la
    COMPARAISON entre les deux membres qui décide. Un gymnase inconnu (None) ne vaut pas
    « même gymnase » — sans preuve de co-localisation, on garde la règle stricte.
    """
    added = 0
    for entries in keyed_entries.values():
        for i in range(len(entries)):
            var_a, venue_a = entries[i]
            for j in range(i + 1, len(entries)):
                var_b, venue_b = entries[j]
                if var_a is var_b:
                    continue
                if venue_a is not None and venue_a == venue_b:
                    continue
                model.Add(var_a + var_b <= 1)
                added += 1
    return added


def _add_interval_at_most_one(
    model: Any,
    person_entries: dict[str, list[tuple[int, int, BoolVarLike, str, str | None, str]]],
    *,
    same_venue_allowed: bool = False,
) -> int:
    """Add pairwise ``varA + varB <= 1`` for overlapping intervals per person per day.

    Args:
        model: CP-SAT model.
        person_entries: ``dict[person_id, list[(start, end, var, day, venue, role)]]`` où
            ``role`` vaut ``"coach"`` ou ``"player"``.
        same_venue_allowed: quand True, deux intervalles qui se chevauchent **dans le même
            gymnase** ne sont PAS opposés — mais UNIQUEMENT si les deux entrées sont des
            rôles ``"coach"``. Voir D-14 ci-dessous.

    Returns: number of pairwise constraints added.

    D-14 (arbitrage fondateur, 2026-08-09) — un coach PEUT tenir deux équipes en même temps
    dans le MÊME gymnase. « Matthieu coache les SM1 et les SM2, et le gestionnaire peut
    vouloir que les deux séances aient lieu simultanément. C'est rare mais c'est possible
    dans les petites structures. » Il est présent une fois et surveille deux groupes.

    ⚠ **L'exemption est réservée aux paires coach-coach**, et c'est pour cela que le rôle
    voyage avec l'entrée. Coacher et JOUER sont deux rôles, pas deux groupes surveillés :
    une même personne ne peut pas les tenir simultanément, même à trois mètres d'écart.

    ⚑ C'est le piège qui a failli passer. ``add_coach_player_non_overlap`` teste lui aussi
    TOUTES les combinaisons de rôles pour une même personne, **coach-coach comprise** : sa
    copie venue-blind continuait de rendre INFEASIBLE le cas Matthieu alors que la
    contrainte 2 l'avait dûment relâché. Relâcher un seul des deux sites ne relâche rien —
    seule la falsification l'a montré, la suite restait verte.

    Deux gymnases différents restent interdits dans tous les cas : impossibilité physique,
    pas choix de gestion.
    """
    added = 0
    for entries in person_entries.values():
        by_day: dict[str, list[tuple[int, int, BoolVarLike, str | None, str]]] = defaultdict(list)
        for start, end, var, day, venue, role in entries:
            by_day[day].append((start, end, var, venue, role))

        for day_entries in by_day.values():
            for i in range(len(day_entries)):
                a_start, a_end, var_a, a_venue, a_role = day_entries[i]
                for j in range(i + 1, len(day_entries)):
                    b_start, b_end, var_b, b_venue, b_role = day_entries[j]
                    if var_a is var_b:
                        continue
                    both_coaching = a_role == "coach" and b_role == "coach"
                    if same_venue_allowed and both_coaching and a_venue is not None and a_venue == b_venue:
                        continue
                    if _intervals_overlap(a_start, a_end, b_start, b_end):
                        model.Add(var_a + var_b <= 1)
                        added += 1
    return added


def _compute_effective_min_sessions(teams: Iterable[Any], priority_tiers: Mapping[int, int]) -> dict[Any, int]:
    """Compute effective minimum sessions per team via tier defaultMinSessions.

    effective_min = min(sessionsPerWeek, tier.defaultMinSessions)

    If the team has no priorityTierId or the tier is not in priority_tiers,
    falls back to sessionsPerWeek as the effective minimum.
    """
    minimums: dict[Any, int] = {}
    for team in teams:
        team_id = _scalar_id(_get(team, "id", "team_id", default=None))
        if team_id is None:
            continue
        sessions_per_week_raw = _get(team, "sessions_per_week", "sessionsPerWeek", default=None)
        if sessions_per_week_raw is None:
            continue
        sessions_per_week = int(sessions_per_week_raw)
        tier_id_raw = _get(team, "priority_tier_id", "priorityTierId", default=None)
        if tier_id_raw is not None:
            try:
                tier_key = int(tier_id_raw)
            except (TypeError, ValueError):
                tier_key = None
            if tier_key is not None and tier_key in priority_tiers:
                minimums[team_id] = min(sessions_per_week, priority_tiers[tier_key])
                continue
        minimums[team_id] = sessions_per_week
    return minimums


def _effective_min_sessions_by_team(
    teams: Iterable[Any], min_sessions_by_team: Mapping[Any, int] | None
) -> dict[Any, int]:
    minimums: dict[Any, int] = {}
    for team in teams:
        team_id = _scalar_id(_get(team, "id", "team_id", default=None))
        if team_id is None:
            continue
        minimum = _get(
            team,
            "min_sessions_effectif",
            "effective_min_sessions",
            "min_sessions",
            "sessions_per_week",
            default=None,
        )
        if minimum is not None:
            minimums[team_id] = int(minimum)

    if min_sessions_by_team:
        for team_id, minimum in min_sessions_by_team.items():
            minimums[_scalar_id(team_id)] = int(minimum)

    return minimums


def _forced_venue_id(assignment: AssignmentVariable, forced_venues: Mapping[Any, Any] | None) -> Any:
    explicit = _scalar_id(
        _get(
            assignment,
            "forced_venue_id",
            "forced_room_id",
            "forced_venue",
            "forced_room",
            default=None,
        )
    )
    if explicit is not None:
        return explicit

    if not forced_venues:
        return None

    team_id = assignment.team_id
    session_id = assignment.session_id
    candidate_keys = (
        (team_id, session_id),
        f"{team_id}:{session_id}" if team_id is not None and session_id is not None else None,
        session_id,
        team_id,
    )
    for key in candidate_keys:
        if key is not None and key in forced_venues:
            return _scalar_id(forced_venues[key])
    return None


def add_shared_training_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    shared_trainings: Iterable[Any] = (),
) -> int:
    """P2-27 — mutualisation : chaque groupe déclaré partage EXACTEMENT ``commonSessions`` séances.

    Une « séance commune » d'un groupe = une case ``(gymnase, jour, heure)`` où TOUTES les
    équipes du groupe sont présentes. Pour chaque case candidate ``s`` on réifie un littéral
    ``y_s`` ⇔ « tous les membres sont sur ``s`` », DANS LES DEUX SENS (décision fondateur) :

      * ``y_s ≤ x[tᵢ, s]`` pour chaque membre (présence de tous ⇐ y),
      * ``y_s ≥ Σᵢ x[tᵢ, s] − (m−1)`` (y ⇐ présence de tous),

    où ``m`` est le nombre de membres à VARIABLE sur ``s`` (les membres VERROUILLÉS sur ``s``
    comptent comme constante 1 — leur séance est pré-placée hors solveur, ``model.py`` ne leur
    crée pas de variable ; sans ce crédit, une séance pourtant commune ne serait « pas comptée »
    et l'exactitude serait fausse, leçon P4-97). Puis ``Σ_s y_s == K``.

    Un membre SANS variable et NON verrouillé sur ``s`` (place bloquée par un verrou d'une autre
    équipe) ne peut y être : ``y_s`` est alors impossible → la case n'est pas candidate.

    ⚠ ``shared_trainings`` vide ⇒ retour immédiat, AUCUNE variable ni contrainte posée : le
    chemin de code reste byte-identique (goldens inchangés). Défensif comme les autres poseurs :
    un modèle nu sans ``hard_slot_keys`` dégrade proprement (``getattr`` avec défaut).
    """
    groups = list(shared_trainings)
    if not groups:
        return 0

    # (team_id, venue_id, slot_id) -> var  — slot_id == "day:start" (idiome des assignments).
    var_by_team_slot: dict[tuple[str, str, str], BoolVarLike] = {}
    for assignment in assignments:
        team_id = assignment.team_id
        venue_id = assignment.venue_id
        slot_id = assignment.slot_id
        if team_id is None or venue_id is None or slot_id is None:
            continue
        var_by_team_slot[(str(team_id), str(venue_id), str(slot_id))] = assignment.var

    # (team_id, venue_id, slot_id) des séances VERROUILLÉES : présence constante 1. Source =
    # ``model.locked_slots`` (UNE entrée par séance verrouillée, à son DÉBUT réel) et NON
    # ``hard_slot_keys`` (qui éclate chaque séance en sous-créneaux de 15 min — les compter tous
    # gonflerait faussement le nombre de séances communes). Le début du verrou coïncide avec le
    # début de la fenêtre d'entraînement, donc la case candidate est bien ``"day:start"``.
    locked_team_slots: set[tuple[str, str, str]] = set()
    for locked in getattr(model, "locked_slots", ()) or ():
        team_id_l = _get(locked, "team_id", "teamId", default=None)
        venue_id_l = _get(locked, "venue_id", "venueId", default=None)
        day_l = _get(locked, "day_of_week", "dayOfWeek", default=None)
        start_l = _get(locked, "start_time", "startTime", default=None)
        if team_id_l is None or venue_id_l is None or day_l is None or start_l is None:
            continue
        slot_id_l = f"{int(day_l)}:{_format_time(_time_to_minutes(start_l))}"
        locked_team_slots.add((str(team_id_l), str(venue_id_l), slot_id_l))

    added = 0
    for group_index, group in enumerate(groups):
        member_ids = [str(t) for t in (_get(group, "teamIds", "team_ids", default=()) or ())]
        common_sessions = int(_get(group, "commonSessions", "common_sessions", default=0) or 0)
        group_id = str(_get(group, "id", default=group_index) or group_index)
        if len(member_ids) < 2:
            continue

        # Cases candidates = union, par membre, des cases à variable ET des cases verrouillées.
        candidate_slots: set[tuple[str, str]] = set()
        for team_id, venue_id, slot_id in var_by_team_slot:
            if team_id in member_ids:
                candidate_slots.add((venue_id, slot_id))
        for team_id, venue_id, slot_id in locked_team_slots:
            if team_id in member_ids:
                candidate_slots.add((venue_id, slot_id))

        y_list: list[BoolVarLike] = []
        for venue_id, slot_id in sorted(candidate_slots):
            var_terms: list[BoolVarLike] = []
            const_present = 0
            feasible = True
            for team_id in member_ids:
                key = (team_id, venue_id, slot_id)
                if key in var_by_team_slot:
                    var_terms.append(var_by_team_slot[key])
                elif key in locked_team_slots:
                    const_present += 1
                else:
                    feasible = False
                    break
            if not feasible:
                continue

            y = cast(Any, model).NewBoolVar(f"shared_{group_id}_{venue_id}_{slot_id}".replace(":", "_"))
            for term in var_terms:
                cast(Any, model).Add(y <= term)
            m = len(var_terms)
            if m == 0:
                # Tous les membres verrouillés sur cette case : présence commune constante.
                cast(Any, model).Add(y == 1)
            else:
                cast(Any, model).Add(y >= sum(cast(Any, v) for v in var_terms) - (m - 1))
            y_list.append(y)
            added += 1

        if y_list:
            cast(Any, model).Add(sum(cast(Any, v) for v in y_list) == common_sessions)
            added += 1
        elif common_sessions >= 1:
            # Aucune case où le groupe peut être ensemble et K≥1 séances exigées → déclaration
            # insatisfiable. On pose une contradiction propre (jamais un `Add(0 == K)` fragile) ;
            # la génération sort INFEASIBLE, le diagnostic `shared_training_not_honored` nomme le groupe.
            infeasible = cast(Any, model).NewBoolVar(f"shared_{group_id}_infeasible")
            cast(Any, model).Add(infeasible == 1)
            cast(Any, model).Add(infeasible == 0)
            added += 1

    return added


# Lot PASSERELLES — une PLACE d'équipe pour l'anti-chevauchement des passerelles :
# ``(start_min, end_min, day_int, venue_id, var)``. ``var`` est la BoolVar CP-SAT d'une séance
# LIBRE, ou ``None`` pour une séance VERROUILLÉE (présente en constante, pas de variable).
TeamLinkPlacement = tuple[int, int, int, str, "BoolVarLike | None"]


def team_link_placements_by_team(
    assignments: Iterable[AssignmentInput] | Mapping[Any, BoolVarLike] | None,
    locked_slots: Iterable[Any],
) -> dict[str, list[TeamLinkPlacement]]:
    """Regroupe, PAR ÉQUIPE, toutes ses PLACES candidates — séances libres (à variable) ET
    séances verrouillées (constantes) — sous la forme ``(start, end, day, venue, var|None)``.

    SOURCE UNIQUE partagée par la pose HARD (``add_team_link_constraints``, qui passe des
    ``AssignmentVariable``) et la pénalité SOFT (``objective.add_team_link_penalty``, qui passe les
    ``list[dict]`` de production) : l'entrée est NORMALISÉE ici (``_normalise_assignments``), donc les
    deux jugent le chevauchement sur EXACTEMENT la même géométrie. Une séance sans intervalle
    exploitable (``_extract_interval`` renvoie None) est ignorée : sans horaire, « se chevaucher »
    n'a pas de sens.
    """
    by_team: dict[str, list[TeamLinkPlacement]] = defaultdict(list)
    for assignment in _normalise_assignments(assignments):
        team_id = assignment.team_id
        venue_id = assignment.venue_id
        if team_id is None or venue_id is None:
            continue
        start, end, day = _extract_interval(assignment)
        if start is None or end is None or day is None:
            continue
        by_team[str(team_id)].append((start, end, int(day), str(venue_id), assignment.var))
    for locked in locked_slots or ():
        team_id_l = _get(locked, "team_id", "teamId", default=None)
        venue_id_l = _get(locked, "venue_id", "venueId", default=None)
        day_l = _get(locked, "day_of_week", "dayOfWeek", default=None)
        start_l = _get(locked, "start_time", "startTime", default=None)
        if team_id_l is None or venue_id_l is None or day_l is None or start_l is None:
            continue
        duration_l = int(_get(locked, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))
        start_min = _time_to_minutes(start_l)
        by_team[str(team_id_l)].append((start_min, start_min + duration_l, int(day_l), str(venue_id_l), None))
    return by_team


def team_share_declared_pairs(shared_trainings: Iterable[Any]) -> set[frozenset[str]]:
    """Les paires d'équipes déclarées MUTUALISÉES (membres d'un même groupe ``sharedTrainings``).

    C'est l'unique condition de l'EXEMPTION doctrinale (arbitrage n°3) : deux séances de deux
    équipes passerelées ne sont exemptes de l'anti-chevauchement QUE si elles sont sur la MÊME
    case (gymnase, jour, heure) ET que ces deux équipes partagent un groupe déclaré. Renvoie le
    set des ``frozenset({tA, tB})`` de tous les couples intra-groupe."""
    pairs: set[frozenset[str]] = set()
    for group in shared_trainings or ():
        members = [str(t) for t in (_get(group, "teamIds", "team_ids", default=()) or ())]
        for i in range(len(members)):
            for j in range(i + 1, len(members)):
                pairs.add(frozenset({members[i], members[j]}))
    return pairs


def iter_team_link_overlaps(
    placements_a: list[TeamLinkPlacement],
    placements_b: list[TeamLinkPlacement],
    *,
    share_declared: bool,
) -> Iterable[tuple[TeamLinkPlacement, TeamLinkPlacement]]:
    """Énumère les paires (place de A, place de B) qui SE CHEVAUCHENT dans le temps et ne sont
    PAS exemptes — le CŒUR de la sémantique passerelle, partagé HARD ⇄ SOFT.

    Chevauchement = même jour + intervalles ``[start, end)`` qui s'intersectent, **quel que soit
    le gymnase** (doctrine n°2 : cross-gymnase compris). EXEMPTION (arbitrage n°3) : une paire sur
    la MÊME case (gymnase, jour, heure de début) est exemptée UNIQUEMENT si ``share_declared`` —
    c'est alors la séance mutualisée volontaire. C'est PLUS STRICT que la tolérance coach D-14
    (``same_venue_allowed``) : même gymnase + même horaire SANS groupe déclaré reste un
    chevauchement. Deux séances SÉPARÉES des mêmes équipes (hors case commune) restent soumises."""
    for a_start, a_end, a_day, a_venue, a_var in placements_a:
        for b_start, b_end, b_day, b_venue, b_var in placements_b:
            if a_day != b_day:
                continue
            if not _intervals_overlap(a_start, a_end, b_start, b_end):
                continue
            same_case = a_venue == b_venue and a_start == b_start
            if same_case and share_declared:
                continue
            yield (a_start, a_end, a_day, a_venue, a_var), (b_start, b_end, b_day, b_venue, b_var)


def add_team_link_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    team_links: Iterable[Any] = (),
    shared_trainings: Iterable[Any] = (),
) -> int:
    """Lot PASSERELLES — anti-chevauchement DUR des passerelles ``MANDATORY``.

    Pour chaque passerelle MANDATORY (deux équipes partageant des joueurs), les séances des deux
    équipes ne doivent JAMAIS se chevaucher dans le temps (patron de ``add_coach_player_non_overlap``
    :585-667, mais ``same_venue_allowed=False`` et l'exemption groupe-mutualisé à la place — c'est
    PLUS STRICT que la tolérance coach D-14, doctrine n°3). Selon la nature de chaque place :

      * libre ⇔ libre : ``var_a + var_b <= 1`` (exclusion mutuelle, comme la contrainte 4) ;
      * libre ⇔ verrouillé : le verrou est SOUVERAIN — la séance libre est forcée à 0 et
        ``_record_closure`` enregistre la cause NOMMANT la passerelle (rail P4-99). Si la libre
        est la seule fenêtre de son équipe, la génération sort INFEASIBLE et ``candidate_closures``
        porte la cause (jamais un « non » nu) ;
      * verrou ⇔ verrou : DEUX actes volontaires du gestionnaire qui se contredisent — on ne pose
        AUCUNE contrainte (poser ``1 + 1 <= 1`` rendrait INFEASIBLE muet). La violation est
        ANNONCÉE post-solve par ``result_builder._diagnose_team_links`` (« un verrou HARD est
        souverain mais diagnostiqué », CLAUDE.md §6).

    ``team_links`` vide (ou aucune MANDATORY) ⇒ retour immédiat, chemin byte-identique (patron
    ``add_shared_training_constraints``). Les passerelles ``PREFERRED`` ne sont PAS traitées ici :
    elles vivent dans l'objectif (``objective.add_team_link_penalty``).
    """
    mandatory = [link for link in (team_links or ()) if str(_get(link, "intensity", default=PREFERRED)) == MANDATORY]
    if not mandatory:
        return 0

    placements = team_link_placements_by_team(assignments, getattr(model, "locked_slots", ()) or ())
    share_pairs = team_share_declared_pairs(shared_trainings)

    added = 0
    for link in mandatory:
        team_a = str(_get(link, "teamAId", "team_a_id", default=""))
        team_b = str(_get(link, "teamBId", "team_b_id", default=""))
        if not team_a or not team_b or team_a == team_b:
            continue
        link_id = str(_get(link, "id", default=f"{team_a}_{team_b}"))
        share_declared = frozenset({team_a, team_b}) in share_pairs
        cause = {"kind": "team_link", "constraintId": link_id, "label": None}
        for (_as, _ae, _ad, _av, a_var), (_bs, _be, _bd, _bv, b_var) in iter_team_link_overlaps(
            placements.get(team_a, []), placements.get(team_b, []), share_declared=share_declared
        ):
            if a_var is not None and b_var is not None:
                model.Add(a_var + b_var <= 1)
                added += 1
            elif a_var is not None:  # b verrouillé : la libre s'écarte, cause nommée.
                model.Add(a_var == 0)
                _record_closure(model, a_var, cause)
                added += 1
            elif b_var is not None:
                model.Add(b_var == 0)
                _record_closure(model, b_var, cause)
                added += 1
            # else : deux verrous → rien posé, diagnostic post-solve (jamais INFEASIBLE muet).
    return added


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


_DAY_LABELS = ("lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi", "dimanche")


def _day_label(day: int) -> str:
    return _DAY_LABELS[day - 1] if 1 <= day <= 7 else f"jour {day}"


def _day_rules_union(
    windows: Iterable[Mapping[str, Any]],
) -> dict[str, tuple[dict[str, set[int]], list[Mapping[str, Any]]]]:
    """Les règles DAY UNIES par équipe, avec les contraintes qui les portent.

    L'union est la seule sémantique que le solveur applique : deux règles
    `allowedDays=[2]` et `allowedDays=[6]` autorisent mardi ET samedi une fois
    unies, alors qu'isolément chacune exclut le jour de l'autre. Les évaluer
    séparément accusait donc à tort.

    Mais l'union seule ne suffit pas à RENDRE COMPTE : le gestionnaire doit
    savoir QUELLE règle corriger. On garde donc les contraintes sources pour
    nommer, à l'émission, celles qui excluent effectivement le jour du verrou —
    un libellé synthétique « règles de jours de SM1 » ne correspond à rien dans
    son écran de contraintes.
    """
    union: dict[str, dict[str, set[int]]] = {}
    sources: dict[str, list[Mapping[str, Any]]] = {}
    for constraint in windows:
        if not _is_enforced_window(constraint):
            continue
        if str(constraint.get("family") or "").upper() != "DAY":
            continue
        team_id = str(constraint.get("scope_target_id") or constraint.get("scopeTargetId") or "")
        if not team_id:
            continue
        config = constraint.get("config") or {}
        rules = union.setdefault(team_id, {"forbidden": set(), "allowed": set(), "forced": set()})
        rules["forbidden"].update(_day_int_set(config.get("forbiddenDays")))
        rules["allowed"].update(_day_int_set(config.get("allowedDays")))
        # `forcedDays` AUSSI : le solveur l'impose (`sum(forced_day_vars) >= 1`).
        # L'omettre laissait un verrou rendre cette exigence insatisfaisable sans
        # que ce diagnostic — dont c'est le rôle — n'en dise rien.
        rules["forced"].update(_day_int_set(config.get("forcedDays")))
        sources.setdefault(team_id, []).append(constraint)

    return {team_id: (rules, sources.get(team_id, [])) for team_id, rules in union.items()}


def _day_constraints_excluding(
    day: int, rules: Mapping[str, set[int]], sources: Iterable[Mapping[str, Any]]
) -> list[Mapping[str, Any]]:
    """Parmi les sources, celles qui excluent RÉELLEMENT ce jour.

    Une règle dont les `allowedDays` ne mentionnent pas le jour ne l'exclut que si
    aucune autre ne l'autorise — c'est l'union qui tranche. On ne nomme donc que
    les règles fautives une fois l'union appliquée : celles qui l'interdisent
    explicitement, et, si le jour est hors de la liste blanche unie, celles qui
    portent cette liste.
    """
    excluded_by_whitelist = bool(rules["allowed"]) and day not in rules["allowed"]
    guilty = []
    for constraint in sources:
        config = constraint.get("config") or {}
        if day in _day_int_set(config.get("forbiddenDays")) or (
            excluded_by_whitelist and _day_int_set(config.get("allowedDays"))
        ):
            guilty.append(constraint)

    return guilty


def _is_enforced_window(constraint: Mapping[str, Any]) -> bool:
    """La MÊME porte que `add_time_window_constraints`, au même endroit : avant tout
    parsing d'horaire. Sans elle on accuse une règle PREFERRED que le solveur
    n'applique jamais, et `_time_to_minutes` lève sur une valeur qu'aucun chemin
    d'exécution ne lit — transformant une génération qui passait en 500."""
    if not constraint.get("isActive", True):
        return False
    rule_type = constraint.get("ruleType") or constraint.get("rule_type")
    family = str(constraint.get("family") or "").upper()
    if rule_type == "PREFERRED" and family == "TIME":
        return False

    return rule_type in ("HARD", "LOCK") and family in ("TIME", "DAY")


def diagnose_locked_slot_violations(
    locked_slots: Iterable[Mapping[str, Any]],
    parsed: Mapping[str, Any],
    *,
    team_names: Mapping[str, str] | None = None,
    coach_names: Mapping[str, str] | None = None,
    venue_names: Mapping[str, str] | None = None,
) -> list[dict[str, Any]]:
    """Warn about the HARD constraints a HARD lock silently annuls (P2-9).

    A HARD lock is pre-placed OUTSIDE the solver: ``model.py`` never creates the
    ``x[team, venue, day, start]`` variable for it. Every constraint below works
    by forcing that variable to 0, so with no variable there is nothing to force
    — the lock doesn't *beat* the constraint, it makes it unreachable. Measured
    before this function existed: the same payload placed SM1 on Tuesday without
    a lock (coach off on Saturday, honoured) and on Saturday WITH one, with an
    empty ``diagnostics`` and a ``completed`` status.

    The lock stays sovereign — that is the founder's ALIGN-07 ruling, and it is
    not reopened here. What changes is the silence: the manager is told what his
    pin overrode, so he can decide. Hence INFO warnings, never errors.

    Scope is deliberately the constraints the manager ENTERED (coach
    availability, team time/day windows, forbidden AND forced venues). The
    structural rules a lock also bypasses — one coach in two gyms at once — are a
    different animal: they describe physical impossibility rather than a
    preference, so they block generation instead of warning, and land in their
    own change. ``venue_minimums`` is deliberately EXCLUDED: it is applied hard
    with only three outcomes (honored · INFEASIBLE→failed · unreachable→ERROR
    diagnostic), so it can never drift in silence — claiming to watch it here
    would be the very lie this docstring forbids.

    Mirrors the enforcement rules exactly (start-based interval match for
    coaches, min/max start for windows, team+venue pair for forbidden venues,
    team→imposed-venue mismatch for forced venues); any drift between the two
    would make this lie about what the solver did.
    """
    rules: Mapping[Any, Any] = parsed.get("coach_unavailability") or {}
    coach_map: Mapping[str, Any] = parsed.get("team_coach_map") or {}
    windows = parsed.get("time_windows") or ()
    forbidden = parsed.get("forbidden_assignments") or ()
    forced_venues: Mapping[str, Any] = parsed.get("forced_venues") or {}

    def _team(team_id: str) -> str:
        return (team_names or {}).get(team_id) or team_id

    def _coach(coach_id: str) -> str:
        return (coach_names or {}).get(coach_id) or coach_id

    def _venue(venue_id: str) -> str:
        return (venue_names or {}).get(venue_id) or venue_id

    day_union = _day_rules_union(windows)

    warnings: list[dict[str, Any]] = []
    seen: set[tuple[str, str, str]] = set()

    def _emit(constraint: Mapping[str, Any], team_id: str, lock_id: str, message: str) -> None:
        # Clé (contrainte, équipe, VERROU) : deux verrous distincts qui violent la
        # même règle sont deux choses à corriger, et n'en montrer qu'une laisserait
        # le gestionnaire croire son planning réparé après un seul geste.
        # (La justification précédente — « un verrou couvre plusieurs départs de
        # 30 min » — était fausse : `locked_slots` porte UNE entrée par verrou,
        # la déduplication par `lock_key` ayant déjà eu lieu dans `model.py`.)
        key = (str(constraint.get("id")), team_id, lock_id)
        if key in seen:
            return
        seen.add(key)
        warning = _not_honored_warning(dict(constraint), "INFO", message)
        warning["teamId"] = team_id
        warnings.append(warning)

    for slot in locked_slots:
        team_id = str(slot.get("team_id") or slot.get("teamId") or "")
        venue_id = str(slot.get("venue_id") or slot.get("venueId") or "")
        day = slot.get("day_of_week")
        start_text = str(slot.get("start_time") or slot.get("startTime") or "")
        if not team_id or day is None or not start_text:
            continue
        day_int = int(day)
        start = _time_to_minutes(start_text)
        duration = int(slot.get("duration_minutes") or slot.get("durationMinutes") or DEFAULT_SESSION_MINUTES)
        # Le JOUR fait partie du message : sans lui, deux verrous même gymnase et
        # même heure des jours différents s'affichent à l'octet près identiques,
        # et le gestionnaire ne sait pas lequel il vient de corriger.
        # La durée fait partie du libellé : deux verrous mêmes gymnase/jour/heure et
        # durées différentes sont DISTINCTS (cf. `lock_id`), et sans elle leurs deux
        # avertissements s'affichaient à l'octet près identiques.
        where = f"{_venue(venue_id)} le {_day_label(day_int)} à {start_text} ({duration} min)"
        # La durée fait partie de la clé, comme dans `_extract_hard_locks` : deux
        # verrous identiques sauf la durée y sont DISTINCTS, les fusionner ici
        # ferait disparaître une violation.
        lock_id = f"{venue_id}|{day_int}|{start_text}|{duration}"

        # 1. Coach availability — every required coach of the team, like the solver.
        coach_ids = [str(c) for c in (coach_map.get(team_id) or [])]
        for coach_id in coach_ids:
            for iv_day, iv_from, iv_to in rules.get(coach_id) or ():
                if iv_day == day_int and iv_from <= start < iv_to:
                    _emit(
                        _constraint_of_coach(coach_id, _coach(coach_id)),
                        team_id,
                        lock_id,
                        f"Réservation maintenue pour {_team(team_id)} ({where}) alors que "
                        f"{_coach(coach_id)} est indisponible : le verrou prime, la contrainte est ignorée.",
                    )

        # 2. Team time/day windows.
        for constraint in windows:
            if str(constraint.get("scope_target_id") or constraint.get("scopeTargetId") or "") != team_id:
                continue
            if not _is_enforced_window(constraint):
                continue
            family = str(constraint.get("family") or "").upper()
            config = constraint.get("config") or {}
            if family == "DAY":
                # Traitée via l'UNION, en dehors de cette boucle : une règle DAY
                # isolée exclut des jours qu'une autre autorise.
                continue
            min_start = config.get("minStartTime")
            max_start = config.get("maxStartTime")
            # `maxEndTime` porte sur la FIN de séance (début + durée), pas sur son
            # début — l'omettre laissait subsister le silence que cette détection
            # existe pour fermer.
            max_end = config.get("maxEndTime")
            # La durée du VERROU, pas celle du créneau de grille. Le round 2 avait
            # basculé sur `slot_durations` par souci de miroir — sur-correction : ce
            # qui déborde de la fenêtre, c'est la séance que le gestionnaire a
            # RÉSERVÉE. Un verrou de 120 min sur un créneau de 90 court jusqu'à
            # 20:00 ; mesurer 90 le déclarait dans les clous et taisait un vrai
            # dépassement. Les verrous de durée ≠ du créneau sont un cas supporté —
            # `_extract_hard_locks` clé justement sur la durée.
            slot_duration = duration
            if (
                (min_start is not None and start < _time_to_minutes(min_start))
                or (max_start is not None and start > _time_to_minutes(max_start))
                or (max_end is not None and start + slot_duration > _time_to_minutes(max_end))
            ):
                _emit(
                    constraint,
                    team_id,
                    lock_id,
                    f"Réservation maintenue pour {_team(team_id)} ({where}) hors de sa fenêtre horaire.",
                )

        # 2 bis. Règles DAY, évaluées sur l'UNION de l'équipe — la seule sémantique
        # que le solveur applique (`forbidden ∪ complément de allowed`).
        # `day_rules` et non `rules` : ce dernier porte déjà les indisponibilités
        # coach, et le réutiliser ici l'écrasait dès le deuxième verrou.
        entry = day_union.get(team_id)
        if entry is not None:
            day_rules, day_sources = entry
            allowed = day_rules["allowed"]
            if day_int in day_rules["forbidden"] or (allowed and day_int not in allowed):
                # On nomme les contraintes RÉELLEMENT fautives, pas un libellé
                # synthétique : le gestionnaire doit retrouver la règle dans son
                # écran pour la corriger.
                for constraint in _day_constraints_excluding(day_int, day_rules, day_sources):
                    _emit(
                        constraint,
                        team_id,
                        lock_id,
                        f"Réservation maintenue pour {_team(team_id)} ({where}) un jour exclu par ses règles de jours.",
                    )

            # `forcedDays` : le solveur exige AU MOINS une séance ce jour-là. Un
            # verrou posé un AUTRE jour peut consommer le créneau qui l'aurait
            # satisfaite — d'où un INFEASIBLE que rien n'expliquait.
            forced = day_rules["forced"]
            if forced and day_int not in forced:
                _emit(
                    {"id": f"forced-days-{team_id}", "name": f"jours imposés de {_team(team_id)}"},
                    team_id,
                    lock_id,
                    f"Réservation maintenue pour {_team(team_id)} ({where}) alors que son planning "
                    f"impose une séance {', '.join(_day_label(d) for d in sorted(forced))} : "
                    "le verrou peut rendre cette exigence insatisfaisable.",
                )

        # 3. Forbidden (team, venue) pairs — venue closures land here.
        for item in forbidden:
            if not isinstance(item, dict):
                continue
            tid = item.get("scope_target_id") or item.get("team_id")
            vid = item.get("venue_id") or item.get("room_id")
            if tid is not None and vid is not None and str(tid) == team_id and str(vid) == venue_id:
                # `parse_v2_constraints` aplatit ces règles en paires (équipe,
                # gymnase) sans `id` ni `name` : passer le dict brut rendait
                # « (contrainte « None ») » et fusionnait tous les gymnases
                # interdits d'une équipe en un seul avertissement.
                _emit(
                    {"id": f"forbidden-venue-{team_id}-{venue_id}", "name": f"gymnase interdit ({_venue(venue_id)})"},
                    team_id,
                    lock_id,
                    f"Réservation maintenue pour {_team(team_id)} ({where}) dans un gymnase qui lui est interdit.",
                )

        # 4. Forced venue — le miroir du gymnase interdit. `parse_v2_constraints`
        # aplatit la règle HARD/LOCK « impose ce gymnase » en team→gymnase unique
        # (`forced_venues`), sans `id` ni `name` : on nomme donc une contrainte
        # synthétique portant le gymnase imposé, comme pour les paires interdites.
        # Le créneau verrouillé n'ayant PAS de variable (`model.py`), le forçage
        # `var == 0` des autres gymnases ne peut pas l'atteindre : le verrou plaçait
        # hors du gymnase imposé, `completed`, sans un mot.
        target_venue = forced_venues.get(team_id)
        if target_venue is not None and venue_id and str(target_venue) != venue_id:
            _emit(
                {"id": f"forced-venue-{team_id}", "name": f"gymnase imposé ({_venue(str(target_venue))})"},
                team_id,
                lock_id,
                f"Réservation maintenue pour {_team(team_id)} ({where}) hors du gymnase imposé "
                f"{_venue(str(target_venue))} : le verrou prime, la contrainte est ignorée.",
            )

    return warnings


def diagnose_candidate_conflicts(
    *,
    candidate: Mapping[str, Any],
    baseline_slots: Sequence[Mapping[str, Any]],
    parsed: Mapping[str, Any],
    coaches: Sequence[Mapping[str, Any]] = (),
    slot_capacities: Mapping[tuple[str, int, str], int] | None = None,
    team_names: Mapping[str, str] | None = None,
    coach_names: Mapping[str, str] | None = None,
    venue_names: Mapping[str, str] | None = None,
) -> list[dict[str, Any]]:
    """Name the HARD rules a move candidate would break (P2-2 F2a).

    The SOLVE is the sovereign verdict (baseline frozen via ``add_fixed_slots`` +
    candidate pinned): it says valid / invalid. This function only EXPLAINS an
    invalid verdict — « un non sans motif est inutilisable ». It mirrors the
    enforcement rules of ``add_level_1_hard_constraints`` exactly, so it does not
    lie about what the solver applies (same discipline as
    ``diagnose_locked_slot_violations``). Anything the solve rejects that no check
    here attributes falls back to a generic ``unknown_hard_conflict`` upstream.

    Coverage is deliberately the families the one-time rail silently ignored —
    capacity, windows, rest — plus the structural double-booking that is the
    founder's motivating example (« le coach Dupont a déjà les U15 à 20h dans un
    autre gymnase »).
    """
    coach_map: Mapping[str, Sequence[str]] = parsed.get("team_coach_map") or {}
    player_map: Mapping[str, Sequence[str]] = parsed.get("team_player_map") or {}
    unavailability: Mapping[str, Any] = parsed.get("coach_unavailability") or {}
    forced_venues: Mapping[str, str] = parsed.get("forced_venues") or {}
    forbidden = parsed.get("forbidden_assignments") or ()
    windows = parsed.get("time_windows") or ()
    caps = slot_capacities or {}

    def _team(team_id: str) -> str:
        return (team_names or {}).get(team_id) or team_id

    def _coach(coach_id: str) -> str:
        return (coach_names or {}).get(coach_id) or coach_id

    def _venue(venue_id: str) -> str:
        return (venue_names or {}).get(venue_id) or venue_id

    c_team = str(candidate["team_id"])
    c_venue = str(candidate["venue_id"])
    c_day = int(candidate["day"])
    c_start = int(candidate["start"])
    c_end = int(candidate["end"])
    c_start_text = str(candidate.get("start_time") or "")

    c_coaches = [str(cid) for cid in coach_map.get(c_team, ())]
    c_players = [str(pid) for pid in player_map.get(c_team, ())]
    c_persons_role: dict[str, str] = {}
    for cid in c_coaches:
        c_persons_role.setdefault(cid, "coach")
    for pid in c_players:
        c_persons_role[pid] = "player"

    coach_ids_in_payload = {
        str(_scalar_id(_get(coach, "id", "coach_id", default=None)))
        for coach in coaches
        if _get(coach, "id", "coach_id", default=None) is not None
    }

    violations: list[dict[str, Any]] = []

    def _emit(rule: str, message: str, **fields: Any) -> None:
        violations.append({"rule": rule, "message": message, **fields})

    # --- Structural rules, checked against the FROZEN baseline occupancy. ---
    coach_working_days: dict[str, set[int]] = {cid: set() for cid in c_coaches}
    baseline_days_same_team: set[int] = set()

    for slot in baseline_slots:
        s_team = str(slot["team_id"])
        s_venue = str(slot["venue_id"])
        s_day = int(slot["day"])
        s_start = int(slot["start"])
        s_end = int(slot["end"])
        overlaps = s_day == c_day and _intervals_overlap(c_start, c_end, s_start, s_end)

        s_persons_role: dict[str, str] = {}
        for cid in coach_map.get(s_team, ()):
            s_persons_role.setdefault(str(cid), "coach")
        for pid in player_map.get(s_team, ()):
            s_persons_role[str(pid)] = "player"

        # Rest day: which weekdays each candidate coach already works.
        if 1 <= s_day <= 5:
            for cid in c_coaches:
                if cid in s_persons_role:
                    coach_working_days[cid].add(s_day)

        # One session per day: same team already busy that day.
        if s_team == c_team and s_day == c_day:
            baseline_days_same_team.add(s_day)

        if s_team == c_team and overlaps:
            _emit(
                "team_no_overlap",
                f"{_team(c_team)} a déjà une séance qui chevauche ce créneau le {_day_label(c_day)}.",
                team_id=c_team,
                day_of_week=c_day,
                start_time=c_start_text,
            )

        if not overlaps:
            continue

        # Coach / coach-player double-booking — venue-aware (D-14): the SAME gym is
        # allowed, a DIFFERENT gym is physically impossible.
        if s_venue != c_venue:
            shared = set(c_persons_role) & set(s_persons_role)
            for person_id in shared:
                is_coach_pair = c_persons_role[person_id] == "coach" and s_persons_role[person_id] == "coach"
                if is_coach_pair:
                    _emit(
                        "coach_no_overlap",
                        f"{_coach(person_id)} a déjà {_team(s_team)} le {_day_label(c_day)} à "
                        f"{c_start_text} dans un autre gymnase : un entraîneur ne peut pas être à deux endroits.",
                        coach_id=person_id,
                        team_id=c_team,
                        conflicting_team_id=s_team,
                        day_of_week=c_day,
                        start_time=c_start_text,
                    )
                else:
                    _emit(
                        "coach_player_no_overlap",
                        f"{_coach(person_id)} est déjà pris par {_team(s_team)} le {_day_label(c_day)} à "
                        f"{c_start_text} dans un autre gymnase (impossible de coacher et jouer en même temps).",
                        coach_id=person_id,
                        team_id=c_team,
                        conflicting_team_id=s_team,
                        day_of_week=c_day,
                        start_time=c_start_text,
                    )

    # Venue capacity: mirror add_room_at_most_one (grouped by venue + exact slot start).
    same_slot_occupants = sum(
        1
        for slot in baseline_slots
        if str(slot["venue_id"]) == c_venue
        and int(slot["day"]) == c_day
        and str(slot.get("start_time")) == c_start_text
    )
    capacity = int(caps.get((c_venue, c_day, c_start_text), 1))
    if same_slot_occupants + 1 > capacity:
        _emit(
            "venue_capacity",
            f"{_venue(c_venue)} le {_day_label(c_day)} à {c_start_text} est déjà à sa capacité "
            f"({capacity}) : aucune place pour {_team(c_team)}.",
            team_id=c_team,
            venue_id=c_venue,
            day_of_week=c_day,
            start_time=c_start_text,
        )

    # Coach rest day: mirror add_coach_rest_day — at most 4 working days Mon-Fri,
    # for every coach present in the payload (no override exemption since P4-51).
    if 1 <= c_day <= 5:
        for cid in c_coaches:
            if cid not in coach_ids_in_payload:
                continue
            worked = coach_working_days[cid] | {c_day}
            if len(worked) >= 5:
                _emit(
                    "coach_no_rest_day",
                    f"placer {_team(c_team)} ici priverait {_coach(cid)} de son unique jour de "
                    "repos (il travaillerait du lundi au vendredi).",
                    coach_id=cid,
                    team_id=c_team,
                    day_of_week=c_day,
                    start_time=c_start_text,
                )

    # One session per day: mirror add_one_session_per_day (at most one per day).
    if c_day in baseline_days_same_team:
        _emit(
            "one_session_per_day",
            f"{_team(c_team)} s'entraîne déjà le {_day_label(c_day)} : une seule séance par jour.",
            team_id=c_team,
            day_of_week=c_day,
            start_time=c_start_text,
        )

    # --- Entered constraints (the manager's own rules). ---
    for cid in c_coaches:
        for iv_day, iv_from, iv_to in unavailability.get(cid) or ():
            if int(iv_day) == c_day and int(iv_from) <= c_start < int(iv_to):
                _emit(
                    "coach_unavailable",
                    f"{_coach(cid)} est indisponible le {_day_label(c_day)} à {c_start_text}.",
                    coach_id=cid,
                    team_id=c_team,
                    day_of_week=c_day,
                    start_time=c_start_text,
                )

    for constraint in windows:
        target = str(constraint.get("scope_target_id") or constraint.get("scopeTargetId") or "")
        if target != c_team or not _is_enforced_window(constraint):
            continue
        if str(constraint.get("family") or "").upper() == "DAY":
            continue
        config = constraint.get("config") or {}
        min_start = config.get("minStartTime")
        max_start = config.get("maxStartTime")
        max_end = config.get("maxEndTime")
        if (
            (min_start is not None and c_start < _time_to_minutes(min_start))
            or (max_start is not None and c_start > _time_to_minutes(max_start))
            or (max_end is not None and c_end > _time_to_minutes(max_end))
        ):
            _emit(
                "time_window",
                f"{_team(c_team)} placé à {c_start_text} sort de sa fenêtre horaire autorisée.",
                team_id=c_team,
                day_of_week=c_day,
                start_time=c_start_text,
            )

    day_union = _day_rules_union(windows).get(c_team)
    if day_union is not None:
        day_rules, _sources = day_union
        allowed = day_rules["allowed"]
        if c_day in day_rules["forbidden"] or (allowed and c_day not in allowed):
            _emit(
                "day_rule",
                f"{_team(c_team)} ne peut pas s'entraîner le {_day_label(c_day)} d'après ses règles de jours.",
                team_id=c_team,
                day_of_week=c_day,
                start_time=c_start_text,
            )

    forced = forced_venues.get(c_team)
    if forced is not None and str(forced) != c_venue:
        _emit(
            "forced_venue",
            f"{_team(c_team)} est forcée dans {_venue(str(forced))} : {_venue(c_venue)} lui est interdit.",
            team_id=c_team,
            venue_id=c_venue,
            day_of_week=c_day,
            start_time=c_start_text,
        )

    for item in forbidden:
        if not isinstance(item, dict):
            continue
        tid = item.get("scope_target_id") or item.get("team_id")
        vid = item.get("venue_id") or item.get("room_id")
        if tid is not None and vid is not None and str(tid) == c_team and str(vid) == c_venue:
            _emit(
                "forbidden_venue",
                f"{_venue(c_venue)} est interdit à {_team(c_team)}.",
                team_id=c_team,
                venue_id=c_venue,
                day_of_week=c_day,
                start_time=c_start_text,
            )

    return violations


def _constraint_of_coach(coach_id: str, label: str) -> dict[str, Any]:
    """The COACH_AVAILABILITY constraint behind a blocked interval, for naming.

    `coach_unavailability` is flattened to intervals at parse time, losing the
    source constraint. A synthetic entry keeps the warning shape valid; the real
    label rides in the message, which names the coach.
    """
    return {"id": f"coach-availability-{coach_id}", "name": f"indisponibilité de {label}"}
