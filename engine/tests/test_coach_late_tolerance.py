"""Chantier H — le contrat 1.6 porte la TOLÉRANCE d'arrivée tardive sur la SÉANCE, plus sur la
personne.

Deux faces du contrat, chacune falsifiable au niveau schéma (le moins cher) :

* ``ScheduleSlotTemplateSchema`` accepte ``coachLateToleranceMinutes`` (alias camelCase), défaut 0,
  borné 0..30 — le porteur de la tolérance qui voyage du backend (``Reservation`` → slotTemplate)
  jusqu'au moteur. ``extra="forbid"`` du modèle sérialisable refuse aujourd'hui tout champ inconnu,
  donc ce test ROUGIT tant que le champ n'existe pas — RED pour la bonne raison (feature absente).
* ``CoachSchema`` REFUSE désormais ``acceptableLateMinutes`` : ce levier était MORT (aucune UI,
  jamais lu par le moteur) et quitte le contrat. ``extra="forbid"`` doit donc le rejeter — ce test
  ROUGIT tant que le champ y figure encore.

La SÉMANTIQUE du solveur (l'adjoint ouvre la séance, le coach rejoint en retard, exemption de trajet
de la paire tolérée) est prouvée de bout en bout contre le VRAI moteur par
``backend/tests/CrossStack/CoachLateToleranceSemanticsGateTest`` (NR gatant, point sensible
« solveur » — second angle VOULU, cité ici) : on ne la re-dérive pas en double ici.
"""

from __future__ import annotations

import pytest
from pydantic import ValidationError

from app.schemas.input_schema import CoachSchema, ScheduleSlotTemplateSchema


def _slot(**over: object) -> dict[str, object]:
    base: dict[str, object] = {
        "id": "slot-1",
        "teamId": "team-1",
        "venueId": "venue-1",
        "dayOfWeek": 2,
        "startTime": "20:00",
        "durationMinutes": 90,
        "lockLevel": "HARD",
    }
    base.update(over)
    return base


def test_slot_template_accepts_coach_late_tolerance_minutes_defaulting_to_zero() -> None:
    # Absent → 0 (une séance ordinaire ne tolère aucun retard : comportement historique).
    assert ScheduleSlotTemplateSchema.model_validate(_slot()).coach_late_tolerance_minutes == 0
    # Présent (camelCase du fil) → lu tel quel.
    assert (
        ScheduleSlotTemplateSchema.model_validate(_slot(coachLateToleranceMinutes=30)).coach_late_tolerance_minutes
        == 30
    )


def test_slot_template_bounds_the_tolerance_to_zero_thirty() -> None:
    # Plafond métier 30 (fondateur) : 31 est refusé au bord, comme −1.
    with pytest.raises(ValidationError):
        ScheduleSlotTemplateSchema.model_validate(_slot(coachLateToleranceMinutes=31))
    with pytest.raises(ValidationError):
        ScheduleSlotTemplateSchema.model_validate(_slot(coachLateToleranceMinutes=-1))


def test_coach_schema_no_longer_accepts_acceptable_late_minutes() -> None:
    # Le levier MORT quitte le contrat 1.6 : extra="forbid" doit le rejeter.
    with pytest.raises(ValidationError):
        CoachSchema.model_validate(
            {"id": "c1", "firstName": "Sébastien", "lastName": "T", "acceptableLateMinutes": 15}
        )
    # Le reste du schéma coach reste valide sans ce champ.
    assert CoachSchema.model_validate({"id": "c1", "firstName": "Sébastien", "lastName": "T"}).id == "c1"
