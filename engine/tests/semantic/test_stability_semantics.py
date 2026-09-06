"""NR sémantique — P3-21 PR A + P2-61 (axe §7.1 constraint semantics) : la PROXIMITÉ au
précédent oriente le PLACEMENT (phase 1, poids 9), une règle SAISIE ≥ 10 prime toujours.

P3-21 (stabilité de phase 2, poids 1 sous chaînage ×4096) ne départageait que des ex æquo
EXACTS ; P2-61 fait entrer la proximité dans l'objectif de PLACEMENT à poids 9, si bien qu'une
séance retrouve sa place précédente même quand la version source score SOUS l'optimum (écart
< 9). Ce que ce fichier garde, chacun falsifié dans les deux sens (un contrôle POSITIF prouve
que le terme est ACTIF, pas inerte) :
  * une contrainte HARD prime toujours (un créneau précédent devenu interdit BOUGE) ;
  * un écart de score saisi < 9 (une PREFERRED TIME isolée, +5) CÈDE : le créneau précédent
    TIENT — le retournement assumé de l'ancien « un écart de score réel bouge » (P2-61) ;
  * un écart de score saisi ≥ 9 (une PREFERRED gymnase, +10) PRIME : le créneau BOUGE.
"""

from __future__ import annotations

from typing import Any

from tests.support import make_payload, make_team, make_venue, solve_payload, team_constraint

TEAM = "team-d"
VENUE = "venue-1"
DAY_A = 1
DAY_B = 3


def _placed_day(result: dict[str, Any]) -> int:
    days = [int(s["dayOfWeek"]) for s in result["slots"] if str(s["teamId"]) == TEAM]
    assert len(days) == 1, f"exactement une séance attendue, obtenu {days}"
    return days[0]


def _prev_a() -> list[dict[str, Any]]:
    return [{"teamId": TEAM, "venueId": VENUE, "dayOfWeek": DAY_A, "startTime": "18:00"}]


# --- Cas 1 : un créneau précédent devenu HARD-interdit BOUGE ---------------------------


def _payload_two_days(constraints: list[dict[str, Any]] | None = None) -> dict[str, Any]:
    return make_payload(
        teams=[make_team(TEAM, sessions_per_week=1, priority_tier_id=5)],
        venues=[make_venue(VENUE, [(DAY_A, "18:00"), (DAY_B, "18:00")])],
        constraints=constraints,
    )


def test_hard_forbidden_beats_stability() -> None:
    """previous = jour A ; interdire A (HARD) → la séance passe au jour B."""
    # Contrôle positif : sans l'interdit, la stabilité restitue bien A.
    control = _payload_two_days()
    control["previousAssignments"] = _prev_a()
    assert _placed_day(solve_payload(control, timeout=10)) == DAY_A, "contrôle : la stabilité fixe bien A"

    forbid_a = [
        team_constraint(
            constraint_id="forbid-a",
            team_id=TEAM,
            family="DAY",
            rule_type="HARD",
            config={"forbiddenDays": [DAY_A]},
        )
    ]
    payload = _payload_two_days(constraints=forbid_a)
    payload["previousAssignments"] = _prev_a()
    result = solve_payload(payload, timeout=10)
    assert result["status"] == "completed"
    assert _placed_day(result) == DAY_B, "une contrainte HARD prime la stabilité : le créneau bouge"


# --- Cas 2 : un écart de score saisi < 9 CÈDE, le créneau précédent TIENT (P2-61) -------


def test_gap_below_proximity_weight_keeps_the_previous_slot() -> None:
    """P2-61 — RETOURNEMENT ASSUMÉ de l'ancien ``test_real_score_gap_beats_stability``.

    previous = jour A (18:00) ; une PREFERRED TIME favorise le jour B (20:00, +5). L'écart
    saisi (5) est SOUS le poids de proximité (9) : la séance retrouve son créneau précédent A
    même si B score mieux au barème saisi. Conséquence assumée (fondateur 2026-09-06) : une
    préférence de jour/heure ISOLÉE (< 9) cède à la proximité au précédent."""
    venues = [make_venue(VENUE, [(DAY_A, "18:00"), (DAY_B, "20:00")])]

    prefer_late = [
        team_constraint(
            constraint_id="prefer-late",
            team_id=TEAM,
            family="TIME",
            rule_type="PREFERRED",
            config={"minStartTime": "19:00"},
        )
    ]

    # Contrôle positif : MÊME préférence, SANS previous → la proximité n'agit pas, B (+5) gagne.
    control = make_payload(
        teams=[make_team(TEAM, sessions_per_week=1, priority_tier_id=5)],
        venues=venues,
        constraints=prefer_late,
    )
    assert _placed_day(solve_payload(control, timeout=10)) == DAY_B, "contrôle : sans previous, +5 fixe B"

    payload = make_payload(
        teams=[make_team(TEAM, sessions_per_week=1, priority_tier_id=5)],
        venues=venues,
        constraints=prefer_late,
    )
    payload["previousAssignments"] = _prev_a()
    result = solve_payload(payload, timeout=10)
    assert result["status"] == "completed"
    assert _placed_day(result) == DAY_A, "un écart saisi < 9 cède à la proximité : le créneau précédent tient"


# --- Cas 3 : un écart de score saisi ≥ 9 PRIME, le créneau BOUGE (P2-61) -----------------

VENUE_1 = "venue-1"
VENUE_2 = "venue-2"


def _prev_v1() -> list[dict[str, Any]]:
    return [{"teamId": TEAM, "venueId": VENUE_1, "dayOfWeek": DAY_A, "startTime": "18:00"}]


def test_preferred_venue_above_proximity_weight_moves_the_session() -> None:
    """P2-61 — une règle SAISIE ≥ 9 prime la proximité. Une PREFERRED gymnase (+10 > 9) sur v2
    déplace la séance vers v2 (jour B) malgré le précédent sur v1 (jour A)."""
    teams = [make_team(TEAM, sessions_per_week=1, priority_tier_id=5)]
    venues = [make_venue(VENUE_1, [(DAY_A, "18:00")]), make_venue(VENUE_2, [(DAY_B, "18:00")])]

    prefer_v2 = [
        team_constraint(
            constraint_id="prefer-v2",
            team_id=TEAM,
            family="FACILITY",
            rule_type="PREFERRED",
            config={"preferredVenueId": VENUE_2},
        )
    ]

    # Contrôle positif : SANS la préférence, la proximité restitue le créneau précédent (v1, jour A).
    control = make_payload(teams=teams, venues=venues)
    control["previousAssignments"] = _prev_v1()
    assert _placed_day(solve_payload(control, timeout=10)) == DAY_A, "contrôle : sans préférence, proximité fixe v1/A"

    payload = make_payload(teams=teams, venues=venues, constraints=prefer_v2)
    payload["previousAssignments"] = _prev_v1()
    result = solve_payload(payload, timeout=10)
    assert result["status"] == "completed"
    assert _placed_day(result) == DAY_B, "une PREFERRED gymnase (+10 > 9) prime la proximité : le créneau bouge"


# --- Cas 4 : FRONTIÈRE C−D — égalité exacte tranchée par la sous-bande (wobble assumé) --

C_TEAM = "team-c-front"
D_TEAM = "team-d-front"
FRONT_VENUE = "venue-front"


def _placed_teams(result: dict[str, Any]) -> set[str]:
    return {str(s["teamId"]) for s in result["slots"]}


def test_c_d_frontier_previous_holds_via_low_band() -> None:
    """P2-61 — FRONTIÈRE C−D (wobble ASSUMÉ). Une SEULE place pour deux (capacité 1) : une équipe
    D restituée à son créneau précédent vaut 21 + proximité 9 = 30 = une équipe C nue (30) — ÉGALITÉ
    EXACTE de placement. Elle est tranchée en PHASE 2 par la sous-bande stabilité (+1 vers le
    précédent) → l'équipe D (incumbent) garde la place. Contrôle : sans previous, le tier SUPÉRIEUR
    C prend la place (aucune inversion S/A/B/C hors frontière).

    Le nombre de séances placées (1 sur 2, imposé par la capacité) est le MÊME avec et sans
    proximité : la proximité ne SUPPRIME rien, elle ne fait que départager l'attributaire."""
    teams = [
        make_team(C_TEAM, sessions_per_week=1, priority_tier_id=4),
        make_team(D_TEAM, sessions_per_week=1, priority_tier_id=5),
    ]
    venues = [make_venue(FRONT_VENUE, [(DAY_A, "18:00")])]  # capacité 1 : une seule place

    # Contrôle : sans previous, le tier supérieur (C) gagne la place unique ; D est écartée.
    control = solve_payload(make_payload(teams=teams, venues=venues), timeout=10)
    assert control["status"] != "failed"
    assert _placed_teams(control) == {C_TEAM}, f"contrôle : le tier C prend la place, obtenu {_placed_teams(control)}"

    payload = make_payload(teams=teams, venues=venues)
    payload["previousAssignments"] = [
        {"teamId": D_TEAM, "venueId": FRONT_VENUE, "dayOfWeek": DAY_A, "startTime": "18:00"}
    ]
    result = solve_payload(payload, timeout=10)
    assert result["status"] != "failed"
    assert _placed_teams(result) == {D_TEAM}, (
        f"à la frontière C/D la proximité (9) égalise et la sous-bande fait tenir le précédent (D), "
        f"obtenu {_placed_teams(result)}"
    )
