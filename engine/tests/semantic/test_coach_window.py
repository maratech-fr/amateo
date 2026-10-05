"""NR — P3-17 : la FENÊTRE HORAIRE d'une indisponibilité coach borne vraiment.

Livrée en lot C (#195, bump de contrat 2.0→2.1), la fenêtre `fromTime`/`untilTime`
n'avait aucun test SÉMANTIQUE : `test_constraints.py` vérifie le PARSE (les
intervalles produits), rien ne prouvait que le solveur place autrement selon la
fenêtre.

⚠ CE QUE CES CAS ÉVITENT (leçon du 2026-08-06). Un premier jeu de cellules de
matrice a été écrit avec un créneau unique DANS la fenêtre bloquée : il passait
au vert même en NEUTRALISANT la fenêtre côté moteur (`from_min, to_min = 0, 1440`),
parce que bloquer la journée entière est PLUS restrictif — le test constatait
« la séance n'est pas là », ce que les deux règles produisent. Une fenêtre ne se
prouve donc que sur ce qu'elle LAISSE PASSER : il faut, LE MÊME JOUR, un créneau
dedans (refusé) ET un créneau dehors (pris). C'est ce que font les cas ci-dessous,
et c'est ce qui les fait rougir quand la fenêtre disparaît.
"""

from __future__ import annotations

from typing import Any

from tests.support.pipeline import coach_availability, make_payload, make_team, make_venue, solve_payload, team_coach

DAY = 3  # mercredi


def _placed(result: dict[str, Any]) -> list[dict[str, Any]]:
    return [slot for slot in result["slots"] if slot["teamId"] == "t"]


def _payload(**window: str) -> dict[str, Any]:
    # UN SEUL jour, DEUX créneaux : 17:00 et 20:00. La fenêtre décide lequel est
    # atteignable — sans elle, les deux tombent (jour entier bloqué) et l'équipe
    # n'est plus placée du tout : c'est la différence que ces cas mesurent.
    return make_payload(
        teams=[make_team("t", sessions_per_week=1)],
        venues=[make_venue("gym", [(DAY, "17:00"), (DAY, "20:00")])],
        coaches=[{"id": "coach-1", "firstName": "Ana", "lastName": "K"}],
        constraints=[
            team_coach("tc", "t", "coach-1"),
            coach_availability("ca", "coach-1", unavailable_days=[DAY], **window),
        ],
    )


def test_from_time_blocks_only_the_evening() -> None:
    # « Indisponible le mercredi À PARTIR DE 19h » : 20:00 est refusé, 17:00 reste pris.
    result = solve_payload(_payload(from_time="19:00"))

    assert result["status"] != "failed", f"solve failed: {result['status']}"
    starts = sorted(str(slot["startTime"])[:5] for slot in _placed(result))
    assert starts == ["17:00"], f"la fenêtre doit laisser le créneau de 17h et refuser celui de 20h, obtenu : {starts}"


def test_until_time_blocks_only_the_afternoon() -> None:
    # « Indisponible le mercredi JUSQU'À 19h » : le miroir — 17:00 refusé, 20:00 pris.
    result = solve_payload(_payload(until_time="19:00"))

    assert result["status"] != "failed", f"solve failed: {result['status']}"
    starts = sorted(str(slot["startTime"])[:5] for slot in _placed(result))
    assert starts == ["20:00"], f"la fenêtre doit laisser le créneau de 20h et refuser celui de 17h, obtenu : {starts}"


def test_no_window_still_blocks_the_whole_day() -> None:
    # TÉMOIN : sans fenêtre, le comportement d'origine (jour entier) est intact —
    # les deux cas ci-dessus mesurent bien un ASSOUPLISSEMENT, pas un effet de bord.
    result = solve_payload(_payload())

    assert _placed(result) == [], "sans fenêtre, l'indisponibilité vaut pour la journée entière"
