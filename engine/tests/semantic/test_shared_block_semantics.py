"""P2-51 PR-3 — SÉMANTIQUE de la mutualisation par BLOC (arbitrages fondateur D9-D12).

Un bloc se comporte comme UNE équipe : le solveur place ses ``commonSessions`` séances comme
celles d'une équipe (chacune = une case gymnase/jour/heure où TOUS les membres sont ensemble), et
ses séances CONSOMMENT celles des membres. La modélisation est un LIAGE : variable de décision du
bloc ``b[s]`` + ``x[membre, s] >= b[s]`` par membre + ``Σb == commonSessions`` (aucun comptage par
co-présence — deux blocs partageant une équipe ont des ``b`` INDÉPENDANTS). Ce fichier prouve, au
travers du VRAI pipeline (``solve_payload``) et du VERDICT (``validate_assignment``), chacun des
neuf arbitrages, chaque test étant écrit pour ROUGIR si sa direction est retirée.
"""

from __future__ import annotations

from typing import Any

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.validate_assignments import validate_assignment
from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload, team_constraint


def _block(block_id: str, team_ids: list[str], common_sessions: int = 1) -> dict[str, Any]:
    return {"id": block_id, "teamIds": team_ids, "commonSessions": common_sessions}


def _slots_of_team(output: dict[str, Any], team_id: str) -> set[tuple[str, int, str]]:
    return {
        (str(s["venueId"]), int(s["dayOfWeek"]), str(s["startTime"])[:5])
        for s in output["slots"]
        if str(s["teamId"]) == team_id
    }


def _common(output: dict[str, Any], team_ids: list[str]) -> set[tuple[str, int, str]]:
    sets = [_slots_of_team(output, t) for t in team_ids]
    return set.intersection(*sets) if sets else set()


# ── Arbitrage n°1 — le solveur place la séance de bloc, en UNE occupation de capacité 1 ──────────


class TestBlockIsPlacedLikeATeam:
    def test_block_places_its_common_session_in_a_capacity_one_slot(self) -> None:
        """Falsification (a) — le CO-PLACEMENT du bloc, contre une INCITATION à SÉPARER : t1 préfère
        vA, t2 préfère vB (gymnases capacité 1 opposés), une seule case commune existe (vC, capacité
        1). SANS le liage ``x >= b``, l'objectif l'emporte et chacun va à son gymnase préféré →
        ``_common`` vide (le co-placement du bloc n'est pas honoré). AVEC : ``Σb == 1`` + le liage
        FORCENT la case commune, et le dé-comptage la fait tenir en capacité 1. Le témoin est
        l'objectif : sans le liage, la préférence gagne."""
        teams = [make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)]
        venues = [
            make_venue("vA", [(1, "18:00")], capacity=1),
            make_venue("vB", [(1, "18:00")], capacity=1),
            make_venue("vC", [(2, "18:00")], capacity=1),
        ]
        constraints = [
            team_constraint(
                constraint_id="pA",
                team_id="t1",
                family="FACILITY",
                rule_type="PREFERRED",
                config={"preferredVenueId": "vA"},
            ),
            team_constraint(
                constraint_id="pB",
                team_id="t2",
                family="FACILITY",
                rule_type="PREFERRED",
                config={"preferredVenueId": "vB"},
            ),
        ]
        payload = make_payload(teams=teams, venues=venues, constraints=constraints)
        payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]
        result = solve_payload(payload)
        assert result["status"] == "completed"
        # Co-localisées MALGRÉ les préférences opposées : exactement UNE case commune. Sans le liage,
        # chacune suivrait sa préférence (t1→vA, t2→vB) et il n'y aurait AUCUNE case commune.
        assert len(_common(result, ["t1", "t2"])) == 1, "le bloc doit forcer une séance commune malgré les préférences"
        assert len(_slots_of_team(result, "t1")) == 1
        assert len(_slots_of_team(result, "t2")) == 1

    def test_block_session_consumes_a_member_session(self) -> None:
        """La séance de bloc CONSOMME une séance du membre : t1 à 2 séances/sem, membre d'un bloc à
        1, garde 1 séance individuelle (1 commune + 1 séparée)."""
        teams = [make_team("t1", sessions_per_week=2), make_team("t2", sessions_per_week=1)]
        venues = [make_venue("v", [(1, "18:00"), (2, "18:00"), (3, "18:00")], capacity=1)]
        payload = make_payload(teams=teams, venues=venues)
        payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]
        result = solve_payload(payload)
        assert result["status"] == "completed"
        assert len(_slots_of_team(result, "t1")) == 2, "t1 : 1 séance de bloc + 1 individuelle"
        assert len(_slots_of_team(result, "t2")) == 1
        assert len(_common(result, ["t1", "t2"])) == 1


# ── Arbitrage n°2 — LE MUR DU DOUBLE-COMPTAGE : deux blocs imbriqués, séances DISTINCTES ─────────


class TestNestedBlocksDoNotDoubleCount:
    def test_two_blocks_sharing_two_teams_produce_distinct_sessions(self) -> None:
        """LE test du lot. Bloc {U9F1,U9F2} 1 séance + bloc {U9M2,U9F1,U9F2} 1 séance → DEUX séances
        distinctes, aucune interférence de comptage : la garde de distinctness interdit aux deux
        blocs de s'effondrer sur la même case. La séance de la paire (sans U9M2) et celle du trio
        sont sur des cases DIFFÉRENTES."""
        teams = [
            make_team("U9F1", sessions_per_week=2),
            make_team("U9F2", sessions_per_week=2),
            make_team("U9M2", sessions_per_week=1),
        ]
        venues = [make_venue("v", [(1, "18:00"), (2, "18:00"), (3, "18:00")], capacity=1)]
        payload = make_payload(teams=teams, venues=venues)
        payload["sharedBlocks"] = [
            _block("b1", ["U9F1", "U9F2"], 1),
            _block("b2", ["U9M2", "U9F1", "U9F2"], 1),
        ]
        result = solve_payload(payload)
        assert result["status"] == "completed"
        trio = _common(result, ["U9M2", "U9F1", "U9F2"])
        pair = _common(result, ["U9F1", "U9F2"])
        assert len(trio) == 1, "le bloc trio place UNE séance commune"
        assert len(pair) == 2, "U9F1/U9F2 sont ensemble deux fois : leur bloc paire ET le bloc trio"
        assert trio.issubset(pair)
        assert len(pair - trio) == 1, "la séance du bloc paire est DISTINCTE de celle du bloc trio"


# ── Arbitrage n°3 — une séance de bloc ne fausse pas l'exact-K d'un groupe imbriqué ──────────────


class TestBlockDoesNotFoulInnerGroupExactK:
    def test_a_block_over_a_group_leaves_the_group_its_own_common_session(self) -> None:
        """Groupe {A,B} K=1 ET bloc {A,B,C} 1 séance. La séance de bloc (A,B,C ensemble) NE compte
        PAS pour l'exact-K du groupe (exclusion arbitrage n°3) : le groupe garde SA séance commune,
        distincte. Résultat : A/B ensemble DEUX fois (la séance de bloc + la séance de groupe).
        SANS l'exclusion, la co-présence imposée par le bloc satisferait le K du groupe et A/B ne
        seraient ensemble qu'une fois (ce test tomberait)."""
        teams = [
            make_team("A", sessions_per_week=2),
            make_team("B", sessions_per_week=2),
            make_team("C", sessions_per_week=1),
        ]
        venues = [
            make_venue("vb", [(1, "18:00"), (2, "18:00")], capacity=1),  # cases de BLOC (dé-comptées)
            make_venue("vg", [(3, "18:00")], capacity=2),  # case de GROUPE (les groupes n'ont pas de dé-comptage)
        ]
        payload = make_payload(teams=teams, venues=venues)
        payload["sharedBlocks"] = [_block("z", ["A", "B", "C"], 1)]
        payload["sharedTrainings"] = [{"id": "g", "teamIds": ["A", "B"], "commonSessions": 1}]
        result = solve_payload(payload)
        assert result["status"] == "completed"
        trio = _common(result, ["A", "B", "C"])
        pair = _common(result, ["A", "B"])
        assert len(trio) == 1, "le bloc place sa séance A,B,C"
        assert len(pair) == 2, "le groupe garde SA séance A,B, distincte de la séance de bloc"


# ── Arbitrage n°5 — la séance de bloc COMPTE pour les règles bien-être (one_session_per_day) ──────


class TestBlockCountsForImplicitRules:
    def test_block_session_counts_for_one_session_per_day(self) -> None:
        """Une équipe avec sa séance de bloc un jour ne peut pas avoir une séance individuelle le
        MÊME jour : la séance de bloc COMPTE dans ``one_session_per_day``. t1 (2 séances/sem) est
        forcée d'étaler ses deux séances sur DEUX jours distincts. SANS le comptage, elle pourrait
        empiler les deux le même jour (len(days) == 1)."""
        teams = [make_team("t1", sessions_per_week=2), make_team("t2", sessions_per_week=1)]
        venues = [
            make_venue("v1", [(1, "18:00")], capacity=1),
            make_venue("v2", [(1, "20:00")], capacity=1),
            make_venue("v3", [(2, "18:00")], capacity=1),
        ]
        payload = make_payload(teams=teams, venues=venues)
        payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]
        result = solve_payload(payload)
        assert result["status"] == "completed"
        days = {d for _, d, _ in _slots_of_team(result, "t1")}
        assert len(days) == 2, "les deux séances de t1 (bloc + individuelle) tombent sur des jours DISTINCTS"


# ── Arbitrage n°6 — la co-présence d'un bloc n'est pas un chevauchement de passerelle fautif ─────


class TestBlockExemptFromMandatoryPasserelle:
    def test_block_coplacement_is_exempt_from_a_mandatory_team_link(self) -> None:
        """Bloc {t1,t2} + passerelle MANDATORY t1-t2 : la co-présence des membres du bloc sur leur
        séance n'est PAS un chevauchement fautif (``team_share_declared_pairs`` inclut les blocs).
        SANS l'exemption, l'anti-chevauchement dur rendrait le bloc INFEASIBLE."""
        teams = [make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)]
        venues = [make_venue("v", [(1, "18:00"), (2, "18:00")], capacity=1)]
        payload = make_payload(teams=teams, venues=venues)
        payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]
        payload["teamLinks"] = [{"id": "l", "teamAId": "t1", "teamBId": "t2", "intensity": "MANDATORY"}]
        result = solve_payload(payload)
        assert result["status"] == "completed"
        assert len(_common(result, ["t1", "t2"])) == 1


# ── Arbitrage n°7 — un bloc non plaçable sort INFEASIBLE avec un diagnostic NOMMÉ ────────────────


class TestUnplaceableBlockIsDiagnosed:
    def test_a_block_needing_more_common_cases_than_exist_is_named(self) -> None:
        """Bloc {t1,t2} 2 séances communes, mais une SEULE case de gymnase existe : impossible de
        placer 2 séances distinctes → génération INFEASIBLE, diagnostic ``shared_block_not_honored``
        nommant le bloc."""
        teams = [make_team("t1", sessions_per_week=2), make_team("t2", sessions_per_week=2)]
        venues = [make_venue("v", [(1, "18:00")], capacity=1)]
        payload = make_payload(teams=teams, venues=venues)
        payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 2)]
        result = solve_payload(payload)
        assert result["status"] == "failed"
        assert any(d["type"] == "shared_block_not_honored" for d in result["diagnostics"])


# ── Arbitrage n°8 — LE VERDICT (D11) : retirer une équipe d'une séance de bloc est REFUSÉ ────────


def _verdict_payload(
    candidate: dict[str, Any],
    slot_templates: list[dict[str, Any]],
    *,
    blocks: list[dict[str, Any]],
    teams: list[dict[str, Any]],
    reference: dict[str, Any] | None = None,
) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "clubId": "club",
        "seasonId": "season",
        # Deux gymnases sur des jours DIFFÉRENTS : un candidat sur B/jeudi laisse LIBRE la case de
        # bloc A/mercredi (jour distinct ⇒ one_session_per_day ne l'attrape pas). Le solveur peut
        # donc « réinventer » la séance de bloc là-bas si le miroir déterministe n'est pas là — d'où
        # la nécessité du miroir (le HARD hérité ne suffit pas), prouvée par la falsification (c).
        "venues": [
            make_venue("A", [(3, "18:00"), (3, "20:00")], capacity=2),
            make_venue("B", [(4, "18:00")], capacity=2),
        ],
        "teams": teams,
        "coaches": [],
        "constraints": [],
        "slotTemplates": slot_templates,
        "sharedBlocks": blocks,
        "candidate": candidate,
    }
    if reference is not None:
        payload["reference"] = reference
    return payload


def _tmpl(tid: str, venue: str, day: int, start: str) -> dict[str, Any]:
    return {
        "id": f"s-{tid}-{start}",
        "teamId": tid,
        "venueId": venue,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": 90,
    }


class TestMoveVerdict:
    def test_moving_a_member_out_of_a_block_session_is_refused(self) -> None:
        """t1 et t2 partagent A/mercredi 18:00 (bloc, 1 séance). On déplace t1 vers B/jeudi 18:00
        (autre gymnase, autre jour). La source est exclue de la baseline (comme MoveSlotService), t2
        reste sur la case de bloc, la référence rejoue la position d'origine de t1. Le HARD hérité ne
        suffit PAS (jour distinct ⇒ le solveur pourrait replacer t1 sur A/mercredi) : c'est le MIROIR
        déterministe qui REFUSE, motif ``shared_block_broken``. Falsification (c) : miroir désactivé →
        ``valid: True`` à tort."""
        result = validate_assignment(
            ValidateAssignmentsInputSchema.model_validate(
                _verdict_payload(
                    candidate={
                        "teamId": "t1",
                        "venueId": "B",
                        "dayOfWeek": 4,
                        "startTime": "18:00",
                        "durationMinutes": 90,
                    },
                    slot_templates=[_tmpl("t2", "A", 3, "18:00")],
                    blocks=[_block("b", ["t1", "t2"], 1)],
                    teams=[make_team("t1", sessions_per_week=2), make_team("t2", sessions_per_week=2)],
                    reference={
                        "teamId": "t1",
                        "venueId": "A",
                        "dayOfWeek": 3,
                        "startTime": "18:00",
                        "durationMinutes": 90,
                    },
                )
            )
        )
        assert result["valid"] is False
        assert any(v["rule"] == "shared_block_broken" for v in result["violations"])

    def test_an_already_broken_block_does_not_lock_moves(self) -> None:
        """GARDE ANTI-ENFERMEMENT (P4-152) : si le bloc est DÉJÀ cassé dans la baseline (t1 n'était
        pas avec t2), déplacer t1 n'est PAS refusé au titre du bloc — le refus ne tombe que si le
        déplacement CASSE un bloc jusque-là honoré."""
        result = validate_assignment(
            ValidateAssignmentsInputSchema.model_validate(
                _verdict_payload(
                    candidate={
                        "teamId": "t1",
                        "venueId": "A",
                        "dayOfWeek": 3,
                        "startTime": "20:00",
                        "durationMinutes": 90,
                    },
                    # baseline : t2 seule sur A/mercredi 18:00 — t1 n'a JAMAIS été avec t2 (bloc cassé).
                    slot_templates=[_tmpl("t2", "A", 3, "18:00")],
                    blocks=[_block("b", ["t1", "t2"], 1)],
                    teams=[make_team("t1"), make_team("t2")],
                    reference={
                        "teamId": "t1",
                        "venueId": "B",
                        "dayOfWeek": 4,
                        "startTime": "18:00",
                        "durationMinutes": 90,
                    },
                )
            )
        )
        assert not any(v["rule"] == "shared_block_broken" for v in result["violations"])

    def test_moving_an_unrelated_team_is_not_refused_by_the_block(self) -> None:
        """t3 n'est dans aucun bloc : son déplacement ne doit jamais rougir la mutualisation par bloc."""
        result = validate_assignment(
            ValidateAssignmentsInputSchema.model_validate(
                _verdict_payload(
                    candidate={
                        "teamId": "t3",
                        "venueId": "B",
                        "dayOfWeek": 4,
                        "startTime": "18:00",
                        "durationMinutes": 90,
                    },
                    slot_templates=[_tmpl("t1", "A", 3, "18:00"), _tmpl("t2", "A", 3, "18:00")],
                    blocks=[_block("b", ["t1", "t2"], 1)],
                    teams=[make_team("t1"), make_team("t2"), make_team("t3")],
                )
            )
        )
        assert not any(v["rule"] == "shared_block_broken" for v in result["violations"])
