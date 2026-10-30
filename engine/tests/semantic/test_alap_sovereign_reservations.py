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

from tests.support.pipeline import solve_payload

FIXTURE = pathlib.Path(__file__).resolve().parents[1] / "fixtures" / "alap_synthetic.json"
THIRD_PARTY_RESERVATION_ID = "r-U18-thu20-G1"


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
