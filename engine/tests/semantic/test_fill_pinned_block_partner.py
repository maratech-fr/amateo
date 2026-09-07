"""P2-51 (comblement) — un partenaire de bloc ÉPINGLÉ laisse la place au membre LIBRE du MÊME bloc.

Structuring axis ``constraint semantics`` (CLAUDE.md §7.1) : une co-présence de bloc VOULUE doit
être honorée par le solveur, même quand un des membres est verrouillé HARD (cas du comblement d'un
plan de fermeture, où les séances transcrites du socle sont épinglées).

Décision fondateur D1 : le membre épinglé libère la case pour le(s) partenaire(s) libre(s) du MÊME
bloc, et pour EUX SEULS — une équipe non partenaire reste refusée sur une case saturée par un pin.

Ce fichier prouve les DEUX sites du kill par le VRAI pipeline (``solve_payload``) :
  * (a) même case, même départ — le montage retirait la variable du membre libre (model.py) ET le
        balayage capacité la fermait (structural.py). Les deux réparés → COMPLETED + co-présence.
  * (b) variante départs chevauchants — le membre libre co-localise sur la case du pin (même début),
        et son candidat à un début DIFFÉRENT qui chevauche le pin reste, lui, refusé (borne case).
  * (c) « à eux seuls » — une équipe NON partenaire reste refusée sur la case saturée par le pin.
Chaque test est écrit pour ROUGIR si le dé-comptage bloc-aware est retiré.
"""

from __future__ import annotations

from typing import Any

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload, team_constraint


def _hard_lock(team_id: str, venue_id: str, day: int, start: str, *, duration: int = 90) -> dict[str, Any]:
    return {
        "id": f"lock-{team_id}-{venue_id}-{day}-{start}",
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": duration,
        "lockLevel": "HARD",
    }


def _block(block_id: str, team_ids: list[str], common_sessions: int = 1) -> dict[str, Any]:
    return {"id": block_id, "teamIds": team_ids, "commonSessions": common_sessions}


def _cases_of(result: dict[str, Any], team_id: str) -> set[tuple[str, int, str]]:
    return {
        (str(s["venueId"]), int(s["dayOfWeek"]), str(s["startTime"])[:5])
        for s in result["slots"]
        if str(s["teamId"]) == team_id
    }


def test_free_partner_lands_on_the_exact_case_of_a_pinned_partner() -> None:
    """(a) t2 est ÉPINGLÉ HARD sur V/lundi/19:30 (gymnase capacité 1) ; t1, LIBRE, partage le bloc
    {t1,t2}. Sans le fix, model.py supprime la variable de t1 sur cette case ET structural.py la
    ferme (le verrou de t2 sature la capacité) → génération FAILED. Avec : t1 rejoint t2 sur la
    MÊME case, les deux tiennent en UNE occupation de capacité 1."""
    payload = make_payload(
        teams=[make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)],
        venues=[make_venue("V", [(1, "19:30")], capacity=1)],
        slot_templates=[_hard_lock("t2", "V", 1, "19:30")],
    )
    payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "completed"
    assert _cases_of(result, "t1") == {("V", 1, "19:30")}, "le membre libre rejoint la case du pin"
    assert _cases_of(result, "t2") == {("V", 1, "19:30")}
    assert not result["diagnostics"], "aucun conflit : la co-présence tient en une occupation"


def test_free_partner_co_locates_and_is_refused_on_an_overlapping_different_start() -> None:
    """(b) V/lundi propose 19:00 ET 19:30 (capacité 1) ; t2 épinglé à 19:30. Le membre libre t1 du
    bloc {t1,t2} DOIT co-localiser à 19:30 (la case du pin) et NON à 19:00 : un candidat à un début
    différent qui chevauche le pin n'est pas une co-présence de bloc — la borne de case ne se
    relâche que sur le début EXACT du verrou. Sans le fix bloc-aware : FAILED (les deux candidats
    de t1 fermés)."""
    payload = make_payload(
        teams=[make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)],
        venues=[make_venue("V", [(1, "19:00"), (1, "19:30")], capacity=1)],
        slot_templates=[_hard_lock("t2", "V", 1, "19:30")],
    )
    payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "completed"
    assert _cases_of(result, "t1") == {("V", 1, "19:30")}, "co-présence sur la case du pin, jamais à 19:00"
    assert _cases_of(result, "t2") == {("V", 1, "19:30")}


def test_a_non_partner_team_stays_refused_on_the_saturated_case() -> None:
    """(c) « à eux SEULS » : t3 n'est dans AUCUN bloc et n'a que V/lundi/19:30 comme case. Le pin de
    t2 + la co-présence du bloc {t1,t2} occupent l'unique place ; t3 ne peut pas s'y glisser. Le
    dé-comptage ne bénéficie qu'aux partenaires : t3 reste non placée."""
    payload = make_payload(
        teams=[
            make_team("t1", sessions_per_week=1),
            make_team("t2", sessions_per_week=1),
            make_team("t3", sessions_per_week=1),
        ],
        venues=[make_venue("V", [(1, "19:30")], capacity=1)],
        slot_templates=[_hard_lock("t2", "V", 1, "19:30")],
    )
    payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "completed"
    assert _cases_of(result, "t1") == {("V", 1, "19:30")}
    assert _cases_of(result, "t2") == {("V", 1, "19:30")}
    assert _cases_of(result, "t3") == set(), "une équipe non partenaire ne se glisse pas sur la case saturée"


def _has_shared_block_diag(result: dict[str, Any]) -> bool:
    return any(d.get("type") == "shared_block_not_honored" for d in result.get("diagnostics", []))


def test_free_session_of_a_block_member_does_not_form_a_second_common_case() -> None:
    """Le cas MESURÉ (BCCL, 2026-09-07). Bloc {t1,t2}, ``commonSessions = 1``, t1 ET t2 ÉPINGLÉS HARD
    ENSEMBLE sur la case C1 (V1/lun/17:30). t2 a une 2ᵉ séance ÉPINGLÉE HARD sur C2 (V2/ven/17:30,
    capacité 1). t1 a une 2ᵉ séance LIBRE et une préférence de gymnase qui rend C2 attractive ; une
    case libre tierce C3 (V3/mer/17:30) existe.

    SANS les fix, la séance libre de t1 se pose sur C2 À CÔTÉ du partenaire épinglé t2 (la porte
    capacité laisse passer le partenaire, la préférence l'y attire) → 2 cases communes RÉELLES pour
    ``commonSessions = 1`` → ``shared_block_not_honored`` que le solveur émet sur SA PROPRE solution.

    AVEC (Fix A : la case toute-épinglée C1 consomme le budget de séance commune ; Fix B : rejoindre
    l'épingle d'un partenaire n'est permis QU'au titre d'une séance de bloc active) : exactement UNE
    case commune (C1), t1 va en C3, t2 reste en C1+C2, aucun diagnostic.

    Le témoin est l'objectif : la préférence C2 attire t1, seul le fix l'écarte."""
    payload = make_payload(
        teams=[make_team("t1", sessions_per_week=2), make_team("t2", sessions_per_week=2)],
        venues=[
            make_venue("V1", [(1, "17:30")], capacity=1),
            make_venue("V2", [(5, "17:30")], capacity=1),
            make_venue("V3", [(3, "17:30")], capacity=1),
        ],
        constraints=[
            team_constraint(
                constraint_id="prefC2",
                team_id="t1",
                family="FACILITY",
                rule_type="PREFERRED",
                config={"preferredVenueId": "V2"},
            ),
        ],
        slot_templates=[
            _hard_lock("t1", "V1", 1, "17:30"),
            _hard_lock("t2", "V1", 1, "17:30"),
            _hard_lock("t2", "V2", 5, "17:30"),
        ],
    )
    payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "completed"
    common = _cases_of(result, "t1") & _cases_of(result, "t2")
    assert common == {("V1", 1, "17:30")}, "exactement UNE case commune (C1), pas de 2ᵉ sur C2"
    assert ("V2", 5, "17:30") not in _cases_of(result, "t1"), "t1 ne rejoint pas l'épingle de t2 sur C2"
    assert _cases_of(result, "t1") == {("V1", 1, "17:30"), ("V3", 3, "17:30")}, "t1 comble sur la case libre C3"
    assert not _has_shared_block_diag(result), "aucun shared_block_not_honored sur la propre solution du solveur"


def test_nested_fully_pinned_blocks_on_one_case_complete_without_infeasible() -> None:
    """Blocs IMBRIQUÉS (régression BCCL 2026-09-07). {A,B} ET {A,B,C} (``commonSessions = 1`` chacun),
    A, B et C ÉPINGLÉS HARD ENSEMBLE sur l'unique case C1 (V1/lun/17:30, capacité 1). A et B ont chacun
    une 2ᵉ séance LIBRE ; deux cases libres C2 (V2/mer) et C3 (V3/ven) existent. C n'a que son pin.

    C1 est toute-épinglée pour LES DEUX blocs. Fix A (``b == 1`` PAR bloc) forçait
    ``b_{A,B} = b_{A,B,C} = 1`` sur C1, mais la distinctness inter-blocs (deux blocs partageant A,B ne
    siègent pas sur la MÊME case) plafonne leur somme à 1 → ``1 + 1 <= 1`` INFEASIBLE en quelques ms
    (le comblement réel du club sortait FAILED). Fix A' pose ``Σ_{blocs toute-épinglés ici} b >= 1``
    sur la case : la distinctness attribue C1 à UN des deux blocs (ici le bloc de 3, dont C1 est la
    SEULE case candidate), l'autre garde son budget pour une case libre → génération ABOUTIT.

    CONTRÔLE POSITIF (consigné) : sur le code actuel (Fix A ``== 1``) ce test sort ``failed``."""
    payload = make_payload(
        teams=[
            make_team("t1", sessions_per_week=2),
            make_team("t2", sessions_per_week=2),
            make_team("t3", sessions_per_week=1),
        ],
        venues=[
            make_venue("V1", [(1, "17:30")], capacity=1),
            make_venue("V2", [(3, "17:30")], capacity=1),
            make_venue("V3", [(5, "17:30")], capacity=1),
        ],
        slot_templates=[
            _hard_lock("t1", "V1", 1, "17:30"),
            _hard_lock("t2", "V1", 1, "17:30"),
            _hard_lock("t3", "V1", 1, "17:30"),
        ],
    )
    payload["sharedBlocks"] = [_block("b2", ["t1", "t2"], 1), _block("b3", ["t1", "t2", "t3"], 1)]

    result = solve_payload(payload)

    # Le CŒUR de la régression : le comblement ABOUTIT (avant Fix A' : ``failed`` INFEASIBLE).
    assert result["status"] == "completed"
    # Le bloc de 3 tient sa séance commune sur C1 (sa seule case candidate) ; le bloc de 2 est
    # écarté de C1 par la distinctness et garde son budget pour une case libre.
    assert _cases_of(result, "t3") == {("V1", 1, "17:30")}
    common3 = _cases_of(result, "t1") & _cases_of(result, "t2") & _cases_of(result, "t3")
    assert common3 == {("V1", 1, "17:30")}, "le bloc de 3 se réunit sur C1"
    # La preuve INFEASIBLE « sur-épinglé » ne doit PAS accuser un bloc dont la case toute-épinglée
    # est PARTAGÉE (imbriquée) : C1 n'est EXCLUSIVE à aucun des deux blocs, aucune accusation.
    assert not any(d.get("id", "").startswith("shared-block-overpinned") for d in result.get("diagnostics", [])), (
        "aucune preuve « sur-épinglé » sur un cas imbriqué"
    )
    # ⚠ RÉSIDU CONNU, HORS PÉRIMÈTRE de ce fix (statut, pas warnings) : t1/t2 étant physiquement
    # ensemble sur C1 (séance du bloc de 3) ET sur leur case libre, la défense en profondeur
    # post-solve les compte à 2 co-présences (``shared-block-not-honored-b2``, WARNING), et le
    # comptage capacité fond {t1,t2} au lieu du bloc maximal {t1,t2,t3} sur C1. Ces deux
    # diagnostics vivent dans le comptage co-présence (branche OPTIMAL) et ``_fold_case_occupant_identity``
    # (``common.py``), pré-existants et non introduits ici ; ils n'affectent pas le STATUT.


def test_two_fully_pinned_cases_for_one_common_session_fail_and_name_the_block() -> None:
    """Verrou souverain mais DIAGNOSTIQUÉ (CLAUDE.md §6). Bloc {t1,t2}, ``commonSessions = 1``, mais
    t1 ET t2 sont épinglés HARD ENSEMBLE sur DEUX cases (C1 lun, C2 mar). Chaque case toute-épinglée
    est une séance commune RÉALISÉE (Fix A la force à 1) → ``Σb == 1`` avec deux ``b`` forcés à 1 est
    insatisfiable → génération FAILED, et le diagnostic nomme le bloc (motif « deux cases
    toute-épinglées > commonSessions »)."""
    payload = make_payload(
        teams=[make_team("t1", sessions_per_week=2), make_team("t2", sessions_per_week=2)],
        venues=[
            make_venue("V1", [(1, "17:30")], capacity=1),
            make_venue("V2", [(2, "17:30")], capacity=1),
        ],
        slot_templates=[
            _hard_lock("t1", "V1", 1, "17:30"),
            _hard_lock("t2", "V1", 1, "17:30"),
            _hard_lock("t1", "V2", 2, "17:30"),
            _hard_lock("t2", "V2", 2, "17:30"),
        ],
    )
    payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "failed"
    assert _has_shared_block_diag(result), "le bloc sur-contraint par ses propres pins doit être nommé"
