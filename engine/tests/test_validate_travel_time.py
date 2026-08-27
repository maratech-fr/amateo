"""P2-55 (ENG-36) — le VERDICT de déplacement (`/validate-assignments`) honore le TRAJET.

Axe §7.1 « constraint semantics » : parité génération ⇄ verdict pour la règle ``travelTime``. Avant
ce lot, la matrice ``venueTravelTimes`` était ACCEPTÉE en entrée du verdict mais JAMAIS consommée —
sous ``MANDATORY``, un déplacement créant un enchaînement au battement interdit DUR était jugé
« valide » à tort (« déclaré ≠ effectif »). Deux miroirs du côté génération, chacun falsifié dans
les deux sens :

  * MANDATORY — un déplacement qui crée un enchaînement cross-gymnase au battement TROP COURT pour
    le coach est REFUSÉ (le HARD posé dans ``_apply_hard`` rend le solve INFEASIBLE). Falsification :
    SANS le passage de la matrice au verdict, le même déplacement passerait « valide » ;
  * PREFERRED — le même enchaînement est ACCEPTÉ mais le battement concédé remonte en COMPROMIS
    nommé (famille ``travel_time``, effet ``broken``) — le rail P2-32. Falsification : SANS le
    câblage du compromis, la sortie serait muette.

Chaque garde a son TÉMOIN : le même coach, une grille au battement CONFORTABLE → accepté sans
compromis trajet, quel que soit le cran. Pipeline RÉEL (``validate_assignment``), jamais un mock.
"""

from __future__ import annotations

from typing import Any

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.validate_assignments import validate_assignment
from tests.support.pipeline import make_team, make_venue, team_coach


def _run(payload: dict[str, Any]) -> dict[str, Any]:
    return validate_assignment(ValidateAssignmentsInputSchema.model_validate(payload))


def _coach(coach_id: str, *, vehicled: bool = False) -> dict[str, Any]:
    return {"id": coach_id, "firstName": "C", "lastName": coach_id, "isActive": True, "isVehicled": vehicled}


def _row(a: str, b: str, driving: int | None, walking: int | None) -> dict[str, Any]:
    return {"venueAId": a, "venueBId": b, "drivingMinutes": driving, "walkingMinutes": walking}


def _template(team_id: str, venue_id: str, day: int, start: str) -> dict[str, Any]:
    return {
        "id": f"tpl-{team_id}-{venue_id}-{day}-{start}",
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": 90,
        "lockLevel": "NONE",
    }


def _payload(*, intensity: str, t1_start: str, t1_slots: list[tuple[int, str]]) -> dict[str, Any]:
    """Coach c1 (à pied) tient t1 (baseline figée à V1) et t2 (candidat vers V2, lundi 20:00,
    fin 21:30). Barème à pied V1–V2 = 30 min. Le battement dépend de la fin de la séance de t1 :
    18:20→19:50 laisse 10 min (< 30, VIOLE) ; 17:00→18:30 laisse 90 min (≥ 30, CONFORME)."""
    payload: dict[str, Any] = {
        "clubId": "c",
        "seasonId": "s",
        "venues": [make_venue("V1", t1_slots), make_venue("V2", [(1, "20:00")])],
        "teams": [make_team("t1"), make_team("t2")],
        "coaches": [_coach("c1")],
        "constraints": [team_coach("tc1", "t1", "c1"), team_coach("tc2", "t2", "c1")],
        "implicitRules": {"travelTime": {"intensity": intensity}},
        "venueTravelTimes": [_row("V1", "V2", 5, 30)],
        "slotTemplates": [_template("t1", "V1", 1, t1_start)],
        "candidate": {
            "teamId": "t2",
            "venueId": "V2",
            "dayOfWeek": 1,
            "startTime": "20:00",
            "durationMinutes": 90,
        },
    }
    return payload


class TestMandatoryTravelVerdict:
    def test_tight_enchainement_is_refused_and_names_travel(self) -> None:
        """MANDATORY : t2 vers V2/20:00 laisse au coach 10 min pour V1→V2 (barème 30) → REFUS,
        motif NOMMÉ ``travel_time_infeasible`` (le miroir déterministe, pas ``unknown_hard_conflict``).

        Falsifie DEUX fois : sans ``venue_travel_times`` passé à ``_apply_hard`` le solve resterait
        faisable (« valide ») ; sans le miroir ``_travel_time_move_violation`` le refus n'aurait pas
        ce NOM. On vérifie donc le nom du motif, pas seulement ``valid is False``."""
        result = _run(_payload(intensity="MANDATORY", t1_start="18:20", t1_slots=[(1, "18:20")]))
        assert result["valid"] is False, f"battement 10 < 30 sous MANDATORY doit être REFUSÉ; got {result}"
        assert result["violations"], "un refus doit rester explicable (violation nommée)"
        assert result["violations"][0]["rule"] == "travel_time_infeasible", (
            f"le refus MANDATORY doit NOMMER le trajet, pas retomber sur unknown_hard_conflict; got {result['violations']}"
        )
        # Le coach est nommé dans les champs structurés ET le texte ne fuit aucun identifiant interne.
        assert result["violations"][0]["coach_id"] == "c1"
        assert "gymnase suivant" in result["violations"][0]["message"]

    def test_comfortable_enchainement_is_accepted(self) -> None:
        """TÉMOIN : la séance de t1 finit à 18:30, le coach a 90 min pour V1→V2 → ACCEPTÉ."""
        result = _run(_payload(intensity="MANDATORY", t1_start="17:00", t1_slots=[(1, "17:00")]))
        assert result["valid"] is True, f"battement 90 ≥ 30 doit être accepté; got {result}"


class TestPreferredTravelVerdict:
    def test_tight_enchainement_surfaces_a_named_compromise(self) -> None:
        """PREFERRED : le battement serré est ACCEPTÉ mais NOMMÉ en compromis ``travel_time``.

        Falsifie : sans le câblage ``add_travel_time_penalty(info_out=…)`` dans ``_evaluate_state``,
        la sortie ``compromises`` serait muette sur le trajet."""
        result = _run(_payload(intensity="PREFERRED", t1_start="18:20", t1_slots=[(1, "18:20")]))
        assert result["valid"] is True, f"PREFERRED ne bloque pas; got {result}"
        families = {c["family"]: c for c in result["compromises"]}
        assert "travel_time" in families, f"le battement PREFERRED concédé doit être nommé; got {result['compromises']}"
        assert families["travel_time"]["effect"] == "broken"

    def test_comfortable_enchainement_has_no_travel_compromise(self) -> None:
        """TÉMOIN : battement confortable → accepté, aucun compromis trajet."""
        result = _run(_payload(intensity="PREFERRED", t1_start="17:00", t1_slots=[(1, "17:00")]))
        assert result["valid"] is True
        assert "travel_time" not in {c["family"] for c in result["compromises"]}
