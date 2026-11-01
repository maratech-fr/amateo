"""P2-42 / ALIGN-08 — « cette ÉQUIPE ne s'entraîne pas N jours d'affilée » est-elle HONORÉE ?

Test SÉMANTIQUE (axe §7.1 « sémantique de contrainte ») : on ne vérifie pas qu'un champ est
lu, on vérifie que le planning rendu obéit. Une contrainte saisie qui n'agit pas est le motif
récurrent du produit (ENG-10/11/12/13/16) — celle-ci naît gardée.

Le montage : une équipe à 3 séances/semaine, et un gymnase qui n'offre QUE trois jours
consécutifs (lundi, mardi, mercredi). Sans la règle, le solveur les prend tous les trois —
c'est le seul moyen d'honorer le quota. Avec la règle en HARD, il ne PEUT plus, et doit
sacrifier une séance : c'est précisément le prix que la règle fait payer, et le test l'exige
plutôt que de l'ignorer.
"""

from __future__ import annotations

from typing import Any

import pytest

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload


def _days_used(result: dict[str, Any], team_id: str) -> list[int]:
    return sorted(int(s["dayOfWeek"]) for s in result["slots"] if s["teamId"] == team_id)


def _solve(implicit_rules: dict[str, Any] | None) -> dict[str, Any]:
    payload = make_payload(
        teams=[make_team("sm1", sessions_per_week=3, priority_tier_id=1)],
        # Trois jours CONSÉCUTIFS et rien d'autre : le seul planning à 3 séances est la suite.
        venues=[make_venue("gym", [(1, "18:00"), (2, "18:00"), (3, "18:00")])],
        implicit_rules=implicit_rules,
    )
    result = solve_payload(payload, timeout=10)
    assert result["status"] == "completed", result.get("status")
    return result


def test_without_the_rule_the_solver_uses_the_three_consecutive_days() -> None:
    """Le témoin. Sans lui, un test vert ne prouverait rien : il faut d'abord établir que le
    solveur PREND bien les trois jours quand rien ne l'en empêche."""
    days = _days_used(_solve(None), "sm1")

    assert days == [1, 2, 3], f"le témoin doit occuper les 3 jours consécutifs, obtenu {days}"


def test_hard_the_team_never_trains_three_days_in_a_row() -> None:
    """HARD : la suite devient impossible. L'équipe perd une séance — c'est le prix de la
    garantie, et il est assumé : un gestionnaire qui active cette règle demande explicitement
    du repos plutôt que du volume."""
    days = _days_used(_solve({"maxConsecutiveDays": {"intensity": "HARD", "maxConsecutiveDays": 3}}), "sm1")

    assert len(days) <= 2, f"3 séances sur 3 jours consécutifs restent possibles : {days}"
    for first in days:
        assert not {first, first + 1, first + 2}.issubset(set(days)), f"suite de 3 trouvée : {days}"


def test_a_threshold_of_two_forbids_even_two_days_in_a_row() -> None:
    """Le seuil est un vrai réglage, pas un décor : à 2, deux jours de suite suffisent à
    violer. Falsifie l'hypothèse « le seuil est ignoré et la règle tape toujours à 3 »."""
    days = _days_used(_solve({"maxConsecutiveDays": {"intensity": "HARD", "maxConsecutiveDays": 2}}), "sm1")

    for first in days:
        assert first + 1 not in days, f"deux jours consécutifs malgré un seuil de 2 : {days}"


def test_preferred_keeps_the_sessions_and_pays_the_malus_instead() -> None:
    """PREFERRED ne garantit rien — et c'est la différence qu'un gestionnaire doit pouvoir
    choisir. Le quota (≥ 21 par séance) bat le malus (−6), donc les 3 séances SURVIVENT là où
    HARD en supprimait une. Si ce test devenait identique au HARD, c'est que l'intensité
    n'aurait plus d'effet."""
    days = _days_used(_solve({"maxConsecutiveDays": {"intensity": "PREFERRED", "maxConsecutiveDays": 3}}), "sm1")

    assert days == [1, 2, 3], f"PREFERRED ne doit pas supprimer une séance (≥21 bat −6), obtenu {days}"


# ── La règle saisie est dite quand le planning posé la dépasse (verrous compris) ─────────────────────
#
# Le solveur ne voit pas les verrous dans sa fenêtre : une suite réservée sort quand même, le gestionnaire
# doit le lire. Angle DIAGNOSTIC, les tests ci-dessus portent l'angle POSE.


def _reserved_on(team_id: str, days: list[int]) -> list[dict[str, Any]]:
    return [
        {
            "id": f"lock-{team_id}-{day}",
            "teamId": team_id,
            "venueId": "gym",
            "dayOfWeek": day,
            "startTime": "18:00",
            "durationMinutes": 90,
            "lockLevel": "HARD",
        }
        for day in days
    ]


def _solve_reserved(days: list[int], rule: dict[str, Any] | None) -> dict[str, Any]:
    """U15 réservée ces jours-là, son quota au complet, aucune autre case au gymnase."""
    return solve_payload(
        make_payload(
            teams=[make_team("U15", sessions_per_week=len(days))],
            venues=[make_venue("gym", [(day, "18:00") for day in days])],
            slot_templates=_reserved_on("U15", days),
            implicit_rules={"maxConsecutiveDays": rule} if rule else None,
        ),
        timeout=10,
    )


def _alerts(result: dict[str, Any]) -> list[dict[str, Any]]:
    return [d for d in result["diagnostics"] if d.get("ruleKey") == "maxConsecutiveDays"]


def _messages(result: dict[str, Any]) -> list[str]:
    return [d["message"] for d in _alerts(result)]


@pytest.mark.parametrize("intensity", ["HARD", "PREFERRED"])
def test_three_reserved_days_in_a_row_are_named_at_the_threshold(intensity: str) -> None:
    """Max 3, trois jours réservés : c'est la borne, le planning sort et le dit, avec la forme du contrat."""
    result = _solve_reserved([1, 2, 3], {"intensity": intensity, "maxConsecutiveDays": 3})

    assert result["status"] == "completed"
    assert _days_used(result, "U15") == [1, 2, 3]
    assert _messages(result) == ["Règle « jours consécutifs » : U15 s'entraîne 3 jours de suite, du lundi au mercredi."]
    alert = _alerts(result)[0]
    assert alert["id"] == "diag-implicit-consecutive-days-U15"
    assert alert["type"] == "implicit_rule_not_honored"
    assert alert["severity"] == "WARNING"
    assert alert["teamId"] == "U15"
    assert alert["suggestions"] == ["Déplacez une des séances réservées, ou assouplissez la règle (étape Contraintes)."]


def test_a_longer_run_is_named_once_with_its_whole_length() -> None:
    """Quatre jours de suite = une alerte de 4 jours, pas deux fenêtres de 3."""
    result = _solve_reserved([1, 2, 3, 4], {"intensity": "PREFERRED", "maxConsecutiveDays": 3})

    assert _messages(result) == ["Règle « jours consécutifs » : U15 s'entraîne 4 jours de suite, du lundi au jeudi."]


def test_a_preferred_rule_paid_by_the_solver_itself_is_named_too() -> None:
    """Sans aucun verrou : le malus a perdu contre le quota, le planning dépasse, l'alerte le dit."""
    result = _solve({"maxConsecutiveDays": {"intensity": "PREFERRED", "maxConsecutiveDays": 3}})

    assert _days_used(result, "sm1") == [1, 2, 3]
    assert _messages(result) == ["Règle « jours consécutifs » : sm1 s'entraîne 3 jours de suite, du lundi au mercredi."]


def test_two_reserved_days_under_a_threshold_of_three_raise_nothing() -> None:
    """Lundi et mardi, max 3 : la règle est tenue, silence."""
    result = _solve_reserved([1, 2], {"intensity": "HARD", "maxConsecutiveDays": 3})

    assert result["status"] == "completed"
    assert _alerts(result) == []


def test_a_gap_breaks_the_run() -> None:
    """Lundi, mardi, jeudi, vendredi, max 3 : deux suites de 2, aucune alerte."""
    result = _solve_reserved([1, 2, 4, 5], {"intensity": "PREFERRED", "maxConsecutiveDays": 3})

    assert result["status"] == "completed"
    assert _alerts(result) == []


def test_a_rule_that_is_off_raises_nothing() -> None:
    """Règle coupée = bloc absent du payload (``parsing.py``, le schéma refuse « OFF ») : trois jours de suite se taisent."""
    result = _solve_reserved([1, 2, 3], None)

    assert result["status"] == "completed"
    assert _days_used(result, "U15") == [1, 2, 3]
    assert _alerts(result) == []


def test_sunday_then_monday_is_not_a_run() -> None:
    """La semaine ne boucle pas, comme à la pose (``consecutive_days.py``) : même à max 2, silence."""
    result = _solve_reserved([1, 7], {"intensity": "PREFERRED", "maxConsecutiveDays": 2})

    assert _days_used(result, "U15") == [1, 7]
    assert _alerts(result) == []


def test_saturday_then_sunday_is_a_run() -> None:
    """TÉMOIN du précédent : le week-end compte comme les autres jours, seul le passage de semaine est exclu."""
    result = _solve_reserved([6, 7], {"intensity": "PREFERRED", "maxConsecutiveDays": 2})

    assert _messages(result) == [
        "Règle « jours consécutifs » : U15 s'entraîne 2 jours de suite, du samedi au dimanche."
    ]


def _solve_group(days_a: list[int], days_b: list[int]) -> dict[str, Any]:
    """U11 A et U11 B forment un groupe mutualisé, réservé aux mêmes cases sur leurs jours communs."""
    days = sorted(set(days_a) | set(days_b))
    payload = make_payload(
        teams=[
            {**make_team("a", sessions_per_week=len(days_a)), "name": "U11 A"},
            {**make_team("b", sessions_per_week=len(days_b)), "name": "U11 B"},
        ],
        venues=[make_venue("gym", [(day, "18:00") for day in days], capacity=2)],
        slot_templates=[*_reserved_on("a", days_a), *_reserved_on("b", days_b)],
        implicit_rules={"maxConsecutiveDays": {"intensity": "PREFERRED", "maxConsecutiveDays": 3}},
    )
    payload["sharedBlocks"] = [{"id": "g", "teamIds": ["a", "b"], "commonSessions": len(set(days_a) & set(days_b))}]
    return solve_payload(payload, timeout=10)


def test_a_group_on_the_same_days_gets_a_single_message() -> None:
    """Le groupe mutualisé se comporte comme UNE équipe : un message qui nomme ses membres, pas un par membre."""
    result = _solve_group([1, 2, 3], [1, 2, 3])

    assert result["status"] == "completed"
    assert _messages(result) == [
        "Règle « jours consécutifs » : U11 A et U11 B (séance commune) s'entraînent 3 jours de suite, "
        "du lundi au mercredi."
    ]


def test_two_separate_runs_of_a_team_give_two_messages_with_distinct_ids() -> None:
    """Lundi-mardi et jeudi-vendredi à max 2 : deux faits, deux lignes que le panneau peut distinguer."""
    result = _solve_reserved([1, 2, 4, 5], {"intensity": "PREFERRED", "maxConsecutiveDays": 2})

    assert _messages(result) == [
        "Règle « jours consécutifs » : U15 s'entraîne 2 jours de suite, du lundi au mardi.",
        "Règle « jours consécutifs » : U15 s'entraîne 2 jours de suite, du jeudi au vendredi.",
    ]
    ids = [d["id"] for d in _alerts(result)]
    assert len(set(ids)) == 2, ids


def test_a_group_sharing_a_run_stays_one_message_when_a_member_has_an_extra_day() -> None:
    """U11 A a son samedi en plus, hors de la suite : la suite commune lundi-mercredi reste un seul message."""
    result = _solve_group([1, 2, 3, 6], [1, 2, 3])

    assert result["status"] == "completed"
    assert _messages(result) == [
        "Règle « jours consécutifs » : U11 A et U11 B (séance commune) s'entraînent 3 jours de suite, "
        "du lundi au mercredi."
    ]


def test_a_group_member_alone_on_a_longer_run_is_named_alone() -> None:
    """« Aux mêmes jours » : U11 B n'a que lundi et mardi, seule U11 A dépasse la règle, seule elle est nommée."""
    result = _solve_group([1, 2, 3], [1, 2])

    assert result["status"] == "completed"
    assert _messages(result) == [
        "Règle « jours consécutifs » : U11 A s'entraîne 3 jours de suite, du lundi au mercredi."
    ]
