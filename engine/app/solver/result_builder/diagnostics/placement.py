"""Diagnostics de placement : équipes non placées, surcharge coach, créneaux inutilisés.

Extrait verbatim de l'ancien ``diagnostics.py`` (découpe pure, P4-295 C1).
"""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Mapping
from datetime import UTC, datetime
from typing import Any

from ...model import DEFAULT_SESSION_MINUTES
from ..helpers import (
    _coach_name_map,
    _coach_threshold,
    _collection,
    _get,
    _label,
    _team_ids,
    _team_name_map,
    _venue_name_map,
)


def _diagnose_unplaced(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Flag teams that have no sessions in the final schedule (who + why)."""
    diagnostics: list[dict[str, Any]] = []
    team_names = _team_name_map(model_data)
    venue_names = _venue_name_map(model_data)
    placed_team_ids = {slot["teamId"] for slot in slots}

    # Total training-slot supply — used to distinguish "no slots at all" from
    # "slots exist but were all taken / incompatible".
    total_available_slots = sum(
        len(_collection(venue, "training_slots", "trainingSlots")) for venue in _collection(model_data, "venues")
    )
    teams_by_id = {
        str(_get(team, "id", "team_id", "teamId")): team
        for team in _collection(model_data, "teams")
        if _get(team, "id", "team_id", "teamId") is not None
    }
    # Which venues actually declare at least one slot (for forced-venue reason).
    venues_with_slots = {
        str(_get(venue, "id", "venue_id", "venueId"))
        for venue in _collection(model_data, "venues")
        if _collection(venue, "training_slots", "trainingSlots")
    }

    for team_id in _team_ids(model_data):
        if team_id in placed_team_ids:
            continue

        team_label = _label(team_id, team_names)
        team = teams_by_id.get(team_id)
        forced_venue_id = _get(team, "forced_venue_id", "forcedVenueId", default=None) if team is not None else None

        if total_available_slots == 0:
            reason = "aucun créneau d'entraînement n'est déclaré dans les gymnases."
            suggestions = ["Ajoutez des créneaux de disponibilité sur au moins un gymnase."]
        elif forced_venue_id is not None and str(forced_venue_id) not in venues_with_slots:
            venue_label = _label(forced_venue_id, venue_names)
            reason = (
                f"son gymnase imposé ({venue_label}) n'a aucun créneau disponible "
                "(gymnase fermé ou sans horaires déclarés)."
            )
            suggestions = [
                f"Ajoutez des créneaux au gymnase {venue_label}, ou retirez le gymnase imposé pour cette équipe.",
            ]
        else:
            reason = (
                "tous les créneaux compatibles étaient déjà occupés par des équipes plus "
                "prioritaires, ou en conflit avec ses contraintes (coach indisponible, "
                "gymnase fermé, jour interdit)."
            )
            suggestions = [
                "Ajoutez de la disponibilité de gymnase ou assouplissez une contrainte dure de cette équipe.",
                "Vérifiez que l'équipe dispose d'au moins un créneau réellement libre.",
            ]

        diagnostics.append(
            {
                "id": f"diag-unplaced-{team_id}",
                "type": "unplaced",
                "severity": "ERROR",
                "teamId": team_id,
                "message": f"L'équipe {team_label} n'a pas pu être placée : {reason}",
                "suggestions": suggestions,
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
    return diagnostics


def _unplaced_team_ids(model_data: Mapping[str, Any] | Any, slots: list[dict[str, Any]]) -> list[str]:
    placed_team_ids = {slot["teamId"] for slot in slots}
    return [team_id for team_id in sorted(_team_ids(model_data)) if team_id not in placed_team_ids]


def _diagnose_coach_overload(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Flag coaches working more DAYS than their recommended maximum."""
    diagnostics: list[dict[str, Any]] = []
    coach_names = _coach_name_map(model_data)
    # ENG-24: the threshold (_coach_threshold = maxDaysOverride) is a number of DAYS, so count
    # distinct working days per coach — NOT 15-min blocks (two 90-min sessions on the same day
    # = 1 day worked, not 12 blocks) which produced systematic false alarms.
    coach_days: dict[str, set[int]] = defaultdict(set)
    for slot in slots:
        coach_id = slot.get("coachId")
        if coach_id and slot.get("dayOfWeek") is not None:
            coach_days[coach_id].add(int(slot["dayOfWeek"]))

    for coach_id, days in coach_days.items():
        count = len(days)
        threshold = _coach_threshold(model_data, coach_id)
        if count > threshold:
            diagnostics.append(
                {
                    "id": f"diag-overload-{coach_id}",
                    "type": "coach_overload",
                    "severity": "WARNING",
                    "coachId": coach_id,
                    "message": (
                        f"Le coach {_label(coach_id, coach_names)} intervient sur {count} jours, "
                        f"au-dessus de la limite recommandée de {threshold} : "
                        "risque de fatigue ou de conflits d'agenda."
                    ),
                    "suggestions": [
                        "Répartissez certaines séances sur un autre coach.",
                        "Vérifiez le nombre de jours maximum dans le profil du coach.",
                    ],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )
    return diagnostics


_DAY_NAMES = {
    0: "Sunday",
    1: "Monday",
    2: "Tuesday",
    3: "Wednesday",
    4: "Thursday",
    5: "Friday",
    6: "Saturday",
}


def _diagnose_unused_slots(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Warn about available training slots that received no team assignment.

    Only slots that were *available* (declared in ``venues[].trainingSlots``)
    but not used by any placed session are reported. Venue closures and coach
    unavailability are excluded because those slots are not in the available
    set the solver could use.
    """
    diagnostics: list[dict[str, Any]] = []

    used: set[tuple[str, int, str]] = {
        (str(slot["venueId"]), int(slot["dayOfWeek"]), str(slot["startTime"])) for slot in slots
    }

    for venue in _collection(model_data, "venues"):
        venue_id = str(_get(venue, "id"))
        for ts in _collection(venue, "training_slots", "trainingSlots"):
            day_of_week = int(_get(ts, "day_of_week", "dayOfWeek"))
            start_time = str(_get(ts, "start_time", "startTime"))
            duration = int(_get(ts, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))

            if (venue_id, day_of_week, start_time) in used:
                continue

            # Le libellé (gymnase, jour, plage horaire) est reconstruit côté backend :
            # aucun texte n'est calculé ici (venue_name / day_name / end_time morts).
            diagnostics.append(
                {
                    "id": f"diag-unused-slot-{venue_id}-{day_of_week}-{start_time}",
                    "type": "unused_slot",
                    "severity": "WARNING",
                    "venueId": venue_id,
                    "dayOfWeek": day_of_week,
                    "startTime": start_time,
                    "durationMinutes": duration,
                    # Copie possédée par le backend : DiagnosticMessageBuilder reconstruit
                    # inconditionnellement le texte FR de ``unused_slot`` (et corrige au passage
                    # le libellé de jour). Émettre un message ici serait du texte anglais mort,
                    # jamais lu — on envoie donc une chaîne vide.
                    "message": "",
                    "suggestions": [],
                    "teamId": None,
                    "coachId": None,
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )

    return diagnostics
