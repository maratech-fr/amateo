"""P4-51 — le plafond de jours d'un coach AGIT, en préféré (arbitrage fondateur 2026-08-09).

Avant cette suite, ``maxDaysOverride`` traversait tout le pipeline (entité, DTO, payload,
schéma d'entrée) et n'était appliqué NULLE PART : la contrainte du jour de repos s'en
servait uniquement pour SAUTER le coach — « repos déjà garanti par le plafond », disait le
commentaire, alors qu'aucun code ne plafonnait rien. Régler « max 3 jours » RETIRAIT donc
la garantie de repos sans poser de limite : l'inverse exact du libellé.

Le besoin, dans les mots du fondateur : « je m'en fous des jours, mais être au club plus
de 3 jours dans la semaine est contraignant ». Un plafond de COMPTE — distinct de
COACH_AVAILABILITY qui dit QUELS jours.

Ces tests passent par ``solve_payload`` (le vrai pipeline — leçon AUD-ENG-28) et prouvent
les trois faces du « préféré » : il regroupe quand c'est possible, il ne sacrifie JAMAIS
une séance quand ça ne l'est pas, et le récap le dit alors.
"""

from collections import defaultdict
from typing import Any

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload, team_coach


def _coach(coach_id: str, cap: int | None) -> dict[str, Any]:
    coach: dict[str, Any] = {"id": coach_id, "firstName": "Matthieu", "lastName": "Cap"}
    if cap is not None:
        coach["maxDaysOverride"] = cap
    return coach


def _solve(cap: int | None, *, venues: list[dict[str, Any]]) -> dict[str, Any]:
    payload = make_payload(
        teams=[make_team("sm1", priority_tier_id=5), make_team("sm2", priority_tier_id=5)],
        venues=venues,
        coaches=[_coach("matthieu", cap)],
        constraints=[team_coach("tc-1", "sm1", "matthieu"), team_coach("tc-2", "sm2", "matthieu")],
    )
    result = solve_payload(payload, timeout=10)
    assert result["status"] == "completed"
    return result


def _days_worked(result: dict[str, Any]) -> set[int]:
    days: set[int] = set()
    for slot in result["slots"]:
        if slot.get("coachId") == "matthieu":
            days.add(int(slot["dayOfWeek"]))
    return days


def test_the_cap_groups_sessions_when_grouping_is_possible() -> None:
    """Deux séances, deux créneaux le mardi + un le jeudi : plafonné à 1 jour, le solveur
    regroupe sur le mardi. Sans plafond, il étale (mesuré à seed 42) — c'est le malus qui décide.

    ⚠ Les créneaux du mardi sont DISJOINTS (18:00-19:30, 20:00-21:30), et c'est le cœur du
    test : avec 18:00/19:30 enchaînés, le bonus de CHAINING regroupait déjà tout seul, et
    neutraliser le plafond laissait ce test VERT — constaté en falsification, pas supposé.
    Un test qui passe pour une autre raison que la sienne ne garde rien.
    """
    venues = [make_venue("gym", [(2, "18:00"), (2, "20:00"), (4, "18:00")])]

    capped = _solve(1, venues=venues)
    assert len(_days_worked(capped)) == 1, "plafonné à 1 jour et regroupable, le coach doit tenir sur UN jour"

    # Les deux séances sont bien placées — regrouper ne supprime rien.
    assert len(capped["slots"]) == 2


def test_the_cap_never_sacrifices_a_session() -> None:
    """PRÉFÉRÉ, pas dur : un seul créneau par jour, regroupement impossible. Les deux
    séances doivent quand même être placées — le plafond cède, jamais le placement.

    C'est la borne 15 < 21 de `overload_day` : supprimer une séance coûte toujours plus
    cher que dépasser le plafond. Un plafond DUR aurait rendu ce club infaisable.
    """
    venues = [make_venue("gym", [(2, "18:00"), (4, "18:00")])]

    result = _solve(1, venues=venues)
    assert len(result["slots"]) == 2, "le plafond est préféré : il ne coûte JAMAIS une séance"
    assert len(_days_worked(result)) == 2


def test_the_recap_names_the_overload_when_the_cap_cannot_hold() -> None:
    """Quand le solveur n'y arrive pas, le gestionnaire doit l'apprendre au récap — pas en
    comptant les jours à la main sur la grille."""
    venues = [make_venue("gym", [(2, "18:00"), (4, "18:00")])]

    result = _solve(1, venues=venues)
    overloads = [d for d in result.get("diagnostics", []) if d.get("type") == "coach_overload"]
    assert overloads, "un plafond dépassé doit produire un diagnostic coach_overload"
    assert "matthieu" in str(overloads[0]).lower() or overloads[0].get("coachId") == "matthieu"


def test_without_a_cap_nothing_changes() -> None:
    """Aucun override : aucun littéral n'entre dans l'objectif, aucun malus, aucun
    diagnostic. Le réglage absent doit être neutre — c'est ce qui protège les goldens."""
    venues = [make_venue("gym", [(2, "18:00"), (4, "18:00")])]

    result = _solve(None, venues=venues)
    assert len(result["slots"]) == 2
    assert [d for d in result.get("diagnostics", []) if d.get("type") == "coach_overload"] == []


def test_grouping_respects_venue_capacity() -> None:
    """Le regroupement passe par des créneaux DISTINCTS du même jour — jamais en entassant
    deux équipes sur un créneau à capacité 1. Le plafond ne doit pas marchander avec la
    capacité du gymnase (contrainte 1, HARD)."""
    venues = [make_venue("gym", [(2, "18:00"), (2, "20:00"), (4, "18:00")])]

    result = _solve(1, venues=venues)
    per_slot: dict[tuple[int, str], int] = defaultdict(int)
    for slot in result["slots"]:
        per_slot[(int(slot["dayOfWeek"]), str(slot["startTime"]))] += 1
    assert all(count <= 1 for count in per_slot.values()), "capacité 1 : un créneau, une équipe"
