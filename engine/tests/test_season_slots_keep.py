"""PR 6 (lot 8) — « garder ses créneaux de saison » (Q3/Q4/Q3bis), prouvé côté moteur.

Structuring axes ``constraint semantics`` + ``generation pipeline`` (CLAUDE.md §7.1). Quand une
équipe porte la contrainte ``SEASON_SLOTS``, le backend émet ses placements de socle AVEC le gymnase
(``keep: true``, contrat 1.5). Le terme ``add_socle_reference_bonus`` donne alors un bonus PLEIN
(gymnase ∧ jour ∧ heure) ou PARTIEL (jour ∧ heure, autre gymnase), bien plus fort que le régime
comblement — « préférence TRÈS FORTE » — sans jamais supprimer une séance ni inverser l'ordre des
tiers (D-g : poids sous l'écart B−C = 90).

Ce que ce fichier garde, chacun falsifiable :
  * le terme KEEP est PONDÉRÉ plein/partiel par tier selon que le gymnase candidat est celui du
    socle (unit sur ``add_socle_reference_bonus``) ;
  * sémantique : une équipe garde son créneau de saison (jour+heure+gymnase) contre une préférence
    souple ailleurs, et le keep ne supprime jamais une séance ;
  * le diagnostic ``season_slots_kept`` : un créneau tenu par un VERROU compte CONSERVÉ ; un créneau
    non tenu dont la fenêtre existe est ``displaced``, dont la fenêtre n'existe pas est
    ``slot_absent``.
"""

from __future__ import annotations

from typing import Any

import pytest

from app.schemas.input_schema import ScheduleInputSchema
from app.solver.model import build_model
from app.solver.objective import (
    SOCLE_KEEP_FULL_TIER_WEIGHTS,
    SOCLE_KEEP_PARTIAL_TIER_WEIGHTS,
    add_socle_reference_bonus,
)
from app.solver.result_builder.diagnostics import _diagnose_season_slots
from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload, team_constraint

MONDAY = 1
WEDNESDAY = 3
THURSDAY = 4
FRIDAY = 5
SATURDAY = 6
AT = "18:00"

TIER_S = 1
TIER_D = 5


def _keep(team_id: str, day: int, start: str, venue_id: str | None) -> dict[str, Any]:
    """Une référence socle en régime KEEP : jour+heure+gymnase, ``keep: true``."""
    entry: dict[str, Any] = {"teamId": team_id, "dayOfWeek": day, "startTime": start, "keep": True}
    if venue_id is not None:
        entry["venueId"] = venue_id
    return entry


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


def _venue_of(slots: list[dict[str, Any]], team_id: str, day: int) -> str | None:
    for s in slots:
        if s.get("teamId") == team_id and s.get("dayOfWeek") == day:
            return str(s.get("venueId"))
    return None


class TestSeasonSlotKeepTerm:
    def test_full_bonus_on_socle_venue_partial_elsewhere(self) -> None:
        """Deux gymnases portent lundi 18:00 ; la référence KEEP pointe W. La variable W reçoit le
        bonus PLEIN du tier, la variable X (même jour+heure, autre gymnase) le PARTIEL."""
        payload = make_payload(
            teams=[make_team("t-D", priority_tier_id=TIER_D)],
            venues=[make_venue("W", [(MONDAY, AT)]), make_venue("X", [(MONDAY, AT)])],
        )
        x, data = _built_model_x(payload)

        terms = add_socle_reference_bonus(x, [_keep("t-D", MONDAY, AT, "W")], data["teams"])

        weights = sorted(w for _var, w in terms)
        assert weights == [SOCLE_KEEP_PARTIAL_TIER_WEIGHTS["D"], SOCLE_KEEP_FULL_TIER_WEIGHTS["D"]], weights
        assert weights == [44, 65]

    def test_keep_weights_grow_with_tier_and_full_beats_partial(self) -> None:
        """Barème KEEP : S > D, et plein > partiel à tier égal (garder aussi le gymnase vaut mieux)."""
        assert SOCLE_KEEP_FULL_TIER_WEIGHTS["S"] == 85
        assert SOCLE_KEEP_FULL_TIER_WEIGHTS["D"] == 65
        assert SOCLE_KEEP_PARTIAL_TIER_WEIGHTS["S"] == 60
        assert SOCLE_KEEP_PARTIAL_TIER_WEIGHTS["D"] == 44
        for tier in ("S", "A", "B", "C", "D"):
            assert SOCLE_KEEP_FULL_TIER_WEIGHTS[tier] > SOCLE_KEEP_PARTIAL_TIER_WEIGHTS[tier]
            # Sous l'écart de placement B−C (90) : jamais d'inversion de tier (D-g).
            assert SOCLE_KEEP_FULL_TIER_WEIGHTS[tier] < 90

    def test_keep_reference_without_venue_falls_back_to_partial(self) -> None:
        """Une référence KEEP sans ``venueId`` ne peut pas matcher un gymnase → barème PARTIEL."""
        payload = make_payload(
            teams=[make_team("t-D", priority_tier_id=TIER_D)],
            venues=[make_venue("W", [(MONDAY, AT)])],
        )
        x, data = _built_model_x(payload)

        terms = add_socle_reference_bonus(x, [_keep("t-D", MONDAY, AT, None)], data["teams"])

        assert sorted(w for _var, w in terms) == [SOCLE_KEEP_PARTIAL_TIER_WEIGHTS["D"]]


class TestSeasonSlotKeepSemantics:
    @pytest.mark.timeout(30)
    def test_team_keeps_its_socle_slot_against_a_soft_preference(self) -> None:
        """L'équipe PRÉFÈRE (souple) mercredi ; son créneau de saison est lundi 18:00 @ W. Sans keep
        → mercredi. Avec keep → lundi @ W (la préférence très forte tient)."""
        payload = make_payload(
            teams=[make_team("t", priority_tier_id=TIER_D)],
            venues=[make_venue("W", [(MONDAY, AT)]), make_venue("V", [(WEDNESDAY, AT)])],
            constraints=[_prefer_days("t", [WEDNESDAY])],
        )

        witness = solve_payload(payload)
        assert witness["status"] == "completed"
        assert _occupies(witness["slots"], "t", WEDNESDAY)
        assert not _occupies(witness["slots"], "t", MONDAY), "témoin cassé : lundi occupé sans keep"

        kept = solve_payload({**payload, "socleReferenceAssignments": [_keep("t", MONDAY, AT, "W")]})
        assert kept["status"] == "completed"
        assert _occupies(kept["slots"], "t", MONDAY), "le keep doit tenir le créneau de saison"
        assert _venue_of(kept["slots"], "t", MONDAY) == "W"
        assert not _occupies(kept["slots"], "t", WEDNESDAY)

    @pytest.mark.timeout(30)
    def test_keep_never_drops_a_session(self) -> None:
        """Le keep n'oriente que des séances DÉJÀ à placer : jamais moins de séances qu'un solve sans."""
        payload = make_payload(
            teams=[make_team("t", sessions_per_week=2, priority_tier_id=TIER_D)],
            venues=[make_venue("V", [(MONDAY, AT), (WEDNESDAY, AT), (FRIDAY, AT)])],
            constraints=[_prefer_days("t", [WEDNESDAY, FRIDAY])],
        )
        base = solve_payload(payload)
        assert base["status"] == "completed"
        assert len(base["slots"]) == 2

        kept = solve_payload({**payload, "socleReferenceAssignments": [_keep("t", MONDAY, AT, "V")]})
        assert kept["status"] == "completed"
        assert len(kept["slots"]) == 2, "le keep a SUPPRIMÉ une séance"
        assert _occupies(kept["slots"], "t", MONDAY)


class TestSeasonSlotsDiagnostic:
    """Les deux cas nommés de la relecture (point 3) — testent ``_diagnose_season_slots`` directement
    sur un modèle + des slots finaux (pas de solve : le diagnostic LIT les slots finaux)."""

    @staticmethod
    def _model(keep_refs: list[dict[str, Any]], windows: list[tuple[int, str]]) -> dict[str, Any]:
        return {
            "teams": [{"id": "SM1", "name": "SM1", "priorityTierId": TIER_S}],
            "venues": [make_venue("V", windows)],
            "socleReferenceAssignments": keep_refs,
        }

    @staticmethod
    def _slot(day: int, start: str, *, lock: str = "NONE") -> dict[str, Any]:
        return {
            "teamId": "SM1",
            "venueId": "V",
            "dayOfWeek": day,
            "startTime": start,
            "durationMinutes": 90,
            "lockLevel": lock,
        }

    def test_slot_held_by_a_lock_counts_as_kept(self) -> None:
        """Trois créneaux de saison : jeudi (placé par le solveur), vendredi (tenu par un VERROU
        HARD, donc PRÉSENT dans les slots finaux), samedi (perdu, aucune fenêtre). Le verrou DOIT
        compter conservé : 2/3, et seul samedi est reporté. Falsifiable : si le verrou n'était pas
        compté, vendredi apparaîtrait ``displaced`` et le compte tomberait à 1/3."""
        model_data = self._model(
            keep_refs=[
                _keep("SM1", THURSDAY, "19:00", "V"),
                _keep("SM1", FRIDAY, "20:00", "V"),
                _keep("SM1", SATURDAY, "10:00", "V"),
            ],
            windows=[(THURSDAY, "19:00"), (FRIDAY, "20:00")],  # samedi n'a AUCUNE fenêtre
        )
        slots = [self._slot(THURSDAY, "19:00"), self._slot(FRIDAY, "20:00", lock="HARD")]

        diags = _diagnose_season_slots(model_data, slots)

        assert len(diags) == 1
        diag = diags[0]
        assert diag["type"] == "season_slots_kept"
        assert diag["teamId"] == "SM1"
        assert "2/3" in diag["message"], diag["message"]
        causes = diag["causes"]
        assert len(causes) == 1, "seul samedi est non conservé (jeudi placé, vendredi tenu par le verrou)"
        assert causes[0]["kind"] == "slot_absent"

    def test_slot_closed_by_unavailability_is_displaced(self) -> None:
        """Le créneau de saison jeudi 19:00 a bien une FENÊTRE dans la période, mais l'équipe n'y est
        pas (jour fermé par l'indisponibilité de tous ses coachs requis, règle 3b A) → ``displaced``.
        Le samedi (aucune fenêtre) reste ``slot_absent`` — le diagnostic distingue les deux causes."""
        model_data = self._model(
            keep_refs=[_keep("SM1", THURSDAY, "19:00", "V"), _keep("SM1", SATURDAY, "10:00", "V")],
            windows=[(THURSDAY, "19:00")],  # la fenêtre jeudi existe ; aucune le samedi
        )
        slots: list[dict[str, Any]] = []  # l'équipe n'est placée NULLE PART (toutes indispos)

        diags = _diagnose_season_slots(model_data, slots)

        assert len(diags) == 1
        by_cause = {c["kind"] for c in diags[0]["causes"]}
        assert by_cause == {"displaced", "slot_absent"}, diags[0]["causes"]
        assert "0/2" in diags[0]["message"], diags[0]["message"]
