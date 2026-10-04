"""Contrat 1.2 — le vocabulaire de `/place-matches` est FERMÉ côté engine.

ENG-56 (audit 2026-10-03) : trois champs du payload `/place-matches` étaient des `str`
libres — `clubRules[].ruleType`, `teams[].coaches[].role`, `teamLinks[].type`. Une valeur
hors liste n'était pas refusée : le solveur ne la reconnaissait dans aucune branche
(`rule_type == "HARD"`, `role == "MAIN"`, `link.type == "NOT_SIMULTANEOUS"/"BACK_TO_BACK"`),
si bien que la règle / le rôle / le lien s'évaporait SANS TRACE — un « oui » du moteur sur
une donnée qu'il n'avait pas comprise.

Depuis le contrat 1.2 ces trois champs sont des `Literal` fermés, miroirs stricts des énums
backend (`ConstraintRuleType`, `TeamCoachRole`, `TeamLinkType`) : une valeur inconnue lève une
`ValidationError` Pydantic, et l'endpoint FastAPI la traduit en 422. Le backend n'émet jamais
hors liste (ses getters renvoient l'énum), donc ce resserrage ne casse aucun fil légitime — il
ferme un angle mort.

La parité « le 422 arrive bien jusqu'au client HTTP » est gardée côté cross-stack
(`backend/tests/CrossStack/MatchPlacementSemanticsGateTest.php`, groupe `contract`, VRAI moteur).
Ici on garde la RÈGLE de schéma elle-même, sans FastAPI.
"""

from __future__ import annotations

import pytest
from pydantic import ValidationError

from app.schemas.match_input_schema import ClubRuleSchema, TeamCoachRefSchema, TeamLinkSchema


def test_known_rule_types_are_accepted() -> None:
    for value in ("HARD", "PREFERRED"):
        rule = ClubRuleSchema.model_validate({"ruleType": value, "daysOfWeek": [6]})
        assert rule.rule_type == value


def test_unknown_club_rule_type_is_refused() -> None:
    with pytest.raises(ValidationError):
        ClubRuleSchema.model_validate({"ruleType": "SORT_OF_HARD", "daysOfWeek": [6]})


def test_known_coach_roles_are_accepted() -> None:
    for value in ("MAIN", "ASSISTANT"):
        ref = TeamCoachRefSchema.model_validate({"coachId": "c1", "role": value})
        assert ref.role == value


def test_unknown_coach_role_is_refused() -> None:
    with pytest.raises(ValidationError):
        TeamCoachRefSchema.model_validate({"coachId": "c1", "role": "head_coach"})


def test_known_team_link_types_are_accepted() -> None:
    for value in ("NOT_SIMULTANEOUS", "BACK_TO_BACK"):
        link = TeamLinkSchema.model_validate({"teamAId": "a", "teamBId": "b", "type": value})
        assert link.type == value


def test_unknown_team_link_type_is_refused() -> None:
    with pytest.raises(ValidationError):
        TeamLinkSchema.model_validate({"teamAId": "a", "teamBId": "b", "type": "MAYBE_SIMULTANEOUS"})
