"""NR-C — le pré-check de capacité du VERDICT est BLOC-AWARE (§7.1 constraint semantics).

``diagnose_candidate_conflicts`` (le nommage post-solve d'un verdict infaisable) comptait les
occupants d'une case naïvement : deux membres d'un même bloc de mutualisation arrivant ENSEMBLE sur
leur case commune (cap 1) étaient criés ``venue_capacity`` — alors qu'un bloc = UN occupant, règle
déjà appliquée par le solveur (``add_room_at_most_one`` + dé-compte) et par la grille. On replie
donc l'identité d'occupant PAR CASE avec le MÊME ``_fold_case_occupant_identity`` que le sur-solde
post-solve (maison unique). Deux équipes SANS bloc commun restent deux occupants → refus inchangé.

On teste le pré-check DIRECTEMENT (il ne tourne que sur un solve infaisable) : c'est son locus exact.
"""

from __future__ import annotations

from typing import Any

from app.solver.constraints import diagnose_candidate_conflicts


def _slot(team: str, venue: str, day: int, start: str, *, duration: int = 90) -> dict[str, Any]:
    from app.solver.model import _time_to_minutes

    s = _time_to_minutes(start)
    return {"team_id": team, "venue_id": venue, "day": day, "start": s, "end": s + duration, "start_time": start}


def _capacity_violations(
    *,
    candidate: dict[str, Any],
    baseline_slots: list[dict[str, Any]],
    shared_blocks: list[dict[str, Any]],
    capacity: int,
) -> list[dict[str, Any]]:
    caps = {(str(candidate["venue_id"]), int(candidate["day"]), str(candidate["start_time"])): capacity}
    violations = diagnose_candidate_conflicts(
        candidate=candidate,
        baseline_slots=baseline_slots,
        parsed={},
        slot_capacities=caps,
        shared_blocks=shared_blocks,
    )
    return [v for v in violations if v["rule"] == "venue_capacity"]


def test_two_block_members_on_a_cap_one_case_is_not_over_capacity() -> None:
    """Deux membres d'un bloc déclaré arrivent ENSEMBLE sur (A, lun, 19:30) capacité 1 (le second
    est vu via ``baseline_slots``, comme le candidat frère augmenté du rail de groupe) → le bloc se
    fond en UN occupant → AUCUN ``venue_capacity``. Rougit sans le repli d'occupant."""
    caps_viol = _capacity_violations(
        candidate=_slot("t1", "A", 1, "19:30"),
        baseline_slots=[_slot("t2", "A", 1, "19:30")],
        shared_blocks=[{"id": "b", "teamIds": ["t1", "t2"]}],
        capacity=1,
    )
    assert caps_viol == []


def test_member_rejoining_a_partner_already_in_baseline_is_not_over_capacity() -> None:
    """Un membre rejoint son partenaire DÉJÀ posé (baseline) sur la case commune cap 1 : même
    repli, AUCUN ``venue_capacity`` (le diagnostic ne distingue pas candidat-frère et partenaire
    baseline — les deux fondent en un occupant)."""
    caps_viol = _capacity_violations(
        candidate=_slot("t1", "A", 1, "19:30"),
        baseline_slots=[_slot("t2", "A", 1, "19:30")],  # t2 déjà en place, non déplacée
        shared_blocks=[{"id": "b", "teamIds": ["t1", "t2"]}],
        capacity=1,
    )
    assert caps_viol == []


def test_two_teams_without_a_common_block_stay_over_capacity() -> None:
    """Deux équipes SANS bloc commun sur une case cap 1 → toujours ``venue_capacity`` (le refus
    légitime ne doit pas être relâché). Bloc présent mais ne contenant PAS le candidat : sans effet."""
    caps_viol = _capacity_violations(
        candidate=_slot("t1", "A", 1, "19:30"),
        baseline_slots=[_slot("t2", "A", 1, "19:30")],
        shared_blocks=[{"id": "b", "teamIds": ["t3", "t4"]}],
        capacity=1,
    )
    assert len(caps_viol) == 1
    assert caps_viol[0]["team_id"] == "t1"


def test_block_folds_but_a_third_unrelated_team_still_overflows() -> None:
    """Le repli ne masque pas une VRAIE sur-capacité : bloc {t1,t2} + une équipe tierce t3 sur une
    case cap 1 → 2 occupants (le bloc + t3) > 1 → ``venue_capacity`` maintenu."""
    caps_viol = _capacity_violations(
        candidate=_slot("t1", "A", 1, "19:30"),
        baseline_slots=[_slot("t2", "A", 1, "19:30"), _slot("t3", "A", 1, "19:30")],
        shared_blocks=[{"id": "b", "teamIds": ["t1", "t2"]}],
        capacity=1,
    )
    assert len(caps_viol) == 1
