"""Lot PASSERELLES PR-2 — le solveur HONORE les passerelles (deux équipes partageant des joueurs).

Sémantique (arbitrages fondateur) :
  * MANDATORY (dur) : les séances des deux équipes ne se chevauchent JAMAIS (cross-gymnase compris) ;
  * PREFERRED (souple) : un malus par chevauchement, dérivé du tier — sépare quand c'est possible
    sans jamais SUPPRIMER une séance ;
  * EXEMPTION : une séance MUTUALISÉE DÉCLARÉE (même case + bloc ``sharedBlocks``) est la
    SEULE simultanéité autorisée — c'est PLUS STRICT que la tolérance coach D-14 (doctrine n°3) ;
  * deux VERROUS HARD qui se chevauchent sur une MANDATORY = acte volontaire contradictoire :
    COMPLETED + diagnostic ``team_link_not_honored``, jamais INFEASIBLE muet (CLAUDE.md §6).

Ce fichier tient la POSE (niveau modèle, byte-identique quand vide) et la SÉMANTIQUE (pipeline
réel). Le NR « PREFERRED ne supprime jamais une séance » vit dans
``tests/semantic/test_team_link_never_drops_session.py`` (axe §7.1 + invariant hypothesis).
"""

from __future__ import annotations

from typing import Any

from ortools.sat.python import cp_model

from app.solver.constraints import AssignmentVariable, add_team_link_constraints
from app.solver.objective import TEAM_LINK_TIER_WEIGHTS, add_team_link_penalty
from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload, team_constraint


def _link(link_id: str, team_a: str, team_b: str, intensity: str) -> dict[str, Any]:
    return {"id": link_id, "teamAId": team_a, "teamBId": team_b, "intensity": intensity}


def _shared_block(block_id: str, team_ids: list[str], common_sessions: int) -> dict[str, Any]:
    return {"id": block_id, "teamIds": team_ids, "commonSessions": common_sessions}


def _mins(hhmm: str) -> int:
    h, m = str(hhmm)[:5].split(":")
    return int(h) * 60 + int(m)


def _cases_of(result: dict[str, Any], team_id: str) -> set[tuple[int, str, int]]:
    """Cases finales d'une équipe : (jour, gymnase, heure de début en minutes)."""
    return {
        (int(s["dayOfWeek"]), str(s["venueId"]), _mins(s["startTime"]))
        for s in result["slots"]
        if str(s["teamId"]) == team_id
    }


def _teams_overlap(result: dict[str, Any], team_a: str, team_b: str) -> bool:
    def spans(team: str) -> list[tuple[int, int, int]]:
        out = []
        for s in result["slots"]:
            if str(s["teamId"]) != team:
                continue
            start = _mins(s["startTime"])
            out.append((int(s["dayOfWeek"]), start, start + int(s["durationMinutes"])))
        return out

    for a_day, a_start, a_end in spans(team_a):
        for b_day, b_start, b_end in spans(team_b):
            if a_day == b_day and a_start < b_end and b_start < a_end:
                return True
    return False


# --- Pose (niveau modèle, byte-identique quand vide) -----------------------------------------


class TestPose:
    def test_empty_block_adds_nothing(self) -> None:
        model = cp_model.CpModel()
        added = add_team_link_constraints(model, [], team_links=[])
        assert added == 0
        assert len(model.Proto().variables) == 0

    def test_all_preferred_adds_no_hard_constraint(self) -> None:
        """Seules les MANDATORY sont posées ici ; une PREFERRED ne pose aucune contrainte dure."""
        model = cp_model.CpModel()
        a, b = model.NewBoolVar("a"), model.NewBoolVar("b")
        assignments = [
            AssignmentVariable(var=a, team_id="t1", venue_id="v", slot_id="1:18:00", start=1080, end=1170),
            AssignmentVariable(var=b, team_id="t2", venue_id="v", slot_id="1:18:00", start=1080, end=1170),
        ]
        added = add_team_link_constraints(model, assignments, team_links=[_link("l", "t1", "t2", "PREFERRED")])
        assert added == 0

    def test_mandatory_posts_non_overlap_for_overlapping_pair(self) -> None:
        model = cp_model.CpModel()
        a, b = model.NewBoolVar("a"), model.NewBoolVar("b")
        # Deux gymnases DIFFÉRENTS, même jour/heure : chevauchement cross-gymnase (doctrine n°2).
        assignments = [
            AssignmentVariable(var=a, team_id="t1", venue_id="vA", slot_id="1:18:00", start=1080, end=1170),
            AssignmentVariable(var=b, team_id="t2", venue_id="vB", slot_id="1:18:00", start=1080, end=1170),
        ]
        added = add_team_link_constraints(model, assignments, team_links=[_link("l", "t1", "t2", "MANDATORY")])
        assert added == 1
        # Falsification : mutuellement exclusifs — les deux ensemble sont INFEASIBLE.
        model.Add(a == 1)
        model.Add(b == 1)
        assert cp_model.CpSolver().Solve(model) == cp_model.INFEASIBLE

    def test_mandatory_ignores_non_overlapping_pair(self) -> None:
        model = cp_model.CpModel()
        a, b = model.NewBoolVar("a"), model.NewBoolVar("b")
        assignments = [
            AssignmentVariable(var=a, team_id="t1", venue_id="vA", slot_id="1:18:00", start=1080, end=1170),
            AssignmentVariable(var=b, team_id="t2", venue_id="vB", slot_id="2:18:00", start=1080, end=1170),
        ]
        added = add_team_link_constraints(model, assignments, team_links=[_link("l", "t1", "t2", "MANDATORY")])
        assert added == 0

    def test_mandatory_exempts_declared_shared_common_case(self) -> None:
        """Même case (gymnase + jour + heure) ET groupe partagé déclaré → EXEMPT, rien posé."""
        model = cp_model.CpModel()
        a, b = model.NewBoolVar("a"), model.NewBoolVar("b")
        assignments = [
            AssignmentVariable(var=a, team_id="t1", venue_id="vS", slot_id="1:18:00", start=1080, end=1170),
            AssignmentVariable(var=b, team_id="t2", venue_id="vS", slot_id="1:18:00", start=1080, end=1170),
        ]
        added = add_team_link_constraints(
            model,
            assignments,
            team_links=[_link("l", "t1", "t2", "MANDATORY")],
            shared_blocks=[_shared_block("g", ["t1", "t2"], 1)],
        )
        assert added == 0
        # Falsification doctrine : MÊME gymnase, même heure, SANS groupe déclaré → chevauchement.
        model2 = cp_model.CpModel()
        a2, b2 = model2.NewBoolVar("a"), model2.NewBoolVar("b")
        assignments2 = [
            AssignmentVariable(var=a2, team_id="t1", venue_id="vS", slot_id="1:18:00", start=1080, end=1170),
            AssignmentVariable(var=b2, team_id="t2", venue_id="vS", slot_id="1:18:00", start=1080, end=1170),
        ]
        assert add_team_link_constraints(model2, assignments2, team_links=[_link("l", "t1", "t2", "MANDATORY")]) == 1

    def test_mandatory_free_vs_locked_closes_the_free_and_names_the_link(self) -> None:
        """Verrou souverain : la séance LIBRE qui chevauche un verrou passerelé est fermée, la
        cause NOMME la passerelle dans ``candidate_closures``. Si cette séance est REQUISE
        (son unique fenêtre), le modèle sort INFEASIBLE avec la cause sur le registre."""
        model = cp_model.CpModel()
        model.candidate_closures = {}  # type: ignore[attr-defined]
        model.locked_slots = (  # type: ignore[attr-defined]
            {"team_id": "t2", "venue_id": "vX", "day_of_week": 1, "start_time": "18:00", "duration_minutes": 90},
        )
        a = model.NewBoolVar("t1_only")
        assignments = [
            AssignmentVariable(var=a, team_id="t1", venue_id="vY", slot_id="1:18:00", start=1080, end=1170),
        ]
        added = add_team_link_constraints(model, assignments, team_links=[_link("l1", "t1", "t2", "MANDATORY")])
        assert added == 1
        closures = model.candidate_closures[a.Index()]  # type: ignore[attr-defined]
        assert any(c["kind"] == "team_link" and c["constraintId"] == "l1" for c in closures)
        # La libre est l'unique fenêtre de t1 et t1 doit jouer → INFEASIBLE, cause nommée.
        model.Add(a == 1)
        assert cp_model.CpSolver().Solve(model) == cp_model.INFEASIBLE


class TestPreferredPenaltyPose:
    def test_preferred_penalty_weight_is_the_higher_tier(self) -> None:
        model = cp_model.CpModel()
        a, b = model.NewBoolVar("a"), model.NewBoolVar("b")
        assignments = [
            {"var": a, "team_id": "t1", "venue_id": "vA", "slot_id": "1:18:00", "start": 1080, "end": 1170},
            {"var": b, "team_id": "t2", "venue_id": "vB", "slot_id": "1:18:00", "start": 1080, "end": 1170},
        ]
        # t1 = tier S (1), t2 = tier C (4) → la PLUS HAUTE (S) commande le poids.
        teams = [
            {"id": "t1", "priorityTierId": 1},
            {"id": "t2", "priorityTierId": 4},
        ]
        terms = add_team_link_penalty(model, assignments, team_links=[_link("l", "t1", "t2", "PREFERRED")], teams=teams)
        assert len(terms) == 1
        _var, weight = terms[0]
        assert weight == -TEAM_LINK_TIER_WEIGHTS["S"]

    def test_mandatory_link_yields_no_penalty_term(self) -> None:
        model = cp_model.CpModel()
        a, b = model.NewBoolVar("a"), model.NewBoolVar("b")
        assignments = [
            {"var": a, "team_id": "t1", "venue_id": "vA", "slot_id": "1:18:00", "start": 1080, "end": 1170},
            {"var": b, "team_id": "t2", "venue_id": "vB", "slot_id": "1:18:00", "start": 1080, "end": 1170},
        ]
        teams = [{"id": "t1", "priorityTierId": 1}, {"id": "t2", "priorityTierId": 1}]
        terms = add_team_link_penalty(model, assignments, team_links=[_link("l", "t1", "t2", "MANDATORY")], teams=teams)
        assert terms == []


# --- Sémantique (pipeline réel) --------------------------------------------------------------

VS = "v-shared"  # gymnase capacité 2 : la case tentante des deux jours
T1, T2 = "team-1", "team-2"


def _both_days_shared_venue() -> list[dict[str, Any]]:
    """Un gymnase capacité 2, mêmes créneaux 18:00 lundi ET mardi — deux jours interchangeables."""
    return [make_venue(VS, [(1, "18:00"), (2, "18:00")], capacity=2)]


def _prefer_monday(team_id: str) -> dict[str, Any]:
    return team_constraint(
        constraint_id=f"{team_id}-pref-mon",
        team_id=team_id,
        family="DAY",
        rule_type="PREFERRED",
        config={"preferredDays": [1]},
    )


class TestSemanticPreferred:
    def _teams(self) -> list[dict[str, Any]]:
        # Tier S (poids passerelle 8) : 8 > preferred_day(5), donc le malus BAT la préférence de
        # jour — un placement séparé est meilleur qu'un chevauchement.
        return [
            make_team(T1, sessions_per_week=1, priority_tier_id=1),
            make_team(T2, sessions_per_week=1, priority_tier_id=1),
        ]

    def _constraints(self) -> list[dict[str, Any]]:
        return [_prefer_monday(T1), _prefer_monday(T2)]

    def test_witness_without_link_they_co_locate(self) -> None:
        """TÉMOIN : sans passerelle, les deux préfèrent lundi et se retrouvent en même temps."""
        result = solve_payload(
            make_payload(teams=self._teams(), venues=_both_days_shared_venue(), constraints=self._constraints()),
            timeout=15,
        )
        assert result["status"] == "completed"
        assert _teams_overlap(result, T1, T2), "témoin cassé : elles se séparent déjà sans passerelle"

    def test_preferred_link_separates_when_possible(self) -> None:
        """PREFERRED : le malus tier S (8 > preferred_day 5) sépare, SANS supprimer de séance."""
        payload = make_payload(teams=self._teams(), venues=_both_days_shared_venue(), constraints=self._constraints())
        payload["teamLinks"] = [_link("l", T1, T2, "PREFERRED")]
        result = solve_payload(payload, timeout=15)
        assert result["status"] == "completed"
        assert not _teams_overlap(result, T1, T2), "la passerelle PREFERRED aurait dû les séparer"
        assert len(_cases_of(result, T1)) == 1 and len(_cases_of(result, T2)) == 1, "aucune séance supprimée"


class TestSemanticMandatory:
    def _teams(self) -> list[dict[str, Any]]:
        # Tier B : la préférence de jour ne suffirait pas à séparer un PREFERRED, mais MANDATORY
        # sépare INCONDITIONNELLEMENT — quel que soit le poids.
        return [
            make_team(T1, sessions_per_week=1, priority_tier_id=3),
            make_team(T2, sessions_per_week=1, priority_tier_id=3),
        ]

    def test_mandatory_separates_even_against_day_preference(self) -> None:
        payload = make_payload(
            teams=self._teams(),
            venues=_both_days_shared_venue(),
            constraints=[_prefer_monday(T1), _prefer_monday(T2)],
        )
        payload["teamLinks"] = [_link("l", T1, T2, "MANDATORY")]
        result = solve_payload(payload, timeout=15)
        assert result["status"] == "completed"
        assert not _teams_overlap(result, T1, T2), "MANDATORY doit interdire tout chevauchement"
        assert len(_cases_of(result, T1)) == 1 and len(_cases_of(result, T2)) == 1

    def test_mandatory_with_declared_shared_block_stays_feasible_and_co_located(self) -> None:
        """EXEMPTION : MANDATORY + mutualisation K=1 déclarée → la séance commune est autorisée.

        Falsification : SANS l'exemption, mutualisation (co-localisation forcée) et MANDATORY
        (chevauchement interdit) se contrediraient → INFEASIBLE. L'exemption les réconcilie."""
        payload = make_payload(
            teams=self._teams(),
            venues=[make_venue(VS, [(1, "18:00")], capacity=2)],
        )
        payload["teamLinks"] = [_link("l", T1, T2, "MANDATORY")]
        payload["sharedBlocks"] = [_shared_block("g", [T1, T2], 1)]
        result = solve_payload(payload, timeout=15)
        assert result["status"] == "completed", "l'exemption doit rendre le scénario faisable"
        assert _cases_of(result, T1) == _cases_of(result, T2), "la séance mutualisée déclarée doit co-localiser"

    def test_mandatory_free_session_escapes_a_locked_overlap(self) -> None:
        """verrou-vs-libre : t2 verrouillée lundi 18:00 ; t1 (liée MANDATORY) s'écarte sur mardi."""
        venues = [
            make_venue("vX", [(1, "18:00")], capacity=1),  # la case verrouillée de t2
            make_venue("vY", [(1, "18:00"), (2, "18:00")], capacity=1),  # les fenêtres de t1
        ]
        locked = [
            {
                "id": "lock-t2",
                "teamId": T2,
                "venueId": "vX",
                "dayOfWeek": 1,
                "startTime": "18:00",
                "durationMinutes": 90,
                "lockLevel": "HARD",
            }
        ]
        payload = make_payload(teams=self._teams(), venues=venues, slot_templates=locked)
        payload["teamLinks"] = [_link("l", T1, T2, "MANDATORY")]
        result = solve_payload(payload, timeout=15)
        assert result["status"] == "completed"
        assert not _teams_overlap(result, T1, T2), "t1 aurait dû fuir le chevauchement avec le verrou de t2"
        # t1 placée mardi (jour 2), sa seule fenêtre libre du chevauchement.
        assert _cases_of(result, T1) == {(2, "vY", _mins("18:00"))}

    def test_two_locked_sessions_overlapping_complete_with_diagnostic(self) -> None:
        """verrou-vs-verrou sur MANDATORY : COMPLETED + diagnostic ``team_link_not_honored``,
        jamais INFEASIBLE muet (deux actes volontaires du gestionnaire, annoncés — CLAUDE.md §6)."""
        venues = [
            make_venue("vX", [(1, "18:00")], capacity=1),
            make_venue("vY", [(1, "18:00")], capacity=1),
        ]
        locked = [
            {
                "id": "lk1",
                "teamId": T1,
                "venueId": "vX",
                "dayOfWeek": 1,
                "startTime": "18:00",
                "durationMinutes": 90,
                "lockLevel": "HARD",
            },
            {
                "id": "lk2",
                "teamId": T2,
                "venueId": "vY",
                "dayOfWeek": 1,
                "startTime": "18:00",
                "durationMinutes": 90,
                "lockLevel": "HARD",
            },
        ]
        payload = make_payload(teams=self._teams(), venues=venues, slot_templates=locked)
        payload["teamLinks"] = [_link("l", T1, T2, "MANDATORY")]
        result = solve_payload(payload, timeout=15)
        assert result["status"] == "completed", "deux verrous ne doivent JAMAIS produire un INFEASIBLE muet"
        assert _teams_overlap(result, T1, T2), "les deux verrous restent posés (souverains)"
        codes = {d["type"] for d in result["diagnostics"]}
        assert "team_link_not_honored" in codes, "le chevauchement de deux verrous doit être ANNONCÉ"
