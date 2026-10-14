"""Lot 3B PR A — « un coach suffit » (Q4B, décision fondateur 2026-10-10).

NR de l'axe §7.1 *constraint semantics*, à travers le VRAI pipeline (``solve_payload``) : une
contrainte d'indisponibilité de coach saisie est honorée par le solveur AU SENS Q4B — dès qu'une
séance a plusieurs coachs requis (équipe seule multi-coachs OU séance de BLOC, union des coachs des
membres), UN seul coach disponible suffit.

Origine prouvée en prod (link 2026-10-10) : Toussaint BCCL, un bloc U11 de 4 équipes aux
coachs mono-jour incompatibles sortait INFEASIBLE alors qu'un coach de l'union couvre chaque jour.
Chaque test est écrit pour ROUGIR si la direction est retirée :
  1. cas prod reproduit : bloc de 4 équipes U11, coachs mardi-seul / mercredi-seul, 0 verrou →
     COMPLETED, la séance de bloc tombe un jour où un coach de l'union est disponible ;
  2. témoin : AUCUN coach de l'union disponible sur la seule case candidate → INFEASIBLE (« failed ») ;
  3. équipe SEULE à 2 coachs requis, l'un indisponible le seul jour → placée (l'autre couvre) ;
  4. équipe SEULE, TOUS les coachs indisponibles le seul jour → fermée (aucune séance) ;
  5. abus : un membre sans coach propre n'est JAMAIS en solo sur une case de bloc (présent QUE via le
     bloc) — la relaxation ``x <= Σ b`` ne dégénère pas en « placer sans coach ».
"""

from __future__ import annotations

from typing import Any

from tests.support import (
    coach_availability,
    make_payload,
    make_team,
    make_venue,
    solve_payload,
    team_coach,
)


def _slots_of_team(output: dict[str, Any], team_id: str) -> set[tuple[str, int, str]]:
    return {
        (str(s["venueId"]), int(s["dayOfWeek"]), str(s["startTime"])[:5])
        for s in output["slots"]
        if str(s["teamId"]) == team_id
    }


def _common(output: dict[str, Any], team_ids: list[str]) -> set[tuple[str, int, str]]:
    sets = [_slots_of_team(output, t) for t in team_ids]
    return set.intersection(*sets) if sets else set()


def _block(block_id: str, team_ids: list[str], common_sessions: int = 1) -> dict[str, Any]:
    return {"id": block_id, "teamIds": team_ids, "commonSessions": common_sessions}


# ── 1. Cas prod BCCL : bloc de 4 U11, coachs mono-jour, un coach de l'union suffit ───────────────


def test_bccl_shared_block_one_union_coach_per_day_is_completed() -> None:
    # 4 U11 en bloc (1 séance commune). Deux coachs ne sont dispo QUE le mardi (jour 2), deux QUE le
    # mercredi (jour 3) : aucun jour n'a les 4 coachs, mais chaque jour a AU MOINS un coach de
    # l'union. ANCIEN sens (« chaque coach requis ferme ») → le mardi fermait u3/u4, le mercredi
    # u1/u2 → aucune case commune → INFEASIBLE. Q4B : la case de bloc emprunte le coach dispo du jour.
    teams = [make_team(f"u{i}", sessions_per_week=1) for i in (1, 2, 3, 4)]
    venue = make_venue("v", [(2, "18:00"), (3, "18:00")], capacity=1)
    constraints = [
        team_coach("tc1", team_id="u1", coach_id="cA"),
        team_coach("tc2", team_id="u2", coach_id="cB"),
        team_coach("tc3", team_id="u3", coach_id="cC"),
        team_coach("tc4", team_id="u4", coach_id="cD"),
        coach_availability("avA", "cA", available_days=[2]),  # mardi seul
        coach_availability("avB", "cB", available_days=[2]),  # mardi seul
        coach_availability("avC", "cC", available_days=[3]),  # mercredi seul
        coach_availability("avD", "cD", available_days=[3]),  # mercredi seul
    ]
    payload = make_payload(teams=teams, venues=[venue], constraints=constraints)
    payload["sharedBlocks"] = [_block("b", ["u1", "u2", "u3", "u4"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "completed", f"un coach de l'union par jour suffit : {result['status']}"
    common = _common(result, ["u1", "u2", "u3", "u4"])
    assert len(common) == 1, f"le bloc doit placer sa séance commune unique : {common}"
    (_venue, block_day, _start) = next(iter(common))
    # La séance tombe un jour où un coach de l'union est RÉELLEMENT disponible (mardi: cA/cB,
    # mercredi: cC/cD) — jamais un jour sans aucun coach.
    assert block_day in (2, 3), f"la séance de bloc doit tomber un jour servi par l'union : jour {block_day}"


# ── 2. Témoin : aucun coach de l'union disponible → INFEASIBLE ───────────────────────────────────


def test_shared_block_no_union_coach_available_is_infeasible() -> None:
    # Même bloc, mais la SEULE case candidate (mardi) n'a AUCUN coach de l'union dispo (les 4 ne sont
    # libres que le mercredi, et le gymnase n'ouvre que le mardi). b == 0 sur la case ⇒ Σb (0) ne peut
    # valoir commonSessions (1) ⇒ INFEASIBLE. C'est le témoin : « un coach suffit » n'invente pas un
    # coach quand il n'y en a aucun.
    teams = [make_team(f"u{i}", sessions_per_week=1) for i in (1, 2, 3, 4)]
    venue = make_venue("v", [(2, "18:00")], capacity=1)  # mardi uniquement
    constraints = [
        team_coach("tc1", team_id="u1", coach_id="cA"),
        team_coach("tc2", team_id="u2", coach_id="cB"),
        team_coach("tc3", team_id="u3", coach_id="cC"),
        team_coach("tc4", team_id="u4", coach_id="cD"),
        coach_availability("avA", "cA", available_days=[3]),  # mercredi seul → off le mardi
        coach_availability("avB", "cB", available_days=[3]),
        coach_availability("avC", "cC", available_days=[3]),
        coach_availability("avD", "cD", available_days=[3]),
    ]
    payload = make_payload(teams=teams, venues=[venue], constraints=constraints)
    payload["sharedBlocks"] = [_block("b", ["u1", "u2", "u3", "u4"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "failed", "aucun coach de l'union le seul jour ⇒ le bloc est infaisable"


# ── 3. Équipe seule à 2 coachs requis, l'un indisponible → placée ────────────────────────────────


def test_solo_team_two_coaches_one_unavailable_is_still_placed() -> None:
    # Pas de bloc : une équipe à 2 coachs requis, le second indisponible le seul jour ouvert ; le
    # premier le couvre. Q4B : la séance est placée (ANCIEN sens → jour fermé, aucune séance).
    venue = make_venue("v", [(4, "18:00")], capacity=1)  # jeudi uniquement
    constraints = [
        team_coach("tc1", team_id="t", coach_id="coach-A"),
        team_coach("tc2", team_id="t", coach_id="coach-B"),
        coach_availability("av", "coach-B", unavailable_days=[4]),  # B indispo jeudi, A libre
    ]
    payload = make_payload(teams=[make_team("t", sessions_per_week=1)], venues=[venue], constraints=constraints)

    result = solve_payload(payload)

    assert _slots_of_team(result, "t") == {("v", 4, "18:00")}, "coach-A libre suffit : séance placée jeudi"


# ── 4. Équipe seule, tous les coachs indisponibles → fermée ──────────────────────────────────────


def test_solo_team_all_coaches_unavailable_is_closed() -> None:
    # Les DEUX coachs requis indisponibles le seul jour ouvert : aucun ne couvre ⇒ la case reste
    # fermée (hors bloc, Q4B ne relâche rien quand TOUS sont bloqués). Aucune séance pour l'équipe.
    venue = make_venue("v", [(4, "18:00")], capacity=1)
    constraints = [
        team_coach("tc1", team_id="t", coach_id="coach-A"),
        team_coach("tc2", team_id="t", coach_id="coach-B"),
        coach_availability("avA", "coach-A", unavailable_days=[4]),
        coach_availability("avB", "coach-B", unavailable_days=[4]),
    ]
    payload = make_payload(teams=[make_team("t", sessions_per_week=1)], venues=[venue], constraints=constraints)

    result = solve_payload(payload)

    assert _slots_of_team(result, "t") == set(), "tous les coachs indisponibles ⇒ aucune séance"


# ── 5. Abus : un membre sans coach propre n'est jamais en solo sur une case de bloc ──────────────


def test_coachless_member_is_never_placed_solo_on_a_block_case() -> None:
    # t1 n'a AUCUN coach dispo les jours ouverts (2 et 4) ; t2 a le sien dispo les deux. Bloc {t1,t2}
    # à 1 séance commune : t1 n'existe sur une case QUE si le bloc y est actif (``x <= Σ b``). t1
    # RÉCLAME 2 séances/semaine — sans la relaxation bornée, le solveur l'enverrait seule sur le jour
    # SANS bloc (sans coach) pour grappiller sa 2ᵉ séance. La borne l'en empêche : t1 ne paraît
    # jamais là où t2 n'est pas.
    teams = [make_team("t1", sessions_per_week=2), make_team("t2", sessions_per_week=1)]
    venue = make_venue("v", [(2, "18:00"), (4, "18:00")], capacity=1)
    constraints = [
        team_coach("tc1", team_id="t1", coach_id="cT1"),
        team_coach("tc2", team_id="t2", coach_id="cT2"),
        coach_availability("avT1", "cT1", unavailable_days=[2, 4]),  # t1 sans coach les 2 jours
        # cT2 : aucune indisponibilité → dispo les deux jours (union servie).
    ]
    payload = make_payload(teams=teams, venues=[venue], constraints=constraints)
    payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "completed", f"le bloc est plaçable (t2 couvre) : {result['status']}"
    t1_slots = _slots_of_team(result, "t1")
    t2_slots = _slots_of_team(result, "t2")
    assert t1_slots, "t1 doit obtenir sa séance commune via le bloc"
    assert t1_slots <= t2_slots, (
        f"t1 (sans coach propre) ne doit paraître QUE sur la case de bloc partagée avec t2 — "
        f"jamais en solo : t1={sorted(t1_slots)} vs t2={sorted(t2_slots)}"
    )
