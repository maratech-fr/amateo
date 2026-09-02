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

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload


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
