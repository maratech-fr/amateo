"""Diagnostics des conflits dus aux VERROUS (structurels + verrou SOFT déplacé).

Extrait verbatim de l'ancien ``diagnostics.py`` (découpe pure, P4-295 C1).
"""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Mapping
from datetime import UTC, datetime
from typing import Any

from ...model import (
    DEFAULT_SESSION_MINUTES,
    _format_time,
    _time_to_minutes,
)
from ..helpers import (
    _coach_name_map,
    _day_label,
    _get,
    _label,
    _slot_day,
    _slot_templates,
    _team_name_map,
    _time_range,
    _venue_name_map,
)


def _diagnose_locked_structural_conflicts(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
    team_coach_map: Mapping[str, list[str]],
    team_player_map: Mapping[str, list[str]],
) -> list[dict[str, Any]]:
    """Diagnostique les conflits STRUCTURELS que des VERROUS SEULS provoquent (P4-97 bis).

    Un créneau verrouillé est souverain : quand deux verrous se contredisent (choix du
    gestionnaire), la génération sort quand même — ``completed`` — mais le silence doit cesser.
    La pose (``constraints.py``) empêche désormais tout créneau LIBRE d'entrer en conflit avec un
    verrou ; ne restent donc que les conflits ENTRE verrous, impossibles à résoudre sans lever un
    verrou. On n'émet un diagnostic que si AU MOINS un créneau HARD est impliqué.

    Deux familles, aux personnes lues des CARTES (``team_coach_map``/``team_player_map``, jamais
    ``slot.coachId``) :
      * une personne dans DEUX gymnases à la même minute (impossible physiquement — le même
        gymnase reste permis pour deux rôles coach, D-14) ;
      * une équipe avec DEUX séances le même jour.
    Texte français nommant équipes/personnes/gymnases/horaires réels ; aucun identifiant interne.
    """
    diagnostics: list[dict[str, Any]] = []
    team_names = _team_name_map(model_data)
    venue_names = _venue_name_map(model_data)
    coach_names = _coach_name_map(model_data)

    # --- Personne dans deux gymnases à la fois ------------------------------------------
    # person -> [(start, end, day, venue, team, role, is_hard)]
    person_slots: dict[str, list[tuple[int, int, int, str, str, str, bool]]] = defaultdict(list)
    for slot in slots:
        day = _slot_day(slot)
        if day is None:
            continue
        team_id = str(slot["teamId"])
        start = _time_to_minutes(str(slot["startTime"]))
        end = start + int(slot.get("durationMinutes") or 0)
        venue = str(slot["venueId"])
        is_hard = str(slot.get("lockLevel") or "NONE").upper() == "HARD"
        roles: dict[str, str] = {}
        for coach_id in team_coach_map.get(team_id, []):
            roles.setdefault(str(coach_id), "coach")
        for player_id in team_player_map.get(team_id, []):
            roles[str(player_id)] = "player"
        for person, role in roles.items():
            person_slots[person].append((start, end, day, venue, team_id, role, is_hard))

    seen_person: set[tuple[str, int, str, str]] = set()
    for person, entries in sorted(person_slots.items()):
        ordered = sorted(entries)
        for i in range(len(ordered)):
            a_start, a_end, a_day, a_venue, a_team, a_role, a_hard = ordered[i]
            for j in range(i + 1, len(ordered)):
                b_start, b_end, b_day, b_venue, b_team, b_role, b_hard = ordered[j]
                if a_day != b_day or not (a_start < b_end and b_start < a_end):
                    continue
                if a_venue == b_venue and a_role == "coach" and b_role == "coach":
                    continue  # deux groupes surveillés dans le même gymnase (D-14)
                if a_venue == b_venue:
                    continue  # même gymnase : c'est le conflit d'ÉQUIPE, traité plus bas
                if not (a_hard or b_hard):
                    continue  # la pose empêche déjà les conflits impliquant un créneau libre
                teams_key = tuple(sorted((a_team, b_team)))
                key = (person, a_day, teams_key[0], teams_key[1])
                if key in seen_person:
                    continue
                seen_person.add(key)
                when = f"{_day_label(a_day)} {_time_range(_format_time(a_start), a_end - a_start)}"
                diagnostics.append(
                    {
                        "id": f"diag-locked-person-{person}-{a_day}-{a_start}",
                        "type": "conflict",
                        "severity": "ERROR",
                        "coachId": person,
                        "dayOfWeek": a_day,
                        # P4-95 lot 8 — l'INSTANT cliquable = le début du CHEVAUCHEMENT
                        # (``max(a_start, b_start)``), jamais ``a_start``. La sémantique front est
                        # « toute séance de cette personne ce jour dont l'intervalle CONTIENT cet
                        # instant » ; avec des débuts décalés (A 18:00, B 19:00) ``a_start`` = 18:00
                        # n'appartient PAS à B → une seule case matcherait, que le front ouvrirait,
                        # en violation de « deux gymnases : on surligne les deux, on n'ouvre rien ».
                        # ``max`` est contenu dans les deux par définition du chevauchement. L'``id``
                        # (qui porte ``a_start``) ne change pas : il est stable.
                        "startTime": _format_time(max(a_start, b_start)),
                        "message": (
                            f"{_label(person, coach_names)} est réservé(e) dans deux gymnases en même temps "
                            f"le {when} — {_label(a_venue, venue_names)} avec {_label(a_team, team_names)} et "
                            f"{_label(b_venue, venue_names)} avec {_label(b_team, team_names)} : "
                            "un créneau verrouillé prime, mais la personne ne peut pas être aux deux endroits."
                        ),
                        "suggestions": [
                            "Déplacez ou retirez l'une des réservations verrouillées de cette personne.",
                        ],
                        "createdAt": datetime.now(UTC).isoformat(),
                    }
                )

    # --- Équipe avec deux séances le même jour ------------------------------------------
    team_day_slots: dict[tuple[str, int], list[tuple[int, str, bool]]] = defaultdict(list)
    for slot in slots:
        day = _slot_day(slot)
        if day is None:
            continue
        team_id = str(slot["teamId"])
        start = _time_to_minutes(str(slot["startTime"]))
        is_hard = str(slot.get("lockLevel") or "NONE").upper() == "HARD"
        team_day_slots[(team_id, day)].append((start, str(slot["startTime"])[:5], is_hard))

    for (team_id, day), day_slots in sorted(team_day_slots.items()):
        if len(day_slots) < 2 or not any(is_hard for _s, _t, is_hard in day_slots):
            continue
        times = ", ".join(t for _s, t, _h in sorted(day_slots))
        diagnostics.append(
            {
                "id": f"diag-locked-team-day-{team_id}-{day}",
                "type": "conflict",
                "severity": "ERROR",
                "teamId": team_id,
                "dayOfWeek": day,
                "message": (
                    f"{_label(team_id, team_names)} a {len(day_slots)} séances le même jour "
                    f"({_day_label(day)} à {times}) alors qu'une seule par jour est permise : "
                    "un créneau verrouillé prime, mais deux séances le même jour restent à arbitrer."
                ),
                "suggestions": [
                    "Déplacez ou retirez l'une des réservations verrouillées de cette équipe ce jour-là.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )

    return diagnostics


def _diagnose_soft_lock_moved(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Warn when a SOFT locked template did not survive in the solution."""
    diagnostics: list[dict[str, Any]] = []
    for template in _slot_templates(model_data):
        lock_level = str(_get(template, "lock_level", "lockLevel", default="")).upper()
        if lock_level != "SOFT":
            continue

        team_id = str(_get(template, "team_id", "teamId"))
        venue_id = str(_get(template, "venue_id", "venueId"))
        day_of_week = int(_get(template, "day_of_week", "dayOfWeek"))
        start_time = str(_get(template, "start_time", "startTime"))
        duration = int(_get(template, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))

        found = any(
            slot["teamId"] == team_id
            and slot["venueId"] == venue_id
            and slot["dayOfWeek"] == day_of_week
            and slot["startTime"] == start_time
            for slot in slots
        )
        if not found:
            diagnostics.append(
                {
                    "id": f"diag-soft-moved-{team_id}-{day_of_week}-{start_time}",
                    "type": "soft_lock_moved",
                    "severity": "WARNING",
                    "teamId": team_id,
                    "venueId": venue_id,
                    "dayOfWeek": day_of_week,
                    # Heure tronquée à HH:MM : la colonne backend ``startTime`` n'accepte que 5
                    # caractères (le payload transmet parfois HH:MM:SS) — même troncature que les
                    # autres foyers de ce fichier. L'``id`` garde, lui, ``start_time`` brut : il est
                    # stable et sert ailleurs.
                    "startTime": str(start_time)[:5],
                    "durationMinutes": duration,
                    # Copie possédée par le backend : DiagnosticMessageBuilder reconstruit
                    # inconditionnellement le texte FR de ``soft_lock_moved`` et lui ajoute le jour
                    # + la plage horaire depuis les champs structurés ci-dessus. Émettre un message
                    # ici serait du texte anglais mort, jamais lu — on envoie donc une chaîne vide,
                    # suggestions comprises (jamais affichées : seules celles d'un solve en échec le
                    # sont).
                    "message": "",
                    "suggestions": [],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )
    return diagnostics
