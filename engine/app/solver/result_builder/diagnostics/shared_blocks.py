"""Diagnostic des blocs mutualisés non honorés (``shared_block_not_honored``).

Extrait verbatim de l'ancien ``diagnostics.py`` (découpe pure, P4-295 C1).
"""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Mapping
from datetime import UTC, datetime
from typing import Any

from ortools.sat.python import cp_model

from ...constraints.availability import (
    _coach_available_during,
    case_has_available_coach,
    interval_bites,
    required_coaches_of,
)
from ...constraints.common import _fold_case_occupant_identity
from ...constraints.targeting import time_window_excludes_slot
from ...model import DEFAULT_SESSION_MINUTES, _format_time, _time_to_minutes
from ..helpers import (
    _collection,
    _day_label,
    _get,
    _label,
    _named_list,
    _slot_day,
    _team_name_map,
    _venue_name_map,
)


def _diagnose_shared_blocks(
    model_data: Mapping[str, Any] | Any,
    solver_status: int,
    slots: list[dict[str, Any]],
    *,
    team_coach_map: Mapping[str, Any] | None = None,
    coach_unavailability: Mapping[str, Any] | None = None,
    coach_unavailability_sources: Mapping[str, Any] | None = None,
    sacrificed_source_keys: frozenset[str] | None = None,
) -> list[dict[str, Any]]:
    """P2-51 (arbitrage n°7) — mutualisation par BLOC : nommer le bloc quand il n'est pas honoré,
    code ``shared_block_not_honored`` (severity ERROR).

      * INFEASIBLE — cause PROUVÉE : le bloc a MOINS de cases communes candidates (gymnase, jour,
        heure où TOUS ses membres ont un créneau disponible) que ses ``commonSessions`` — il ne
        pourra jamais placer ses séances. On nomme le bloc (le message ``diag-infeasible`` reste,
        celui-ci l'attribue). Prudent : on n'accuse que quand le compte de cases l'exclut.
      * OPTIMAL/FEASIBLE — défense en profondeur : si le nombre RÉEL de séances communes du bloc
        (co-présence de TOUS ses membres dans les slots finaux) diffère de ``commonSessions``.

    Message français nommant les équipes réelles du bloc ; aucun identifiant interne."""
    blocks = _collection(model_data, "sharedBlocks", "shared_blocks")
    if not blocks:
        return []

    team_names = _team_name_map(model_data)
    diagnostics: list[dict[str, Any]] = []

    if solver_status == cp_model.INFEASIBLE:
        # Cases (gymnase, jour, heure) qui EXISTENT — n'importe quelle équipe peut y siéger, donc
        # ce sont les cases communes candidates d'un bloc. Un bloc qui en a moins que sa demande de
        # séances est provablement non plaçable (miroir du ``Σb == commonSessions`` insatisfiable).
        candidate_cases = 0
        for venue in _collection(model_data, "venues"):
            for _slot in _collection(venue, "training_slots", "trainingSlots"):
                candidate_cases += 1
        # Seconde preuve PRUDENTE — les cases où TOUS les membres du bloc sont ÉPINGLÉS HARD
        # ENSEMBLE. Le moteur exige qu'une telle case porte une séance commune pour AU MOINS un des
        # blocs qui y sont toute-épinglés (``Σ b >= 1`` par case, jamais ``b == 1`` par bloc). Donc
        # une case toute-épinglée n'est une cause CERTAINE de sur-contrainte QUE si elle est
        # EXCLUSIVE au bloc — aucun AUTRE bloc n'y est toute-épinglé, sinon le moteur peut l'attribuer
        # à cet autre bloc. Blocs IMBRIQUÉS (ex. {A,B} sous {A,B,C}, cas BCCL) : leur case commune
        # n'est exclusive à aucun → on n'accuse PERSONNE (le cas imbriqué aboutit, cf. test dédié).
        # ``exclusives > commonSessions`` ⇒ le bloc est sur-contraint par ses PROPRES verrous →
        # ``Σb == commonSessions`` insatisfiable — verrou souverain mais diagnostiqué.
        pinned_teams_by_case: dict[tuple[str, str, str], set[str]] = defaultdict(set)
        for pin in _collection(model_data, "slotTemplates", "slot_templates"):
            if _get(pin, "lockLevel", "lock_level", default=None) != "HARD":
                continue
            team = str(_get(pin, "teamId", "team_id", default=""))
            if not team:
                continue
            case = (
                str(_get(pin, "venueId", "venue_id", default="")),
                str(_get(pin, "dayOfWeek", "day_of_week", default="")),
                str(_get(pin, "startTime", "start_time", default=""))[:5],
            )
            pinned_teams_by_case[case].add(team)
        # Blocs valides (≥2 membres) et leur ensemble de membres — indexés par position.
        valid_blocks = [
            (index, block, members, set(members))
            for index, block in enumerate(blocks)
            if len(members := [str(t) for t in (_get(block, "teamIds", "team_ids", default=[]) or [])]) >= 2
        ]
        # Pour chaque case toute-épinglée, les positions des blocs valides qui y sont toute-épinglés.
        # Une case n'est exclusive à un bloc que si elle ne liste QUE lui.
        fully_pinned_here: dict[tuple[str, str, str], list[int]] = {}
        for case, teams in pinned_teams_by_case.items():
            positions = [pos for pos, (_i, _b, _m, member_set) in enumerate(valid_blocks) if member_set <= teams]
            if positions:
                fully_pinned_here[case] = positions
        for pos, (index, block, members, _member_set) in enumerate(valid_blocks):
            common_sessions = int(_get(block, "commonSessions", "common_sessions", default=0) or 0)
            exclusives = sum(1 for these in fully_pinned_here.values() if these == [pos])
            if candidate_cases < common_sessions:
                diagnostics.append(
                    {
                        "id": f"shared-block-infeasible-{_get(block, 'id', default=index)}",
                        "type": "shared_block_not_honored",
                        "severity": "ERROR",
                        "message": (
                            f"Le bloc de mutualisation ({_named_list(members, team_names)}) ne peut pas placer "
                            f"ses {common_sessions} séance(s) commune(s) : il n'existe que {candidate_cases} "
                            "créneau(x) de gymnase où réunir ses équipes. Ajoutez des créneaux communs ou "
                            "réduisez le nombre de séances communes du bloc."
                        ),
                        "suggestions": [
                            "Ajoutez des créneaux de gymnase où toutes les équipes du bloc peuvent se réunir.",
                            "Réduisez le nombre de séances communes déclarées pour le bloc.",
                        ],
                        "createdAt": datetime.now(UTC).isoformat(),
                    }
                )
            elif exclusives > common_sessions:
                diagnostics.append(
                    {
                        "id": f"shared-block-overpinned-{_get(block, 'id', default=index)}",
                        "type": "shared_block_not_honored",
                        "severity": "ERROR",
                        "message": (
                            f"Le bloc de mutualisation ({_named_list(members, team_names)}) est verrouillé sur "
                            f"{exclusives} créneau(x) où toutes ses équipes sont épinglées ensemble, alors "
                            f"qu'il ne doit partager que {common_sessions} séance(s) commune(s) : ses propres "
                            "verrous se contredisent. Retirez un verrou commun ou augmentez le nombre de séances "
                            "communes du bloc."
                        ),
                        "suggestions": [
                            "Retirez l'un des verrous qui réunissent toutes les équipes du bloc sur une même case.",
                            "Augmentez le nombre de séances communes déclarées pour le bloc.",
                        ],
                        "createdAt": datetime.now(UTC).isoformat(),
                    }
                )
        # P4-322 — deux causes de plus, au MÊME patron que le rail coach : on n'accuse qu'avec une
        # preuve de compte (cases servables < séances communes <= total), on nomme le groupe, la cause
        # réutilise un kind EXISTANT (``hard_lock`` / ``time_window``, aucun bump de contrat), et la
        # question est REJOUÉE via le helper de pose concerné (comblement model.py / algèbre
        # ``targeting.time_window_excludes_slot``), jamais une seconde logique.
        concrete_cases = _concrete_candidate_cases(model_data)
        venue_names = _venue_name_map(model_data)
        diagnostics.extend(
            _diagnose_blocks_blocked_by_nonmember_lock(
                valid_blocks, team_names, venue_names, concrete_cases, pinned_teams_by_case
            )
        )
        diagnostics.extend(
            _diagnose_blocks_blocked_by_time_window(model_data, valid_blocks, team_names, concrete_cases)
        )
        # Lot 3B PR B — rail de preuve « un coach suffit » (Q4B). La boucle ci-dessus ne compte que
        # les cases de gymnase (sur-contrainte de PLACE/verrous). Un bloc peut avoir ASSEZ de cases
        # mais AUCUNE où un coach de l'UNION de ses membres soit disponible : après la pose « un
        # coach suffit » (``closures._close_coachless_block_cases``), ces cases sont fermées → le
        # bloc est infaisable. On rejoue ICI la MÊME question (helper UNIQUE ``constraints.availability``)
        # pour NOMMER le groupe et les indisponibilités en cause, sans re-tester une seconde logique.
        diagnostics.extend(
            _diagnose_blocks_without_union_coach(
                model_data,
                valid_blocks,
                team_names,
                team_coach_map or {},
                coach_unavailability or {},
                coach_unavailability_sources or {},
            )
        )
        return diagnostics

    if solver_status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        return []

    occupancy: dict[str, set[tuple[str, int, str]]] = defaultdict(set)
    for slot in slots:
        day = _slot_day(slot)
        if day is None:
            continue
        occupancy[str(slot["teamId"])].add((str(slot["venueId"]), day, str(slot["startTime"])[:5]))

    # P4-183 — index inverse case → équipes présentes, et blocs valides au format d'ÉLECTION
    # (clé, membres). La case (gymnase, jour, heure) où DEUX blocs imbriqués se réunissent ({A,B}
    # sous {A,B,C}) n'appartient qu'au bloc MAXIMAL : ``_fold_case_occupant_identity`` (base des
    # constraints, même élection que le compteur de sur-capacité) l'attribue à {A,B,C}. Compter la
    # co-présence BRUTE créditait AUSSI {A,B} de cette case → faux ``shared_block_not_honored`` sur
    # le bloc inclus. On ne crédite un bloc d'une case QUE s'il y est le bloc élu.
    teams_by_case: dict[tuple[str, int, str], set[str]] = defaultdict(set)
    for team_id, member_cases in occupancy.items():
        for member_case in member_cases:
            teams_by_case[member_case].add(team_id)
    team_to_group: dict[str, str] = {}
    fold_blocks: list[tuple[str, frozenset[str]]] = []
    for index, block in enumerate(blocks):
        block_members = frozenset(str(m) for m in (_get(block, "teamIds", "team_ids", default=[]) or []))
        if len(block_members) >= 2:
            fold_blocks.append((f"__shared_block__{_get(block, 'id', default=index)}", block_members))

    sacrificed = sacrificed_source_keys or frozenset()
    for index, block in enumerate(blocks):
        members = [str(t) for t in (_get(block, "teamIds", "team_ids", default=[]) or [])]
        if len(members) < 2:
            continue
        common_sessions = int(_get(block, "commonSessions", "common_sessions", default=0) or 0)
        # Réservation souveraine — un bloc que le best-effort a SACRIFIÉ (sa règle relâchée parce
        # qu'un verrou la bloquait) n'est PAS une erreur : il est NOMMÉ en ``rule_sacrificed``
        # (WARNING) par ``_diagnose_sacrificed_rules``. On ne l'accuse donc pas ici d'un
        # ``shared_block_not_honored`` ERROR qui ferait doublon et crierait à tort.
        block_id = str(_get(block, "id", default=index) or index)
        if f"shared_block:{block_id}" in sacrificed:
            continue
        member_sets = [occupancy.get(member, set()) for member in members]
        common_cases = set.intersection(*member_sets) if member_sets else set()
        block_key = f"__shared_block__{_get(block, 'id', default=index)}"
        honored = 0
        for common_case in common_cases:
            _identity, block_keys_here = _fold_case_occupant_identity(
                list(teams_by_case[common_case]), team_to_group, fold_blocks
            )
            if block_key in block_keys_here:
                honored += 1
        if honored != common_sessions:
            diagnostics.append(
                {
                    "id": f"shared-block-not-honored-{_get(block, 'id', default=index)}",
                    "type": "shared_block_not_honored",
                    "severity": "ERROR",
                    "message": (
                        f"Le bloc de mutualisation n'est pas respecté : les équipes "
                        f"{_named_list(members, team_names)} devraient partager {common_sessions} séance(s) "
                        f"commune(s) en bloc mais en partagent {honored}."
                    ),
                    "suggestions": ["Vérifiez les disponibilités communes de ces équipes ou ajustez le bloc."],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )
    return diagnostics


def _block_candidate_cases(model_data: Mapping[str, Any] | Any) -> list[tuple[int, int, int]]:
    """Les cases de gymnase ``(jour, minute de début, minute de fin)`` où un bloc POURRAIT tenir une séance commune
    — toutes les ``trainingSlots`` du payload, le gymnase étant indifférent au relais de coach (un
    coach de l'union couvre la case quel que soit le gymnase). Slot illisible (jour/heure) écarté."""
    cases: list[tuple[int, int, int]] = []
    for venue in _collection(model_data, "venues"):
        for slot in _collection(venue, "training_slots", "trainingSlots"):
            raw_day = _get(slot, "day_of_week", "dayOfWeek", default=None)
            raw_start = _get(slot, "start_time", "startTime", default=None)
            if raw_day is None or raw_start is None:
                continue
            try:
                day = int(raw_day)
                start = _time_to_minutes(str(raw_start))
            except TypeError, ValueError:
                continue
            duration = int(_get(slot, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES) or 0)
            cases.append((day, start, start + (duration or DEFAULT_SESSION_MINUTES)))
    return cases


# Case CONCRÈTE de gymnase : (venue_id, jour, début HH:MM, début en minutes, durée en minutes).
_ConcreteCase = tuple[str, int, str, int, int]


def _concrete_candidate_cases(model_data: Mapping[str, Any] | Any) -> list[_ConcreteCase]:
    """Toutes les ``trainingSlots`` du payload en cases CONCRÈTES — le gymnase est GARDÉ (contrairement
    à ``_block_candidate_cases`` du rail coach) : un verrou de non-membre ou une fenêtre horaire ferme
    une case PRÉCISE, pas toutes les cases du même horaire. Slot illisible (jour/heure) écarté."""
    cases: list[_ConcreteCase] = []
    for venue in _collection(model_data, "venues"):
        venue_id = str(_get(venue, "id", "venue_id", "venueId", default=""))
        for slot in _collection(venue, "training_slots", "trainingSlots"):
            raw_day = _get(slot, "day_of_week", "dayOfWeek", default=None)
            raw_start = _get(slot, "start_time", "startTime", default=None)
            if raw_day is None or raw_start is None:
                continue
            try:
                day = int(raw_day)
                start_minutes = _time_to_minutes(str(raw_start))
            except TypeError, ValueError:
                continue
            duration = int(_get(slot, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES) or 0)
            cases.append(
                (venue_id, day, _format_time(start_minutes), start_minutes, duration or DEFAULT_SESSION_MINUTES)
            )
    return cases


def _sessions_phrase(common_sessions: int) -> str:
    """« sa séance commune » au singulier, « ses N séances communes » au pluriel (jamais le laid
    « ses 1 séance(s) commune(s) »)."""
    return "sa séance commune" if common_sessions == 1 else f"ses {common_sessions} séances communes"


def _format_locked_cases(
    blocked: list[tuple[str, int, str, frozenset[str]]],
    team_names: Mapping[str, str],
    venue_names: Mapping[str, str],
    *,
    limit: int = 3,
) -> str:
    """Énumère, de façon DÉTERMINISTE (tri jour/heure/gymnase) et BORNÉE, les cases verrouillées en
    les nommant : « mardi 18:00 au gymnase X (verrouillé pour SF1) ». Jamais d'identifiant interne —
    ``_label`` rend le NOM (repli sur l'id brut seulement si le nom manque)."""
    ordered = sorted(blocked, key=lambda case: (case[1], case[2], case[0]))
    parts = [
        f"{_day_label(day)} {start} au gymnase {_label(venue_id, venue_names)} "
        f"(verrouillé pour {_named_list(sorted(blockers), team_names)})"
        for venue_id, day, start, blockers in ordered[:limit]
    ]
    text = " ; ".join(parts)
    extra = len(ordered) - limit
    if extra > 0:
        text += f" ; et {extra} autre(s)"
    return text


def _diagnose_blocks_blocked_by_nonmember_lock(
    valid_blocks: list[tuple[int, Any, list[str], set[str]]],
    team_names: Mapping[str, str],
    venue_names: Mapping[str, str],
    concrete_cases: list[_ConcreteCase],
    pinned_teams_by_case: Mapping[tuple[str, str, str], set[str]],
) -> list[dict[str, Any]]:
    """PROUVE, à l'échec, qu'un bloc est infaisable parce que ses SEULES cases communes sont
    VERROUILLÉES HARD pour une équipe NON membre du groupe — et NOMME le groupe, les cases et l'équipe
    qui verrouille, avec QUOI FAIRE (décision fondateur Q3, P4-322).

    Rejoue la logique de COMBLEMENT (``model.py`` : un verrou de non-partenaire RETIRE le candidat du
    membre libre ; un verrou de partenaire le GARDE) — une case est fermée pour le bloc ssi elle porte
    un pin HARD ET qu'AUCUN membre du bloc n'y est épinglé. On ne tire la sonnette que lorsque les
    cases LIBRES sont STRICTEMENT moins nombreuses que les séances communes demandées ALORS QUE la
    grille en offre assez (``usable < commonSessions <= total``) — sinon la pénurie est de PLACE, déjà
    nommée par la boucle de sur-contrainte. Cause kind EXISTANT ``hard_lock`` (un verrou n'est pas une
    contrainte nommée → ``constraintId``/``label`` None ; le backend n'y accole aucune origine)."""
    total = len(concrete_cases)
    if total == 0:
        return []
    diagnostics: list[dict[str, Any]] = []
    for index, block, members, member_set in valid_blocks:
        common_sessions = int(_get(block, "commonSessions", "common_sessions", default=0) or 0)
        if common_sessions <= 0:
            continue
        blocked: list[tuple[str, int, str, frozenset[str]]] = []
        for venue_id, day, start_hhmm, _start_min, _duration in concrete_cases:
            pins = pinned_teams_by_case.get((venue_id, str(day), start_hhmm[:5]))
            if pins and not (pins & member_set):
                blocked.append((venue_id, day, start_hhmm[:5], frozenset(pins)))
        usable = total - len(blocked)
        if not (usable < common_sessions <= total):
            continue
        names = _named_list(members, team_names)
        detail = _format_locked_cases(blocked, team_names, venue_names)
        sessions = _sessions_phrase(common_sessions)
        if usable == 0:
            message = (
                f"Le groupe {names} ne peut placer {sessions} : chaque case où réunir ses équipes est "
                f"verrouillée pour une autre équipe ({detail}). "
                "Libérez l'une de ces cases ou déplacez le verrou qui l'occupe."
            )
        else:
            reste = "reste" if usable == 1 else "restent"
            message = (
                f"Le groupe {names} : seulement {usable} case(s) où réunir ses équipes {reste} libre(s), "
                f"pour {sessions} à placer — les autres sont verrouillées pour une autre équipe ({detail}). "
                "Libérez l'une de ces cases ou déplacez un verrou."
            )
        diagnostics.append(
            {
                "id": f"shared-block-locked-{_get(block, 'id', default=index)}",
                "type": "shared_block_not_honored",
                "severity": "ERROR",
                "message": message,
                "causes": [{"kind": "hard_lock", "constraintId": None, "label": None, "count": len(blocked)}],
                "suggestions": [
                    "Libérez l'une des cases verrouillées pour une autre équipe, ou déplacez le verrou qui l'occupe.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
    return diagnostics


def _diagnose_blocks_blocked_by_time_window(
    model_data: Mapping[str, Any] | Any,
    valid_blocks: list[tuple[int, Any, list[str], set[str]]],
    team_names: Mapping[str, str],
    concrete_cases: list[_ConcreteCase],
) -> list[dict[str, Any]]:
    """PROUVE, à l'échec, qu'un bloc est infaisable parce qu'une FENÊTRE HORAIRE dure (``family=TIME``,
    ``ruleType=HARD``) d'un de ses membres exclut ses cases communes — et NOMME le groupe + porte la
    contrainte en cause (kind EXISTANT ``time_window``) pour que le backend en compose l'origine
    (P4-322, décision Q3).

    RÉUTILISE l'algèbre de pose ``targeting.time_window_excludes_slot`` (source unique) : une case est
    fermée pour le bloc dès qu'UNE fenêtre d'UN membre l'exclut (tous doivent être présents). Même
    garde de compte que le rail coach : ``usable < commonSessions <= total``."""
    total = len(concrete_cases)
    if total == 0:
        return []
    # Fenêtres horaires HARD par équipe : (constraintId, label, minStart, maxStart, maxEnd) en minutes.
    windows_by_team: dict[str, list[tuple[Any, Any, int | None, int | None, int | None]]] = defaultdict(list)
    for constraint in _collection(model_data, "constraints"):
        if (_get(constraint, "ruleType", "rule_type", default=None)) != "HARD":
            continue
        if _get(constraint, "family", default=None) != "TIME":
            continue
        team_id = _get(constraint, "scope_target_id", "scopeTargetId", default=None)
        if team_id is None:
            continue
        config = _get(constraint, "config", default=None) or {}
        min_start = config.get("minStartTime")
        max_start = config.get("maxStartTime")
        max_end = config.get("maxEndTime")
        windows_by_team[str(team_id)].append(
            (
                _get(constraint, "id", default=None),
                _get(constraint, "name", default=None),
                _time_to_minutes(str(min_start)) if min_start is not None else None,
                _time_to_minutes(str(max_start)) if max_start is not None else None,
                _time_to_minutes(str(max_end)) if max_end is not None else None,
            )
        )
    if not windows_by_team:
        return []

    diagnostics: list[dict[str, Any]] = []
    for index, block, members, _member_set in valid_blocks:
        common_sessions = int(_get(block, "commonSessions", "common_sessions", default=0) or 0)
        if common_sessions <= 0:
            continue
        closed = 0
        # constraintId → [team_id (propriétaire de la fenêtre), label, nombre de cases qu'elle ferme].
        # Ordre d'apparition (membres puis fenêtres) = déterministe ; une fenêtre appartient à UNE équipe.
        offenders: dict[Any, list[Any]] = {}
        for _venue_id, _day, _start_hhmm, start_min, duration in concrete_cases:
            excluded = False
            for team_id in members:
                for constraint_id, label, min_start, max_start, max_end in windows_by_team.get(team_id, ()):
                    if time_window_excludes_slot(
                        start_min,
                        duration,
                        min_start_minutes=min_start,
                        max_start_minutes=max_start,
                        max_end_minutes=max_end,
                    ):
                        excluded = True
                        if constraint_id not in offenders:
                            offenders[constraint_id] = [team_id, label, 0]
                        offenders[constraint_id][2] += 1
            if excluded:
                closed += 1
        usable = total - closed
        if not (usable < common_sessions <= total):
            continue
        names = _named_list(members, team_names)
        sessions = _sessions_phrase(common_sessions)
        # NOMME l'équipe (Q3) et le libellé de chaque fenêtre en cause : « « pas après 18h » de U11M2 ».
        fragments = [
            f"« {label} » de {_label(team_id, team_names)}" if label else f"de {_label(team_id, team_names)}"
            for team_id, label, _count in offenders.values()
        ]
        if len(fragments) == 1:
            windows_phrase, verb, elargir = f"la fenêtre horaire {fragments[0]}", "exclut", "cette fenêtre"
        else:
            windows_phrase, verb, elargir = "les fenêtres horaires " + ", ".join(fragments), "excluent", "ces fenêtres"
        if usable == 0:
            message = (
                f"Le groupe {names} ne peut placer {sessions} : {windows_phrase} {verb} chaque case où "
                f"réunir ses équipes. Élargissez {elargir} ou ajoutez un créneau commun compatible."
            )
        else:
            message = (
                f"Le groupe {names} : seulement {usable} case(s) commune(s) échappe(nt) à {windows_phrase}, "
                f"pour {sessions} à placer. Élargissez {elargir} ou ajoutez un créneau commun compatible."
            )
        causes = [
            {"kind": "time_window", "constraintId": constraint_id, "label": label, "count": count}
            for constraint_id, (_team_id, label, count) in offenders.items()
        ]
        diagnostics.append(
            {
                "id": f"shared-block-timewindow-{_get(block, 'id', default=index)}",
                "type": "shared_block_not_honored",
                "severity": "ERROR",
                "message": message,
                "causes": causes,
                "suggestions": [
                    "Élargissez la fenêtre horaire en cause, ou ajoutez un créneau commun compatible.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
    return diagnostics


def _diagnose_blocks_without_union_coach(
    model_data: Mapping[str, Any] | Any,
    valid_blocks: list[tuple[int, Any, list[str], set[str]]],
    team_names: Mapping[str, str],
    team_coach_map: Mapping[str, Any],
    coach_unavailability: Mapping[str, Any],
    coach_unavailability_sources: Mapping[str, Any],
) -> list[dict[str, Any]]:
    """PROUVE, à l'échec, qu'un bloc est infaisable parce qu'AUCUN (ou trop peu de) créneau n'a de
    coach de l'union disponible — et NOMME le groupe + les indisponibilités en cause.

    Miroir exact de la pose « un coach suffit » (``closures._close_coachless_block_cases`` via le
    helper UNIQUE ``constraints.availability.case_has_available_coach``) : une case sert le bloc dès
    qu'UN coach de l'union de ses membres y est libre. On ne tire la sonnette que lorsque les cases
    servables sont STRICTEMENT moins nombreuses que les séances communes demandées ALORS QUE la
    grille en offre assez (``commonSessions <= total``) — sinon la pénurie est de PLACE (gymnase),
    déjà nommée par la boucle de sur-contrainte ci-dessus, pas de coach. Les blocs IMBRIQUÉS
    servables ont ≥ ``commonSessions`` cases à coach (leur union est plus riche) : ils ne sont pas
    accusés (non-régression).

    ``causes[]`` porte les contraintes d'indisponibilité des coachs de l'union (kind EXISTANT
    ``coach_unavailability`` — aucun bump de contrat), nommées pour que le backend compose leur
    ORIGINE (héritée de la saison / posée pour la période)."""
    cases = _block_candidate_cases(model_data)
    total = len(cases)
    if total == 0:
        return []  # Aucune case du tout : pénurie de place, la boucle de sur-contrainte l'a dite.

    rules: Mapping[str, Any] = coach_unavailability if isinstance(coach_unavailability, Mapping) else {}
    diagnostics: list[dict[str, Any]] = []
    for index, block, members, _member_set in valid_blocks:
        common_sessions = int(_get(block, "commonSessions", "common_sessions", default=0) or 0)
        if common_sessions <= 0:
            continue
        servable = sum(
            1 for day, start, end in cases if case_has_available_coach(members, day, start, rules, team_coach_map, end)
        )
        # Pénurie de coach PROUVÉE, et de coach SEULEMENT : assez de cases dans la grille
        # (``common_sessions <= total``), mais trop peu portent un coach de l'union.
        if not (servable < common_sessions <= total):
            continue

        causes = _coach_unavailability_causes(members, cases, team_coach_map, rules, coach_unavailability_sources)
        names = _named_list(members, team_names)
        if servable == 0:
            message = f"Groupe {names} : aucun créneau où au moins un coach du groupe est disponible."
        else:
            message = (
                f"Groupe {names} : seulement {servable} créneau(x) où au moins un coach du groupe est "
                f"disponible, pour {common_sessions} séance(s) commune(s) à placer."
            )
        diagnostics.append(
            {
                "id": f"shared-block-coachless-{_get(block, 'id', default=index)}",
                "type": "shared_block_not_honored",
                "severity": "ERROR",
                "message": message,
                "causes": causes,
                "suggestions": [
                    "Rendez au moins un coach du groupe disponible sur un créneau commun, "
                    "ou allégez l'indisponibilité en cause.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
    return diagnostics


def _coach_unavailability_causes(
    members: list[str],
    cases: list[tuple[int, int, int]],
    team_coach_map: Mapping[str, Any],
    rules: Mapping[str, Any],
    coach_unavailability_sources: Mapping[str, Any],
) -> list[dict[str, Any]]:
    """Les contraintes d'indisponibilité des coachs de l'UNION du bloc, chacune avec le COMPTE de
    cases candidates qu'elle ferme pour son coach. Ordre déterministe (union ordonnée de
    ``required_coaches_of``, sources dans l'ordre de pose), dédup par ``constraintId`` (first-wins —
    une contrainte citée une fois). Un coach sans source (dispo pleine, ou lien absent) n'est pas
    une cause ; une source qui ne ferme AUCUNE case candidate non plus (elle ne pèse pas ici)."""
    union = required_coaches_of(members, team_coach_map)
    causes: list[dict[str, Any]] = []
    seen: set[Any] = set()
    for coach_id in union:
        for source in coach_unavailability_sources.get(str(coach_id)) or []:
            constraint_id = source.get("constraint_id")
            if constraint_id in seen:
                continue
            intervals = source.get("intervals") or ()
            closed = sum(
                1
                for day, start, end in cases
                if any(
                    iv_day == day and interval_bites(iv_from, iv_to, start, end) for iv_day, iv_from, iv_to in intervals
                )
            )
            if closed == 0:
                continue
            seen.add(constraint_id)
            causes.append(
                {
                    "kind": "coach_unavailability",
                    "constraintId": constraint_id,
                    "label": source.get("label"),
                    "count": closed,
                }
            )
    # Repli : l'union est bloquée mais AUCUNE source nommée (indispo portée par la whitelist
    # ``availableDays`` d'une contrainte sans libellé, ou fixture legacy) — on cite tout de même le
    # kind, cause dégradée (``constraintId``/``label`` None), jamais un groupe accusé sans cause.
    if not causes:
        degraded = _coachless_case_count(cases, union, rules)
        causes.append({"kind": "coach_unavailability", "constraintId": None, "label": None, "count": degraded})
    return causes


def _coachless_case_count(cases: list[tuple[int, int, int]], union: list[str], rules: Mapping[str, Any]) -> int:
    """Nombre de cases candidates où AUCUN coach de l'union n'est disponible (le poids de la cause
    dégradée quand aucune source nommée ne porte l'indisponibilité)."""
    return sum(
        1 for day, start, end in cases if not any(_coach_available_during(c, day, start, end, rules) for c in union)
    )


def _diagnose_sacrificed_rules(
    model_data: Mapping[str, Any] | Any,
    sacrificed_source_keys: frozenset[str],
) -> list[dict[str, Any]]:
    """Réservation souveraine (besoin fondateur 2026-10-30, amendement ADR-0001) — NOMME chaque groupe
    que le best-effort a privé de sa mutualisation parce qu'un VERROU d'une autre équipe occupe sa
    case : le groupe, l'équipe qui réserve, le jour et l'heure. Le planning est PRODUIT, le verrou
    tenu, l'écart signalé en ``rule_sacrificed`` (severity WARNING, jamais une erreur bloquante)."""
    if not sacrificed_source_keys:
        return []

    team_names = _team_name_map(model_data)
    venue_names = _venue_name_map(model_data)
    concrete_cases = _concrete_candidate_cases(model_data)
    pinned_teams_by_case: dict[tuple[str, str, str], set[str]] = defaultdict(set)
    for pin in _collection(model_data, "slotTemplates", "slot_templates"):
        if _get(pin, "lockLevel", "lock_level", default=None) != "HARD":
            continue
        case = (
            str(_get(pin, "venueId", "venue_id", default="")),
            str(_get(pin, "dayOfWeek", "day_of_week", default="")),
            str(_get(pin, "startTime", "start_time", default=""))[:5],
        )
        pinned_teams_by_case[case].add(str(_get(pin, "teamId", "team_id", default="")))

    diagnostics: list[dict[str, Any]] = []
    for index, block in enumerate(_collection(model_data, "sharedBlocks", "shared_blocks")):
        members = [str(t) for t in (_get(block, "teamIds", "team_ids", default=[]) or [])]
        block_id = str(_get(block, "id", default=index) or index)
        key = f"shared_block:{block_id}"
        if len(members) < 2 or key not in sacrificed_source_keys:
            continue
        member_set = set(members)
        blocked = [
            (venue_id, day, start_hhmm[:5], frozenset(pins))
            for venue_id, day, start_hhmm, _start_min, _duration in concrete_cases
            if (pins := pinned_teams_by_case.get((venue_id, str(day), start_hhmm[:5]))) and not (pins & member_set)
        ]
        names = _named_list(members, team_names)
        sessions = _sessions_phrase(int(_get(block, "commonSessions", "common_sessions", default=0) or 0))
        by_case = [
            f"{_day_label(day)} {start}, {_label(venue_id, venue_names)}, séance fixée de {_named_list(sorted(pins), team_names)}"
            for venue_id, day, start, pins in sorted(blocked, key=lambda case: (case[1], case[2], case[0]))[:3]
        ]
        detail = f" ({' ; '.join(by_case)})" if by_case else ""
        diagnostic: dict[str, Any] = {
            "id": f"rule-sacrificed-{key}",
            "type": "rule_sacrificed",
            "severity": "WARNING",
            "message": (
                f"Le groupe {names} ne peut pas tenir {sessions} : une séance fixée occupe la case où "
                f"réunir ses équipes{detail}. La séance fixée est tenue ; la mutualisation du groupe est relâchée."
            ),
            "causes": [{"kind": "hard_lock", "constraintId": None, "label": None, "count": max(1, len(blocked))}],
            "suggestions": [
                "Libérez la case ou déplacez la séance fixée si vous voulez que le groupe se réunisse.",
            ],
            "createdAt": datetime.now(UTC).isoformat(),
        }
        if len(blocked) == 1:
            diagnostic.update({"venueId": blocked[0][0], "dayOfWeek": blocked[0][1], "startTime": blocked[0][2]})
        diagnostics.append(diagnostic)
    return diagnostics
