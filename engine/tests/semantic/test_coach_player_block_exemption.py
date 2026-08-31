"""Exemption coach-joueur sur la SÉANCE DE BLOC — sémantique bout-en-bout (vraie pipeline).

Cas réel BCCL : une même personne COACHE une équipe et JOUE dans une autre, les deux réunies
dans un bloc de mutualisation. Sur la case de bloc ACTIVE il n'y a physiquement qu'UNE séance
(les deux équipes s'entraînent ensemble), donc pas de conflit de rôle : l'anti-chevauchement
coach-joueur DOIT s'effacer là — et LÀ SEULEMENT.

Arbitrages (co-construits avec le fondateur) prouvés ici :
  * l'exemption ne vaut QUE si la séance de bloc est active sur la case — une coïncidence de
    deux séances SOLO des mêmes équipes au même gymnase+heure (capacité ≥ 2) reste un conflit ;
  * « même case » = même gymnase + même jour + même heure de DÉBUT (la géométrie fine — un simple
    chevauchement, un autre gymnase — est prouvée au grain de la pose, voir
    ``tests/test_coach_player_block_exemption.py``) ;
  * parité génération ⇄ verdict : ``validate_assignments`` juge valide une baseline qui réunit la
    personne double-rôle sur une case de bloc.

Chaque test est écrit pour ROUGIR si sa direction est retirée. Axe structurant
``constraint semantics`` (CLAUDE.md §7.1).
"""

from __future__ import annotations

from typing import Any

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.validate_assignments import validate_assignment
from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload, team_coach


def _block(block_id: str, team_ids: list[str], common_sessions: int = 1) -> dict[str, Any]:
    return {"id": block_id, "teamIds": team_ids, "commonSessions": common_sessions}


def _coach_player(team_id: str, person_id: str) -> dict[str, Any]:
    """Lie une personne à une équipe comme JOUEUSE (``team_player_map``) — forme v1
    ``COACH_PLAYER_UNAVAILABILITY`` (cf. ``serializeCoachPlayerConstraints`` côté backend)."""
    return {
        "id": f"cp-{team_id}-{person_id}",
        "type": "COACH_PLAYER_UNAVAILABILITY",
        "isActive": True,
        "metadata": {"teamId": team_id, "coachId": person_id},
    }


def _person(person_id: str) -> dict[str, Any]:
    return {"id": person_id, "firstName": person_id, "lastName": "X", "isActive": True}


def _slots_of_team(output: dict[str, Any], team_id: str) -> set[tuple[str, int, str]]:
    return {
        (str(s["venueId"]), int(s["dayOfWeek"]), str(s["startTime"])[:5])
        for s in output["slots"]
        if str(s["teamId"]) == team_id
    }


def _common(output: dict[str, Any], team_ids: list[str]) -> set[tuple[str, int, str]]:
    sets = [_slots_of_team(output, t) for t in team_ids]
    return set.intersection(*sets) if sets else set()


# ── (a) LE CAS MAXIME — coach de SM1, joueur de SM2, bloc SM1+SM2 → COMPLETED ────────────────────


def test_a_coach_player_of_a_block_shares_the_active_block_case() -> None:
    """Maxime coache t1 et joue dans t2 ; bloc {t1,t2} 1 séance commune. La case de bloc réunit les
    deux équipes en UNE séance : le rôle double n'y est pas un conflit → génération COMPLETED avec
    exactement la case commune attendue. SANS l'exemption, ``add_coach_player_non_overlap`` oppose le
    coaching de t1 au jeu dans t2 sur cette case → le liage du bloc rend le solve INFEASIBLE."""
    teams = [make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)]
    venues = [make_venue("v", [(1, "18:00")], capacity=1)]
    constraints = [
        team_coach("tc-t1", "t1", "maxime"),  # Maxime COACHE t1
        _coach_player("t2", "maxime"),  # Maxime JOUE dans t2
    ]
    payload = make_payload(teams=teams, venues=venues, constraints=constraints, coaches=[_person("maxime")])
    payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "completed", "la case de bloc active exempte le rôle double coach-joueur"
    assert len(_common(result, ["t1", "t2"])) == 1


# ── (b) CONTRE-CAS D-A — b=0 : deux séances SOLO au même gymnase+heure restent un conflit ────────


def test_b_solo_coincidence_off_the_block_case_is_still_a_conflict() -> None:
    """Bloc {t1,t2} 1 séance, chaque équipe 2 séances, capacité 2. Deux cases partagées existent
    (jour 1 et jour 2, même gymnase, capacité 2) : le bloc en occupe UNE (b=1, exemptée). Sur
    l'AUTRE (b=0) une coïncidence solo+solo de la personne double-rôle reste un conflit → une seule
    équipe y garde sa 2ᵉ séance. Résultat : EXACTEMENT une case commune. SANS le garde b=1, l'exemption
    fuirait sur la case b=0 et les deux équipes se co-placeraient deux fois (``_common`` de taille 2)."""
    teams = [make_team("t1", sessions_per_week=2), make_team("t2", sessions_per_week=2)]
    venues = [make_venue("v", [(1, "18:00"), (2, "18:00")], capacity=2)]
    constraints = [
        team_coach("tc-t1", "t1", "maxime"),
        _coach_player("t2", "maxime"),
    ]
    payload = make_payload(teams=teams, venues=venues, constraints=constraints, coaches=[_person("maxime")])
    payload["sharedBlocks"] = [_block("b", ["t1", "t2"], 1)]

    result = solve_payload(payload)

    assert result["status"] == "completed"
    assert len(_common(result, ["t1", "t2"])) == 1, "la coïncidence solo hors case de bloc (b=0) reste interdite"


# ── (d) PARITÉ VERDICT — validate_assignments juge valide une baseline à case de bloc double-rôle ─


def test_d_verdict_accepts_a_baseline_with_a_double_role_person_on_a_block_case() -> None:
    """Parité génération ⇄ verdict : la baseline réunit t1 (coachée par Maxime) et t2 (où Maxime
    joue) sur leur case de bloc A/mercredi 18:00, et l'on déplace t3 (étrangère au bloc) vers un
    créneau libre B/jeudi. La couche HARD du verdict (``_apply_hard``) exempte la case de bloc
    exactement comme ``/generate`` → verdict VALIDE. SANS l'exemption, la baseline gelée serait
    INFEASIBLE (le rôle double y est opposé) et le déplacement neutre serait rejeté à tort."""
    payload: dict[str, Any] = {
        "clubId": "club",
        "seasonId": "season",
        "venues": [
            make_venue("A", [(3, "18:00")], capacity=2),
            make_venue("B", [(4, "18:00")], capacity=2),
        ],
        "teams": [
            make_team("t1", sessions_per_week=1),
            make_team("t2", sessions_per_week=1),
            make_team("t3", sessions_per_week=1),
        ],
        "coaches": [_person("maxime")],
        "constraints": [
            team_coach("tc-t1", "t1", "maxime"),
            _coach_player("t2", "maxime"),
        ],
        # Baseline : t1 et t2 réunis sur leur case de bloc (Maxime y coache l'une, joue dans l'autre).
        "slotTemplates": [
            {"id": "s-t1", "teamId": "t1", "venueId": "A", "dayOfWeek": 3, "startTime": "18:00", "durationMinutes": 90},
            {"id": "s-t2", "teamId": "t2", "venueId": "A", "dayOfWeek": 3, "startTime": "18:00", "durationMinutes": 90},
        ],
        "sharedBlocks": [_block("b", ["t1", "t2"], 1)],
        # Déplacement NEUTRE d'une équipe étrangère au bloc vers un créneau libre.
        "candidates": [{"teamId": "t3", "venueId": "B", "dayOfWeek": 4, "startTime": "18:00", "durationMinutes": 90}],
    }

    result = validate_assignment(ValidateAssignmentsInputSchema.model_validate(payload))

    assert result["valid"] is True, "la case de bloc double-rôle est faisable côté verdict comme côté génération"
