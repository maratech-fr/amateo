"""Dérive ``fully_reserved_two_employees_grid.json`` d'un snapshot de génération, en LISTE BLANCHE.

Dépôt public : aucun nom ni identifiant réel ne doit passer. Chaque champ d'entrée tombe dans l'une
de trois cases, et rien d'autre n'est possible :

* conservé (identifiant remappé, nom remplacé par un nom générique) ;
* écarté sans condition (``_DROPPED``) : ne sert pas à ce décor, ne sort jamais ;
* toléré seulement VIDE (``_MUST_BE_EMPTY``) : renseigné, il ferait un autre décor ou porterait une
  référence externe, donc refus.

Tout autre champ arrête le script (``SystemExit`` nommant le champ, jamais sa valeur).

Usage : ``python -I derive_fully_reserved_grid.py <snapshot.json> > fully_reserved_two_employees_grid.json``
Le snapshot reste hors dépôt. Sortie déterministe (ordre du snapshot, aucune date).
"""

from __future__ import annotations

import json
import string
import sys
from collections.abc import Callable, Mapping
from typing import Any

_MUST_BE_EMPTY_TOP = {"previousAssignments", "socleReferenceAssignments", "venueTravelTimes", "teamLinks"}

_DROPPED: dict[str, set[str]] = {
    "teams": {"sportCategoryId", "ageMax", "gender", "level"},
    "venues": {"color", "source", "latitude", "longitude"},
    "coaches": set(),
    "constraints": set(),
    "slotTemplates": set(),
    "sharedBlocks": set(),
}

_MUST_BE_EMPTY: dict[str, set[str]] = {
    "teams": {"matchDay", "parentTeamId", "ffbbTeamId", "externalRef"},
    "venues": {"externalRef", "isExternal", "parentVenueId"},
    "coaches": set(),
    "constraints": set(),
    "slotTemplates": {"pendingConstraintSuggestion", "coachLateToleranceMinutes"},
    "sharedBlocks": set(),
}

_CONFIG_VALUE_KEYS = {"maxStartTime", "minStartTime", "forbiddenDays", "forcedDays", "unavailableDays"}
_METADATA_VALUE_KEYS = {"role", "isRequired", "position", "isActive", "defaultMinSessions"}


def _refuse(where: str, field: str) -> SystemExit:
    return SystemExit(f"derive_fully_reserved_grid: champ refusé {where}.{field}")


def _check_fields(collection: str, item: Mapping[str, Any], kept: set[str]) -> None:
    for field, value in item.items():
        if field in kept or field in _DROPPED[collection]:
            continue
        if field in _MUST_BE_EMPTY[collection] and not value:
            continue
        raise _refuse(collection, field)


def _mapper(prefix: str, items: list[dict[str, Any]]) -> dict[str, str]:
    return {str(item["id"]): f"{prefix}-{index}" for index, item in enumerate(items, start=1)}


def _mapped(mapping: Mapping[str, str], value: Any, where: str) -> str | None:
    if value is None:
        return None
    if str(value) not in mapping:
        raise _refuse(where, "référence inconnue")
    return mapping[str(value)]


def _letter(index: int) -> str:
    letters = string.ascii_uppercase
    return (
        letters[index]
        if index < len(letters)
        else f"{letters[index // len(letters) - 1]}{letters[index % len(letters)]}"
    )


def _config(raw: Mapping[str, Any] | None) -> dict[str, Any]:
    for key in raw or {}:
        if key not in _CONFIG_VALUE_KEYS:
            raise _refuse("constraints.config", key)
    return dict(raw or {})


def _metadata(raw: Mapping[str, Any], teams: Mapping[str, str], coaches: Mapping[str, str]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in raw.items():
        if key == "coachId":
            result[key] = _mapped(coaches, value, "constraints.metadata")
        elif key == "teamId":
            result[key] = _mapped(teams, value, "constraints.metadata")
        elif key == "id":
            result[key] = value  # rang de priorité : un numéro, pas un identifiant de club
        elif key == "label":
            result[key] = f"Rang {raw.get('id')}"
        elif key in _METADATA_VALUE_KEYS:
            result[key] = value
        else:
            raise _refuse("constraints.metadata", key)
    return result


def derive(snapshot: Mapping[str, Any]) -> dict[str, Any]:
    """Construit le décor anonyme ; lève ``SystemExit`` sur tout champ hors liste."""
    known_top = {
        "version",
        "clubId",
        "seasonId",
        "solverSeed",
        "solverTimeoutSeconds",
        "teams",
        "venues",
        "coaches",
        "constraints",
        "slotTemplates",
        "sharedBlocks",
        "implicitRules",
        "priorityTiers",
    }
    for field, value in snapshot.items():
        if field in known_top or (field in _MUST_BE_EMPTY_TOP and not value):
            continue
        raise _refuse("snapshot", field)

    raw_teams = list(snapshot.get("teams", []))
    raw_venues = list(snapshot.get("venues", []))
    raw_coaches = list(snapshot.get("coaches", []))
    teams = _mapper("t", raw_teams)
    venues = _mapper("v", raw_venues)
    coaches = {str(c["id"]): f"c-{_letter(i).lower()}" for i, c in enumerate(raw_coaches)}

    def scope_target(value: Any, where: str) -> str | None:
        if value is None:
            return None
        return _mapped({**teams, **coaches, **venues}, value, where)

    def team_out(index: int, team: Mapping[str, Any]) -> dict[str, Any]:
        _check_fields(
            "teams", team, {"id", "name", "category", "sessionsPerWeek", "ageMin", "priorityTierId", "forcedVenueId"}
        )
        return {
            "id": teams[str(team["id"])],
            "name": str(team.get("category") or f"Équipe {index}"),
            "sportCategoryId": "cat",  # requis par le contrat ; la valeur source est un identifiant, écartée
            "sessionsPerWeek": team["sessionsPerWeek"],
            "ageMin": team.get("ageMin"),
            "priorityTierId": team.get("priorityTierId"),
            "forcedVenueId": _mapped(venues, team.get("forcedVenueId"), "teams"),
        }

    def venue_out(index: int, venue: Mapping[str, Any]) -> dict[str, Any]:
        _check_fields("venues", venue, {"id", "name", "trainingSlots"})
        slots = []
        for slot in venue.get("trainingSlots", []):
            for field in slot:
                if field not in {"dayOfWeek", "startTime", "durationMinutes", "capacity"}:
                    raise _refuse("venues.trainingSlots", field)
            slots.append(dict(slot))
        return {"id": venues[str(venue["id"])], "name": f"Gymnase {index}", "trainingSlots": slots}

    def coach_out(index: int, coach: Mapping[str, Any]) -> dict[str, Any]:
        _check_fields(
            "coaches",
            coach,
            {"id", "firstName", "lastName", "isEmployee", "isVehicled", "maxDaysOverride", "parentCoachId"},
        )
        return {
            "id": coaches[str(coach["id"])],
            "firstName": "Coach",
            "lastName": _letter(index),
            "isEmployee": coach.get("isEmployee", False),
            "isVehicled": coach.get("isVehicled", False),
            "maxDaysOverride": coach.get("maxDaysOverride"),
            "parentCoachId": _mapped(coaches, coach.get("parentCoachId"), "coaches"),
        }

    def constraint_out(index: int, row: Mapping[str, Any]) -> dict[str, Any]:
        _check_fields(
            "constraints",
            row,
            {
                "id",
                "name",
                "family",
                "scope",
                "scopeTargetId",
                "ruleType",
                "isActive",
                "config",
                "sortOrder",
                "type",
                "teamId",
                "severity",
                "value",
                "metadata",
            },
        )
        out: dict[str, Any] = {"id": f"k-{index}"}
        for field in ("family", "scope", "ruleType", "isActive", "sortOrder", "type", "severity"):
            if field in row:
                out[field] = row[field]
        if "name" in row:
            out["name"] = f"Règle {index}"
        if "scopeTargetId" in row:
            out["scopeTargetId"] = scope_target(row["scopeTargetId"], "constraints.scopeTargetId")
        if "teamId" in row:
            # « * » : ligne de rang de priorité, valable pour tout le club (joker du contrat).
            out["teamId"] = "*" if row["teamId"] == "*" else _mapped(teams, row["teamId"], "constraints.teamId")
        if "value" in row:
            out["value"] = scope_target(row["value"], "constraints.value")
        if "config" in row:
            out["config"] = _config(row["config"])
        if "metadata" in row:
            out["metadata"] = _metadata(row["metadata"], teams, coaches)
        return out

    def template_out(index: int, pin: Mapping[str, Any]) -> dict[str, Any]:
        _check_fields(
            "slotTemplates",
            pin,
            {"id", "teamId", "venueId", "coachId", "dayOfWeek", "startTime", "durationMinutes", "lockLevel"},
        )
        return {
            "id": f"p-{index}",
            "teamId": _mapped(teams, pin["teamId"], "slotTemplates"),
            "venueId": _mapped(venues, pin["venueId"], "slotTemplates"),
            "coachId": _mapped(coaches, pin.get("coachId"), "slotTemplates"),
            "dayOfWeek": pin["dayOfWeek"],
            "startTime": pin["startTime"],
            "durationMinutes": pin["durationMinutes"],
            "lockLevel": pin["lockLevel"],
        }

    def block_out(index: int, block: Mapping[str, Any]) -> dict[str, Any]:
        _check_fields("sharedBlocks", block, {"id", "teamIds", "commonSessions", "label"})
        out: dict[str, Any] = {
            "id": f"b-{index}",
            "teamIds": [_mapped(teams, team, "sharedBlocks") for team in block["teamIds"]],
            "commonSessions": block["commonSessions"],
        }
        if "label" in block:
            out["label"] = f"Groupe {index}"
        return out

    def each(items: list[Any], build: Callable[[int, Any], dict[str, Any]]) -> list[dict[str, Any]]:
        return [build(index, item) for index, item in enumerate(items, start=1)]

    def coach_each() -> list[dict[str, Any]]:
        return [coach_out(index, coach) for index, coach in enumerate(raw_coaches)]

    return {
        "version": snapshot["version"],
        "clubId": "club-fully-reserved-grid",
        "seasonId": "season-1",
        "scheduleName": None,
        "solverSeed": snapshot.get("solverSeed", 42),
        "solverTimeoutSeconds": snapshot.get("solverTimeoutSeconds", 60),
        "teams": each(raw_teams, team_out),
        "venues": each(raw_venues, venue_out),
        "coaches": coach_each(),
        "constraints": each(list(snapshot.get("constraints", [])), constraint_out),
        "slotTemplates": each(list(snapshot.get("slotTemplates", [])), template_out),
        "sharedBlocks": each(list(snapshot.get("sharedBlocks", [])), block_out),
        "implicitRules": snapshot.get("implicitRules"),
    }


def main(argv: list[str]) -> int:
    if len(argv) != 2:
        raise SystemExit("usage: derive_fully_reserved_grid.py <snapshot.json>")
    with open(argv[1], encoding="utf-8") as handle:
        snapshot = json.load(handle)
    json.dump(derive(snapshot), sys.stdout, ensure_ascii=False, indent=2)
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
