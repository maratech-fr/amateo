"""Diagnostics des passerelles et des temps de trajet résiduels.

Extrait verbatim de l'ancien ``diagnostics.py`` (découpe pure, P4-295 C1).

⚠ ENG-37 : ``_diagnose_travel_times`` consomme la SOURCE UNIQUE ``is_travel_too_tight`` (importée
ci-dessous). Le test-garde ``test_travel_diagnostic_delegates_to_the_shared_geometry_source``
neutralise cette source en patchant
``app.solver.result_builder.diagnostics.links_travel.is_travel_too_tight`` — le nom doit rester
résolu DANS CE MODULE pour que le garde morde.
"""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Mapping
from datetime import UTC, datetime
from typing import Any

from ortools.sat.python import cp_model

from ...constraints import (
    build_travel_matrix,
    is_travel_too_tight,
    iter_team_link_overlaps,
    team_share_declared_pairs,
)
from ...model import (
    DEFAULT_SESSION_MINUTES,
    _time_to_minutes,
)
from ..helpers import (
    _coach_name_map,
    _collection,
    _get,
    _label,
    _slot_day,
    _team_name_map,
)


def _team_link_placements_from_slots(slots: list[dict[str, Any]]) -> dict[str, list[tuple[int, int, int, str, None]]]:
    """Les PLACES finales par équipe, au format ``iter_team_link_overlaps`` (var toujours None :
    on juge la solution posée, pas des variables). ``(start, end, day, venue, None)``."""
    placements: dict[str, list[tuple[int, int, int, str, None]]] = defaultdict(list)
    for slot in slots:
        day = _slot_day(slot)
        if day is None:
            continue
        try:
            start = _time_to_minutes(str(slot["startTime"])[:5])
        except (KeyError, ValueError, TypeError):
            continue
        duration = int(slot.get("durationMinutes") or DEFAULT_SESSION_MINUTES)
        placements[str(slot["teamId"])].append((start, start + duration, day, str(slot["venueId"]), None))
    return placements


def _diagnose_team_links(
    model_data: Mapping[str, Any] | Any,
    solver_status: int,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Lot PASSERELLES PR-2 — NOMMER un chevauchement RÉSIDUEL entre deux équipes passerelées.

    Un seul code ``team_link_not_honored`` (ERROR), sur un solve abouti : on lit les PLACES
    finales et, pour chaque passerelle, on compte les chevauchements NON exemptés (même géométrie
    et même exemption doctrinale que la pose — ``iter_team_link_overlaps``). Deux régimes convergent
    ici, aucun n'est INFEASIBLE muet (CLAUDE.md §6) :

      * ``PREFERRED`` — le solveur a CÉDÉ le malus (les deux séances coïncident malgré la pénalité) ;
      * ``MANDATORY`` — le seul chevauchement possible est deux VERROUS HARD (``add_team_link_constraints``
        ne pose rien entre deux constantes) : deux actes volontaires du gestionnaire qui se
        contredisent, annoncés plutôt qu'avalés.

    Message français nommant les deux équipes réelles ; aucun identifiant interne. Une séance
    mutualisée DÉCLARÉE (même case + groupe partagé) n'est JAMAIS rapportée (exemption)."""
    links = _collection(model_data, "teamLinks", "team_links")
    if not links or solver_status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        return []

    team_names = _team_name_map(model_data)
    share_pairs = team_share_declared_pairs(_collection(model_data, "sharedBlocks", "shared_blocks"))
    placements = _team_link_placements_from_slots(slots)

    diagnostics: list[dict[str, Any]] = []
    for index, link in enumerate(links):
        team_a = str(_get(link, "teamAId", "team_a_id", default=""))
        team_b = str(_get(link, "teamBId", "team_b_id", default=""))
        if not team_a or not team_b or team_a == team_b:
            continue
        share_declared = frozenset({team_a, team_b}) in share_pairs
        overlaps = list(
            iter_team_link_overlaps(
                placements.get(team_a, []), placements.get(team_b, []), share_declared=share_declared
            )
        )
        if not overlaps:
            continue
        intensity = str(_get(link, "intensity", default="PREFERRED"))
        link_id = _get(link, "id", default=index)
        diagnostics.append(
            {
                "id": f"team-link-not-honored-{link_id}",
                "type": "team_link_not_honored",
                "severity": "ERROR",
                "message": (
                    f"Les équipes {_label(team_a, team_names)} et {_label(team_b, team_names)}, déclarées "
                    f"en passerelle, ont {len(overlaps)} séance(s) qui se chevauchent dans le temps"
                    + (
                        " : deux séances verrouillées se contredisent."
                        if intensity == "MANDATORY"
                        else " (chevauchement toléré à contrecœur)."
                    )
                ),
                "suggestions": [
                    "Déplacez l'une des séances pour qu'elles ne se chevauchent plus, "
                    "ou déclarez ces équipes en séance mutualisée si le chevauchement est voulu.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
    return diagnostics


def _diagnose_travel_times(
    model_data: Mapping[str, Any] | Any,
    solver_status: int,
    slots: list[dict[str, Any]],
    team_coach_map: Mapping[str, list[str]],
) -> list[dict[str, Any]]:
    """P2-53 RMM-8 PR-2 — NOMMER un battement de trajet RÉSIDUEL sous une règle MANDATORY.

    ``add_travel_time_hard_constraints`` interdit tout enchaînement cross-gymnase au battement
    trop court entre séances LIBRES (ou libre⇔verrou). Le seul cas qui SURVIT est deux séances
    VERROUILLÉES qui s'enchaînent trop serré à des gymnases différents : deux actes du
    gestionnaire qui se contredisent, ANNONCÉS post-solve plutôt qu'avalés (« jamais INFEASIBLE
    muet », CLAUDE.md §6 — patron ``_diagnose_team_links``). PREFERRED ne passe pas ici : son
    battement concédé est un COMPROMIS (famille ``travel_time``), pas un diagnostic. Matrice
    absente / règle inactive ou PREFERRED / solve non abouti ⇒ ``[]``."""
    implicit = _get(model_data, "implicitRules", "implicit_rules", default=None)
    travel = _get(implicit, "travelTime", "travel_time", default=None) if implicit is not None else None
    if travel is None or solver_status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        return []
    if str(_get(travel, "intensity", default="PREFERRED")).upper() != "MANDATORY":
        return []

    matrix = build_travel_matrix(_collection(model_data, "venueTravelTimes", "venue_travel_times"))
    if not matrix:
        return []
    default_minutes = int(_get(travel, "defaultMinutes", "default_minutes", default=20))
    # Battement toléré (décision fondateur 2026-09-30) : le diagnostic juge le MÊME écart exigé que
    # la pose (barème − tolérance, borné à 0). Sans lui, un enchaînement accepté par le solveur
    # serait faussement annoncé infaisable.
    tolerance_minutes = int(_get(travel, "toleranceMinutes", "tolerance_minutes", default=20))

    placements = _team_link_placements_from_slots(slots)
    coach_names = _coach_name_map(model_data)
    team_names = _team_name_map(model_data)
    vehicled = {
        str(_get(c, "id", default="")): bool(_get(c, "is_vehicled", "isVehicled", default=False))
        for c in _collection(model_data, "coaches")
    }

    def _too_tight(pa: tuple[int, int, int, str, None], pb: tuple[int, int, int, str, None], *, driving: bool) -> bool:
        # ENG-37 — le prédicat battement/barème n'est PLUS recalculé ici : la source unique
        # (``is_travel_too_tight``, qui compose ``_cross_venue_gap`` + ``_barometer``) est celle-là
        # même que la pose du solveur consomme. Le diagnostic juge donc EXACTEMENT la géométrie que
        # ``add_travel_time_hard_constraints`` a interdite.
        return is_travel_too_tight(
            pa, pb, driving=driving, matrix=matrix, default_minutes=default_minutes, tolerance_minutes=tolerance_minutes
        )

    diagnostics: list[dict[str, Any]] = []
    seen: set[tuple[str, ...]] = set()

    # Voyageur COACH : ses séances (celles de ses équipes), barème voiture/à pied selon véhiculé.
    coach_teams: dict[str, list[str]] = defaultdict(list)
    for team_id, coach_ids in (team_coach_map or {}).items():
        for coach_id in coach_ids or ():
            coach_teams[str(coach_id)].append(str(team_id))
    for coach_id, team_ids in coach_teams.items():
        gathered = [p for team_id in team_ids for p in placements.get(team_id, [])]
        ordered = sorted(gathered, key=lambda p: (p[2], p[0], p[1], p[3]))
        driving = vehicled.get(coach_id, False)
        for i in range(len(ordered)):
            for j in range(i + 1, len(ordered)):
                if not _too_tight(ordered[i], ordered[j], driving=driving):
                    continue
                key: tuple[str, ...] = ("coach", coach_id, str(ordered[i][2]), ordered[i][3], ordered[j][3])
                if key in seen:
                    continue
                seen.add(key)
                diagnostics.append(
                    {
                        "id": f"travel-time-infeasible-coach-{coach_id}-{ordered[i][2]}-{ordered[i][3]}-{ordered[j][3]}",
                        "type": "travel_time_infeasible",
                        "severity": "ERROR",
                        "coachId": coach_id,
                        "message": (
                            f"Le coach {_label(coach_id, coach_names)} enchaîne deux séances verrouillées "
                            "à des gymnases différents sans avoir le temps de faire le trajet entre les deux."
                        ),
                        "suggestions": [
                            "Déverrouillez l'une des deux séances, écartez-les dans la journée, "
                            "ou ajustez le temps de trajet entre ces deux gymnases.",
                        ],
                        "createdAt": datetime.now(UTC).isoformat(),
                    }
                )

    # Voyageur PASSERELLE : séances de A face à celles de B, barème À PIED d'office.
    for index, link in enumerate(_collection(model_data, "teamLinks", "team_links")):
        team_a = str(_get(link, "teamAId", "team_a_id", default=""))
        team_b = str(_get(link, "teamBId", "team_b_id", default=""))
        if not team_a or not team_b or team_a == team_b:
            continue
        for pa in placements.get(team_a, []):
            for pb in placements.get(team_b, []):
                if not _too_tight(pa, pb, driving=False):
                    continue
                key = ("link", team_a, team_b, str(pa[2]), pa[3], pb[3])
                if key in seen:
                    continue
                seen.add(key)
                diagnostics.append(
                    {
                        "id": f"travel-time-infeasible-link-{index}-{pa[2]}-{pa[3]}-{pb[3]}",
                        "type": "travel_time_infeasible",
                        "severity": "ERROR",
                        "message": (
                            f"Les équipes {_label(team_a, team_names)} et {_label(team_b, team_names)}, "
                            "déclarées en passerelle, ont des séances verrouillées à des gymnases différents "
                            "sans le temps de faire le trajet à vélo entre les deux."
                        ),
                        "suggestions": [
                            "Déverrouillez l'une des séances, écartez-les dans la journée, "
                            "ou ajustez le temps de trajet entre ces deux gymnases.",
                        ],
                        "createdAt": datetime.now(UTC).isoformat(),
                    }
                )

    return diagnostics
