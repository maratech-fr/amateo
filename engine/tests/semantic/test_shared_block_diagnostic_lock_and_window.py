"""P4-322 — le diagnostic d'un bloc mutualisé non honoré NOMME deux causes de plus que « un coach
suffit » : un VERROU HARD d'une équipe NON membre sur la seule case commune, et une FENÊTRE HORAIRE
dure d'un membre qui exclut la case.

Axe §7.1 *constraint semantics* : quand un groupe n'est pas honoré, le diagnostic nomme l'équipe et
la case qui bloquent, dit POURQUOI et QUOI FAIRE (décision fondateur Q3, 2026-10-13). De bout en
bout contre le VRAI pipeline (``solve_payload``) — un bloc infaisable pour l'une de ces raisons
retombait avant sur le message générique ``diag-infeasible``, sans nommer le groupe ni la cause.

Chaque test est écrit pour ROUGIR si le rail de preuve est retiré (le diagnostic
``shared_block_not_honored`` disparaîtrait au profit du générique) et porte un TÉMOIN qui prouve que
le rail ne sur-accuse pas (cause levée ⇒ bloc placé, aucun diagnostic de bloc).
"""

from __future__ import annotations

from typing import Any

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload, team_constraint


def _hard_lock(team_id: str, venue_id: str, day: int, start: str, *, duration: int = 90) -> dict[str, Any]:
    return {
        "id": f"lock-{team_id}-{venue_id}-{day}-{start}",
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": duration,
        "lockLevel": "HARD",
    }


def _block(block_id: str, team_ids: list[str], common_sessions: int = 1) -> dict[str, Any]:
    return {"id": block_id, "teamIds": team_ids, "commonSessions": common_sessions}


def _shared_block_diags(result: dict[str, Any]) -> list[dict[str, Any]]:
    return [d for d in result.get("diagnostics", []) if d.get("type") == "shared_block_not_honored"]


def _cause_kinds(diag: dict[str, Any]) -> list[str]:
    return [str(c.get("kind")) for c in diag.get("causes", [])]


# ── Cause 1 : un verrou HARD d'une équipe NON membre ferme la seule case commune ─────────────────


def test_nonmember_hard_lock_on_the_only_common_case_names_the_group_and_the_lock() -> None:
    """Bloc {t1,t2} (1 séance commune), gymnase V capacité 1 avec UNE case lundi 18:00. t3 (NON
    membre) est épinglé HARD sur cette case : le comblement ne garde le candidat que pour un
    partenaire — t3 n'en est pas un → la case est retirée au bloc.

    ⚠ AMENDÉ le 2026-10-30 (GO fondateur, ADR-0001 amendée « la réservation gagne ») : ce cas sortait
    ``failed`` + ``shared_block_not_honored``. Tout verrou HARD étant souverain, le planning est
    désormais PRODUIT et le groupe sacrifié est NOMMÉ AVEC la réservation en cause (équipe, case) en
    ``rule_sacrificed`` WARNING. Jumeau backend, angle bout-en-bout HTTP :
    ``SharedBlockCoachRelayGateTest::testNonMemberLockAndHardTimeWindowNameTheGroup``."""
    payload = make_payload(
        teams=[make_team("t1"), make_team("t2"), make_team("t3")],
        venues=[make_venue("V", [(1, "18:00")], capacity=1)],
        slot_templates=[_hard_lock("t3", "V", 1, "18:00")],
    )
    payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "completed", "un verrou HARD est souverain : le planning est produit"
    assert not _shared_block_diags(result), "un groupe sacrifié est une alerte, pas un échec du groupe"
    sacrificed = [d for d in result["diagnostics"] if d.get("type") == "rule_sacrificed"]
    assert sacrificed, "le groupe sacrifié doit être NOMMÉ (rule_sacrificed), jamais tu"
    locked = next((d for d in sacrificed if "hard_lock" in _cause_kinds(d)), None)
    assert locked is not None, "la cause verrou non-membre doit être portée (kind hard_lock)"
    assert locked["severity"] == "WARNING", "un sacrifice est une alerte"
    message = str(locked["message"])
    # Nomme le groupe, l'équipe qui verrouille, la case et dit quoi faire.
    assert "t1" in message and "t2" in message, f"le groupe doit être nommé : {message}"
    assert "t3" in message, f"l'équipe qui verrouille doit être nommée : {message}"
    assert "lundi" in message and "18:00" in message, f"la case réservée doit être nommée : {message}"
    actions = " ".join([message, *locked.get("suggestions", [])])
    assert "Libérez" in actions or "déplacez" in actions, f"le diagnostic doit dire quoi faire : {actions}"
    lock_cause = next(c for c in locked["causes"] if c.get("kind") == "hard_lock")
    assert lock_cause["count"] == 1


def test_nonmember_lock_witness_a_second_free_case_places_the_block_without_diag() -> None:
    """TÉMOIN (anti sur-accusation) : la MÊME configuration avec une DEUXIÈME case libre (mardi
    18:00). t3 ne verrouille que la case du lundi : le bloc se place le mardi → COMPLETED, aucun
    ``shared_block_not_honored``. Sans ce témoin, un rail qui accuserait dès qu'un verrou existe
    passerait au vert."""
    payload = make_payload(
        teams=[make_team("t1"), make_team("t2"), make_team("t3")],
        venues=[make_venue("V", [(1, "18:00"), (2, "18:00")], capacity=1)],
        slot_templates=[_hard_lock("t3", "V", 1, "18:00")],
    )
    payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "completed", "une case commune libre subsiste : le bloc se place"
    assert not _shared_block_diags(result), "aucune pénurie prouvée ⇒ aucun bloc accusé"


# ── Cause 2 : une fenêtre horaire dure d'un membre exclut la seule case commune ──────────────────


def test_hard_time_window_of_a_member_closes_the_only_case_names_the_group_and_window() -> None:
    """Bloc {t1,t2} (1 séance commune), gymnase V capacité 1 avec UNE case lundi 20:00. t1 porte une
    fenêtre horaire dure « début au plus tard 18:00 » : 20:00 lui est interdit → le bloc ne peut
    réunir ses équipes → INFEASIBLE. Le diagnostic nomme le groupe et porte la contrainte en cause
    (kind ``time_window``, de quoi composer son origine côté backend)."""
    window = team_constraint(
        constraint_id="win-t1-late",
        team_id="t1",
        family="TIME",
        rule_type="HARD",
        config={"maxStartTime": "18:00"},
        name="pas de séance après 18h",
    )
    payload = make_payload(
        teams=[make_team("t1"), make_team("t2")],
        venues=[make_venue("V", [(1, "20:00")], capacity=1)],
        constraints=[window],
    )
    payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "failed", "la fenêtre horaire ferme la seule case : INFEASIBLE"
    diags = _shared_block_diags(result)
    assert diags, "l'échec doit NOMMER le groupe, jamais le générique muet"
    windowed = next((d for d in diags if "time_window" in _cause_kinds(d)), None)
    assert windowed is not None, "la cause fenêtre horaire doit être portée (kind time_window)"
    message = str(windowed["message"])
    assert "t1" in message and "t2" in message, f"le groupe doit être nommé : {message}"
    # Q3 : la cause fenêtre NOMME l'équipe dont la fenêtre ferme les cases ET le libellé de la règle.
    assert "« pas de séance après 18h » de t1" in message, (
        f"l'équipe et la règle en cause doivent être nommées : {message}"
    )
    cause = next(c for c in windowed["causes"] if c.get("kind") == "time_window")
    assert cause["constraintId"] == "win-t1-late", "la contrainte doit être nommée pour composer son origine"
    assert cause["label"] == "pas de séance après 18h"
    assert cause["count"] == 1


def test_time_window_witness_relaxed_window_places_the_block_without_diag() -> None:
    """TÉMOIN : la MÊME configuration avec une fenêtre élargie (« début au plus tard 21:00 »)
    autorise 20:00 → le bloc se place, aucun ``shared_block_not_honored``."""
    window = team_constraint(
        constraint_id="win-t1-ok",
        team_id="t1",
        family="TIME",
        rule_type="HARD",
        config={"maxStartTime": "21:00"},
        name="pas de séance après 21h",
    )
    payload = make_payload(
        teams=[make_team("t1"), make_team("t2")],
        venues=[make_venue("V", [(1, "20:00")], capacity=1)],
        constraints=[window],
    )
    payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "completed", "la fenêtre élargie autorise la case : le bloc se place"
    assert not _shared_block_diags(result), "aucune pénurie prouvée ⇒ aucun bloc accusé"
