"""P2-46 — le second piège de la réservation de groupe, CONSTATÉ ici, GARDÉ en amont.

Une réservation de groupe s'éclate en N verrous HARD sur UNE case ; `add_shared_training_constraints`
lit une case ENTIÈREMENT verrouillée par le groupe comme une séance commune CERTAINE (`y == 1`,
constants.py). Réserver le MÊME groupe sur PLUS de cases que son `commonSessions` (K) rend donc
`Σ y == K` contradictoire : chaque case pleine force `y == 1`, leur somme dépasse K → le solveur
sort INFEASIBLE (status ``failed``).

Ce test ne CORRIGE rien : la garde vit en amont, au moment de l'ÉCRITURE (PR-2). Refuser une
réservation de groupe qui porterait le nombre de séances communes verrouillées au-delà de K est
une décision du rail d'écriture, pas du diagnostic post-solve — corriger ici (par ex. relâcher
l'égalité en `<=`) trahirait la sémantique EXACTE de `commonSessions` (« ni au moins ni au plus »,
SharedTrainingGroupSchema) et masquerait au gestionnaire que ses réservations se contredisent.
C'est CE constat qui motive la garde de PR-2.

Axe structurant `constraint semantics` (CLAUDE.md §7.1).
"""

from __future__ import annotations

from typing import Any

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload

TUESDAY = 2
THURSDAY = 4


def _hard_lock(lock_id: str, team_id: str, venue_id: str, day: int, start: str) -> dict[str, Any]:
    return {
        "id": lock_id,
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": 90,
        "lockLevel": "HARD",
    }


def test_group_reserved_on_more_cases_than_k_is_infeasible() -> None:
    """K=1 mais DEUX cases entièrement verrouillées par le groupe → Σ y == 1 impossible."""
    payload = make_payload(
        teams=[make_team("t1", sessions_per_week=2), make_team("t2", sessions_per_week=2)],
        venues=[make_venue("gym", [(TUESDAY, "18:00"), (THURSDAY, "18:00")], capacity=2)],
        slot_templates=[
            _hard_lock("l1", "t1", "gym", TUESDAY, "18:00"),
            _hard_lock("l2", "t2", "gym", TUESDAY, "18:00"),
            _hard_lock("l3", "t1", "gym", THURSDAY, "18:00"),
            _hard_lock("l4", "t2", "gym", THURSDAY, "18:00"),
        ],
    )
    payload["sharedTrainings"] = [{"id": "gr", "teamIds": ["t1", "t2"], "commonSessions": 1}]

    result = solve_payload(payload)

    # Le constat : deux séances communes verrouillées contre K=1 rendent le modèle infaisable.
    # (La garde qui EMPÊCHE d'en arriver là vit à l'écriture — PR-2, pas ici.)
    assert result["status"] == "failed", (
        f"réserver le groupe sur plus de cases que K doit sortir INFEASIBLE — obtenu status={result['status']!r}"
    )


def test_group_reserved_on_exactly_k_cases_stays_feasible() -> None:
    """TÉMOIN — le même groupe sur UNE seule case pleine (K=1) reste résoluble.

    Sans lui, l'infaisabilité ci-dessus pourrait tenir à une autre cause (verrous mal formés,
    sessions impossibles) plutôt qu'au SURNOMBRE de séances communes. Ici une case pleine, K=1 :
    Σ y == 1 est satisfaite, le solveur aboutit.
    """
    payload = make_payload(
        teams=[make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)],
        venues=[make_venue("gym", [(TUESDAY, "18:00")], capacity=2)],
        slot_templates=[
            _hard_lock("l1", "t1", "gym", TUESDAY, "18:00"),
            _hard_lock("l2", "t2", "gym", TUESDAY, "18:00"),
        ],
    )
    payload["sharedTrainings"] = [{"id": "gr", "teamIds": ["t1", "t2"], "commonSessions": 1}]

    result = solve_payload(payload)

    assert result["status"] == "completed", "une case pleine et K=1 doivent rester résolubles"
