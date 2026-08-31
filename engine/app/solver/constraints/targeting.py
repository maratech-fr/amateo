"""Placement-targeting families: time windows, forced/minimum venues, shared trainings, team links.

Imports ``..`` externals and ``.common``. Called by ``structural``'s
``add_level_1_hard_constraints`` orchestrator; imports no other sibling."""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Iterable, Mapping, Sequence
from datetime import UTC, datetime
from typing import Any, cast

from ..model import DEFAULT_SESSION_MINUTES, _format_time, _time_to_minutes
from .common import (
    MANDATORY,
    PREFERRED,
    AssignmentInput,
    AssignmentVariable,
    BoolVarLike,
    _day_int_set,
    _extract_interval,
    _get,
    _intervals_overlap,
    _normalise_assignments,
    _record_closure,
    _scalar_id,
)


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

    # P2-51 arbitrage n°3 — les séances de BLOC posées AVANT (agrégateur), par case
    # ``(venue_id, slot_id)`` → ``[(b_var, frozenset(membres))]``. Une case où siège une séance de
    # bloc dont les membres CONTIENNENT tout le groupe est EXCLUE du comptage exact-K : la
    # co-présence y serait imposée par le bloc, pas choisie par le groupe — sans quoi le bloc
    # fausserait l'exact-K d'un groupe imbriqué. Bloc absent ⇒ carte vide ⇒ chemin byte-identique.
    block_sessions_by_case: Mapping[tuple[str, str], list[tuple[Any, frozenset[str]]]] = (
        getattr(model, "shared_block_sessions_by_case", None) or {}
    )

    added = 0
    for group_index, group in enumerate(groups):
        member_ids = [str(t) for t in (_get(group, "teamIds", "team_ids", default=()) or ())]
        common_sessions = int(_get(group, "commonSessions", "common_sessions", default=0) or 0)
        group_id = str(_get(group, "id", default=group_index) or group_index)
        if len(member_ids) < 2:
            continue
        member_set = frozenset(member_ids)

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

            # Blocs SUR-ENSEMBLES du groupe siégeant sur cette case : leur ``b`` DÉSARME ``y``.
            block_bvars = [
                b for b, members in block_sessions_by_case.get((venue_id, slot_id), []) if member_set <= members
            ]

            y = cast(Any, model).NewBoolVar(f"shared_{group_id}_{venue_id}_{slot_id}".replace(":", "_"))
            for term in var_terms:
                cast(Any, model).Add(y <= term)
            # y=0 dès qu'une séance de bloc sur-ensemble est active ici (exclusion, n°3).
            for b in block_bvars:
                cast(Any, model).Add(y <= 1 - b)
            m = len(var_terms)
            if m == 0:
                # Tous les membres verrouillés sur cette case : présence commune constante (sauf
                # si un bloc sur-ensemble y siège, auquel cas les ``y <= 1 - b`` ci-dessus tranchent).
                cast(Any, model).Add(y == 1)
            else:
                cast(Any, model).Add(
                    y >= sum(cast(Any, v) for v in var_terms) - (m - 1) - sum(cast(Any, b) for b in block_bvars)
                )
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


def add_shared_block_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    shared_blocks: Iterable[Any] = (),
) -> int:
    """P2-51 — mutualisation par BLOC : un bloc se comporte comme UNE équipe, ses séances lui
    APPARTIENNENT (arbitrage fondateur D9). Le solveur place ``commonSessions`` séances, chacune =
    UNE case ``(gymnase, jour, heure)`` où TOUS les membres sont ensemble.

    MODÉLISATION — LIAGE (variable propre au bloc + liage des variables membres), et POURQUOI :
    pour chaque case candidate ``s`` on crée une variable de DÉCISION du bloc ``b[s]`` (« le bloc
    tient une séance ici »), reliée aux membres par ``x[tᵢ, s] >= b[s]`` (b=1 ⟹ tous présents) ; puis
    ``Σ_s b[s] == commonSessions``. On NE réifie PAS ``b`` depuis la co-présence (pas de ``b ⇔ tous
    présents``) : c'est ce qui dissout le mur du double-comptage du modèle groupe (deux blocs
    partageant une équipe ont des ``b`` INDÉPENDANTS, chacun compte SES séances). Le liage donne
    GRATIS la sémantique membre : ``x[tᵢ,s]=1`` fait de la séance de bloc une séance du membre —
    elle CONSOMME une de ses séances/semaine, COMPTE pour ``one_session_per_day``, le repos coach,
    les enchaînements, ``team_no_overlap`` et l'objectif de placement, tous exprimés sur ``x``. Le
    seul poste qui demande une chirurgie est la CAPACITÉ gymnase (une séance de bloc = UNE
    occupation, pas N) : on enregistre le dé-comptage ``(n_free-1)·b`` que ``add_room_at_most_one``
    soustrait (patron du crédit des verrouillés, P4-97).

    Deux blocs partageant un membre ne peuvent PAS s'effondrer sur la MÊME case (sinon UNE séance
    physique compterait pour deux blocs, ≠ « 2 séances distinctes ») : une garde de distinctness
    ``Σ_{blocs ∋ membre} b[membre, case] <= 1`` les force sur des cases DISTINCTES.

    ``shared_blocks`` vide ⇒ retour immédiat, AUCUNE variable ni contrainte posée : chemin
    byte-identique (goldens inchangés). Le crédit VERROUILLÉ est pris de ``model.locked_slots`` (UNE
    entrée par séance, à son début réel), jamais de ``hard_slot_keys`` (qui éclate en sous-créneaux).
    """
    blocks = list(shared_blocks)
    if not blocks:
        return 0

    var_by_team_slot: dict[tuple[str, str, str], BoolVarLike] = {}
    for assignment in assignments:
        team_id = assignment.team_id
        venue_id = assignment.venue_id
        slot_id = assignment.slot_id
        if team_id is None or venue_id is None or slot_id is None:
            continue
        var_by_team_slot[(str(team_id), str(venue_id), str(slot_id))] = assignment.var

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

    # Cartes ÉCRITES ici, LUES par la capacité gymnase (dé-comptage) et le comptage exact-K
    # (exclusion n°3). ⚠ On garde la RÉFÉRENCE du dict porté par le modèle (même vide) : un
    # ``... or {}`` fabriquerait un dict jetable quand le modèle en porte un vide, et les
    # lecteurs ne verraient jamais nos écritures. Défensif : un ``cp_model.CpModel`` nu (tests de
    # pose) n'a pas l'attribut → ``None`` → dict local (aucun lecteur dans ce cas).
    room_relief = getattr(model, "shared_block_room_relief", None)
    if room_relief is None:
        room_relief = {}
    sessions_by_case = getattr(model, "shared_block_sessions_by_case", None)
    if sessions_by_case is None:
        sessions_by_case = {}

    # Distinctness inter-blocs : (membre, case) → les ``b`` des DIFFÉRENTS blocs qui y siègent.
    member_case_bvars: dict[tuple[str, str, str], list[BoolVarLike]] = defaultdict(list)

    added = 0
    for block_index, block in enumerate(blocks):
        member_ids = [str(t) for t in (_get(block, "teamIds", "team_ids", default=()) or ())]
        common_sessions = int(_get(block, "commonSessions", "common_sessions", default=0) or 0)
        block_id = str(_get(block, "id", default=block_index) or block_index)
        if len(member_ids) < 2:
            continue
        member_set = frozenset(member_ids)

        candidate_slots: set[tuple[str, str]] = set()
        for team_id, venue_id, slot_id in var_by_team_slot:
            if team_id in member_ids:
                candidate_slots.add((venue_id, slot_id))
        for team_id, venue_id, slot_id in locked_team_slots:
            if team_id in member_ids:
                candidate_slots.add((venue_id, slot_id))

        b_list: list[BoolVarLike] = []
        for venue_id, slot_id in sorted(candidate_slots):
            var_terms: list[BoolVarLike] = []
            feasible = True
            for team_id in member_ids:
                key = (team_id, venue_id, slot_id)
                if key in var_by_team_slot:
                    var_terms.append(var_by_team_slot[key])
                elif key in locked_team_slots:
                    continue  # membre verrouillé : présent en constante, aucun liage à poser.
                else:
                    feasible = False
                    break
            if not feasible:
                continue

            b = cast(Any, model).NewBoolVar(f"block_{block_id}_{venue_id}_{slot_id}".replace(":", "_"))
            for term in var_terms:
                cast(Any, model).Add(cast(Any, term) >= b)  # b=1 ⟹ le membre est présent ici.
                added += 1
            n_free = len(var_terms)
            if n_free >= 2:
                # La co-présence des ``n_free`` membres libres tient dans UNE occupation.
                room_relief.setdefault((venue_id, slot_id), []).append((b, n_free - 1))
            sessions_by_case.setdefault((venue_id, slot_id), []).append((b, member_set))
            for team_id in member_ids:
                member_case_bvars[(team_id, venue_id, slot_id)].append(b)
            b_list.append(b)

        if b_list:
            cast(Any, model).Add(sum(cast(Any, v) for v in b_list) == common_sessions)
            added += 1
        elif common_sessions >= 1:
            # Aucune case où le bloc peut réunir ses membres et ≥1 séance exigée → insatisfiable.
            # Contradiction propre (jamais un ``Add(0 == K)`` fragile) : la génération sort
            # INFEASIBLE, le diagnostic ``shared_block_not_honored`` nomme le bloc.
            infeasible = cast(Any, model).NewBoolVar(f"block_{block_id}_infeasible")
            cast(Any, model).Add(infeasible == 1)
            cast(Any, model).Add(infeasible == 0)
            added += 1

    # Garde de distinctness : deux blocs partageant un membre ne siègent pas sur la MÊME case.
    for (_team_id, _venue_id, _slot_id), bvars in member_case_bvars.items():
        if len(bvars) >= 2:
            cast(Any, model).Add(sum(cast(Any, v) for v in bvars) <= 1)
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


def team_share_declared_pairs(
    shared_trainings: Iterable[Any], shared_blocks: Iterable[Any] = ()
) -> set[frozenset[str]]:
    """Les paires d'équipes déclarées MUTUALISÉES — membres d'un même groupe ``sharedTrainings``
    OU d'un même bloc ``sharedBlocks`` (P2-51, arbitrage n°6).

    C'est l'unique condition de l'EXEMPTION doctrinale passerelle : deux séances de deux équipes
    passerelées ne sont exemptes de l'anti-chevauchement QUE si elles sont sur la MÊME case
    (gymnase, jour, heure) ET que ces deux équipes partagent un groupe OU un bloc déclaré — la
    co-présence des membres d'un bloc sur leur séance n'est pas un chevauchement fautif. Renvoie le
    set des ``frozenset({tA, tB})`` de tous les couples intra-groupe ET intra-bloc."""
    pairs: set[frozenset[str]] = set()
    for declaration in list(shared_trainings or ()) + list(shared_blocks or ()):
        members = [str(t) for t in (_get(declaration, "teamIds", "team_ids", default=()) or ())]
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
    shared_blocks: Iterable[Any] = (),
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
    share_pairs = team_share_declared_pairs(shared_trainings, shared_blocks)

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
