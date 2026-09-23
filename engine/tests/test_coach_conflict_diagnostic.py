"""D-14 — le filet post-solve « ce coach est-il à deux endroits ? ».

Ce diagnostic n'avait **aucun test** avant cette suite. C'est ce qui lui a permis de vivre
avec deux défauts opposés sans jamais rougir :

  * il ne voyait que les séances **commençant à la même minute** (clé `startTime` exacte),
    donc 17h00-18h30 contre 17h30-19h00 lui échappait — plus laxiste que la contrainte HARD
    du solveur, qu'il est pourtant censé surveiller ;
  * il criait ERROR sur deux équipes du **même gymnase**, un geste que le backend et l'UI
    offrent explicitement (arbitrage fondateur : Matthieu tient les SM1 et les SM2 côte à
    côte, il est présent une fois).

Un filet muet là où il faut parler et bruyant là où il faut se taire ne protège de rien.
"""

from typing import Any

from ortools.sat.python import cp_model

from app.solver.result_builder import _diagnose_conflicts


def _slot(team: str, venue: str, start: str, duration: int = 90, coach: str = "matthieu") -> dict[str, Any]:
    return {
        "teamId": team,
        "venueId": venue,
        "coachId": coach,
        "dayOfWeek": 2,
        "startTime": start,
        "durationMinutes": duration,
    }


def _coach_conflicts(slots: list[dict[str, Any]]) -> list[dict[str, Any]]:
    diagnostics = _diagnose_conflicts({}, cp_model.OPTIMAL, slots)

    return [d for d in diagnostics if str(d.get("id", "")).startswith("diag-conflict-coach-")]


def test_same_venue_is_not_a_conflict() -> None:
    """Les SM1 et les SM2 au même endroit : le coach n'y est qu'une fois."""
    assert _coach_conflicts([_slot("sm1", "gymnase-unique", "18:00"), _slot("sm2", "gymnase-unique", "18:00")]) == []


def test_two_venues_at_the_same_start_is_a_conflict() -> None:
    conflicts = _coach_conflicts([_slot("sm1", "gymnase-nord", "18:00"), _slot("sm2", "gymnase-sud", "18:00")])

    assert len(conflicts) == 1
    assert "gymnases différents" in conflicts[0]["message"]


def test_two_venues_overlapping_without_the_same_start_is_a_conflict() -> None:
    """Le cas que la clé « début exact » laissait passer — celui qui a motivé D-14."""
    conflicts = _coach_conflicts([_slot("sm1", "gymnase-nord", "17:00"), _slot("sm2", "gymnase-sud", "17:30")])

    assert len(conflicts) == 1


def test_two_venues_back_to_back_is_not_a_conflict() -> None:
    """Intervalles demi-ouverts : la fin de l'une est le début de l'autre, le coach se déplace."""
    assert _coach_conflicts([_slot("sm1", "gymnase-nord", "17:00"), _slot("sm2", "gymnase-sud", "18:30")]) == []


def test_the_same_team_in_two_venues_at_once_stays_a_conflict() -> None:
    """Une équipe non plus ne se dédouble pas — le gymnase différent reste le critère."""
    assert len(_coach_conflicts([_slot("sm1", "gymnase-nord", "18:00"), _slot("sm1", "gymnase-sud", "18:00")])) == 1


def test_the_same_team_in_the_same_venue_stays_silent() -> None:
    """Doublon de template dupliqué : deux fois la même séance n'est pas un dédoublement."""
    assert _coach_conflicts([_slot("sm1", "gymnase-unique", "18:00"), _slot("sm1", "gymnase-unique", "18:00")]) == []


def test_a_slot_without_a_coach_is_ignored() -> None:
    no_coach = _slot("sm2", "gymnase-sud", "18:00")
    no_coach["coachId"] = None

    assert _coach_conflicts([_slot("sm1", "gymnase-nord", "18:00"), no_coach]) == []


def test_two_different_coaches_never_clash() -> None:
    assert (
        _coach_conflicts([_slot("sm1", "gymnase-nord", "18:00"), _slot("sm2", "gymnase-sud", "18:00", coach="julie")])
        == []
    )


def test_staggered_starts_emit_the_overlap_start_not_the_first_session_start() -> None:
    """P4-95 lot 8 (décision D) — sur des débuts DÉCALÉS, le champ ``startTime`` doit être le début
    du CHEVAUCHEMENT (``max``), pas ``a_raw`` (début de la 1ʳᵉ séance).

    Sinon, côté front, l'instant n'appartient qu'à UNE des deux cases → le panneau en ouvrirait
    une, alors que le choc s'étale sur deux gymnases (on surligne les deux, on n'ouvre rien).

    Falsification : revenir à ``str(a_raw)[:5]`` (18:00) → l'assert ``"19:00"`` rougit."""
    conflicts = _coach_conflicts([_slot("sm1", "gymnase-nord", "18:00"), _slot("sm2", "gymnase-sud", "19:00")])

    assert len(conflicts) == 1
    assert conflicts[0]["startTime"] == "19:00"
    # L'id garde le début de la 1ʳᵉ séance (stable) — il ne suit PAS le champ.
    assert conflicts[0]["id"].endswith("-18:00")
