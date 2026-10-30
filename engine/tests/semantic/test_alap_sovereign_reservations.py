"""Réservation souveraine au MOTEUR (besoin fondateur 2026-10-30, pt 3 ; amendement ADR-0001).

« La réservation gagne envers tout, mais c'est la SEULE exception. » Quand un VERROU HARD rend une
règle DURE insatisfiable, le moteur PRODUIT le planning et NOMME la règle sacrifiée — jamais un
INFEASIBLE muet (cas ALAP prod : grille réservée, INFEASIBLE en 4 ms sans rien nommer).

Angle MOTEUR (doublon VOULU, point sensible solveur ; le bout-en-bout HTTP est gardé côté backend
par ``SovereignReservationProducesScheduleTest``) : sur un décor SYNTHÉTIQUE (noms inventés, le
snapshot prod ne sort JAMAIS) où un bloc de mutualisation IMBRIQUÉ (``b-nested`` SF1+SM1+SM2,
superset de ``b-seniors`` SM1+SM2) ne peut réunir ses trois membres QUE sur une case (G1 jeudi
20:00, cap 3) RÉSERVÉE par une équipe TIERCE (U18) :

  * MAIN — la génération ABOUTIT (``completed``) et AU MOINS un diagnostic ``rule_sacrificed`` nomme
    la règle (le bloc) sacrifiée. Aujourd'hui, sans la feature : INFEASIBLE (``failed``) muet — c'est
    le ROUGE.
  * TÉMOIN — on retire la réservation TIERCE : le bloc retrouve sa case, la génération aboutit SANS
    aucun sacrifice. Sans ce témoin, un décor infaisable pour une autre raison ferait passer MAIN au
    vert sans rien prouver du verrou.

⚠ NOTE FIXTURE (cf. ``alap_synthetic.json``) : la saturation littérale 14 équipes / 21 créneaux /
26 réservations du brief est FEASIBLE aujourd'hui (la lenteur des contraintes de bloc / plancher de
séances du solveur offre des échappatoires) ; le décor est donc réduit au noyau nested-bloc-réservé
VÉRIFIÉ infaisable, seul capable de servir de ROUGE à une feature déclenchée sur INFEASIBLE.
"""

from __future__ import annotations

import copy
import json
import pathlib
from typing import Any

from tests.support.pipeline import (
    make_payload,
    make_team,
    make_venue,
    solve_payload,
    team_coach,
    team_constraint,
)

FIXTURE = pathlib.Path(__file__).resolve().parents[1] / "fixtures" / "alap_synthetic.json"
THIRD_PARTY_RESERVATION_ID = "r-U18-thu20-G1"
FRIDAY = 5


def _fixture() -> dict[str, Any]:
    data = json.loads(FIXTURE.read_text(encoding="utf-8"))
    return {key: value for key, value in data.items() if not key.startswith("_")}


def _sacrificed(result: dict[str, Any]) -> list[dict[str, Any]]:
    return [d for d in result["diagnostics"] if d.get("type") == "rule_sacrificed"]


def test_a_reservation_on_the_only_block_case_produces_a_schedule_and_names_the_sacrifice() -> None:
    result = solve_payload(_fixture())

    assert result["status"] == "completed", (
        f"une réservation souveraine doit PRODUIRE le planning, jamais INFEASIBLE muet "
        f"(obtenu {result['status']})"
    )
    sacrificed = _sacrificed(result)
    assert sacrificed, "une règle dure rendue insatisfiable par le verrou doit être NOMMÉE (rule_sacrificed)"
    assert all(d.get("severity") == "WARNING" for d in sacrificed), (
        f"un sacrifice est une ALERTE, pas une erreur bloquante : {sacrificed}"
    )


def test_without_the_third_party_reservation_the_block_places_and_nothing_is_sacrificed() -> None:
    payload = _fixture()
    payload["slotTemplates"] = [t for t in payload["slotTemplates"] if t["id"] != THIRD_PARTY_RESERVATION_ID]

    result = solve_payload(payload)

    assert result["status"] == "completed", "sans la réservation tierce, le bloc retrouve sa case"
    assert _sacrificed(result) == [], (
        f"aucune règle « sacrifiée » quand aucun verrou ne la contredit (obtenu {_sacrificed(result)})"
    )


def test_a_rule_conflict_not_caused_by_a_lock_stays_failed_even_with_reservations_elsewhere() -> None:
    """Décision fondateur 2026-10-30 (2) — GARDE-FOU : le best-effort ne relâche QUE les règles
    DIRECTEMENT bloquées par un VERROU. « La réservation est la SEULE exception. »

    Un club peut avoir des réservations AILLEURS (ici l'équipe A, épinglée HARD lundi 18:00) — donc
    ``model.locked_slots`` NON vide, le gate du best-effort est franchi. Mais une contradiction
    ENTRE RÈGLES dont AUCUN verrou n'est la cause (U21M1 : coach indisponible le vendredi + vendredi
    imposé, gymnase ouvert le vendredi seulement) reste un ÉCHEC EXPLIQUÉ (``failed`` +
    ``diag-infeasible``) — JAMAIS un ``rule_sacrificed``.

    C'est le garde-fou de la Behat ``une-contrainte-saisie-est-honoree`` (générée sur le club démo,
    qui PORTE des réservations de base) : sans ce test, un best-effort qui relâcherait TOUT le noyau
    d'infaisabilité ferait basculer un échec LÉGITIME en planning produit. Vert aujourd'hui (le
    comportement voulu tient déjà, le best-effort n'existe pas encore) ; sa FALSIFICATION est un
    best-effort naïf qui relâcherait la contradiction A-free → ``completed`` + ``rule_sacrificed``,
    que ce garde interdit.
    """
    payload = make_payload(
        teams=[make_team("A", sessions_per_week=1), make_team("U21M1", sessions_per_week=1)],
        venues=[
            make_venue("G1", [(1, "18:00")]),
            make_venue("G2", [(FRIDAY, "18:00")]),
        ],
        coaches=[{"id": "coach-marc", "firstName": "Marc", "lastName": "D.", "isEmployee": False}],
        constraints=[
            team_coach("tc-1", "U21M1", "coach-marc"),
            team_constraint(
                constraint_id="day-1",
                team_id="U21M1",
                family="DAY",
                rule_type="HARD",
                config={"forcedDays": [FRIDAY]},
                name="Vendredi imposé",
            ),
            {
                "id": "coach-1",
                "scope": "COACH",
                "scopeTargetId": "coach-marc",
                "family": "COACH_AVAILABILITY",
                "ruleType": "HARD",
                "name": "Indispo du vendredi",
                "config": {"unavailableDays": [FRIDAY]},
                "sortOrder": 0,
                "isActive": True,
            },
        ],
        slot_templates=[
            {
                "id": "r-A-mon18-G1",
                "teamId": "A",
                "venueId": "G1",
                "dayOfWeek": 1,
                "startTime": "18:00",
                "durationMinutes": 90,
                "lockLevel": "HARD",
            }
        ],
    )

    result = solve_payload(payload)

    assert result["status"] == "failed", (
        "une contradiction entre règles sans verrou en cause reste un ÉCHEC, même avec des "
        f"réservations ailleurs (la réservation est la SEULE exception) — obtenu {result['status']}"
    )
    assert _sacrificed(result) == [], (
        f"aucun rule_sacrificed : le best-effort ne relâche que les règles bloquées par un verrou "
        f"(obtenu {_sacrificed(result)})"
    )
    assert any(d.get("id") == "diag-infeasible" for d in result["diagnostics"]), (
        "l'échec doit rester EXPLIQUÉ (diag-infeasible nomme les règles en conflit)"
    )
