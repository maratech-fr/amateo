"""Pose de l'exemption coach-joueur sur la SÉANCE DE BLOC — au grain du modèle CP-SAT.

Ces tests sondent la MÉCANIQUE exacte de la relaxation (``≤ 1 + Σb``), là où les tests sémantiques
(``tests/semantic/test_coach_player_block_exemption.py``) en prouvent l'EFFET bout-en-bout :
  * une paire de la même case n'est co-satisfiable que si la séance de bloc est ACTIVE (b=1) ;
  * face à un verrou de la même case, la séance libre tient sous ``var ≤ Σb`` — SANS fermeture
    P4-99, alors que le chemin inconditionnel garde sa cause ``hard_lock`` ;
  * la géométrie fine D-D : un chevauchement à débuts DIFFÉRENTS, ou un AUTRE gymnase, reste
    opposé même quand un bloc siège sur la case voisine ;
  * sans aucun bloc, la pose est strictement celle d'avant (borne ``≤ 1``).

Axe structurant ``constraint semantics`` (CLAUDE.md §7.1).
"""

from __future__ import annotations

import unittest

from ortools.sat.python import cp_model

from app.solver.constraints import AssignmentVariable, add_coach_player_non_overlap
from app.solver.model import ScheduleCpModel

# p1 coache t1 et joue dans t2 : la personne double-rôle du cas Maxime.
COACH_MAP = {"t1": ["p1"]}
PLAYER_MAP = {"t2": ["p1"]}


def _assignment(model: cp_model.CpModel, name: str, *, team: str, venue: str, slot_id: str, start: int, end: int):
    return AssignmentVariable(
        var=model.NewBoolVar(name),
        team_id=team,
        venue_id=venue,
        slot_id=slot_id,
        start=start,
        end=end,
    )


def _locked(team: str, venue: str, day: int, start: str, *, duration: int = 90) -> dict[str, object]:
    return {
        "team_id": team,
        "venue_id": venue,
        "day_of_week": day,
        "start_time": start,
        "duration_minutes": duration,
    }


def _solve(model: cp_model.CpModel) -> int:
    solver = cp_model.CpSolver()
    solver.parameters.max_time_in_seconds = 2
    return solver.Solve(model)


FEASIBLE_STATES = (cp_model.OPTIMAL, cp_model.FEASIBLE)


class SameCasePairGatedOnBlockTest(unittest.TestCase):
    """Une paire coach-joueur de la MÊME case n'est co-plaçable QUE si la séance de bloc est active."""

    def test_both_sessions_need_the_block_session_active(self) -> None:
        model = ScheduleCpModel()
        coaching = _assignment(model, "coaching", team="t1", venue="v", slot_id="1:18:00", start=1080, end=1170)
        playing = _assignment(model, "playing", team="t2", venue="v", slot_id="1:18:00", start=1080, end=1170)
        b = model.NewBoolVar("block_b")
        model.shared_block_case_bvars = {("v", "1:18:00"): [(frozenset({"t1", "t2"}), b)]}

        add_coach_player_non_overlap(model, [coaching, playing], team_coach_map=COACH_MAP, team_player_map=PLAYER_MAP)

        # b=1 (séance de bloc active) → les deux tiennent ensemble.
        model.Add(coaching.var == 1)
        model.Add(playing.var == 1)
        model.Add(b == 1)
        self.assertIn(_solve(model), FEASIBLE_STATES)

    def test_both_sessions_are_impossible_without_the_block_session(self) -> None:
        model = ScheduleCpModel()
        coaching = _assignment(model, "coaching", team="t1", venue="v", slot_id="1:18:00", start=1080, end=1170)
        playing = _assignment(model, "playing", team="t2", venue="v", slot_id="1:18:00", start=1080, end=1170)
        b = model.NewBoolVar("block_b")
        model.shared_block_case_bvars = {("v", "1:18:00"): [(frozenset({"t1", "t2"}), b)]}

        add_coach_player_non_overlap(model, [coaching, playing], team_coach_map=COACH_MAP, team_player_map=PLAYER_MAP)

        # b=0 (pas de séance de bloc) → la coïncidence redevient un conflit strict.
        model.Add(coaching.var == 1)
        model.Add(playing.var == 1)
        model.Add(b == 0)
        self.assertEqual(cp_model.INFEASIBLE, _solve(model))


class FreeVersusLockedBlockCaseTest(unittest.TestCase):
    """Un verrou de la même case : la séance libre tient sous ``var ≤ Σb`` (P4-97 bis réifié)."""

    def _model(self) -> tuple[ScheduleCpModel, object, object]:
        model = ScheduleCpModel()
        # t2 (où p1 JOUE) est VERROUILLÉE sur la case ; t1 (que p1 COACHE) est libre sur la même case.
        model.locked_slots = (_locked("t2", "v", 1, "18:00"),)
        coaching = _assignment(model, "coaching", team="t1", venue="v", slot_id="1:18:00", start=1080, end=1170)
        b = model.NewBoolVar("block_b")
        model.shared_block_case_bvars = {("v", "1:18:00"): [(frozenset({"t1", "t2"}), b)]}
        add_coach_player_non_overlap(model, [coaching], team_coach_map=COACH_MAP, team_player_map=PLAYER_MAP)
        return model, coaching.var, b

    def test_free_session_holds_when_the_block_session_is_active(self) -> None:
        model, coaching_var, b = self._model()
        model.Add(coaching_var == 1)
        model.Add(b == 1)
        self.assertIn(_solve(model), FEASIBLE_STATES)

    def test_free_session_is_forced_to_zero_without_the_block_session(self) -> None:
        model, coaching_var, b = self._model()
        model.Add(coaching_var == 1)
        model.Add(b == 0)
        self.assertEqual(cp_model.INFEASIBLE, _solve(model))

    def test_reified_path_records_no_hard_lock_closure(self) -> None:
        """Le chemin réifié (case de bloc) ne pose AUCUNE fermeture P4-99 : la séance n'est pas
        « fermée », elle est conditionnée à ``b``."""
        model, coaching_var, _b = self._model()
        self.assertNotIn(coaching_var.Index(), model.candidate_closures)


class UnconditionalLockKeepsItsClosureTest(unittest.TestCase):
    """Sans bloc sur la case, le verrou FERME le créneau libre et garde sa cause ``hard_lock``."""

    def test_unconditional_lock_records_a_hard_lock_closure(self) -> None:
        model = ScheduleCpModel()
        model.locked_slots = (_locked("t2", "v", 1, "18:00"),)
        coaching = _assignment(model, "coaching", team="t1", venue="v", slot_id="1:18:00", start=1080, end=1170)
        # AUCUN bloc : ``shared_block_case_bvars`` reste vide.
        add_coach_player_non_overlap(model, [coaching], team_coach_map=COACH_MAP, team_player_map=PLAYER_MAP)

        model.Add(coaching.var == 1)
        self.assertEqual(cp_model.INFEASIBLE, _solve(model))
        closures = model.candidate_closures.get(coaching.var.Index(), [])
        self.assertTrue(any(c.get("kind") == "hard_lock" for c in closures))


class DistinctCasesStayOpposedTest(unittest.TestCase):
    """D-D — l'exemption exige la MÊME case (même gymnase + même début). Un chevauchement à débuts
    DIFFÉRENTS ou un AUTRE gymnase reste opposé, même quand un bloc siège sur la case voisine."""

    def test_overlapping_but_different_start_is_not_exempt(self) -> None:
        model = ScheduleCpModel()
        coaching = _assignment(model, "coaching", team="t1", venue="v", slot_id="1:18:00", start=1080, end=1170)
        playing = _assignment(model, "playing", team="t2", venue="v", slot_id="1:18:30", start=1095, end=1185)
        b = model.NewBoolVar("block_b")
        # Le bloc siège à 18:00 ; la séance de t2 à 18:30 CHEVAUCHE sans être la même case.
        model.shared_block_case_bvars = {("v", "1:18:00"): [(frozenset({"t1", "t2"}), b)]}
        add_coach_player_non_overlap(model, [coaching, playing], team_coach_map=COACH_MAP, team_player_map=PLAYER_MAP)

        model.Add(coaching.var == 1)
        model.Add(playing.var == 1)
        model.Add(b == 1)
        self.assertEqual(cp_model.INFEASIBLE, _solve(model))

    def test_same_start_different_venue_is_not_exempt(self) -> None:
        model = ScheduleCpModel()
        coaching = _assignment(model, "coaching", team="t1", venue="vA", slot_id="1:18:00", start=1080, end=1170)
        playing = _assignment(model, "playing", team="t2", venue="vB", slot_id="1:18:00", start=1080, end=1170)
        b = model.NewBoolVar("block_b")
        model.shared_block_case_bvars = {("vA", "1:18:00"): [(frozenset({"t1", "t2"}), b)]}
        add_coach_player_non_overlap(model, [coaching, playing], team_coach_map=COACH_MAP, team_player_map=PLAYER_MAP)

        model.Add(coaching.var == 1)
        model.Add(playing.var == 1)
        model.Add(b == 1)
        self.assertEqual(cp_model.INFEASIBLE, _solve(model))


class NoBlockIsByteIdenticalTest(unittest.TestCase):
    """Aucun bloc ⇒ borne stricte ``≤ 1`` : la paire coach-joueur reste un conflit dur."""

    def test_pair_is_opposed_without_any_block(self) -> None:
        model = ScheduleCpModel()
        coaching = _assignment(model, "coaching", team="t1", venue="v", slot_id="1:18:00", start=1080, end=1170)
        playing = _assignment(model, "playing", team="t2", venue="v", slot_id="1:18:00", start=1080, end=1170)
        # ``shared_block_case_bvars`` vide (défaut) : aucune exemption.
        add_coach_player_non_overlap(model, [coaching, playing], team_coach_map=COACH_MAP, team_player_map=PLAYER_MAP)

        model.Add(coaching.var == 1)
        model.Add(playing.var == 1)
        self.assertEqual(cp_model.INFEASIBLE, _solve(model))


if __name__ == "__main__":
    unittest.main()
