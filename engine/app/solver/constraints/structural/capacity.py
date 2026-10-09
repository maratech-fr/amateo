"""Contraintes structurelles — « au plus une équipe par case de gymnase » (sous-paquet P4-295 C5).

Extrait verbatim de l'ancien ``constraints/structural.py`` (découpe pure). N'importe QUE les
externes ``...model`` / ``..common`` ; aucune arête vers un autre sous-module. L'orchestrateur
``add_level_1_hard_constraints`` du paquet ``constraints/__init__`` consomme via les ré-exports."""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Sequence
from typing import Any

from ...model import SLOT_MINUTES, _format_time, _hard_locks_by_case
from ..common import (
    AssignmentVariable,
    BoolVarLike,
    _assignment_day_start,
    _assignment_time_key,
    _dedupe_variables,
    _locked_venue_substart_counts,
    _record_closure,
)


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
            max_locked_raw = 0  # occupation SANS le dé-compte des verrous partenaires.
            minute = start_min
            while minute < end_min:
                raw = locked_counts.get((str(venue_id), day, minute), 0)
                occupied = raw
                if partner_lock_ends:
                    occupied -= sum(1 for lock_end in partner_lock_ends if minute < lock_end)
                if occupied > max_locked:
                    max_locked = occupied
                if raw > max_locked_raw:
                    max_locked_raw = raw
                minute += SLOT_MINUTES
            if max_locked >= cap:
                model.Add(assignment.var == 0)
                added += 1
                # P4-99 — un verrou (d'une autre équipe) sature la capacité du gymnase sur ce
                # sous-créneau : la vraie cause de ce candidat fermé est un verrou.
                _record_closure(model, assignment.var, {"kind": "hard_lock"})
            elif partner_lock_ends and max_locked_raw >= cap:
                # P2-51 (comblement) — ce candidat LIBRE ne survit QUE grâce au dé-compte des verrous
                # partenaires (sans dé-compte la case serait saturée). Le liage ``x >= b`` étant
                # unidirectionnel, ``b = 0`` n'interdit pas ``x = 1`` : rien n'empêcherait sinon le
                # membre libre de rejoindre l'épingle du partenaire HORS de toute séance de bloc active
                # (double-comptage sur la solution du solveur, ``shared_block_not_honored`` post-solve).
                # On borne ``x <= Σb`` des blocs de CETTE case qui contiennent l'équipe : rejoindre
                # l'épingle d'un partenaire n'est permis QU'au titre d'une séance de bloc ACTIVE.
                # Limite connue (porte générique préexistante, non touchée) : sur une case à capacité
                # ≥ 2 réellement ouverte, deux membres peuvent toujours co-siéger hors bloc.
                case_bvars = getattr(model, "shared_block_case_bvars", None) or {}
                slot_id = f"{day}:{_format_time(start_min)}"
                team_str = str(assignment.team_id)
                block_bvars = [b for members, b in case_bvars.get((str(venue_id), slot_id), ()) if team_str in members]
                if block_bvars:
                    model.Add(assignment.var <= sum(block_bvars))
                    added += 1
    return added
