"""Réservation souveraine au MOTEUR (besoin fondateur 2026-10-30, pt 3 ; amendement ADR-0001).

« La réservation gagne envers tout, mais c'est la SEULE exception. » Quand un VERROU HARD rend une
règle DURE insatisfiable, le moteur PRODUIT le planning et NOMME la règle sacrifiée — jamais un
INFEASIBLE muet (cas réel : grille réservée, INFEASIBLE en 4 ms sans rien nommer).

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

⚠ NOTE FIXTURE (cf. ``sovereign_reservation_grid.json``) : la saturation littérale 14 équipes / 21 créneaux /
26 réservations du brief est FEASIBLE aujourd'hui (la lenteur des contraintes de bloc / plancher de
séances du solveur offre des échappatoires) ; le décor est donc réduit au noyau nested-bloc-réservé
VÉRIFIÉ infaisable, seul capable de servir de ROUGE à une feature déclenchée sur INFEASIBLE.
"""

from __future__ import annotations

import asyncio
import json
import pathlib
import re
from typing import Any

from ortools.sat.python import cp_model

from app import main
from app.schemas.input_schema import ScheduleInputSchema
from tests.support.pipeline import (
    make_payload,
    make_team,
    make_venue,
    solve_payload,
    team_coach,
    team_constraint,
)

FIXTURE = pathlib.Path(__file__).resolve().parents[1] / "fixtures" / "sovereign_reservation_grid.json"
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
        f"une réservation souveraine doit PRODUIRE le planning, jamais INFEASIBLE muet (obtenu {result['status']})"
    )
    sacrificed = _sacrificed(result)
    assert sacrificed, "une règle dure rendue insatisfiable par le verrou doit être NOMMÉE (rule_sacrificed)"
    assert all(d.get("severity") == "WARNING" for d in sacrificed), (
        f"un sacrifice est une ALERTE, pas une erreur bloquante : {sacrificed}"
    )
    for diagnostic in sacrificed:
        _assert_screen_wording(diagnostic)


def test_without_the_third_party_reservation_the_block_places_and_nothing_is_sacrificed() -> None:
    payload = _fixture()
    payload["slotTemplates"] = [t for t in payload["slotTemplates"] if t["id"] != THIRD_PARTY_RESERVATION_ID]

    result = solve_payload(payload)

    assert result["status"] == "completed", "sans la réservation tierce, le bloc retrouve sa case"
    assert _reservation_signals(result) == [], (
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
    assert _reservation_signals(result) == [], (
        f"aucun rule_sacrificed : le best-effort ne relâche que les règles bloquées par un verrou "
        f"(obtenu {_sacrificed(result)})"
    )
    assert any(d.get("id") == "diag-infeasible" for d in result["diagnostics"]), (
        "l'échec doit rester EXPLIQUÉ (diag-infeasible nomme les règles en conflit)"
    )


# ── Portée élargie (décision fondateur 2026-10-30) : TOUTE règle contredite par un verrou ────────
#
# « On place la séance et on dit que la contrainte d'indisponibilité a été violée avec la réservation
# X. » Deux cas, tranchés par le fondateur le 2026-10-30 :
#   * la règle contredit LA réservation elle-même : la réservation est placée, la règle sacrifiée ;
#   * la réservation d'une AUTRE équipe prend la seule case compatible : la règle n'est PAS relâchée, la
#     séance reste NON placée, le résultat reste ``completed``, sans repartir en INFEASIBLE.
# Dans les deux cas, UN SEUL message remplace les signaux historiques : ``rule_sacrificed`` pour le
# premier (et le groupe privé de sa case), ``blocked_by_reservation`` pour le second.


def _reservation(team_id: str, venue_id: str, day: int, start: str = "18:00") -> dict[str, Any]:
    return {
        "id": f"resa-{team_id}-{day}-{start}",
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": 90,
        "lockLevel": "HARD",
    }


# Les signaux historiques qu'un ``rule_sacrificed`` remplace dès qu'une réservation est en cause.
_REPLACED_SIGNALS = {
    "constraint_not_honored",
    "session_below_effective_min",
    "conflict",
    "unplaced",
    "venue_minimum_unreachable",
    "day_constraint_conflict",
}


def _assert_screen_wording(diagnostic: dict[str, Any]) -> None:
    """Décision fondateur 2026-10-30 : à l'écran, « la séance fixée de X » pour tout verrou, réservation
    ou manuel. Le texte lu est le message, les libellés de cause et les suggestions."""
    causes = [str(c.get("label") or "") for c in diagnostic.get("causes", [])]
    read = " ".join([str(diagnostic["message"]), *causes, *(str(s) for s in diagnostic.get("suggestions") or [])])
    lowered = read.lower()
    assert "verrou" not in lowered, f"jamais « verrou » à l'écran : {read!r}"
    assert not re.search(r"\bréservations?\b", lowered), f"jamais « réservation » pour une séance fixée : {read!r}"
    assert re.search(r"\bséances? fixées?\b", lowered), f"le verrou se dit « la séance fixée de X » : {read!r}"


def _assert_single_message(result: dict[str, Any], *, expected_type: str) -> None:
    other_type = {"rule_sacrificed": "blocked_by_reservation", "blocked_by_reservation": "rule_sacrificed"}[
        expected_type
    ]
    duplicates = [d for d in result["diagnostics"] if d.get("type") in _REPLACED_SIGNALS | {other_type}]
    assert duplicates == [], f"un seul message quand une réservation est en cause, doublons : {duplicates}"


def _reservation_signals(result: dict[str, Any]) -> list[dict[str, Any]]:
    return [d for d in result["diagnostics"] if d.get("type") in {"rule_sacrificed", "blocked_by_reservation"}]


def _slots_of(result: dict[str, Any], team_id: str) -> list[int]:
    return [s["dayOfWeek"] for s in result["slots"] if s["teamId"] == team_id]


def _named_team(team_id: str, name: str, *, sessions_per_week: int = 1) -> dict[str, Any]:
    return {**make_team(team_id, sessions_per_week=sessions_per_week), "name": name}


def _coach(coach_id: str = "c") -> dict[str, Any]:
    return {"id": coach_id, "firstName": "Ana", "lastName": "Berger", "isEmployee": False}


def _hard_team_rule(rule_id: str, team_id: str, family: str, config: dict[str, Any], name: str) -> dict[str, Any]:
    return team_constraint(
        constraint_id=rule_id, team_id=team_id, family=family, rule_type="HARD", config=config, name=name
    )


def _coach_off(rule_id: str, coach_id: str, day: int, name: str) -> dict[str, Any]:
    return {
        "id": rule_id,
        "scope": "COACH",
        "scopeTargetId": coach_id,
        "family": "COACH_AVAILABILITY",
        "ruleType": "HARD",
        "name": name,
        "config": {"unavailableDays": [day]},
        "sortOrder": 0,
        "isActive": True,
    }


def _only_sacrifice_of_kind(result: dict[str, Any], kind: str) -> dict[str, Any]:
    """Le ``rule_sacrificed`` de ce kind : il doit exister, une alerte, sur un planning produit."""
    assert result["status"] == "completed", f"le verrou gagne : le planning est produit (obtenu {result['status']})"
    matching = [d for d in _sacrificed(result) if kind in {c.get("kind") for c in d.get("causes", [])}]
    assert matching, f"écart attendu en rule_sacrificed, kind {kind} ; diagnostics : {result['diagnostics']}"
    assert matching[0]["severity"] == "WARNING", "un sacrifice est une alerte"
    _assert_single_message(result, expected_type="rule_sacrificed")
    _assert_screen_wording(matching[0])
    return matching[0]


def _only_block_of_kind(result: dict[str, Any], kind: str) -> dict[str, Any]:
    """Le ``blocked_by_reservation`` de ce kind : la règle tient, la séance reste non placée, planning produit."""
    assert result["status"] == "completed", f"une séance non placée ne fait pas échouer (obtenu {result['status']})"
    matching = [
        d
        for d in result["diagnostics"]
        if d.get("type") == "blocked_by_reservation" and kind in {c.get("kind") for c in d.get("causes", [])}
    ]
    assert matching, f"écart attendu en blocked_by_reservation, kind {kind} ; diagnostics : {result['diagnostics']}"
    assert matching[0]["severity"] == "WARNING", "un blocage par réservation est une alerte"
    _assert_single_message(result, expected_type="blocked_by_reservation")
    _assert_screen_wording(matching[0])
    return matching[0]


def _assert_names(diagnostic: dict[str, Any], *expected: str) -> None:
    # La règle est nommée une fois : dans le message, ou dans la cause que le panneau affiche.
    labels = " ".join(str(c.get("label")) for c in diagnostic.get("causes", []))
    message = f"{diagnostic['message']} {labels}"
    for fragment in expected:
        assert fragment in message, f"{fragment!r} doit être nommé : {message!r}"


def test_the_only_coach_off_on_the_reserved_case_is_a_named_violation_with_the_reservation() -> None:
    """Le seul coach d'une équipe est indisponible sur sa case réservée : la séance reste placée et
    l'écart nomme le coach, la règle, l'équipe et la réservation (jour, heure)."""
    result = solve_payload(
        make_payload(
            teams=[_named_team("T", "Seniors A")],
            venues=[make_venue("V", [(4, "18:00")])],
            coaches=[_coach()],
            constraints=[team_coach("tc", "T", "c"), _coach_off("off", "c", 4, "Indispo du jeudi")],
            slot_templates=[_reservation("T", "V", 4)],
        )
    )

    diagnostic = _only_sacrifice_of_kind(result, "coach_unavailability")
    assert [(s["teamId"], s["dayOfWeek"]) for s in result["slots"]] == [("T", 4)], "la séance réservée reste placée"
    assert diagnostic["teamId"] == "T"
    _assert_names(diagnostic, "Ana Berger", "Indispo du jeudi", "Seniors A", "jeudi", "18:00")
    assert diagnostic["causes"][0]["constraintId"] == "off", "la règle doit rester cliquable (constraintId)"
    # Le panneau ouvre la case réservée à partir de ces champs.
    assert (diagnostic["venueId"], diagnostic["dayOfWeek"], diagnostic["startTime"]) == ("V", 4, "18:00")


def test_a_grid_exhausted_by_other_teams_locks_names_the_team_and_the_session_gap() -> None:
    """Équipe à 2 séances, 1 réservation, aucune case libre à cause du verrou d'une AUTRE équipe : la
    séance manquante reste non placée, l'écart nomme l'équipe, le manque (2 demandées, 1 placée) et la
    réservation en cause."""
    result = solve_payload(
        make_payload(
            teams=[_named_team("T", "Seniors A", sessions_per_week=2), _named_team("U", "Cadets")],
            venues=[make_venue("V", [(1, "18:00"), (2, "18:00")])],
            slot_templates=[_reservation("T", "V", 1), _reservation("U", "V", 2)],
        )
    )

    diagnostic = _only_block_of_kind(result, "min_sessions")
    assert diagnostic["teamId"] == "T"
    assert _slots_of(result, "T") == [1], "seule la séance réservée est posée"
    _assert_names(diagnostic, "Seniors A", "2", "1", "Cadets")


def test_a_hard_time_window_excluding_the_reserved_case_is_a_named_violation() -> None:
    """Fenêtre horaire dure qui exclut la case réservée de l'équipe elle-même."""
    window = _hard_team_rule("w", "T", "TIME", {"maxStartTime": "18:00"}, "Pas après 18h")
    result = solve_payload(
        make_payload(
            teams=[_named_team("T", "Seniors A")],
            venues=[make_venue("V", [(1, "20:00")])],
            constraints=[window],
            slot_templates=[_reservation("T", "V", 1, "20:00")],
        )
    )

    _assert_names(_only_sacrifice_of_kind(result, "time_window"), "Pas après 18h", "Seniors A", "lundi", "20:00")


def test_a_hard_forbidden_day_on_the_reserved_case_is_a_named_violation() -> None:
    """Jour interdit en dur alors que la réservation de l'équipe tombe ce jour-là."""
    forbidden = _hard_team_rule("d", "T", "DAY", {"forbiddenDays": [1]}, "Pas le lundi")
    result = solve_payload(
        make_payload(
            teams=[_named_team("T", "Seniors A")],
            venues=[make_venue("V", [(1, "18:00")])],
            constraints=[forbidden],
            slot_templates=[_reservation("T", "V", 1)],
        )
    )

    _assert_names(_only_sacrifice_of_kind(result, "day_forbidden"), "Pas le lundi", "Seniors A", "lundi", "18:00")


def test_a_forced_day_taken_by_another_teams_reservation_leaves_the_session_unplaced_and_named() -> None:
    """Le jour imposé n'a qu'une case, réservée par une autre équipe. Une case libre existe un autre
    jour : le moteur n'y place JAMAIS la séance, la règle n'est pas relâchée. Résultat ``completed``,
    séance non placée, message nommant l'équipe, la règle et la réservation. Aujourd'hui : ``failed``."""
    forced = _hard_team_rule("d", "T", "DAY", {"forcedDays": [FRIDAY]}, "Vendredi imposé")
    result = solve_payload(
        make_payload(
            teams=[_named_team("T", "Seniors A"), _named_team("U", "Cadets")],
            venues=[make_venue("V", [(1, "18:00"), (FRIDAY, "18:00")])],
            constraints=[forced],
            slot_templates=[_reservation("U", "V", FRIDAY)],
        )
    )

    diagnostic = _only_block_of_kind(result, "day_forced")
    assert _slots_of(result, "T") == [], "la séance ne se place jamais hors de sa règle"
    _assert_names(diagnostic, "Vendredi imposé", "Seniors A", "Cadets")


def test_a_forced_day_whose_only_grid_case_is_reserved_does_not_go_infeasible() -> None:
    """Aucune case possible du tout pour l'équipe non réservée : le résultat ne repart pas en
    INFEASIBLE à cause de cette seule règle."""
    forced = _hard_team_rule("d", "T", "DAY", {"forcedDays": [FRIDAY]}, "Vendredi imposé")
    result = solve_payload(
        make_payload(
            teams=[_named_team("T", "Seniors A"), _named_team("U", "Cadets")],
            venues=[make_venue("V", [(FRIDAY, "18:00")])],
            constraints=[forced],
            slot_templates=[_reservation("U", "V", FRIDAY)],
        )
    )

    diagnostic = _only_block_of_kind(result, "day_forced")
    assert _slots_of(result, "T") == []
    _assert_names(diagnostic, "Vendredi imposé", "Seniors A", "Cadets")


def test_a_hard_window_whose_only_case_is_reserved_leaves_the_session_unplaced_and_named() -> None:
    """Fenêtre dure dont la seule case compatible est réservée par une autre équipe : la case libre
    hors fenêtre n'est pas utilisée, la séance reste non placée et le message nomme tout."""
    window = _hard_team_rule("w", "T", "TIME", {"maxStartTime": "18:00"}, "Pas après 18h")
    result = solve_payload(
        make_payload(
            teams=[_named_team("T", "Seniors A"), _named_team("U", "Cadets")],
            venues=[make_venue("V", [(1, "18:00"), (1, "20:00")])],
            constraints=[window],
            slot_templates=[_reservation("U", "V", 1)],
        )
    )

    diagnostic = _only_block_of_kind(result, "time_window")
    assert _slots_of(result, "T") == [], "la séance ne se place jamais hors de sa fenêtre"
    _assert_names(diagnostic, "Pas après 18h", "Seniors A", "Cadets")


def test_a_venue_floor_taken_by_another_teams_reservation_sends_the_session_elsewhere_and_is_named() -> None:
    """Plancher « au moins 1 séance à W » dont la seule case est réservée par une autre équipe : un
    plancher n'interdit pas les autres gymnases, la séance va à V, seul le minimum manque."""
    floor = _hard_team_rule("f", "T", "FACILITY", {"minAtVenueId": "W", "minAtVenueCount": 1}, "Au moins 1 à W")
    result = solve_payload(
        make_payload(
            teams=[_named_team("T", "Seniors A"), _named_team("U", "Cadets")],
            venues=[make_venue("V", [(1, "18:00")]), make_venue("W", [(2, "18:00")])],
            constraints=[floor],
            slot_templates=[_reservation("U", "W", 2)],
        )
    )

    diagnostic = _only_block_of_kind(result, "session_floor")
    assert _slots_of(result, "T") == [1], "un plancher n'interdit pas les autres gymnases"
    assert "0 séance à W au lieu de 1" in diagnostic["message"], diagnostic["message"]
    _assert_names(diagnostic, "Au moins 1 à W", "Seniors A", "Cadets")


def test_a_coach_also_playing_on_two_simultaneous_reservations_is_a_named_violation() -> None:
    """Une personne coache E et joue en J, deux réservations simultanées dans deux gymnases."""
    player_link = {
        "id": "pl",
        "type": "COACH_PLAYER_UNAVAILABILITY",
        "teamId": "J",
        "severity": "HARD",
        "value": "c",
        "metadata": {"coachId": "c"},
    }
    result = solve_payload(
        make_payload(
            teams=[_named_team("E", "Benjamins"), _named_team("J", "Seniors B")],
            venues=[make_venue("V", [(4, "19:00")]), make_venue("W", [(4, "19:00")])],
            coaches=[_coach()],
            constraints=[team_coach("tc", "E", "c"), player_link],
            slot_templates=[_reservation("E", "V", 4, "19:00"), _reservation("J", "W", 4, "19:00")],
        )
    )

    diagnostic = _only_sacrifice_of_kind(result, "hard_lock")
    _assert_names(diagnostic, "Ana Berger", "Benjamins", "Seniors B", "jeudi", "19:00")
    # Le panneau surligne les deux cases à partir de ces champs : ils survivent au changement de type.
    assert (diagnostic["coachId"], diagnostic["dayOfWeek"], diagnostic["startTime"]) == ("c", 4, "19:00")


def test_a_reservation_that_contradicts_nothing_sacrifices_nothing() -> None:
    """TÉMOIN : une réservation compatible avec les règles ne produit aucun écart. Sans lui, un moteur
    qui émettrait ``rule_sacrificed`` dès qu'un verrou existe passerait les tests ci-dessus."""
    result = solve_payload(
        make_payload(
            teams=[_named_team("T", "Seniors A")],
            venues=[make_venue("V", [(4, "18:00"), (1, "18:00")])],
            coaches=[_coach()],
            constraints=[team_coach("tc", "T", "c"), _coach_off("off", "c", 1, "Indispo du lundi")],
            slot_templates=[_reservation("T", "V", 4)],
        )
    )

    assert result["status"] == "completed"
    assert _reservation_signals(result) == [], f"aucun écart attendu, obtenu {_sacrificed(result)}"


def test_a_core_rule_no_lock_contradicts_is_not_relaxed_and_the_result_stays_failed() -> None:
    """LIMITE fondateur : le noyau mêle un groupe bloqué par un verrou ET une contradiction entre deux
    règles sans verrou (X). Relâcher le groupe ne suffit pas, X reste impossible : ``failed`` expliqué,
    aucun écart annoncé. Complète, avec un verrou réellement en cause, le garde-fou
    ``test_a_rule_conflict_not_caused_by_a_lock_stays_failed_even_with_reservations_elsewhere``."""
    payload = make_payload(
        teams=[make_team("t1"), make_team("t2"), make_team("t3"), make_team("X")],
        venues=[make_venue("V", [(1, "18:00")]), make_venue("W", [(FRIDAY, "18:00")])],
        coaches=[_coach()],
        constraints=[
            team_coach("tc", "X", "c"),
            _hard_team_rule("d", "X", "DAY", {"forcedDays": [FRIDAY]}, "Vendredi imposé"),
            _coach_off("off", "c", FRIDAY, "Indispo du vendredi"),
        ],
        slot_templates=[_reservation("t3", "V", 1)],
    )
    payload["sharedBlocks"] = [{"id": "b", "teamIds": ["t1", "t2"], "commonSessions": 1}]

    result = solve_payload(payload)

    assert result["status"] == "failed", f"X reste impossible sans verrou en cause (obtenu {result['status']})"
    assert _reservation_signals(result) == [], f"aucun écart sur un planning non produit, obtenu {_sacrificed(result)}"
    infeasible = next(d for d in result["diagnostics"] if d.get("id") == "diag-infeasible")
    assert "Vendredi imposé" in infeasible["message"] and "Indispo du vendredi" in infeasible["message"]


def test_without_any_reservation_the_historic_signals_do_not_change() -> None:
    """TÉMOIN Q1 : aucun verrou, donc aucun remplacement. Le manque de séances reste un
    ``session_below_effective_min``. Les ``conflict`` d'échec sans verrou sont gardés par le garde-fou
    ci-dessus, les ``constraint_not_honored`` de parsing par ``test_unhonorable_rule_emits_an_explicit_diagnostic``."""
    result = solve_payload(
        make_payload(teams=[make_team("T", sessions_per_week=2)], venues=[make_venue("V", [(1, "18:00")])])
    )

    assert result["status"] == "completed"
    assert _reservation_signals(result) == []
    assert [d["type"] for d in result["diagnostics"]] == ["session_below_effective_min"]


def test_a_forced_day_the_grid_never_offers_stays_failed_even_with_reservations_elsewhere() -> None:
    """LIMITE Q2 : la grille n'a aucune case ce jour-là, et aucune réservation n'y est pour rien. Ce
    n'est pas « la seule case prise par un verrou » : le résultat reste ``failed``."""
    forced = _hard_team_rule("d", "T", "DAY", {"forcedDays": [FRIDAY]}, "Vendredi imposé")
    result = solve_payload(
        make_payload(
            teams=[_named_team("T", "Seniors A"), _named_team("U", "Cadets")],
            venues=[make_venue("V", [(1, "18:00"), (2, "18:00")])],
            constraints=[forced],
            slot_templates=[_reservation("U", "V", 1)],
        )
    )

    assert result["status"] == "failed", f"aucun verrou en cause (obtenu {result['status']})"
    assert _reservation_signals(result) == []


def test_two_floors_sharing_the_one_case_left_by_a_reservation_place_one_team_and_name_the_other() -> None:
    """Une réservation tierce laisse UNE case pour deux planchers : la case libre sert une équipe, seule
    l'autre reste non placée. Les laisser toutes les deux dehors gâcherait une case que la règle permet.
    Jumeau backend : ``CapacityMirrorParityTest::testSaturationVerdictMatchesEngineFeasibility``."""
    floors = [
        _hard_team_rule(
            f"f-{team}", team, "FACILITY", {"minAtVenueId": "V", "minAtVenueCount": 1}, f"Au moins 1 {team}"
        )
        for team in ("t1", "t2")
    ]
    result = solve_payload(
        make_payload(
            teams=[make_team("t1"), make_team("t2"), make_team("t3")],
            venues=[make_venue("V", [(1, "18:00"), (3, "18:00")])],
            constraints=floors,
            slot_templates=[_reservation("t3", "V", 1)],
        )
    )

    assert result["status"] == "completed"
    placed = [team for team in ("t1", "t2") if _slots_of(result, team) == [3]]
    assert len(placed) == 1, f"la case libre doit servir une équipe, obtenu {result['slots']}"
    blocked = [d["teamId"] for d in result["diagnostics"] if d.get("type") == "blocked_by_reservation"]
    assert blocked == [next(t for t in ("t1", "t2") if t not in placed)], (
        f"seule l'équipe non placée est nommée : {blocked}"
    )


# ── Contre-revue (décisions fondateur 2026-10-30) ────────────────────────────────────────────────


def _floor(team: str, venue: str, count: int) -> dict[str, Any]:
    return _hard_team_rule(
        f"f-{team}",
        team,
        "FACILITY",
        {"minAtVenueId": venue, "minAtVenueCount": count},
        f"Au moins {count} à {venue}",
    )


def test_a_team_whose_own_reservations_serve_its_floor_does_not_turn_the_probe_against_another_team() -> None:
    """Sonde de causalité : T exige 2 séances à V et ses 2 réservations remplissent V ; U exige 1 séance
    à V. Libérer les verrous sans garder le crédit de T ferait croire à une grille trop petite (2+1 > 2),
    donc à un échec sans réservation en cause. C'est bien la réservation qui prive U.
    Jumeau backend : ``CapacityMirrorParityTest::testFloorServedByOwnLocksStaysAWarningOnBothSides``."""
    result = solve_payload(
        make_payload(
            teams=[_named_team("T", "Seniors A", sessions_per_week=2), _named_team("U", "Cadets")],
            venues=[make_venue("V", [(1, "18:00"), (2, "18:00")])],
            constraints=[_floor("T", "V", 2), _floor("U", "V", 1)],
            slot_templates=[_reservation("T", "V", 1), _reservation("T", "V", 2)],
        )
    )

    assert result["status"] == "completed", f"c'est la réservation qui prive U (obtenu {result['status']})"
    assert sorted(_slots_of(result, "T")) == [1, 2], "les séances réservées de T tiennent"
    assert _slots_of(result, "U") == [], "U n'a plus de case à V"
    diagnostic = _only_block_of_kind(result, "session_floor")
    assert diagnostic["teamId"] == "U"
    _assert_names(diagnostic, "Cadets", "Seniors A")


def test_a_team_losing_one_case_of_its_floor_keeps_all_its_sessions_and_only_the_minimum_is_missing() -> None:
    """T veut 3 séances dont 2 à V ; V a 2 jours, U en réserve un ; W a 3 cases libres. T garde ses 3
    séances (1 à V, 2 à W), seul le minimum manque. Le message dit l'écart chiffré et nomme U."""
    result = solve_payload(
        make_payload(
            teams=[_named_team("T", "Seniors A", sessions_per_week=3), _named_team("U", "Cadets")],
            venues=[
                make_venue("V", [(1, "18:00"), (2, "18:00")]),
                make_venue("W", [(3, "18:00"), (4, "18:00"), (FRIDAY, "18:00")]),
            ],
            constraints=[_floor("T", "V", 2)],
            slot_templates=[_reservation("U", "V", 2)],
        )
    )

    placed = [s["venueId"] for s in result["slots"] if s["teamId"] == "T"]
    assert sorted(placed) == ["V", "W", "W"], f"T garde ses 3 séances, 1 à V et 2 à W : {result['slots']}"
    diagnostic = _only_block_of_kind(result, "session_floor")
    assert "1 séance à V au lieu de 2" in diagnostic["message"], diagnostic["message"]
    _assert_names(diagnostic, "Seniors A", "Cadets")


def test_a_block_losing_one_of_its_two_cases_drops_only_the_missing_common_session() -> None:
    """Groupe à 2 séances communes, deux cases communes possibles (lundi, mardi), lundi réservé par t3.
    La séance commune de mardi reste : les deux membres s'y retrouvent, et chacun garde sa 2ᵉ séance sur
    une case que lui seul peut prendre. Seule la commune manquante tombe."""
    payload = make_payload(
        teams=[make_team("t1", sessions_per_week=2), make_team("t2", sessions_per_week=2), make_team("t3")],
        venues=[make_venue("V", [(1, "18:00"), (2, "18:00"), (3, "18:00"), (4, "18:00")])],
        constraints=[
            _hard_team_rule("a", "t1", "DAY", {"forbiddenDays": [4]}, "t1 jamais le jeudi"),
            _hard_team_rule("b", "t2", "DAY", {"forbiddenDays": [3]}, "t2 jamais le mercredi"),
        ],
        slot_templates=[_reservation("t3", "V", 1)],
    )
    payload["sharedBlocks"] = [{"id": "b", "teamIds": ["t1", "t2"], "commonSessions": 2}]

    result = solve_payload(payload)

    assert result["status"] == "completed"
    assert sorted(_slots_of(result, "t1")) == [2, 3], f"commune de mardi + sa 2ᵉ séance : {result['slots']}"
    assert sorted(_slots_of(result, "t2")) == [2, 4], f"commune de mardi + sa 2ᵉ séance : {result['slots']}"
    assert _sacrificed(result), "le groupe privé d'une case est nommé"


def test_a_forced_day_whose_only_case_is_taken_removes_only_that_session() -> None:
    """Décision antérieure tenue : le jour imposé pris reste NON placé, jamais déporté. Mais une équipe à
    2 séances ne perd que celle-là : l'autre se place sur la case libre."""
    forced = _hard_team_rule("d", "T", "DAY", {"forcedDays": [FRIDAY]}, "Vendredi imposé")
    result = solve_payload(
        make_payload(
            teams=[_named_team("T", "Seniors A", sessions_per_week=2), _named_team("U", "Cadets")],
            venues=[make_venue("V", [(1, "18:00"), (FRIDAY, "18:00")])],
            constraints=[forced],
            slot_templates=[_reservation("U", "V", FRIDAY)],
        )
    )

    assert _slots_of(result, "T") == [1], f"seule la séance du jour imposé tombe : {result['slots']}"
    _only_block_of_kind(result, "day_forced")


def test_the_causality_probe_stays_within_its_budget_and_skips_the_chaining_phase(monkeypatch: Any) -> None:
    """Budget mesuré à 29,2 s pour 15 s : la sonde enchaînait la phase de chaînage. Elle répond à une seule
    question (faisable sans verrou ?), un seul solve suffit, sous son plafond."""
    budgets: list[float] = []
    real_solve = cp_model.CpSolver.Solve

    def spy(self: Any, *args: Any, **kwargs: Any) -> Any:
        budgets.append(self.parameters.max_time_in_seconds)
        return real_solve(self, *args, **kwargs)

    # Deux équipes du même coach sur des cases voisines : la phase de chaînage s'ouvre si on la laisse faire.
    data = make_payload(
        teams=[make_team("A"), make_team("B")],
        venues=[make_venue("V", [(1, "18:00"), (1, "19:30")])],
        coaches=[_coach()],
        constraints=[team_coach("tc-a", "A", "c"), team_coach("tc-b", "B", "c")],
    )
    input_data = ScheduleInputSchema.model_validate(data)
    witness: list[float] = []
    monkeypatch.setattr(cp_model.CpSolver, "Solve", spy)
    main._solve(data, input_data, budget_override=main.BEST_EFFORT_PROBE_MAX_SECONDS)
    witness.extend(budgets)
    budgets.clear()
    assert len(witness) == 2, f"témoin : ce décor ouvre bien la phase de chaînage (solves : {witness})"

    feasible = asyncio.run(main._locks_are_the_cause(data, input_data))

    assert feasible is True
    assert len(budgets) == 1, f"la sonde ne lance pas la phase de chaînage (solves : {budgets})"
    assert budgets[0] <= main.BEST_EFFORT_PROBE_MAX_SECONDS
