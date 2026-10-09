"""Contraintes structurelles — non-chevauchement coach / coach-joueur / équipe (sous-paquet P4-295 C5).

Extrait verbatim de l'ancien ``constraints/structural.py`` (découpe pure). SEULE arête interne du
sous-paquet : importe les briques « au plus un » de ``.pairing``. S'appuie par ailleurs sur les
externes ``..common``. L'orchestrateur ``add_level_1_hard_constraints`` du paquet
``constraints/__init__`` consomme via les ré-exports."""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Sequence
from typing import Any

from ..common import (
    AssignmentVariable,
    BoolVarLike,
    _assignment_time_key,
    _extract_interval,
    _locked_person_day_occupations,
)
from .pairing import (
    CaseBvars,
    _add_at_most_one_groups,
    _add_coach_player_time_key_pairs,
    _add_cross_venue_at_most_one,
    _add_free_vs_locked_interval_conflicts,
    _add_interval_at_most_one,
)


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
    person_entries: dict[str, list[tuple[int, int, BoolVarLike, str, str | None, str, str | None]]] = defaultdict(list)

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
                person_entries[str(coach_id)].append((start, end, var, day, venue_id, "coach", team_id_str))

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

    ⚑ Exemption SÉANCE DE BLOC — sur une case où une séance de bloc est ACTIVE, les deux équipes
    s'entraînent PHYSIQUEMENT ENSEMBLE en UNE séance : une personne qui coache l'une et joue dans
    l'autre n'y tient qu'un rôle à la fois du point de vue du planning. La borne d'anti-chevauchement
    de la paire passe donc de ``≤ 1`` à ``≤ 1 + Σb`` (``b`` = variable de séance du bloc de la case,
    lue dans ``model.shared_block_case_bvars``) — elle s'efface QUAND ``b = 1`` et RESTE stricte
    sinon. C'est plus strict que la tolérance coach-coach D-14 : l'exemption exige la MÊME case
    (même gymnase + même heure de DÉBUT) ET une séance de bloc active — une simple coïncidence solo
    (b=0), un chevauchement à débuts différents ou un autre gymnase restent des conflits. Aucun bloc
    ⇒ ``case_bvars`` vide ⇒ borne stricte partout, chemin byte-identique.
    """

    case_bvars: CaseBvars | None = getattr(model, "shared_block_case_bvars", None)

    # Groupes clé-temps : chaque entrée porte ``(var, gymnase, équipe)`` pour permettre l'exemption
    # PAIRE À PAIRE. Le gymnase reste HORS de la clé (piège ``_add_cross_venue_at_most_one``).
    coach_groups: dict[tuple[Any, Any], list[tuple[BoolVarLike, str | None, str | None]]] = defaultdict(list)
    player_groups: dict[tuple[Any, Any], list[tuple[BoolVarLike, str | None, str | None]]] = defaultdict(list)
    person_entries: dict[str, list[tuple[int, int, BoolVarLike, str, str | None, str, str | None]]] = defaultdict(list)

    for assignment in assignments:
        time_key = _assignment_time_key(assignment)
        if time_key is None:
            continue

        team_id = assignment.team_id
        team_id_str = str(team_id) if team_id is not None else None
        var = assignment.var
        venue_id = str(assignment.venue_id) if assignment.venue_id is not None else None

        # D-14 : le RÔLE est retenu, pas seulement la personne. Une même personne peut être
        # coach ici et joueuse là ; seule la paire coach-coach tolère le même gymnase, et
        # `player` l'emporte quand les deux s'appliquent (on ne joue pas en coachant).
        person_roles: dict[str, str] = {}
        all_person_ids: set[str] = set()

        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            for coach_id in team_coach_map[team_id_str]:
                coach_groups[(coach_id, time_key)].append((var, venue_id, team_id_str))
                all_person_ids.add(str(coach_id))
                person_roles.setdefault(str(coach_id), "coach")
        else:
            single_coach = assignment.coach_id
            if single_coach is not None:
                coach_groups[(single_coach, time_key)].append((var, venue_id, team_id_str))
                all_person_ids.add(str(single_coach))
                person_roles.setdefault(str(single_coach), "coach")

        if team_player_map is not None and team_id_str is not None and team_id_str in team_player_map:
            for player_id in team_player_map[team_id_str]:
                player_groups[(player_id, time_key)].append((var, venue_id, team_id_str))
                all_person_ids.add(str(player_id))
                person_roles[str(player_id)] = "player"
        else:
            for player_id in assignment.player_ids:
                player_groups[(player_id, time_key)].append((var, venue_id, team_id_str))
                all_person_ids.add(str(player_id))
                person_roles[str(player_id)] = "player"

        start, end, day = _extract_interval(assignment)
        if start is not None and end is not None and day is not None:
            for person_id in all_person_ids:
                person_entries[person_id].append(
                    (start, end, var, day, venue_id, person_roles.get(person_id, "player"), team_id_str)
                )

    if case_bvars:
        # Un bloc existe : chemin PAIRE À PAIRE avec exemption (équivalent at-most-one, sauf la paire
        # exemptable de la case).
        time_key_added = _add_coach_player_time_key_pairs(model, coach_groups, player_groups, case_bvars)
    else:
        # Aucun bloc : chemin d'origine STRICTEMENT inchangé (``add_at_most_one`` sur l'union des
        # variables) — garantie byte-identique, goldens intacts.
        overlap_groups = (
            [entry[0] for entry in coach_groups[key]] + [entry[0] for entry in player_groups[key]]
            for key in coach_groups.keys() & player_groups.keys()
        )
        time_key_added = _add_at_most_one_groups(model, overlap_groups)
    # D-14 : le drapeau est levé ici AUSSI, mais il ne relâche que les paires coach-coach —
    # que la contrainte 2 possède déjà. Coach-joueur et joueur-joueur restent opposés, SAUF sur une
    # case de bloc active (``case_bvars``).
    interval_added = _add_interval_at_most_one(model, person_entries, same_venue_allowed=True, case_bvars=case_bvars)

    # P4-97 bis — le CAS RÉEL (BCCL) : « Mara » coache une équipe LIBRE pendant qu'elle JOUE
    # dans une équipe VERROUILLÉE au même moment dans un AUTRE gymnase. Le verrou occupe la
    # personne ; le placement libre incompatible est refusé (toutes combinaisons de rôles, avec
    # la seule exemption coach-coach même-gymnase de D-14). Source : les cartes, jamais slot.coachId.
    # ``case_bvars`` ajoute l'exemption bloc : un verrou de bloc réunit les deux équipes → la séance
    # libre tient (``var ≤ Σb``) au lieu d'être fermée.
    locked_occ = _locked_person_day_occupations(model, team_coach_map, team_player_map)
    locked_added = _add_free_vs_locked_interval_conflicts(model, person_entries, locked_occ, case_bvars=case_bvars)
    return time_key_added + interval_added + locked_added


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
