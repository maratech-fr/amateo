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
        "candidates": [candidate],
    }
    if reference is not None:
        payload["references"] = [reference]
    return payload


def _verdict_payload_multi(
    candidates: list[dict[str, Any]],
    slot_templates: list[dict[str, Any]],
    *,
    blocks: list[dict[str, Any]],
    teams: list[dict[str, Any]],
    references: list[dict[str, Any]] | None = None,
    venues: list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    """Comme ``_verdict_payload`` mais N candidats sous UN verdict (déplacement de bloc, P2-51
    PR-5b) : ``candidates`` / ``references`` sont des LISTES appariées par index — la vraie forme du
    contrat 2.18, écrite en clair (pas de shim ici)."""
    payload: dict[str, Any] = {
        "clubId": "club",
        "seasonId": "season",
        "venues": venues
        or [
            make_venue("A", [(3, "18:00"), (3, "20:00")], capacity=2),
            make_venue("B", [(4, "18:00")], capacity=2),
        ],
        "teams": teams,
        "coaches": [],
        "constraints": [],
        "slotTemplates": slot_templates,
        "sharedBlocks": blocks,
        "candidates": candidates,
    }
    if references is not None:
        payload["references"] = references
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


# ── P2-51 PR-5b — LE VERDICT DE GROUPE (D11) : N déplacements sous UN verdict, état FINAL ─────────


class TestBlockGroupMoveVerdict:
    """Le déplacement du BLOC ENTIER (rail ``/move-group``) : N candidats jugés ENSEMBLE, sur l'état
    FINAL. C'est le cœur de PR-5b — juger chaque déplacement isolément verrait le bloc « cassé » à
    chaque étape, alors qu'il se RECONSTITUE à la nouvelle case."""

    def test_moving_all_members_together_to_one_new_case_is_accepted(self) -> None:
        """Falsification (c) — LE test du rail. Bloc {t1,t2} 1 séance à A/mercredi 18:00. On déplace
        les DEUX membres vers A/mercredi 20:00 (même nouvelle case, capacité 2). Les deux sources
        sont exclues de la baseline (comme MoveGroupService) ; les deux candidats posent la séance de
        bloc au nouvel endroit → le bloc reste HONORÉ, verdict ``valid: True``. Si la généralisation
        est cassée (le miroir re-sérialise le jugement, ne voyant qu'UN candidat à la fois), t2
        n'aurait aucune case et le bloc paraîtrait rompu → refus ``shared_block_broken`` à tort, ce
        test ROUGIT."""
        result = validate_assignment(
            ValidateAssignmentsInputSchema.model_validate(
                _verdict_payload_multi(
                    candidates=[
                        {"teamId": "t1", "venueId": "A", "dayOfWeek": 3, "startTime": "20:00", "durationMinutes": 90},
                        {"teamId": "t2", "venueId": "A", "dayOfWeek": 3, "startTime": "20:00", "durationMinutes": 90},
                    ],
                    slot_templates=[],  # les DEUX sources retirées de la baseline (déplacement de bloc)
                    blocks=[_block("b", ["t1", "t2"], 1)],
                    teams=[make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)],
                    references=[
                        {"teamId": "t1", "venueId": "A", "dayOfWeek": 3, "startTime": "18:00", "durationMinutes": 90},
                        {"teamId": "t2", "venueId": "A", "dayOfWeek": 3, "startTime": "18:00", "durationMinutes": 90},
                    ],
                )
            )
        )
        assert result["valid"] is True, "déplacer TOUT le bloc vers une même case le reconstitue — accepté"
        assert not any(v["rule"] == "shared_block_broken" for v in result["violations"])

    def test_moving_the_members_to_two_different_cases_breaks_the_block(self) -> None:
        """Le pendant : déplacer les deux membres vers des cases DIFFÉRENTES (t1→A/mercredi 20:00,
        t2→B/jeudi 18:00) casse la séance commune → refus ``shared_block_broken`` (l'état final n'a
        plus aucune case partagée). Prouve que le verdict de groupe REFUSE toujours une vraie
        rupture, pas seulement qu'il accepte tout."""
        result = validate_assignment(
            ValidateAssignmentsInputSchema.model_validate(
                _verdict_payload_multi(
                    candidates=[
                        {"teamId": "t1", "venueId": "A", "dayOfWeek": 3, "startTime": "20:00", "durationMinutes": 90},
                        {"teamId": "t2", "venueId": "B", "dayOfWeek": 4, "startTime": "18:00", "durationMinutes": 90},
                    ],
                    slot_templates=[],
                    blocks=[_block("b", ["t1", "t2"], 1)],
                    teams=[make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)],
                    references=[
                        {"teamId": "t1", "venueId": "A", "dayOfWeek": 3, "startTime": "18:00", "durationMinutes": 90},
                        {"teamId": "t2", "venueId": "A", "dayOfWeek": 3, "startTime": "18:00", "durationMinutes": 90},
                    ],
                )
            )
        )
        assert result["valid"] is False
        assert any(v["rule"] == "shared_block_broken" for v in result["violations"])


# ── NR-B — le miroir de bloc raisonne sur l'ÉTAT FINAL COMPLET (ensembles de cases par équipe) ────


def _block_venue() -> dict[str, Any]:
    """Un gymnase A, capacité 2, offrant les cases 19:30 des jours 1/3/4/5 — de quoi rejouer le lot
    réel refusé à tort (U18F1/U18F2)."""
    return make_venue("A", [(1, "19:30"), (3, "19:30"), (4, "19:30"), (5, "19:30")], capacity=2, duration_minutes=90)


class TestBlockGroupMoveFinalStateSets:
    """Un membre peut être déplacé PLUSIEURS fois dans le MÊME lot (ses deux séances bougent). Le
    miroir raisonne alors sur des ENSEMBLES de cases par équipe (toutes les références ré-ajoutées,
    tous les candidats), pas une seule case par équipe (dernière gagne). Le ``dict`` collapsant
    perdait les autres cases et déclarait le bloc rompu à tort."""

    def test_a_member_moved_twice_keeps_the_block_honored(self) -> None:
        """LE cas réel refusé à tort (U18F1/U18F2, K=2). Baseline gelée : t1 {(A,4),(A,5)}, t2 {(A,4)}.
        t2 est déplacée DEUX fois. « avant » (baseline + toutes les références) : commun {(A,4),(A,5)}=2.
        « après » (baseline + tous les candidats) : commun {(A,4),(A,1)}=2 — le bloc RESTE honoré → accepté.
        Si le miroir garde « une case par équipe » (dernière gagne), le candidat co-localisé (A,1,19:30)
        de t2 disparaît et l'après tombe à 1 → refus ``shared_block_broken`` à tort : ce test ROUGIT."""
        result = validate_assignment(
            ValidateAssignmentsInputSchema.model_validate(
                _verdict_payload_multi(
                    candidates=[
                        {"teamId": "t1", "venueId": "A", "dayOfWeek": 1, "startTime": "19:30", "durationMinutes": 90},
                        {"teamId": "t2", "venueId": "A", "dayOfWeek": 1, "startTime": "19:30", "durationMinutes": 90},
                        {"teamId": "t2", "venueId": "A", "dayOfWeek": 3, "startTime": "19:30", "durationMinutes": 90},
                    ],
                    slot_templates=[
                        _tmpl("t1", "A", 4, "19:30"),
                        _tmpl("t1", "A", 5, "19:30"),
                        _tmpl("t2", "A", 4, "19:30"),
                    ],
                    blocks=[_block("b", ["t1", "t2"], 2)],
                    teams=[make_team("t1", sessions_per_week=3), make_team("t2", sessions_per_week=3)],
                    references=[
                        {"teamId": "t1", "venueId": "A", "dayOfWeek": 2, "startTime": "18:15", "durationMinutes": 90},
                        {"teamId": "t2", "venueId": "A", "dayOfWeek": 2, "startTime": "19:30", "durationMinutes": 90},
                        {"teamId": "t2", "venueId": "A", "dayOfWeek": 5, "startTime": "19:30", "durationMinutes": 90},
                    ],
                    venues=[_block_venue()],
                )
            )
        )
        assert result["valid"] is True, "le bloc se reconstitue sur l'état final complet — accepté"
        assert not any(v["rule"] == "shared_block_broken" for v in result["violations"])

    def test_a_moved_member_rejoining_a_partners_untouched_session_is_credited(self) -> None:
        """Reformation CRÉDITÉE : t2 n'est pas déplacée (deux séances baseline (A,3),(A,4)). t1 quitte
        (A,3) — où elle était avec t2 — pour (A,4), l'AUTRE séance baseline de t2 : elle reforme la
        commune ailleurs. « après » commun = {(A,4)} = 1 ≥ 1 → accepté. Sans créditer le candidat qui
        atterrit sur une séance baseline non déplacée du partenaire, l'après tomberait à 0 → refus."""
        result = validate_assignment(
            ValidateAssignmentsInputSchema.model_validate(
                _verdict_payload_multi(
                    candidates=[
                        {"teamId": "t1", "venueId": "A", "dayOfWeek": 4, "startTime": "19:30", "durationMinutes": 90},
                    ],
                    slot_templates=[_tmpl("t2", "A", 3, "19:30"), _tmpl("t2", "A", 4, "19:30")],
                    blocks=[_block("b", ["t1", "t2"], 1)],
                    teams=[make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=2)],
                    references=[
                        {"teamId": "t1", "venueId": "A", "dayOfWeek": 3, "startTime": "19:30", "durationMinutes": 90},
                    ],
                    venues=[_block_venue()],
                )
            )
        )
        assert result["valid"] is True
        assert not any(v["rule"] == "shared_block_broken" for v in result["violations"])

    def test_removing_a_member_from_an_honored_common_without_reforming_is_still_refused(self) -> None:
        """Non-régression : le fix des ensembles ne doit PAS relâcher le refus légitime. t1 et t2
        partagent (A,4) (bloc K=1) ; t1 part vers (A,1) SANS reformer (t2 reste seule sur (A,4)) →
        l'après n'a plus de commune → refus ``shared_block_broken`` maintenu."""
        result = validate_assignment(
            ValidateAssignmentsInputSchema.model_validate(
                _verdict_payload_multi(
                    candidates=[
                        {"teamId": "t1", "venueId": "A", "dayOfWeek": 1, "startTime": "19:30", "durationMinutes": 90},
                    ],
                    slot_templates=[_tmpl("t2", "A", 4, "19:30")],
                    blocks=[_block("b", ["t1", "t2"], 1)],
                    teams=[make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)],
                    references=[
                        {"teamId": "t1", "venueId": "A", "dayOfWeek": 4, "startTime": "19:30", "durationMinutes": 90},
                    ],
                    venues=[_block_venue()],
                )
            )
        )
        assert result["valid"] is False
        assert any(v["rule"] == "shared_block_broken" for v in result["violations"])
