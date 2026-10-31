"""Un club entièrement réservé se génère : le planning sort, et seuls les faits sont signalés.

Axe §7.1 *constraint semantics* (décisions fondateur 2026-10-31). Le décor
``fully_reserved_two_employees_grid.json`` est un club anonymisé de 14 équipes, 26 séances toutes
réservées, deux salariés et aucun salarié le jeudi. Il a échoué en production avec des lignes
« N candidats, tous fermés » à l'adresse d'équipes qui n'y étaient pour rien.

Angle voulu en plus de ``test_salarie_rule_is_an_objective.py`` (décor synthétique, règle seule) :
ici, la règle sur un vrai volume, et le silence sur le manque de place d'une équipe réservée.
Le refus amont (coach à deux endroits sans autre tenant, bloqueurs du Récap) reste côté backend, il
n'est pas contredit ici.
"""

from __future__ import annotations

import importlib.util
import json
import os
import pathlib
from collections.abc import Iterator
from types import ModuleType
from typing import Any

import pytest

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload, team_coach

FIXTURES = pathlib.Path(__file__).resolve().parents[1] / "fixtures"
FIXTURE = FIXTURES / "fully_reserved_two_employees_grid.json"

SHORTAGE_TYPES = {"unplaced", "session_below_effective_min", "blocked_by_reservation", "conflict"}
"""Ce qu'un manque de place peut dire d'une équipe : jamais à une équipe entièrement réservée."""


def _fixture() -> dict[str, Any]:
    data = json.loads(FIXTURE.read_text(encoding="utf-8"))
    data["solverTimeoutSeconds"] = 30
    return data


def _serious(result: dict[str, Any]) -> list[dict[str, Any]]:
    return [d for d in result["diagnostics"] if d["severity"] in {"WARNING", "ERROR"}]


def _shortage_lines(result: dict[str, Any], team_ids: set[str]) -> list[dict[str, Any]]:
    return [d for d in result["diagnostics"] if d.get("teamId") in team_ids and d["type"] in SHORTAGE_TYPES]


def test_the_fully_reserved_club_generates_with_a_single_factual_alert() -> None:
    """Le cas de l'essai : planning complet, une seule alerte, celle du jeudi, énoncée en fait."""
    result = solve_payload(_fixture())

    assert result["status"] == "completed", _serious(result)
    assert len(result["slots"]) == 26
    assert {s["lockLevel"] for s in result["slots"]} == {"HARD"}

    serious = _serious(result)
    assert [d["id"] for d in serious] == ["diag-implicit-salarie"], serious
    message = serious[0]["message"]
    assert message == "Aucun salarié n'a de séance le jeudi."
    assert not any(d["id"].startswith("diag-infeasible") for d in result["diagnostics"])
    assert not any("candidat" in d["message"] for d in result["diagnostics"])


def test_giving_thursday_an_employee_removes_the_alert() -> None:
    """Le geste inverse : une séance de salarié réservée le jeudi, et l'alerte disparaît."""
    payload = _fixture()
    links = [c for c in payload["constraints"] if c.get("type") == "TEAM_COACH"]
    teams_of = {
        c["id"]: {k["teamId"] for k in links if k["metadata"]["coachId"] == c["id"]} for c in payload["coaches"]
    }

    def working_days(coach_id: str) -> int:
        return len({t["dayOfWeek"] for t in payload["slotTemplates"] if t["teamId"] in teams_of[coach_id]})

    # Le salarié le moins chargé : il garde son jour de repos en prenant le jeudi.
    employee = min((c["id"] for c in payload["coaches"] if c["isEmployee"]), key=working_days)
    thursday = next(t for t in payload["slotTemplates"] if t["dayOfWeek"] == 4)
    for link in links:
        if link["teamId"] == thursday["teamId"]:
            link["metadata"]["coachId"] = employee
            link["value"] = employee
    thursday["coachId"] = employee

    result = solve_payload(payload)

    assert result["status"] == "completed", _serious(result)
    alerts = [d["message"] for d in result["diagnostics"] if d["id"] == "diag-implicit-salarie"]
    assert not any("jeudi" in alert for alert in alerts), alerts


def test_no_fully_reserved_team_is_told_it_lacks_room() -> None:
    """Aucune des 14 équipes, toutes entièrement réservées, ne reçoit de message de manque de place."""
    payload = _fixture()

    result = solve_payload(payload)

    assert result["status"] == "completed", _serious(result)
    assert _shortage_lines(result, {t["id"] for t in payload["teams"]}) == []


@pytest.mark.parametrize("kept", [13, 0], ids=["half-reserved", "nothing-reserved"])
def test_a_partly_reserved_club_generates_and_keeps_its_alert_consistent(kept: int) -> None:
    """Avec moins de réservations, le planning sort aussi ; l'alerte dit ce que les séances montrent."""
    payload = _fixture()
    payload["slotTemplates"] = payload["slotTemplates"][:kept]

    result = solve_payload(payload)

    assert result["status"] == "completed", _serious(result)
    assert not any(d["id"].startswith("diag-infeasible") for d in result["diagnostics"])

    employees = {c["id"] for c in payload["coaches"] if c["isEmployee"]}
    teams_by_employee = {
        c["teamId"]
        for c in payload["constraints"]
        if c.get("type") == "TEAM_COACH" and c["metadata"]["coachId"] in employees
    }
    days_with_employee = {s["dayOfWeek"] for s in result["slots"] if s["teamId"] in teams_by_employee}
    alert = next((d for d in result["diagnostics"] if d["id"] == "diag-implicit-salarie"), None)
    if set(range(1, 6)) <= days_with_employee:
        assert alert is None
    else:
        assert alert is not None, "un jour sans salarié doit être signalé"


# --- Décor 100 % réservé, une perturbation à la fois : la réservation gagne, l'écart est nommé --------


def _lock(team: str, venue: str, day: int) -> dict[str, Any]:
    return {
        "id": f"r-{team}-{day}",
        "teamId": team,
        "venueId": venue,
        "dayOfWeek": day,
        "startTime": "18:00",
        "durationMinutes": 90,
        "lockLevel": "HARD",
    }


def _reserved_club(perturbation: str) -> dict[str, Any]:
    constraints = [team_coach(f"tc{i}", f"E{i}", f"c{i}") for i in range(1, 5)]
    locks = [_lock("E1", "G1", 1), _lock("E2", "G1", 2), _lock("E3", "G2", 3), _lock("E4", "G2", 4)]
    if perturbation == "coach_unavailable":
        constraints.append(
            {
                "id": "off-c1",
                "scope": "COACH",
                "scopeTargetId": "c1",
                "family": "COACH_AVAILABILITY",
                "ruleType": "HARD",
                "name": "Indispo du lundi",
                "config": {"unavailableDays": [1]},
                "sortOrder": 0,
                "isActive": True,
            }
        )
    elif perturbation == "coach_in_two_gyms":
        constraints[1] = team_coach("tc2", "E2", "c1")
        locks[1] = _lock("E2", "G2", 1)
    elif perturbation == "capacity_exceeded":
        locks[1] = _lock("E2", "G1", 1)
    return make_payload(
        teams=[make_team(f"E{i}") for i in range(1, 5)],
        venues=[make_venue(gym, [(day, "18:00") for day in range(1, 6)]) for gym in ("G1", "G2")],
        coaches=[{"id": f"c{i}", "firstName": f"Coach{i}", "lastName": "X"} for i in range(1, 5)],
        constraints=constraints,
        slot_templates=locks,
    )


@pytest.mark.parametrize(
    ("perturbation", "diagnostic_id", "named"),
    [
        ("coach_unavailable", "rule-sacrificed-coach-availability", ["E1", "lundi", "indisponible"]),
        ("coach_in_two_gyms", "diag-locked-person", ["E1", "E2", "lundi"]),
        ("capacity_exceeded", "diag-conflict-venue", ["E1", "E2", "lundi"]),
    ],
)
def test_a_fully_reserved_club_names_the_reservation_and_never_a_lack_of_room(
    perturbation: str, diagnostic_id: str, named: list[str]
) -> None:
    """La réservation qui viole une règle est nommée, planning produit, sans aucun manque de place."""
    result = solve_payload(_reserved_club(perturbation))

    assert result["status"] == "completed"
    assert len(result["slots"]) == 4
    lines = [d for d in result["diagnostics"] if d["id"].startswith(diagnostic_id)]
    assert lines, result["diagnostics"]
    for word in named:
        assert word in lines[0]["message"], lines[0]["message"]
    assert _shortage_lines(result, {"E1", "E2", "E3", "E4"}) == []


def test_a_fully_reserved_club_without_perturbation_reports_nothing() -> None:
    """Témoin du précédent : sans perturbation, aucune de ces lignes, donc elles viennent bien d'elle."""
    result = solve_payload(_reserved_club("none"))

    assert result["status"] == "completed"
    assert len(result["slots"]) == 4
    assert [d for d in result["diagnostics"] if d["type"] in {"rule_sacrificed", *SHORTAGE_TYPES}] == []


# --- Le groupe sur-réservé reste un échec : il doit nommer le groupe, sans fausse piste ---------------


def _overpinned_group(*, with_innocent_group: bool = False) -> dict[str, Any]:
    """SM1 + SM2 réservées ensemble sur deux cases pour une seule séance commune."""
    locks = [_lock(team, gym, day) for team in ("SM1", "SM2") for gym, day in (("G1", 1), ("G2", 2))]
    payload = make_payload(
        teams=[
            make_team("SM1", sessions_per_week=2),
            make_team("SM2", sessions_per_week=2),
            make_team("SF1"),
            make_team("SF2"),
        ],
        venues=[make_venue("G1", [(1, "18:00"), (3, "18:00")]), make_venue("G2", [(2, "18:00"), (4, "18:00")])],
        slot_templates=locks,
    )
    payload["sharedBlocks"] = [{"id": "b-seniors", "teamIds": ["SM1", "SM2"], "commonSessions": 1}]
    if with_innocent_group:
        payload["sharedBlocks"].insert(0, {"id": "b-other", "teamIds": ["SF1", "SF2"], "commonSessions": 1})
    return payload


def _infeasible(result: dict[str, Any]) -> dict[str, Any]:
    return next(d for d in result["diagnostics"] if d["id"] == "diag-infeasible")


def test_an_overbooked_group_fails_and_is_named_directly() -> None:
    """Une phrase directe qui nomme le bon groupe et le remède, sans piste « bien-être » : ici la
    cause est connue, la réservation du groupe, jamais une autre règle."""
    result = solve_payload(_overpinned_group())

    assert result["status"] == "failed"
    infeasible = _infeasible(result)
    assert infeasible["message"] == (
        "Le groupe SM1 + SM2 est réservé sur plus de cases que ses séances communes, ou n'a aucune case "
        "commune : retirez une réservation du groupe ou ajustez ses séances communes dans l'assistant."
    )
    text = " ".join([infeasible["message"], *infeasible["suggestions"]])
    assert "bien-être" not in text
    assert "Obligatoire" not in text
    cause = next(c for c in infeasible["causes"] if c["kind"] == "shared_block")
    assert cause["label"] == "groupe SM1 + SM2"
    assert cause["constraintId"] is None


def test_the_overbooked_group_is_named_even_beside_another_group() -> None:
    """Le noyau rendu par le solveur n'est pas minimal : il cite aussi un groupe sans histoire. Le
    groupe en cause doit être nommé quel que soit son rang dans la liste."""
    result = solve_payload(_overpinned_group(with_innocent_group=True))

    assert result["status"] == "failed"
    infeasible = _infeasible(result)
    assert "SM1 + SM2" in infeasible["message"]
    assert "SF1" not in infeasible["message"], "un groupe sans histoire n'est jamais accusé"
    assert [c["label"] for c in infeasible["causes"]] == ["groupe SM1 + SM2"]


def test_several_groups_with_none_overbooked_are_all_named() -> None:
    """Deux groupes pour une seule case, aucun réservé : rien ne désigne l'un plutôt que l'autre, le
    message les nomme tous, au pluriel."""
    payload = make_payload(
        teams=[make_team(team) for team in ("SM1", "SM2", "SF1", "SF2")],
        venues=[make_venue("G1", [(1, "18:00")])],
    )
    payload["sharedBlocks"] = [
        {"id": "b-seniors", "teamIds": ["SM1", "SM2"], "commonSessions": 1},
        {"id": "b-feminines", "teamIds": ["SF1", "SF2"], "commonSessions": 1},
    ]

    result = solve_payload(payload)

    assert result["status"] == "failed"
    infeasible = _infeasible(result)
    assert infeasible["message"] == (
        "Les groupes SM1 + SM2 et SF1 + SF2 n'ont pas assez de cases communes pour leurs séances communes : "
        "ajoutez une case commune aux équipes d'un groupe ou ajustez leurs séances communes dans l'assistant."
    )
    assert [c["label"] for c in infeasible["causes"]] == ["groupe SM1 + SM2", "groupe SF1 + SF2"]


# --- Hygiène du décor : aucun nom ni identifiant source dans un dépôt public ---------------------------


def _derive_module() -> ModuleType:
    spec = importlib.util.spec_from_file_location(
        "derive_fully_reserved_grid", FIXTURES / "derive_fully_reserved_grid.py"
    )
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


_IDENTIFYING_KEYS = {
    "id",
    "name",
    "firstName",
    "lastName",
    "clubId",
    "seasonId",
    "teamId",
    "venueId",
    "coachId",
    "scopeTargetId",
    "value",
    "sportCategoryId",
    "teamIds",
    "label",
    "externalRef",
    "ffbbTeamId",
}
"""Les champs qui portent un nom ou un identifiant. Pas les énumérations ni les heures, qui ne
disent rien du club et qu'un décor rejouable garde forcément (« HARD », « 18:00 »)."""


def _identifying_values(node: Any, key: str | None = None) -> Iterator[str]:
    if isinstance(node, dict):
        for child_key, child in node.items():
            yield from _identifying_values(child, child_key)
    elif isinstance(node, list):
        for child in node:
            yield from _identifying_values(child, key)
    elif isinstance(node, str) and key in _IDENTIFYING_KEYS and node not in {"", "*"}:
        yield node


def test_fixture_carries_no_source_values() -> None:
    """Aucun nom ni identifiant du snapshot source ne se retrouve dans le décor. Le message d'échec
    ne cite que le compte, jamais la valeur : un journal de CI est public aussi."""
    source_path = os.environ.get("FULLY_RESERVED_SNAPSHOT_PATH")
    if not source_path or not pathlib.Path(source_path).exists():
        pytest.skip("snapshot source hors dépôt : à jouer une fois, à la dérivation")
    snapshot = json.loads(pathlib.Path(source_path).read_text(encoding="utf-8"))
    fixture_text = FIXTURE.read_text(encoding="utf-8")

    values = set(_identifying_values(snapshot))
    assert len(values) > 30, "garde-fou : la collecte des valeurs source ne doit pas être vide"
    # Un libellé d'un ou deux caractères (rang « S », numéro « 1 ») n'identifie personne et se
    # retrouve par hasard dans n'importe quel texte : il ne fait pas fuite.
    leaked = [value for value in values if len(value) >= 3 and value in fixture_text]
    assert len(leaked) == 0, f"{len(leaked)} valeur(s) source présente(s) dans le décor"


@pytest.mark.parametrize(
    ("path", "field"),
    [
        ((), "unknownTopField"),
        (("teams", 0), "unknownTeamField"),
        (("venues", 0), "unknownVenueField"),
        (("coaches", 0), "email"),
        (("constraints", 0), "unknownConstraintField"),
        (("slotTemplates", 0), "unknownPinField"),
        (("sharedBlocks", 0), "unknownBlockField"),
    ],
)
def test_derive_rejects_unknown_field(path: tuple[Any, ...], field: str) -> None:
    """Un champ que la liste blanche ne connaît pas arrête la dérivation, en le nommant."""
    snapshot = _minimal_snapshot()
    node: Any = snapshot
    for step in path:
        node = node[step]
    node[field] = "valeur"

    with pytest.raises(SystemExit) as refused:
        _derive_module().derive(snapshot)
    assert field in str(refused.value)
    assert "valeur" not in str(refused.value)


@pytest.mark.parametrize(
    ("path", "field", "value"),
    [
        ((), "previousAssignments", [{"teamId": "x"}]),
        ((), "socleReferenceAssignments", [{"teamId": "x"}]),
        ((), "venueTravelTimes", [{"from": "x"}]),
        ((), "teamLinks", [{"teamId": "x"}]),
        (("venues", 0), "externalRef", "REF-1"),
        (("teams", 0), "ffbbTeamId", "REF-2"),
    ],
)
def test_derive_rejects_filled_external_references(path: tuple[Any, ...], field: str, value: Any) -> None:
    """Un historique ou une référence externe renseignés arrêtent la dérivation ; vides, ils passent."""
    snapshot = _minimal_snapshot()
    node: Any = snapshot
    for step in path:
        node = node[step]
    node[field] = value

    with pytest.raises(SystemExit) as refused:
        _derive_module().derive(snapshot)
    assert field in str(refused.value)


def _minimal_snapshot() -> dict[str, Any]:
    return {
        "version": "1.8",
        "clubId": "source-club",
        "seasonId": "source-season",
        "teams": [{"id": "source-team", "name": "Nom Source", "sessionsPerWeek": 1}],
        "venues": [{"id": "source-venue", "name": "Salle Source", "trainingSlots": []}],
        "coaches": [{"id": "source-coach", "firstName": "Prénom", "lastName": "Source", "isEmployee": True}],
        "constraints": [{"id": "source-rule", "name": "Règle source", "family": "DAY", "config": {}}],
        "slotTemplates": [
            {
                "id": "source-pin",
                "teamId": "source-team",
                "venueId": "source-venue",
                "dayOfWeek": 1,
                "startTime": "18:00",
                "durationMinutes": 90,
                "lockLevel": "HARD",
            }
        ],
        "sharedBlocks": [{"id": "source-block", "teamIds": ["source-team"], "commonSessions": 1}],
    }


def test_derive_output_replaces_every_name_and_identifier() -> None:
    """Témoin positif : la dérivation aboutit sur le minimum, sans laisser passer une valeur source."""
    derived = json.dumps(_derive_module().derive(_minimal_snapshot()), ensure_ascii=False)

    assert "ource" not in derived, derived
    assert "Prénom" not in derived, derived
