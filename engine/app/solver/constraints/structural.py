"""Structural hard constraints: venue/coach/player/team no-overlap, fixed/forbidden slots, min-sessions.

Imports ``..`` externals and ``.common``. The ``add_level_1_hard_constraints``
orchestrator that drives these lives in the package ``__init__`` (a test-seam
constraint — see its docstring), not here; it reaches these posers through the
package re-exports."""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Iterable, Mapping, Sequence
from typing import Any

from ..model import SLOT_MINUTES, _format_time, _hard_locks_by_case
from .common import (
    AssignmentVariable,
    BoolVarLike,
    RuleCollection,
    _assignment_day_start,
    _assignment_time_key,
    _dedupe_variables,
    _extract_interval,
    _get,
    _intervals_overlap,
    _locked_person_day_occupations,
    _locked_venue_substart_counts,
    _record_closure,
    _scalar_id,
)

# Exemption coach-joueur sur la SÉANCE DE BLOC — carte ``(venue_id, slot_id)`` (slot_id ==
# "day:HH:MM") → ``[(frozenset(membres du bloc), b)]``, remplie par ``add_shared_block_constraints``
# et portée par le modèle (``ScheduleCpModel.shared_block_case_bvars``). ``None`` (modèle nu des
# tests de pose) ou vide (aucun bloc) ⇒ aucune exemption, borne stricte partout (byte-identique).
CaseBvars = Mapping[tuple[str, str], list[tuple[frozenset[str], BoolVarLike]]]


def _block_case_exemption_bvars(
    case_bvars: CaseBvars | None,
    team_a: str | None,
    team_b: str | None,
    venue: str | None,
    slot_id: str,
) -> list[BoolVarLike]:
    """Les ``b`` des blocs qui, sur la case ``(venue, slot_id)``, réunissent À LA FOIS ``team_a`` et
    ``team_b``. L'appelant relâche alors l'anti-chevauchement de la paire en ``≤ 1 + Σb`` : la borne
    s'efface QUAND la séance de bloc est active (b=1), et RESTE stricte sinon (b=0). La garde de
    distinctness ``Σb ≤ 1`` par membre-et-case (``add_shared_block_constraints``) borne cette somme à
    1 pour une paire donnée. Carte absente/vide, gymnase inconnu (None) ou paire hors de tout bloc de
    la case ⇒ liste vide ⇒ borne stricte inchangée."""
    if not case_bvars or team_a is None or team_b is None or venue is None:
        return []
    pair = {team_a, team_b}
    return [b for members, b in case_bvars.get((venue, slot_id), ()) if pair <= members]


def add_room_at_most_one(model: Any, assignments: Sequence[AssignmentVariable]) -> int:
    """Constraint 1: one room/venue can host at most capacity teams per time slot."""

    slot_capacities: dict[Any, int] = getattr(model, "slot_capacities", {})
    # P2-51 — dé-comptage des séances de BLOC : ``(venue_id, slot_id)`` → ``[(b_var, n_free-1)]``.
    # Une séance de bloc réunit ``n_free`` membres LIBRES sur la case mais n'y occupe qu'UNE place ;
    # on retranche ``(n_free-1)·b`` de la somme pour que la co-présence tienne en capacité 1. Bloc
    # absent (ou modèle nu des tests de pose) ⇒ carte vide ⇒ contrainte byte-identique (goldens).
    room_relief: dict[Any, list[tuple[Any, int]]] = getattr(model, "shared_block_room_relief", None) or {}
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
        relief = room_relief.get((venue_id, time_key))
        if relief:
            model.Add(sum(deduped) - sum(coef * b for b, coef in relief) <= cap)
        else:
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
        # P2-51 (comblement) — verrous par case + partenaires de bloc : un partenaire ÉPINGLÉ sur
        # une case accueille le membre LIBRE du MÊME bloc en UNE occupation ; on dé-compte SON
        # verrou du balayage pour ne pas fermer le candidat. Cartes vides sans ``sharedBlocks`` ⇒
        # aucun dé-compte, chemin byte-identique. La borne du ``room_relief`` ci-dessus ne couvre
        # que le regroupement par début EXACT ; ce dé-compte-ci vise les verrous chevauchants.
        block_partners: dict[str, set[str]] = getattr(model, "block_partners", None) or {}
        locks_by_case = _hard_locks_by_case(getattr(model, "locked_slots", ()) or ()) if block_partners else {}
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
            # Verrous de PARTENAIRES de bloc épinglés sur CETTE case exacte (même début) : leurs
            # fins de séance, à dé-compter du balayage (à eux SEULS — un verrou non-partenaire ou à
            # un autre début compte plein).
            partner_lock_ends: list[int] = []
            team_partners = block_partners.get(str(assignment.team_id)) if block_partners else None
            if team_partners:
                partner_lock_ends = [
                    lock_end
                    for locked_team, lock_end in locks_by_case.get((str(venue_id), day, _format_time(start_min)), ())
                    if locked_team in team_partners
                ]
            max_locked = 0
            minute = start_min
            while minute < end_min:
                occupied = locked_counts.get((str(venue_id), day, minute), 0)
                if partner_lock_ends:
                    occupied -= sum(1 for lock_end in partner_lock_ends if minute < lock_end)
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


def _add_coach_player_time_key_pairs(
    model: Any,
    coach_groups: dict[tuple[Any, Any], list[tuple[BoolVarLike, str | None, str | None]]],
    player_groups: dict[tuple[Any, Any], list[tuple[BoolVarLike, str | None, str | None]]],
    case_bvars: CaseBvars,
) -> int:
    """Version PAIRE À PAIRE de l'at-most-one clé-temps de la contrainte 3, avec exemption bloc.

    N'est empruntée QUE lorsqu'un bloc existe (``case_bvars`` non vide). La clé de groupe reste
    ``(personne, slot_id)`` SANS gymnase (le porter dans la clé serait le piège documenté de
    ``_add_cross_venue_at_most_one``) ; ``slot_id`` == le ``time_key`` de la clé (== "day:HH:MM").
    Chaque paire de la MÊME case (même gymnase non-None, même slot_id) dont les deux équipes
    partagent un bloc de la case voit sa borne passer à ``≤ 1 + Σb`` (elle s'efface quand la séance
    de bloc est active) ; toute autre paire garde ``≤ 1`` — équivalent strict de l'at-most-one."""
    added = 0
    for key in coach_groups.keys() & player_groups.keys():
        _person, time_key = key
        entries = _dedupe_meta_entries(coach_groups[key] + player_groups[key])
        for i in range(len(entries)):
            var_a, venue_a, team_a = entries[i]
            for j in range(i + 1, len(entries)):
                var_b, venue_b, team_b = entries[j]
                if var_a is var_b:
                    continue
                bs = (
                    _block_case_exemption_bvars(case_bvars, team_a, team_b, venue_a, str(time_key))
                    if venue_a is not None and venue_a == venue_b
                    else []
                )
                if bs:
                    model.Add(var_a + var_b <= 1 + sum(bs))
                else:
                    model.Add(var_a + var_b <= 1)
                added += 1
    return added


def _dedupe_meta_entries(
    entries: list[tuple[BoolVarLike, str | None, str | None]],
) -> list[tuple[BoolVarLike, str | None, str | None]]:
    """Comme ``_dedupe_variables`` mais sur des entrées ``(var, gymnase, équipe)`` : garde la première
    occurrence de chaque variable (une personne coach ET joueuse de la MÊME séance y apparaît deux
    fois)."""
    seen: set[Any] = set()
    unique: list[tuple[BoolVarLike, str | None, str | None]] = []
    for entry in entries:
        var = entry[0]
        marker = var.Index() if hasattr(var, "Index") else id(var)
        if marker in seen:
            continue
        seen.add(marker)
        unique.append(entry)
    return unique


def _add_free_vs_locked_interval_conflicts(
    model: Any,
    free_entries: dict[str, list[tuple[int, int, BoolVarLike, str, str | None, str, str | None]]],
    locked_occupations: dict[str, dict[int, list[tuple[int, int, str | None, str, str]]]],
    *,
    case_bvars: CaseBvars | None = None,
) -> int:
    """Force à 0 tout créneau LIBRE d'une personne qui chevauche une de ses occupations
    VERROUILLÉES, sous l'exemption D-14 et l'exemption SÉANCE DE BLOC (P4-97 bis).

    ``free_entries`` : ``person -> [(start, end, var, day, venue, role, team)]`` (le ``day`` est une
    chaîne, comme le produit ``_extract_interval``). ``locked_occupations`` :
    ``person -> weekday(int) -> [(start, end, venue, role, team)]`` (cf.
    ``_locked_person_day_occupations``).

    D-14 (arbitrage fondateur) : deux occupations **coach-coach dans le MÊME gymnase** ne
    s'opposent pas (le coach surveille deux groupes, présent une fois) ; tout le reste —
    gymnases différents, ou l'un des deux rôles ``player`` — est une impossibilité physique.
    Le verrou est souverain : on ne touche QUE le créneau libre, jamais le verrou.

    Exemption bloc : si le verrou et le créneau libre sont la MÊME case (même gymnase + même début)
    et que leurs deux équipes partagent un bloc de la case, la séance libre tient QUAND la séance de
    bloc est active — ``var ≤ Σb`` remplace ``var == 0``. Ce chemin réifié ne pose AUCUNE fermeture
    (le rail P4-99 ne porte que les fermetures inconditionnelles) ; le chemin inconditionnel garde
    sa cause ``hard_lock``.
    """
    added = 0
    for person, entries in free_entries.items():
        locked_days = locked_occupations.get(person)
        if not locked_days:
            continue
        for start, end, var, day, venue, role, team in entries:
            try:
                day_int = int(day)
            except (TypeError, ValueError):
                continue
            for l_start, l_end, l_venue, l_role, l_team in locked_days.get(day_int, ()):
                if not _intervals_overlap(start, end, l_start, l_end):
                    continue
                both_coaching = role == "coach" and l_role == "coach"
                if both_coaching and venue is not None and venue == l_venue:
                    continue
                bs = (
                    _block_case_exemption_bvars(case_bvars, team, l_team, venue, f"{day_int}:{_format_time(start)}")
                    if venue is not None and venue == l_venue and start == l_start
                    else []
                )
                if bs:
                    # Case de bloc RÉIFIÉE : la séance libre tient si la séance de bloc est active.
                    # PAS de fermeture ici (le rail P4-99 ne porte que l'inconditionnel).
                    model.Add(var <= sum(bs))
                    added += 1
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
    person_entries: dict[str, list[tuple[int, int, BoolVarLike, str, str | None, str, str | None]]],
    *,
    same_venue_allowed: bool = False,
    case_bvars: CaseBvars | None = None,
) -> int:
    """Add pairwise ``varA + varB <= 1`` for overlapping intervals per person per day.

    Args:
        model: CP-SAT model.
        person_entries: ``dict[person_id, list[(start, end, var, day, venue, role, team)]]`` où
            ``role`` vaut ``"coach"`` ou ``"player"``.
        same_venue_allowed: quand True, deux intervalles qui se chevauchent **dans le même
            gymnase** ne sont PAS opposés — mais UNIQUEMENT si les deux entrées sont des
            rôles ``"coach"``. Voir D-14 ci-dessous.
        case_bvars: carte des séances de bloc par case. Quand deux intervalles partagent la MÊME
            case (même gymnase + même début) et que leurs équipes partagent un bloc de la case, la
            borne passe à ``≤ 1 + Σb`` : elle s'efface quand la séance de bloc est active. ``None`` /
            vide (chemin coach de la contrainte 2, ou aucun bloc) ⇒ borne stricte, byte-identique.

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
        by_day: dict[str, list[tuple[int, int, BoolVarLike, str | None, str, str | None]]] = defaultdict(list)
        for start, end, var, day, venue, role, team in entries:
            by_day[day].append((start, end, var, venue, role, team))

        for day, day_entries in by_day.items():
            for i in range(len(day_entries)):
                a_start, a_end, var_a, a_venue, a_role, a_team = day_entries[i]
                for j in range(i + 1, len(day_entries)):
                    b_start, b_end, var_b, b_venue, b_role, b_team = day_entries[j]
                    if var_a is var_b:
                        continue
                    both_coaching = a_role == "coach" and b_role == "coach"
                    if same_venue_allowed and both_coaching and a_venue is not None and a_venue == b_venue:
                        continue
                    if not _intervals_overlap(a_start, a_end, b_start, b_end):
                        continue
                    bs = (
                        _block_case_exemption_bvars(
                            case_bvars, a_team, b_team, a_venue, f"{day}:{_format_time(a_start)}"
                        )
                        if a_venue is not None and a_venue == b_venue and a_start == b_start
                        else []
                    )
                    if bs:
                        model.Add(var_a + var_b <= 1 + sum(bs))
                    else:
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
