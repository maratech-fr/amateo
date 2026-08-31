"""P2-27 — mutualisation : N équipes déclarées s'entraînent ENSEMBLE.

La déclaration dit combien de séances sont PARTAGÉES (même gymnase, même jour, même heure),
EXACTEMENT — ni « au moins » ni « au plus ». Le solveur réifie le lien DANS LES DEUX SENS
(chaque membre présent ⇔ séance comptée) puis pose l'égalité ``Σ y == K``.

⚑ Falsification de la double réification (décision fondateur) :
  * sans la borne SUPÉRIEURE (``y ≤ x[tᵢ,s]``) le solveur peut allumer ``y`` sans co-localiser
    → il « fabrique » une séance commune inexistante ;
  * sans la borne INFÉRIEURE (``y ≥ Σx − (N−1)``) le solveur peut laisser ``y=0`` alors que
    tous les membres sont là → il « ne compte pas » une séance pourtant commune.
Chacun des deux tests ci-dessous tombe si SA direction est retirée.
"""

from __future__ import annotations

from typing import Any

from ortools.sat.python import cp_model

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.constraints import AssignmentVariable, add_shared_training_constraints
from app.solver.validate_assignments import validate_assignment
from tests.support.pipeline import (
    as_validate_payload,
    make_payload,
    make_team,
    make_venue,
    solve_payload,
    team_constraint,
)


def _shared_group(group_id: str, team_ids: list[str], common_sessions: int) -> dict[str, Any]:
    return {"id": group_id, "teamIds": team_ids, "commonSessions": common_sessions}


def _slots_of_team(output: dict[str, Any], team_id: str) -> set[tuple[str, int, str]]:
    return {
        (str(s["venueId"]), int(s["dayOfWeek"]), str(s["startTime"])[:5])
        for s in output["slots"]
        if str(s["teamId"]) == team_id
    }


def _common_sessions(output: dict[str, Any], team_ids: list[str]) -> set[tuple[str, int, str]]:
    sets = [_slots_of_team(output, t) for t in team_ids]
    return set.intersection(*sets) if sets else set()


# --- Pose (niveau modèle, byte-identique quand vide) -----------------------------------------


class TestPose:
    def test_empty_block_adds_nothing(self) -> None:
        model = cp_model.CpModel()
        added = add_shared_training_constraints(model, [], shared_trainings=[])
        assert added == 0
        assert len(model.Proto().variables) == 0

    def test_a_group_posts_reification_and_equality(self) -> None:
        model = cp_model.CpModel()
        assignments = [
            AssignmentVariable(var=model.NewBoolVar("t1_s"), team_id="t1", venue_id="v", slot_id="1:18:00"),
            AssignmentVariable(var=model.NewBoolVar("t2_s"), team_id="t2", venue_id="v", slot_id="1:18:00"),
        ]
        added = add_shared_training_constraints(
            model, assignments, shared_trainings=[_shared_group("g", ["t1", "t2"], 1)]
        )
        # 1 réification (y ≤ x pour 2 membres + y ≥ Σ) + l'égalité finale.
        assert added > 0
        assert len(model.Proto().variables) == 3  # t1_s, t2_s, y


# --- Exactitude : la borne INFÉRIEURE (compter une vraie commune) ----------------------------


class TestExactlyKLowerBound:
    """K=1 sur 2+2 séances → EXACTEMENT une commune, l'autre séparée. La borne INFÉRIEURE
    (``y ≥ Σx − (N−1)``) FORCE ``y=1`` dès que tous les membres sont sur la case — elle COMPTE
    chaque vraie co-présence. Pour la falsifier, il faut une INCITATION à co-localiser au-delà de
    K : les deux équipes PRÉFÈRENT le même gymnase (vA). Grille : vA capacité 2 (jours 1 et 2) +
    vB capacité 1 (jour 2, l'échappatoire qui rend K=1 atteignable).

    Avec la double réification : ``== 1`` interdit une 2ᵉ commune → une équipe part sur vB le jour
    2, EXACTEMENT une séance commune. SANS la borne inférieure : le solveur pose les deux équipes
    sur vA les DEUX jours (bonus de préférence ×2), n'allume qu'un ``y`` (``Σy = 1``) et croit
    respecter K — alors qu'il y a 2 séances communes RÉELLES. Ce test tombe alors (2 ≠ 1)."""

    def test_exactly_one_common_the_other_separate(self) -> None:
        teams = [make_team("t1", sessions_per_week=2), make_team("t2", sessions_per_week=2)]
        venues = [
            make_venue("vA", [(1, "18:00"), (2, "18:00")], capacity=2),
            make_venue("vB", [(2, "18:00")], capacity=1),
        ]
        # Les deux équipes PRÉFÈRENT vA : l'objectif VEUT les y co-localiser sur vA les deux jours.
        # Seule la borne inférieure de la réification empêche de « cacher » la 2ᵉ commune.
        constraints = [
            team_constraint(
                constraint_id="pv1",
                team_id="t1",
                family="FACILITY",
                rule_type="PREFERRED",
                config={"preferredVenueId": "vA"},
            ),
            team_constraint(
                constraint_id="pv2",
                team_id="t2",
                family="FACILITY",
                rule_type="PREFERRED",
                config={"preferredVenueId": "vA"},
            ),
        ]
        payload = make_payload(teams=teams, venues=venues, constraints=constraints)
        payload["sharedTrainings"] = [_shared_group("g", ["t1", "t2"], 1)]
        result = solve_payload(payload)
        assert result["status"] == "completed"
        common = _common_sessions(result, ["t1", "t2"])
        assert len(common) == 1, f"attendu EXACTEMENT 1 séance commune, obtenu {common}"
        assert len(_slots_of_team(result, "t1")) == 2
        assert len(_slots_of_team(result, "t2")) == 2


# --- Exactitude : la borne SUPÉRIEURE (ne pas fabriquer une commune) -------------------------


class TestExactlyKUpperBound:
    """K=1, une seule case partageable (capacité 2) et deux cases capacité 1. La seule façon
    d'obtenir 1 séance commune est de POSER les deux équipes sur la case capacité 2. Sans la
    borne supérieure, le solveur allumerait ``y`` sans les co-localiser → elles resteraient
    séparées et ce test tomberait."""

    def test_the_common_session_is_real_co_location(self) -> None:
        teams = [make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)]
        venues = [
            make_venue("vA", [(1, "18:00")], capacity=1),
            make_venue("vB", [(1, "18:00")], capacity=1),
            make_venue("vC", [(2, "18:00")], capacity=2),
        ]
        payload = make_payload(teams=teams, venues=venues)
        payload["sharedTrainings"] = [_shared_group("g", ["t1", "t2"], 1)]
        result = solve_payload(payload)
        assert result["status"] == "completed"
        common = _common_sessions(result, ["t1", "t2"])
        assert common == {("vC", 2, "18:00")}, f"les deux équipes doivent partager vC jour 2, obtenu {common}"


# --- Verrou pré-placé compté comme présence constante ----------------------------------------


class TestLockedSessionCounts:
    """Les séances VERROUILLÉES (HARD) n'ont pas de variable (``model.py`` la retire) : leur
    présence doit compter comme constante 1 dans la réification. Deux membres verrouillés sur
    la MÊME case forment une séance commune que la contrainte doit COMPTER — sans ce crédit,
    le groupe n'aurait aucune case candidate et ``Σy == K`` rendrait la génération infaisable."""

    def _lock(self, team_id: str) -> dict[str, Any]:
        return {
            "id": f"lock-{team_id}",
            "teamId": team_id,
            "venueId": "vC",
            "dayOfWeek": 2,
            "startTime": "18:00",
            "durationMinutes": 90,
            "lockLevel": "HARD",
        }

    def test_two_locked_members_form_a_counted_common_session(self) -> None:
        teams = [make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)]
        # Capacité 2 : les deux verrous cohabitent sur la même case.
        venues = [make_venue("vC", [(2, "18:00")], capacity=2), make_venue("vA", [(1, "18:00")], capacity=1)]
        payload = make_payload(teams=teams, venues=venues, slot_templates=[self._lock("t1"), self._lock("t2")])
        payload["sharedTrainings"] = [_shared_group("g", ["t1", "t2"], 1)]
        result = solve_payload(payload)
        # La séance commune verrouillée est COMPTÉE → Σy=1=K → faisable. Sans le crédit du
        # verrou, aucune case candidate n'existerait et la contrainte serait infaisable.
        assert result["status"] == "completed"
        assert len(_common_sessions(result, ["t1", "t2"])) == 1


# --- Infaisable quand aucune case de capacité suffisante -------------------------------------


class TestInfeasibleWithoutCapacity:
    def test_no_slot_of_sufficient_capacity_is_diagnosed(self) -> None:
        teams = [make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)]
        # Toutes les cases sont en capacité 1 : deux équipes ne peuvent JAMAIS partager.
        venues = [make_venue("vA", [(1, "18:00"), (2, "18:00"), (3, "18:00")], capacity=1)]
        payload = make_payload(teams=teams, venues=venues)
        payload["sharedTrainings"] = [_shared_group("g", ["t1", "t2"], 1)]
        result = solve_payload(payload)
        assert result["status"] == "failed"
        assert any(d["type"] == "shared_training_not_honored" for d in result["diagnostics"])


# --- Bloc vide == bloc absent (chemin byte-identique) ----------------------------------------


class TestEmptyEqualsAbsent:
    def test_empty_shared_block_matches_no_block(self) -> None:
        teams = [make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)]
        venues = [make_venue("vA", [(1, "18:00"), (2, "18:00")], capacity=2)]
        without = solve_payload(make_payload(teams=teams, venues=venues))
        with_empty_payload = make_payload(teams=teams, venues=venues)
        with_empty_payload["sharedTrainings"] = []
        with_empty = solve_payload(with_empty_payload)
        assert without["slots"] == with_empty["slots"]
        assert without["score"] == with_empty["score"]


# --- Verdict de déplacement (parité génération ⇄ verdict) ------------------------------------


class TestMoveVerdict:
    """Sortir une équipe d'une case commune est REFUSÉ, motif nommé. Le solveur seul ne peut
    pas produire ce refus (il remettrait l'équipe sur son ancienne case) : le verdict s'appuie
    sur un miroir DÉTERMINISTE de l'état concret proposé (baseline sans la source + candidat)."""

    def _payload(self, candidate: dict[str, Any], slot_templates: list[dict[str, Any]], **over: Any) -> dict[str, Any]:
        payload: dict[str, Any] = {
            "clubId": "club",
            "seasonId": "season",
            "venues": [make_venue("A", [(4, "18:00"), (4, "20:00")], capacity=2)],
            "teams": over.get("teams", [make_team("U13"), make_team("U15")]),
            "coaches": [],
            "constraints": [],
            "slotTemplates": slot_templates,
            "sharedTrainings": over.get("sharedTrainings", [_shared_group("g", ["U13", "U15"], 1)]),
            "candidate": candidate,
        }
        return as_validate_payload(payload)

    def test_moving_a_member_out_of_the_common_case_is_refused(self) -> None:
        # U13 + U15 partagent A/18:00 (K=1) ; on déplace U13 vers A/20:00 (seul). La source est
        # exclue de la baseline (comme le fait MoveSlotService), U15 reste sur la case commune.
        result = validate_assignment(
            ValidateAssignmentsInputSchema.model_validate(
                self._payload(
                    candidate={
                        "teamId": "U13",
                        "venueId": "A",
                        "dayOfWeek": 4,
                        "startTime": "20:00",
                        "durationMinutes": 90,
                    },
                    slot_templates=[
                        {
                            "id": "s-u15",
                            "teamId": "U15",
                            "venueId": "A",
                            "dayOfWeek": 4,
                            "startTime": "18:00",
                            "durationMinutes": 90,
                        },
                    ],
                )
            )
        )
        assert result["valid"] is False
        assert any(v["rule"] == "shared_training_broken" for v in result["violations"])

    def test_moving_an_unrelated_team_is_not_refused_by_shared_training(self) -> None:
        # U20 n'est dans aucun groupe : son déplacement ne doit jamais rougir la mutualisation.
        result = validate_assignment(
            ValidateAssignmentsInputSchema.model_validate(
                self._payload(
                    teams=[make_team("U13"), make_team("U15"), make_team("U20")],
                    candidate={
                        "teamId": "U20",
                        "venueId": "A",
                        "dayOfWeek": 4,
                        "startTime": "20:00",
                        "durationMinutes": 90,
                    },
                    slot_templates=[
                        {
                            "id": "s-u13",
                            "teamId": "U13",
                            "venueId": "A",
                            "dayOfWeek": 4,
                            "startTime": "18:00",
                            "durationMinutes": 90,
                        },
                        {
                            "id": "s-u15",
                            "teamId": "U15",
                            "venueId": "A",
                            "dayOfWeek": 4,
                            "startTime": "18:00",
                            "durationMinutes": 90,
                        },
                    ],
                )
            )
        )
        assert not any(v["rule"] == "shared_training_broken" for v in result["violations"])
