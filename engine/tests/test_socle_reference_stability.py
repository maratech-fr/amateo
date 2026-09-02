"""PR-3 (comblement référencé au socle) — le BONUS de référence socle, prouvé.

Structuring axes ``generation pipeline`` + ``constraint semantics`` (CLAUDE.md §7.1) : en mode
comblement, une carence comblée doit RETROUVER le jour+heure de la version pointée du socle (quel
que soit le gymnase) — « faire bouger le planning de référence LE MOINS POSSIBLE ». Le terme
``add_socle_reference_bonus`` porte cette préférence dans le PLACEMENT (phase 1), pondérée par tier
(S>A>B>C>D), SANS jamais supprimer une séance et sans inverser l'ordre des tiers de placement.

Ce que ce fichier garde, chacun falsifiable :
  * le terme est CONSTRUIT et PONDÉRÉ par tier (unit sur ``add_socle_reference_bonus``), et il
    matche ``(team, day, start)`` en IGNORANT le gymnase ;
  * champ absent/vide ⇒ objectif byte-identique (aucun terme) ;
  * (i) une séance manquante reprend son jour+heure de socle sur un AUTRE gymnase libre plutôt
    qu'un autre créneau (préférence souple inverse en témoin) ;
  * (ii) à une place pour deux, le tier SUPÉRIEUR garde son horaire de socle ;
  * (iii) le bonus ne fait JAMAIS supprimer une séance (invariant de comptage base ⇄ référencé).
"""

from __future__ import annotations

from typing import Any

import pytest

from app.schemas.input_schema import ScheduleInputSchema
from app.solver.model import build_model
from app.solver.objective import SOCLE_REFERENCE_TIER_WEIGHTS, add_socle_reference_bonus
from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload, team_constraint

MONDAY = 1
TUESDAY = 2
WEDNESDAY = 3
FRIDAY = 5
AT = "18:00"

# priorityTierId : 1=S 2=A 3=B 4=C 5=D
TIER_S = 1
TIER_D = 5


def _socle(team_id: str, day: int, start: str) -> dict[str, Any]:
    """Une entrée de référence socle : {teamId, dayOfWeek, startTime} — SANS venueId."""
    return {"teamId": team_id, "dayOfWeek": day, "startTime": start}


def _prefer_days(team_id: str, days: list[int]) -> dict[str, Any]:
    return team_constraint(
        constraint_id=f"prefer-{team_id}",
        team_id=team_id,
        family="DAY",
        rule_type="PREFERRED",
        config={"preferredDays": days},
        name="préférence de jour",
    )


def _built_model_x(payload: dict[str, Any]) -> Any:
    data = ScheduleInputSchema.model_validate(payload).model_dump(by_alias=True)
    return build_model(data).x, data


def _occupies(slots: list[dict[str, Any]], team_id: str, day: int) -> bool:
    return any(s.get("teamId") == team_id and s.get("dayOfWeek") == day for s in slots)


def _team_on_day(slots: list[dict[str, Any]], day: int, start: str) -> str | None:
    for s in slots:
        if s.get("dayOfWeek") == day and str(s.get("startTime", "")).startswith(start):
            return str(s.get("teamId"))
    return None


class TestSocleReferenceTerm:
    def test_term_is_built_and_weighted_per_tier_ignoring_venue(self) -> None:
        """Le terme tombe sur CHAQUE variable ``(team, day, start)`` de la référence, quel que
        soit le gymnase, avec le poids du tier de l'équipe."""
        payload = make_payload(
            teams=[make_team("t-S", priority_tier_id=TIER_S), make_team("t-D", priority_tier_id=TIER_D)],
            # DEUX gymnases portent lundi 18:00 : la référence (sans venue) doit matcher les DEUX.
            venues=[
                make_venue("W", [(MONDAY, AT), (WEDNESDAY, AT)]),
                make_venue("X", [(MONDAY, AT)]),
            ],
        )
        x, data = _built_model_x(payload)

        terms = add_socle_reference_bonus(
            x,
            [_socle("t-S", MONDAY, AT), _socle("t-D", MONDAY, AT)],
            data["teams"],
        )

        # Une variable t-S lundi 18:00 par gymnase (W, X) → 2 termes à 20 ; idem t-D → 2 à 12.
        weights = sorted(w for _var, w in terms)
        assert weights == [12, 12, 20, 20], weights
        # Le poids EST celui du tier (S=20, D=12), et le mercredi (hors référence) ne porte rien.
        assert SOCLE_REFERENCE_TIER_WEIGHTS["S"] == 20
        assert SOCLE_REFERENCE_TIER_WEIGHTS["D"] == 12

    def test_absent_or_empty_reference_builds_no_term(self) -> None:
        payload = make_payload(
            teams=[make_team("t", priority_tier_id=TIER_D)],
            venues=[make_venue("W", [(MONDAY, AT)])],
        )
        x, data = _built_model_x(payload)

        assert add_socle_reference_bonus(x, None, data["teams"]) == []
        assert add_socle_reference_bonus(x, [], data["teams"]) == []


class TestSocleReferenceSemantics:
    @pytest.mark.timeout(30)
    def test_empty_reference_is_byte_identical(self) -> None:
        """Champ vide ⇒ score et placements identiques à un solve sans le champ (terme inerte)."""
        base = make_payload(
            teams=[make_team("t", priority_tier_id=TIER_D)],
            venues=[make_venue("V", [(MONDAY, AT), (WEDNESDAY, AT)])],
            constraints=[_prefer_days("t", [WEDNESDAY])],
        )
        without = solve_payload(base)

        with_empty = solve_payload({**base, "socleReferenceAssignments": []})

        assert without["status"] == with_empty["status"] == "completed"
        assert without["score"] == with_empty["score"]
        assert [(s["teamId"], s["dayOfWeek"], s["startTime"]) for s in without["slots"]] == [
            (s["teamId"], s["dayOfWeek"], s["startTime"]) for s in with_empty["slots"]
        ]

    @pytest.mark.timeout(30)
    def test_hole_takes_socle_day_time_on_another_free_venue(self) -> None:
        """(i) L'équipe préfère (souple) mercredi ; sa référence socle est lundi 18:00, disponible
        seulement dans un AUTRE gymnase. Sans référence → mercredi. Avec → lundi (gymnase libre)."""
        payload = make_payload(
            teams=[make_team("t", priority_tier_id=TIER_D)],
            venues=[
                make_venue("W", [(MONDAY, AT)]),  # jour+heure de socle, autre gymnase
                make_venue("V", [(WEDNESDAY, AT)]),  # créneau préféré (souple)
            ],
            constraints=[_prefer_days("t", [WEDNESDAY])],
        )

        # Témoin : sans référence, la préférence souple gagne → mercredi, lundi vide.
        witness = solve_payload(payload)
        assert witness["status"] == "completed"
        assert _occupies(witness["slots"], "t", WEDNESDAY)
        assert not _occupies(witness["slots"], "t", MONDAY), "témoin cassé : lundi déjà occupé sans référence"

        # Avec la référence socle lundi 18:00 : la séance comble sur SON jour+heure de socle (autre
        # gymnase), pas sur le créneau préféré.
        referenced = solve_payload({**payload, "socleReferenceAssignments": [_socle("t", MONDAY, AT)]})
        assert referenced["status"] == "completed"
        assert _occupies(referenced["slots"], "t", MONDAY), (
            "la référence socle (lundi 18:00) doit tenir malgré la préférence mercredi"
        )
        assert not _occupies(referenced["slots"], "t", WEDNESDAY)

    @pytest.mark.timeout(30)
    def test_higher_tier_keeps_socle_slot_when_contested(self) -> None:
        """(ii) Deux équipes référencent le MÊME créneau de socle (lundi 18:00), une seule place.
        Le tier SUPÉRIEUR (S) le garde, le secondaire (D) absorbe le déplacement."""
        payload = make_payload(
            teams=[make_team("H", priority_tier_id=TIER_S), make_team("L", priority_tier_id=TIER_D)],
            venues=[
                make_venue("X", [(MONDAY, AT)]),  # LE créneau de socle disputé (capacité 1)
                make_venue("Y", [(TUESDAY, AT), (WEDNESDAY, AT)]),  # de quoi replacer le perdant
            ],
        )
        referenced = solve_payload(
            {**payload, "socleReferenceAssignments": [_socle("H", MONDAY, AT), _socle("L", MONDAY, AT)]}
        )
        assert referenced["status"] == "completed"
        # Les deux équipes sont placées (une séance chacune) ; c'est bien un partage de place.
        assert len(referenced["slots"]) == 2
        assert _team_on_day(referenced["slots"], MONDAY, AT) == "H", (
            "le tier supérieur (S) doit garder son horaire de socle disputé"
        )
        assert _occupies(referenced["slots"], "L", TUESDAY) or _occupies(referenced["slots"], "L", WEDNESDAY)

    @pytest.mark.timeout(30)
    def test_bonus_never_drops_a_session(self) -> None:
        """(iii) La référence n'oriente que des séances DÉJÀ à placer : le nombre de séances placées
        avec référence n'est jamais INFÉRIEUR à celui sans référence."""
        payload = make_payload(
            teams=[make_team("t", sessions_per_week=2, priority_tier_id=TIER_D)],
            venues=[make_venue("V", [(MONDAY, AT), (WEDNESDAY, AT), (FRIDAY, AT)])],
            constraints=[_prefer_days("t", [WEDNESDAY, FRIDAY])],
        )
        base = solve_payload(payload)
        assert base["status"] == "completed"
        base_count = len(base["slots"])
        assert base_count == 2  # deux séances placées (mer + ven, les préférés)

        referenced = solve_payload({**payload, "socleReferenceAssignments": [_socle("t", MONDAY, AT)]})
        assert referenced["status"] == "completed"
        assert len(referenced["slots"]) >= base_count, "la référence socle a SUPPRIMÉ une séance"
        assert len(referenced["slots"]) == 2
        # La référence a bien ORIENTÉ (lundi désormais occupé) sans réduire le compte.
        assert _occupies(referenced["slots"], "t", MONDAY)
