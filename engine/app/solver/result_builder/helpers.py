"""Result builder — lecteurs de champs, cartes de noms et libellés FR (paquet ENG-39).

Base du DAG du paquet ``result_builder`` : ce module ne dépend d'aucun autre sous-module.
Extrait tel quel de l'ancien monolithe ``result_builder.py`` (déplacement pur, ENG-39).
"""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

from ..model import _format_time, _time_to_minutes


def _slot_day(slot: Mapping[str, Any]) -> int | None:
    raw = slot.get("dayOfWeek")
    if raw is None:
        return None
    try:
        return int(raw)
    except (TypeError, ValueError):
        return None


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

_FR_DAYS = {
    1: "lundi",
    2: "mardi",
    3: "mercredi",
    4: "jeudi",
    5: "vendredi",
    6: "samedi",
    7: "dimanche",
}


def _day_label(day_of_week: Any) -> str:
    """Human day name (French). Falls back to 'jour N' for unknown values."""
    try:
        return _FR_DAYS.get(int(day_of_week), f"jour {int(day_of_week)}")
    except (TypeError, ValueError):
        return f"jour {day_of_week}"


def _time_range(start_time: str, duration_minutes: int | None) -> str:
    """Return 'HH:MM–HH:MM' from a start time and duration (start only if unknown)."""
    start = str(start_time)[:5]
    if not duration_minutes:
        return start
    try:
        end = _format_time(_time_to_minutes(start) + int(duration_minutes))
    except (TypeError, ValueError):
        return start
    return f"{start}–{end}"


def _team_name_map(model_data: Mapping[str, Any] | Any) -> dict[str, str]:
    names: dict[str, str] = {}
    for team in _collection(model_data, "teams"):
        team_id = _get(team, "id", "team_id", "teamId")
        if team_id is not None:
            names[str(team_id)] = str(_get(team, "name", "team_name", default=str(team_id)))
    return names


def _venue_name_map(model_data: Mapping[str, Any] | Any) -> dict[str, str]:
    names: dict[str, str] = {}
    for venue in _collection(model_data, "venues"):
        venue_id = _get(venue, "id", "venue_id", "venueId")
        if venue_id is not None:
            names[str(venue_id)] = str(_get(venue, "name", default=str(venue_id)))
    return names


def _coach_name_map(model_data: Mapping[str, Any] | Any) -> dict[str, str]:
    names: dict[str, str] = {}
    for coach in _collection(model_data, "coaches"):
        coach_id = _get(coach, "id", "coach_id", "coachId")
        if coach_id is None:
            continue
        full = _get(coach, "name", "coach_name", default=None)
        if full is None:
            first = _get(coach, "first_name", "firstName", default="")
            last = _get(coach, "last_name", "lastName", default="")
            full = f"{first} {last}".strip() or str(coach_id)
        names[str(coach_id)] = str(full)
    return names


def _label(entity_id: Any, names: Mapping[str, str]) -> str:
    """'Name' when known, else the raw id — never bare so the manager can act."""
    return names.get(str(entity_id), str(entity_id))


def _named_list(ids: list[str], names: Mapping[str, str]) -> str:
    return ", ".join(_label(i, names) for i in ids)


def _occupant_list(team_ids: list[str], team_to_group: Mapping[str, str], names: Mapping[str, str]) -> str:
    """Énumère les OCCUPANTS d'une case, un groupe mutualisé comptant pour un seul (P2-46).

    Miroir exact du comptage qui décide de la violation : les membres co-localisés d'un même
    groupe déclaré se fondent en une entrée « le groupe mutualisé (A, B) », les autres équipes
    restent nommées une à une. Un message qui énumère plus d'entrées que le compte annoncé
    ferait viser le mauvais remède.
    """
    parts: list[str] = []
    seen_groups: set[str] = set()
    for team_id in team_ids:
        group_key = team_to_group.get(team_id)
        if group_key is None:
            parts.append(_label(team_id, names))
            continue
        if group_key in seen_groups:
            continue
        seen_groups.add(group_key)
        members = [_label(other, names) for other in team_ids if team_to_group.get(other) == group_key]
        parts.append(f"le groupe mutualisé ({', '.join(members)})")
    return ", ".join(parts)


def _get(source: Mapping[str, Any] | Any, *names: str, default: Any = None) -> Any:
    """Read the first available field from a dict or object."""
    for name in names:
        if isinstance(source, Mapping):
            if name in source and source[name] is not None:
                return source[name]
            continue
        if hasattr(source, name):
            value = getattr(source, name)
            if value is not None:
                return value
    return default


def _team_ids(model_data: Mapping[str, Any] | Any) -> set[str]:
    """Return all team IDs found in the input data."""
    ids: set[str] = set()
    for team in _collection(model_data, "teams"):
        team_id = _get(team, "id", "team_id", "teamId")
        if team_id is not None:
            ids.add(str(team_id))
    return ids


def _slot_templates(model_data: Mapping[str, Any] | Any) -> list[Any]:
    """Return all slot templates from the input data."""
    return list(_collection(model_data, "slot_templates", "slotTemplates", "slots"))


def _collection(source: Mapping[str, Any] | Any, *names: str) -> list[Any]:
    """Extract a list-like collection from a dict or object by field name."""
    for name in names:
        values = _get(source, name, default=None)
        if values is None:
            continue
        if isinstance(values, (list, tuple)):
            return list(values)
        raise TypeError(f"{name} must be a list-like collection")
    return []


def _find_coach_for_team(
    model_data: Mapping[str, Any] | Any,
    team_id: str,
    team_coach_map: Mapping[str, list[str]] | None = None,
) -> str | None:
    """Qui encadre les séances GÉNÉRÉES de cette équipe ? (ENG-17)

    Le coach MAIN d'abord — c'est ce que le solveur a réellement modélisé
    (exclusivité coach, bonus de chaînage) et ce que `main.py` attache à chaque
    assignment. Avant ce correctif, seuls les `slotTemplates` étaient consultés :
    une équipe dont le coach vient d'une contrainte TEAM_COACH (le chemin
    DOMINANT — c'est ainsi que le backend sérialise les liens) sortait
    `coachId=None` sur toutes ses séances placées, et TOUS les diagnostics coach
    (double-réservation, surcharge, jour de repos) restaient muets pour elle.

    Le repli sur les `slotTemplates` reste : un payload sans contrainte
    TEAM_COACH (tests, legacy) garde son comportement d'avant.
    """
    if team_coach_map is not None:
        coaches = team_coach_map.get(team_id) or []
        if coaches:
            return str(coaches[0])

    for template in _slot_templates(model_data):
        template_team_id = _get(template, "team_id", "teamId")
        if template_team_id is not None and str(template_team_id) == team_id:
            coach_id = _get(template, "coach_id", "coachId")
            if coach_id is not None:
                return str(coach_id)
    return None


def _coach_threshold(model_data: Mapping[str, Any] | Any, coach_id: str) -> int:
    """Return the recommended maximum number of working DAYS for a coach (maxDaysOverride)."""
    for coach in _collection(model_data, "coaches"):
        cid = _get(coach, "id", "coach_id", "coachId")
        if cid is not None and str(cid) == coach_id:
            override = _get(coach, "max_days_override", "maxDaysOverride")
            if override is not None:
                return max(1, int(override))
    return 10**9
